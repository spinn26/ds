<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Синхронизация денормализованных имён контракта (productName/programName)
 * с реальными названиями по FK.
 *
 * Зачем: менеджер контрактов и фильтры показывают именно `contract.programName`
 * / `contract.productName`, а не JOIN по FK. Если чинить контракт напрямую в БД
 * (как правку неверного матча загрузчика), меняя только `program`/`product`,
 * в интерфейсе останется СТАРОЕ имя — «вроде ничего не изменилось» (кейс
 * 2026-08-03: 75 контрактов «Парус» показывались как «Парус-МАКС»).
 */
class ContractsResyncDenormNames extends Command
{
    protected $signature = 'contracts:resync-denorm-names
        {--products : синхронизировать также productName}
        {--dry-run : только показать расхождения}';

    protected $description = 'Выровнять contract.programName/productName с названиями по FK';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $targets = [
            ['label' => 'programName', 'column' => 'programName', 'fk' => 'program', 'table' => 'program'],
        ];
        if ($this->option('products')) {
            $targets[] = ['label' => 'productName', 'column' => 'productName', 'fk' => 'product', 'table' => 'product'];
        }

        $total = 0;
        foreach ($targets as $t) {
            $diff = DB::table('contract as c')
                ->join($t['table'] . ' as r', 'r.id', '=', 'c.' . $t['fk'])
                ->whereRaw('c."' . $t['column'] . '" IS DISTINCT FROM r.name')
                ->selectRaw('c."' . $t['column'] . '" as denorm, r.name as real, count(*) as cnt')
                ->groupBy('denorm', 'real')
                ->orderByDesc('cnt')
                ->get();

            if ($diff->isEmpty()) {
                $this->info("{$t['label']}: расхождений нет");
                continue;
            }

            $this->warn("{$t['label']}: расхождения");
            $this->table(['в контракте', 'по справочнику', 'шт'],
                $diff->map(fn ($r) => [$r->denorm, $r->real, $r->cnt])->all());
            $total += (int) $diff->sum('cnt');

            if (! $dry) {
                $updated = DB::update(
                    'UPDATE contract c SET "' . $t['column'] . '" = r.name, "changedAt" = NOW()
                     FROM ' . $t['table'] . ' r
                     WHERE r.id = c."' . $t['fk'] . '" AND c."' . $t['column'] . '" IS DISTINCT FROM r.name'
                );
                $this->info("{$t['label']}: обновлено {$updated}");
            }
        }

        if ($dry) {
            $this->line("Всего расхождений: {$total} (dry-run, ничего не записано)");
        }

        return self::SUCCESS;
    }
}
