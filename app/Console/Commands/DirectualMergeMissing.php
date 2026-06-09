<?php

namespace App\Console\Commands;

use App\Support\LegacyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Импортирует из MySQL (directual_import) записи которых нет в локальном PG.
 *
 * Безопасность связей:
 *   - contract.consultant   → матч по consultantName/personName
 *   - commission.consultant → матч по consultantPersonName (backfill в MySQL)
 *   - transaction.contract  → через remap mysql_contract_id → pg_contract_id
 *   - commission.transaction→ через remap mysql_tx_id → pg_tx_id
 *
 * Запуск:
 *   php artisan directual:merge-missing            # реальный прогон
 *   php artisan directual:merge-missing --dry-run  # только отчёт, без записи
 */
class DirectualMergeMissing extends Command
{
    protected $signature = 'directual:merge-missing
        {--dry-run : Только показать что будет сделано, ничего не писать}
        {--contracts-only : Только контракты, без транзакций и комиссий}
        {--skip-commissions : Контракты + транзакции, без комиссий}
        {--recover-commissions : Восстановить комиссии для транзакций без комиссий}';

    protected $description = 'Мерж пропущенных контрактов/транзакций/комиссий из MySQL Directual → PG';

    private bool $dry;
    private array $contractRemap = [];  // mysql_contract_id => pg_contract_id
    private array $txRemap       = [];  // mysql_tx_id       => pg_tx_id
    private array $quarantine    = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('=== DRY-RUN: изменений в БД не будет ===');
        }

        // Проверяем соединение с MySQL
        try {
            DB::connection('directual_mysql')->getPdo();
            $this->info('✓ MySQL подключён');
        } catch (\Throwable $e) {
            $this->error('Не удалось подключиться к MySQL: ' . $e->getMessage());
            return 1;
        }

        // Строим карту consultant: personName => pg_id
        $consultantMap = DB::table('consultant')
            ->whereNotNull('personName')
            ->pluck('id', 'personName')
            ->all();
        $this->info('Загружено ' . count($consultantMap) . ' консультантов из PG');

        if ($this->option('recover-commissions')) {
            $this->recoverOrphanCommissions($consultantMap);
        } else {
            $this->mergeContracts($consultantMap);

            if (! $this->option('contracts-only')) {
                $this->mergeTransactions();

                if (! $this->option('skip-commissions')) {
                    $this->mergeCommissions($consultantMap);
                }
            }
        }

        $this->printSummary();
        return 0;
    }

    // ─────────────────────────────────────────────────────────
    // КОНТРАКТЫ
    // ─────────────────────────────────────────────────────────
    private function mergeContracts(array $consultantMap): void
    {
        $this->info('');
        $this->info('── Контракты ──');

        // Номера уже существующих контрактов в PG
        $pgNumbers = DB::table('contract')
            ->whereNotNull('number')
            ->pluck('id', 'number')
            ->all();

        // Нужные колонки из MySQL contract
        $pgProductIds = DB::table('product')->pluck('id', 'id')->all();
        $pgProgramIds = DB::table('program')->pluck('id', 'id')->all();
        $pgCurrencyIds = DB::table('currency')->pluck('id', 'id')->all();

        $mysqlContracts = DB::connection('directual_mysql')
            ->table('contract')
            ->whereNotNull('number')
            ->whereNotIn('number', array_keys($pgNumbers))
            ->get([
                'id','number','consultant','consultantName','client','clientName',
                'product','productName','program','programName',
                'status','createDate','openDate','closeDate',
                'ammount','currency','term','comment','dsCommission',
                'counterpartyContractId','type',
            ]);

        $this->info("Контрактов к импорту: {$mysqlContracts->count()}");

        $imported = 0;
        $skipped  = 0;

        // Карта статусов (по имени → id в PG)
        $statusMap = DB::table('contractStatus')->pluck('id', 'name')->all();

        foreach ($mysqlContracts as $mc) {
            // Резолвим consultant по имени
            $pgConsultantId = $this->resolveConsultant($mc->consultantName, $consultantMap);
            if ($pgConsultantId === null) {
                $this->quarantine[] = [
                    'table' => 'contract',
                    'mysql_id' => $mc->id,
                    'reason' => "Консультант не найден: «{$mc->consultantName}»",
                ];
                $skipped++;
                continue;
            }

            // Статус: если это число — оставляем как есть (ID статуса стабильны),
            // если строка — маппим через имя.
            $pgStatus = is_numeric($mc->status) ? (int)$mc->status : ($statusMap[$mc->status] ?? null);

            $pgId = null;
            if (! $this->dry) {
                $pgId = LegacyId::next('contract');
                DB::table('contract')->insert([
                    'id'                   => $pgId,
                    'number'               => $mc->number,
                    'consultant'           => $pgConsultantId,
                    'consultantName'       => $mc->consultantName,
                    'clientName'           => $mc->clientName,
                    'product'              => isset($pgProductIds[(int)$mc->product]) ? (int)$mc->product : null,
                    'productName'          => $mc->productName,
                    'program'              => isset($pgProgramIds[(int)$mc->program]) ? (int)$mc->program : null,
                    'programName'          => $mc->programName,
                    'status'               => $pgStatus,
                    'createDate'           => $mc->createDate,
                    'openDate'             => $mc->openDate,
                    'closeDate'            => $mc->closeDate,
                    'ammount'              => $mc->ammount,
                    'currency'             => isset($pgCurrencyIds[(int)$mc->currency]) ? (int)$mc->currency : null,
                    'term'                 => $this->firstInt($mc->term),
                    'comment'              => $mc->comment,
                    'dsCommission'         => $this->firstInt($mc->dsCommission),
                    'counterpartyContractId' => $mc->counterpartyContractId,
                    'type'                 => $this->firstInt($mc->type),
                    'createdAt'            => now(),
                    'changedAt'            => now(),
                ]);
            } else {
                $pgId = 0; // placeholder для dry-run
            }

            $this->contractRemap[$mc->id] = $pgId;
            $imported++;
        }

        $this->info("  ✓ Импортировано: {$imported}");
        $this->info("  ⚠ Пропущено (нет консультанта): {$skipped}");
    }

    // ─────────────────────────────────────────────────────────
    // ТРАНЗАКЦИИ
    // ─────────────────────────────────────────────────────────
    private function mergeTransactions(): void
    {
        if (empty($this->contractRemap)) {
            $this->info('Нет новых контрактов → транзакции пропускаем');
            return;
        }

        $this->info('');
        $this->info('── Транзакции ──');

        $mysqlContractIds = array_keys($this->contractRemap);
        $mysqlTxs = DB::connection('directual_mysql')
            ->table('transaction')
            ->whereIn('contract', $mysqlContractIds)
            ->get([
                'id','contract','amount','amountRUB','amountUSD',
                'currency','currencyRate','date','dateCreated',
                'dateDay','dateMonth','dateYear','changedAt',
                'comment','techComment','commissionCalcProperty',
                'dsCommissionPercentage','dsCommissionAbsolute',
                'personalVolume','groupVolume','profit','profitRUB',
                'comission','comissionRUB','netRevenue','netRevenueRUB',
                'deletedAt',
            ]);

        $this->info("Транзакций к импорту: {$mysqlTxs->count()}");

        $imported = 0;
        foreach ($mysqlTxs as $mt) {
            $pgContractId = $this->contractRemap[$mt->contract] ?? null;
            if ($pgContractId === null) continue;

            $pgId = null;
            if (! $this->dry) {
                $pgId = LegacyId::next('transaction');
                DB::table('transaction')->insert([
                    'id'                     => $pgId,
                    'contract'               => $pgContractId,
                    'amount'                 => $mt->amount,
                    'amountRUB'              => $mt->amountRUB,
                    'amountUSD'              => $mt->amountUSD,
                    'currency'               => $mt->currency,
                    'currencyRate'           => $mt->currencyRate,
                    'date'                   => $mt->date,
                    'dateCreated'            => $mt->dateCreated,
                    'dateDay'                => $mt->dateDay,
                    'dateMonth'              => $mt->dateMonth,
                    'dateYear'               => $mt->dateYear,
                    'changedAt'              => $mt->changedAt ?? now(),
                    'comment'                => $mt->comment,
                    'techComment'            => $mt->techComment,
                    'commissionCalcProperty' => $mt->commissionCalcProperty,
                    'dsCommissionPercentage' => $mt->dsCommissionPercentage,
                    'dsCommissionAbsolute'   => $mt->dsCommissionAbsolute,
                    'personalVolume'         => $mt->personalVolume,
                    'groupVolume'            => $mt->groupVolume,
                    'profit'                 => $mt->profit,
                    'profitRUB'              => $mt->profitRUB,
                    'comission'              => $mt->comission,
                    'comissionRUB'           => $mt->comissionRUB,
                    'netRevenue'             => $mt->netRevenue,
                    'netRevenueRUB'          => $mt->netRevenueRUB,
                    'deletedAt'              => $mt->deletedAt,
                ]);
            } else {
                $pgId = 0;
            }

            $this->txRemap[$mt->id] = $pgId;
            $imported++;
        }

        $this->info("  ✓ Импортировано: {$imported}");
    }

    // ─────────────────────────────────────────────────────────
    // КОМИССИИ
    // ─────────────────────────────────────────────────────────
    private function mergeCommissions(array $consultantMap): void
    {
        if (empty($this->txRemap)) {
            $this->info('Нет новых транзакций → комиссии пропускаем');
            return;
        }

        $this->info('');
        $this->info('── Комиссии ──');

        $mysqlTxIds = array_keys($this->txRemap);
        $mysqlComms = DB::connection('directual_mysql')
            ->table('commission')
            ->whereIn('transaction', $mysqlTxIds)
            ->get([
                'id','transaction','consultant','consultantPersonName',
                'amount','amountRUB','amountUSD','currency',
                'chainOrder','type','percent','absolute',
                'date','dateDay','dateMonth','dateYear','createdAt',
                'personalVolume','groupVolume','groupBonus','groupBonusRub',
                'reduction','withheldPercent','withheldForGap',
                'qualificationLog','calculationLevel','deletedAt',
            ]);

        $this->info("Комиссий к импорту: {$mysqlComms->count()}");

        $imported = 0;
        $skipped  = 0;

        foreach ($mysqlComms as $mc) {
            $pgTxId = $this->txRemap[$mc->transaction] ?? null;
            if ($pgTxId === null) continue;

            // Консультант — матч по имени (backfill сделали в MySQL)
            $pgConsultantId = $this->resolveConsultant($mc->consultantPersonName, $consultantMap);
            if ($pgConsultantId === null) {
                $this->quarantine[] = [
                    'table'    => 'commission',
                    'mysql_id' => $mc->id,
                    'reason'   => "Консультант не найден: «{$mc->consultantPersonName}»",
                ];
                $skipped++;
                continue;
            }

            if (! $this->dry) {
                $pgId = LegacyId::next('commission');
                DB::table('commission')->insert([
                    'id'               => $pgId,
                    'transaction'      => $pgTxId,
                    'consultant'       => $pgConsultantId,
                    'amount'           => $mc->amount,
                    'amountRUB'        => $mc->amountRUB,
                    'amountUSD'        => $mc->amountUSD,
                    'currency'         => $mc->currency,
                    'chainOrder'       => $mc->chainOrder,
                    'type'             => $mc->type,
                    'percent'          => $mc->percent,
                    'absolute'         => $mc->absolute,
                    'date'             => $this->msToTs($mc->date),
                    'dateDay'          => $this->msToTs($mc->dateDay),
                    'dateMonth'        => $mc->dateMonth,
                    'dateYear'         => $mc->dateYear,
                    'createdAt'        => $this->msToTs($mc->createdAt) ?? now(),
                    'personalVolume'   => $mc->personalVolume,
                    'groupVolume'      => $mc->groupVolume,
                    'groupBonus'       => $mc->groupBonus,
                    'groupBonusRub'    => $mc->groupBonusRub,
                    'reduction'        => $mc->reduction,
                    'withheldPercent'  => $mc->withheldPercent,
                    'withheldForGap'   => $mc->withheldForGap,
                    'qualificationLog' => $mc->qualificationLog,
                    'calculationLevel' => $mc->calculationLevel,
                    'deletedAt'        => $this->msToTs($mc->deletedAt),
                ]);
            }

            $imported++;
        }

        $this->info("  ✓ Импортировано: {$imported}");
        $this->info("  ⚠ Пропущено (нет консультанта): {$skipped}");
    }

    // ─────────────────────────────────────────────────────────
    // ХЕЛПЕРЫ
    // ─────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────
    // ВОССТАНОВЛЕНИЕ КОМИССИЙ ДЛЯ ТРАНЗАКЦИЙ БЕЗ КОМИССИЙ
    // ─────────────────────────────────────────────────────────
    private function recoverOrphanCommissions(array $consultantMap): void
    {
        $this->info('');
        $this->info('── Восстановление комиссий для транзакций без комиссий ──');

        // PG-транзакции у которых нет ни одной комиссии
        $orphanTxs = DB::table('transaction as t')
            ->leftJoin('commission as c', 'c.transaction', '=', 't.id')
            ->whereNull('c.id')
            ->whereNull('t.deletedAt')
            ->select('t.id as pg_tx_id', 't.contract', 't.amount', 't.dateMonth')
            ->get();

        $this->info("Транзакций без комиссий в PG: {$orphanTxs->count()}");
        if ($orphanTxs->isEmpty()) {
            $this->info('  Всё в порядке — комиссии есть у всех транзакций.');
            return;
        }

        // Номера контрактов для этих транзакций
        $pgContractIds = $orphanTxs->pluck('contract')->unique()->values()->all();
        $pgNumberMap   = DB::table('contract')
            ->whereIn('id', $pgContractIds)
            ->pluck('number', 'id')
            ->all(); // pg_contract_id => number

        // MySQL: tx_id сгруппированные по contract.number + amount + dateMonth
        $mysqlNumbers = array_values(array_filter(array_unique(array_values($pgNumberMap))));
        $mysqlTxIndex = DB::connection('directual_mysql')
            ->table('transaction as t')
            ->join('contract as c', 'c.id', '=', 't.contract')
            ->whereIn('c.number', $mysqlNumbers)
            ->select('t.id as mysql_tx_id', 'c.number', 't.amount', 't.dateMonth')
            ->get()
            ->groupBy(fn ($r) => $r->number . '|' . $r->amount . '|' . $r->dateMonth);

        // Строим локальный remap
        $recovered = 0;
        $skipped   = 0;

        foreach ($orphanTxs as $otx) {
            $number = $pgNumberMap[$otx->contract] ?? null;
            if (! $number) continue;

            $key     = $number . '|' . $otx->amount . '|' . $otx->dateMonth;
            $matches = $mysqlTxIndex[$key] ?? collect();

            if ($matches->count() !== 1) {
                // Неоднозначность или не нашли — пропускаем
                $this->quarantine[] = [
                    'table'    => 'commission/recover',
                    'mysql_id' => 0,
                    'reason'   => "tx pg_id={$otx->pg_tx_id} contract={$number}: " .
                                  ($matches->isEmpty() ? 'не найден в MySQL' : 'неоднозначных совпадений: ' . $matches->count()),
                ];
                $skipped++;
                continue;
            }

            $mysqlTxId = $matches->first()->mysql_tx_id;
            $this->txRemap[$mysqlTxId] = $otx->pg_tx_id;
            $recovered++;
        }

        $this->info("  Транзакций сматчено: {$recovered}, пропущено: {$skipped}");

        // Теперь грузим комиссии для сматченных транзакций
        $this->mergeCommissions($consultantMap);
    }

    private function msToTs(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        // Если уже ISO-строка — вернуть как есть
        if (! is_numeric($value)) return (string) $value;
        // Unix timestamp в миллисекундах → секунды
        $sec = (int) $value;
        if ($sec > 1_000_000_000_000) $sec = intdiv($sec, 1000);
        if ($sec <= 0) return null;
        return date('Y-m-d H:i:s', $sec);
    }

    private function firstInt(?string $value): ?int
    {
        if ($value === null || $value === '') return null;
        $first = explode(',', $value)[0];
        return is_numeric(trim($first)) ? (int)trim($first) : null;
    }

    private function resolveConsultant(?string $name, array $map): ?int
    {
        if (! $name) return null;
        $id = $map[$name] ?? null;
        if ($id) return (int)$id;

        // Попытка нормализации пробелов
        $norm = preg_replace('/\s+/', ' ', trim($name));
        return isset($map[$norm]) ? (int)$map[$norm] : null;
    }

    private function printSummary(): void
    {
        $this->info('');
        $this->info('══ ИТОГ ══');
        $this->info('  Контрактов remapped: ' . count($this->contractRemap));
        $this->info('  Транзакций remapped: ' . count($this->txRemap));
        $this->info('  Карантин (не найдены): ' . count($this->quarantine));

        if (! empty($this->quarantine)) {
            $this->warn('');
            $this->warn('Карантин — требуют ручной проверки:');
            foreach (array_slice($this->quarantine, 0, 30) as $q) {
                $this->warn("  [{$q['table']}] mysql_id={$q['mysql_id']}: {$q['reason']}");
            }
            if (count($this->quarantine) > 30) {
                $this->warn('  ... ещё ' . (count($this->quarantine) - 30));
            }
        }

        if ($this->dry) {
            $this->warn('');
            $this->warn('DRY-RUN завершён. Для реального прогона уберите --dry-run');
        }
    }
}
