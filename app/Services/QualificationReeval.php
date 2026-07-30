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
    /**
     * Кандидаты на повышение (последний снимок ниже target). READ-ONLY.
     *
     * @return array<int, object{ql_id:int, consultant:int, name:string, ngp:float, cur_lvl:int, legacy_lvl:int, ngp_lvl:int, target:int}>
     */
    public static function candidates(): array
    {
        $ngpLevelExpr = '(SELECT max(sl.level) FROM status_levels sl'
            . ' WHERE sl."groupVolumeCumulative" <= COALESCE(l.ngp, 0))';

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
                   GREATEST($ngpLevelExpr, COALESCE(c.status_and_lvl, 0)) AS target
            FROM latest l
            JOIN consultant c ON c.id = l.consultant AND c."dateDeleted" IS NULL
            WHERE l.cur_lvl >= 1
              AND GREATEST($ngpLevelExpr, COALESCE(c.status_and_lvl, 0)) > l.cur_lvl
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
                DB::table('consultant')
                    ->where('id', $r->consultant)
                    ->where(function ($q) use ($r) {
                        // promote-only: не понижаем существующий status_and_lvl
                        $q->whereNull('status_and_lvl')->orWhere('status_and_lvl', '<', $r->target);
                    })
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
    public static function run(bool $apply): array
    {
        $rows = self::candidates();

        return [
            'candidates' => $rows,
            'promoted' => $apply ? self::apply($rows) : 0,
        ];
    }
}
