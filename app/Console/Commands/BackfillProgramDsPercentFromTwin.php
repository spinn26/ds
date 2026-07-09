<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Проставить program.dsPercent для «пустых» программ-дублей из одноимённого
 * близнеца, у которого %ДС задан ОДНОЗНАЧНО (единственное значение).
 *
 * Корень: импорт кладёт контракты на программы-дубли (напр. Парус #1639,
 * Акцент-5 #1642) с dsPercent=NULL и без строк dsCommission, тогда как тариф
 * живёт на каноническом близнеце (#304=3%, #746=2.6%). Каскад и превью
 * (resolveLegacyDsCommission / programRow->dsPercent) не находят ставку →
 * fallback 100%. Копируем плоский %ДС с близнеца → превью/расчёт снова верны.
 *
 * ⚠ Только ОДНОЗНАЧНЫЕ близнецы (один distinct dsPercent). Программы с
 * несколькими вариантами (term/год-зависимые, напр. «Азбука защиты») НЕ
 * трогаем — им нужен матричный тариф dsCommission, а не плоский dsPercent.
 *
 * --dry-run: показать план без записи.
 */
class BackfillProgramDsPercentFromTwin extends Command
{
    protected $signature = 'programs:backfill-dspercent-from-twin {--dry-run : показать план без изменений}';

    protected $description = 'Проставить program.dsPercent из одноимённого близнеца (только однозначные)';

    /** Программы с null dsPercent, у которых есть одноимённый близнец с РОВНО одним %ДС. */
    private const CANDIDATES_SQL = <<<'SQL'
        WITH twin AS (
            SELECT btrim(lower(name)) AS nm,
                   count(DISTINCT "dsPercent") FILTER (WHERE "dsPercent" IS NOT NULL) AS distinct_vals,
                   max("dsPercent") FILTER (WHERE "dsPercent" IS NOT NULL) AS val
            FROM program GROUP BY 1
        )
        SELECT p.id, p.name, t.val
        FROM program p JOIN twin t ON t.nm = btrim(lower(p.name))
        WHERE p."dsPercent" IS NULL
          AND t.val IS NOT NULL
          AND t.distinct_vals = 1
        ORDER BY p.name, p.id
        SQL;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = DB::select(self::CANDIDATES_SQL);

        $this->info(($dry ? '[DRY-RUN] ' : '') . 'Программ к заполнению dsPercent из однозначного близнеца: ' . count($rows));
        foreach ($rows as $r) {
            $this->line(sprintf('  prog#%-5s %-40s → %.3f%%', $r->id, mb_substr($r->name, 0, 40), (float) $r->val));
        }

        if ($dry) {
            $this->warn('[DRY-RUN] Запись не выполнялась.');
            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($rows as $r) {
            DB::table('program')->where('id', $r->id)->update(['dsPercent' => $r->val]);
            $updated++;
        }

        $this->info("Готово. Проставлено dsPercent: {$updated}.");
        $this->warn('⚠ Существующие транзакции с явным dsCommissionPercentage не меняются; фикс влияет на превью и новые расчёты. Неоднозначные (term/год) программы НЕ тронуты — им нужен матричный тариф.');

        return self::SUCCESS;
    }
}
