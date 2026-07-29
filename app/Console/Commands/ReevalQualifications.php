<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Промоут-онли переоценка уровня квалификации по НГП.
 *
 * Платформа не повышает `consultant.status_and_lvl` по накоплению НГП
 * автоматически (см. спеку/заметки). Эта команда добирает пропущенные
 * повышения: у кого последний снимок `qualificationLog` показывает уровень
 * ниже положенного по его НГП (или ниже легаси-грандфазера status_and_lvl),
 * поднимает уровень — ТОЛЬКО вверх.
 *
 * Что пишем:
 *   - последний снимок qualificationLog: nominalLevel = calculationLevel = target
 *     (снимок конца месяца → CommissionCalculator берёт его для СЛЕДУЮЩЕГО
 *     месяца, т.е. новый % с 1-го числа след. месяца — по правилу спеки, текущий
 *     месяц ретроактивно не меняется);
 *   - consultant.status_and_lvl = target (чтобы дашборды/пул/штрафы видели уровень).
 *
 * target = max(уровень_по_НГП, status_and_lvl). НГП берём из ЛОГА
 * (qualificationLog.groupVolumeCumulative — истина; consultant-колонка бывает
 * устаревшей). Пустые уровни (cur < 1, НГП=0, нет легаси-уровня) НЕ трогаем.
 *
 * По умолчанию dry-run — печатает список. Применение — флагом --apply.
 * ⚠ Меняет комиссии/пул со следующего месяца. Снимать бэкап БД ДО --apply.
 */
class ReevalQualifications extends Command
{
    protected $signature = 'partners:reeval-qualifications {--apply : применить (по умолчанию dry-run — только показать)}';

    protected $description = 'Промоут-онли переоценка квалификации по НГП: добирает пропущенные повышения (последний снимок лога + status_and_lvl). Пустые уровни не трогает.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // Кандидаты: у последнего снимка лога уровень ниже target =
        // max(уровень_по_НГП, status_and_lvl). cur >= 1 — не трогаем NULL/0.
        $ngpLevelExpr = '(SELECT max(sl.level) FROM status_levels sl'
            . ' WHERE sl."groupVolumeCumulative" <= COALESCE(l.ngp, 0))';

        $rows = DB::select(<<<SQL
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

        if (empty($rows)) {
            $this->info('Нет партнёров к повышению — все уровни соответствуют НГП.');

            return self::SUCCESS;
        }

        $this->table(
            ['ql_id', 'consultant', 'ФИО', 'НГП', 'уровень (лог)', 'по НГП', 'легаси', '→ target'],
            array_map(fn ($r) => [
                $r->ql_id, $r->consultant, $r->name, $r->ngp,
                $r->cur_lvl, $r->ngp_lvl, $r->legacy_lvl, $r->target,
            ], $rows)
        );
        $this->info(count($rows) . ' партнёров к повышению.');

        if (! $apply) {
            $this->warn('DRY-RUN — ничего не изменено. Для применения: --apply (снимите бэкап БД до этого).');

            return self::SUCCESS;
        }

        $n = DB::transaction(function () use ($rows) {
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

        $this->info("Применено: {$n} повышений. Новый % действует со следующего месяца.");

        return self::SUCCESS;
    }
}
