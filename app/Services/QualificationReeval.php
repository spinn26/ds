<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Промоут-онли переоценка уровня квалификации по НГП.
 *
 * Платформа не повышает уровень партнёра по накоплению НГП автоматически.
 * Этот сервис добирает пропущенные повышения: у кого последний снимок
 * `qualificationLog` показывает уровень ниже положенного по его НГП (или ниже
 * легаси-грандфазера `consultant.status_and_lvl`) — поднимает уровень, ТОЛЬКО
 * вверх.
 *
 * target = max(уровень_по_НГП, status_and_lvl). НГП берём из ЛОГА
 * (qualificationLog.groupVolumeCumulative — истина). Пустые уровни (cur < 1)
 * НЕ трогаем.
 *
 * Пишем: последний снимок qualificationLog (nominalLevel=calculationLevel=target,
 * снимок конца месяца → новый % с 1-го числа СЛЕДУЮЩЕГО месяца) + промоут-онли
 * consultant.status_and_lvl (чтобы дашборды/пул/штрафы/отчёты видели уровень).
 *
 * Используется командой `partners:reeval-qualifications` и авто-вызовом при
 * сохранении матрицы квалификаций (AdminQualificationMatrixController) — чтобы
 * правка порога сразу разошлась по уровням.
 */
class QualificationReeval
{
    /** @var array<int,float>|null кэш порогов: уровень => требуемый НГП */
    private static ?array $thresholds = null;

    /**
     * Уровень по НГП: максимальный, чей порог `groupVolumeCumulative` взят.
     *
     * ⚠ `status_levels.mandatoryGP` (обязательный ГП месяца) НЕ применяется —
     * так работала платформа всегда, и владелец подтвердил это правило на пяти
     * разборах 25.08.2026 (Лебедев/Кутлугалямов/Рудин/Иванова/Виноградов).
     * Если mandatoryGP решат включить — это меняет уровни, менять надо здесь.
     */
    public static function levelForNgp(float $ngp): int
    {
        if (self::$thresholds === null) {
            self::$thresholds = DB::table('status_levels')
                ->orderBy('level')
                ->pluck('groupVolumeCumulative', 'level')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        $level = 1;
        foreach (self::$thresholds as $lvl => $required) {
            if ($ngp >= $required) {
                $level = max($level, (int) $lvl);
            }
        }

        return $level;
    }

    /** Сбросить кэш порогов (после правки матрицы квалификаций). */
    public static function flushThresholds(): void
    {
        self::$thresholds = null;
    }

    /**
     * Кандидаты на изменение уровня. READ-ONLY.
     *
     * @param  bool  $promoteOnly  true — только повышения (прежнее поведение)
     * @return array<int, object{ql_id:int, consultant:int, name:string, ngp:float, cur_lvl:int, legacy_lvl:int, ngp_lvl:int, target:int}>
     */
    public static function candidates(bool $promoteOnly = false): array
    {
        $ngpLevelExpr = '(SELECT max(sl.level) FROM status_levels sl'
            . ' WHERE sl."groupVolumeCumulative" <= COALESCE(l.ngp, 0))';

        // ⚠ Раньше target = GREATEST(уровень_по_НГП, status_and_lvl) — легаси-
        // грандфазер из Directual не давал уровню опуститься НИКОГДА. Из-за
        // этого партнёр с НГП 11,7 годами висел на «Про», а исправить это было
        // нечем: другого инструмента понижения в платформе нет. Теперь target =
        // строго уровень по НГП; прежнее поведение — флагом $promoteOnly.
        $target = $promoteOnly
            ? "GREATEST($ngpLevelExpr, COALESCE(c.status_and_lvl, 0))"
            : $ngpLevelExpr;

        $direction = $promoteOnly ? '>' : '<>';

        return DB::select(<<<SQL
            WITH latest AS (
                SELECT DISTINCT ON (consultant) id AS ql_id, consultant,
                    "groupVolumeCumulative" AS ngp,
                    GREATEST(COALESCE("nominalLevel", 0), COALESCE("calculationLevel", 0)) AS cur_lvl
                FROM "qualificationLog"
                WHERE "dateDeleted" IS NULL
                ORDER BY consultant, date DESC
            )
            SELECT l.ql_id, l.consultant, c."personName" AS name,
                   round(l.ngp::numeric, 0) AS ngp, l.cur_lvl,
                   COALESCE(c.status_and_lvl, 0) AS legacy_lvl,
                   $ngpLevelExpr AS ngp_lvl,
                   $target AS target
            FROM latest l
            JOIN consultant c ON c.id = l.consultant AND c."dateDeleted" IS NULL
            WHERE l.cur_lvl >= 1
              -- ⚠ «Зарегистрирован» (4) исключён: партнёр ещё в активационном
              -- окне, квалификации у него нет — присваивать её нельзя даже по
              -- НГП (решение владельца 25.08.2026, случай Тернер).
              AND c.activity <> 4
              AND $target $direction l.cur_lvl
            ORDER BY target DESC, l.ngp DESC
        SQL);
    }

    /**
     * Применить повышения кандидатов. Возвращает число применённых.
     *
     * @param array<int, object> $rows
     */
    public static function apply(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        return DB::transaction(function () use ($rows) {
            $count = 0;
            foreach ($rows as $r) {
                DB::table('qualificationLog')
                    ->where('id', $r->ql_id)
                    ->update(['nominalLevel' => $r->target, 'calculationLevel' => $r->target]);
                // Карточку ведём к тому же target, что и снимок — иначе они
                // разъедутся, а месячный раннер стал брать уровень из снимка.
                DB::table('consultant')
                    ->where('id', $r->consultant)
                    ->update(['status_and_lvl' => $r->target]);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Найти кандидатов и (при $apply) применить. Возвращает список и число.
     *
     * @return array{candidates: array<int, object>, promoted: int}
     */
    public static function run(bool $apply, bool $promoteOnly = false): array
    {
        $rows = self::candidates($promoteOnly);

        return [
            'candidates' => $rows,
            'promoted' => $apply ? self::apply($rows) : 0,
        ];
    }
}
