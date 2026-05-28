<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Одноразовый backfill `commissionCalcProperty=9` («МФ») для транзакций
 * продукта «Тинькофф портфель» с NULL свойством.
 *
 * Контекст: коммит 33fab7d4 закрыл потерю свойства в SheetProfiles только
 * для листов IB MF / IB UP. Лист «робоэдвайзер» (продукт «Тинькофф
 * портфель») остался без дефолта → 1003 майских импорта пришли с
 * commissionCalcProperty=NULL и неправильным расчётом комиссий.
 *
 * Что делает:
 *  1) Находит transaction WHERE product.name='Тинькофф портфель'
 *     AND product.has_property=true
 *     AND commissionCalcProperty IS NULL
 *     AND deletedAt IS NULL.
 *  2) UPDATE commissionCalcProperty=9 для всех таких.
 *  3) Пересчитывает commission через CommissionCalculator::calculateForTransaction
 *     для каждой — иначе цепочка выплат останется со старыми значениями
 *     (или пустой, если расчёт ещё не делался).
 *
 * Поведение идемпотентно: повторный запуск увидит 0 строк к обновлению
 * и завершится без побочных эффектов.
 *
 * Использование:
 *   php artisan finance:backfill-tinkoff-property --dry-run
 *   php artisan finance:backfill-tinkoff-property
 */
class BackfillTinkoffPortfolioProperty extends Command
{
    protected $signature = 'finance:backfill-tinkoff-property
        {--dry-run : Preview without writing}
        {--no-recalc : Только UPDATE свойства, без пересчёта commission}
        {--property=9 : ID свойства из commissionCalcProperty (9=МФ, 10=Апфронт)}';

    protected $description = 'Backfill commissionCalcProperty (МФ) for «Тинькофф портфель» transactions imported without property';

    public function handle(CommissionCalculator $calc): int
    {
        $propertyId = (int) $this->option('property');

        // Sanity-check: указанный property существует.
        $propertyTitle = DB::table('commissionCalcProperty')
            ->where('id', $propertyId)
            ->value('title');
        if (! $propertyTitle) {
            $this->error("commissionCalcProperty id={$propertyId} не найден");

            return self::FAILURE;
        }

        // Целевая выборка — все Тинькофф-портфель транзакции без свойства.
        // product.name → contract.productName joined via contract.product.
        // Используем product.id (FK), не name, чтобы исключить случайное
        // совпадение по названию продукта в legacy-данных.
        $rows = DB::table('transaction as t')
            ->join('contract as c', 'c.id', '=', 't.contract')
            ->join('product as p', 'p.id', '=', 'c.product')
            ->whereNull('t.deletedAt')
            ->whereNull('t.commissionCalcProperty')
            ->where('p.name', 'Тинькофф портфель')
            ->where('p.has_property', true)
            ->select('t.id', 't.date', 'c.number as contract_number')
            ->orderBy('t.id')
            ->get();

        $count = $rows->count();
        $this->info("Транзакций к бэкфиллу: {$count} (свойство → {$propertyId} «{$propertyTitle}»)");

        if ($count === 0) {
            $this->info('Нечего делать.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry-run — изменения не применяются. Первые 5 примеров:');
            foreach ($rows->take(5) as $r) {
                $this->line("  tx#{$r->id}  contract={$r->contract_number}  date={$r->date}");
            }

            return self::SUCCESS;
        }

        // 1) Bulk UPDATE свойства одним запросом — дёшево, атомарно.
        $ids = $rows->pluck('id')->all();
        $updated = DB::table('transaction')
            ->whereIn('id', $ids)
            ->update(['commissionCalcProperty' => $propertyId]);
        $this->info("UPDATE: {$updated} транзакций.");

        if ($this->option('no-recalc')) {
            $this->warn('--no-recalc: пересчёт commission пропущен. Запустите вручную позже.');

            return self::SUCCESS;
        }

        // 2) Пересчёт commission на каждую транзакцию. Идёт по одной
        // через CommissionCalculator (там lockForUpdate на parent-row,
        // см. фикс 33fab7d4 race-condition) — параллелить нельзя.
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        $ok = 0;
        $failed = 0;
        foreach ($ids as $txId) {
            try {
                $calc->calculateForTransaction($txId);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Backfill recalc failed', [
                    'transaction' => $txId,
                    'error' => $e->getMessage(),
                ]);
                $this->newLine();
                $this->error("tx#{$txId}: {$e->getMessage()}");
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info("Пересчёт commission: ok={$ok}, failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
