<?php

namespace App\Console\Commands;

use App\Jobs\RecomputeTransferChainJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Массовое схлопывание дублей контрактов по номеру — ТОЛЬКО «полное совпадение».
 *
 * Правило (per владелец): для групп с одинаковым номером, где у всех членов
 * совпадают ключевые поля (клиент+продукт+программа+сумма+валюта) — это дубли
 * ОДНОЙ сделки. Оставляем контракт С ТРАНЗАКЦИЯМИ (канонический = максимум
 * транзакций, при равенстве младший id), транзакции остальных переносим на него,
 * сами дубли soft-удаляем. Группы, где данные РАЗЛИЧАЮТСЯ — это разные сделки под
 * одним номером (напр. Inssmart-хэши), их НЕ трогаем.
 *
 * Зеркалит логику AdminDataController::mergeContractDuplicates (перенос транзакций
 * + soft-delete + пересчёт цепочки канонического за открытые периоды). Деньги не
 * теряются: транзакции сохраняются на каноническом, комиссии пересчитываются.
 * Обратимо (deletedAt). Идемпотентна. --dry-run — план без изменений.
 */
class CollapseFullMatchDuplicates extends Command
{
    protected $signature = 'contracts:collapse-fullmatch-duplicates {--dry-run : показать план без изменений}';

    protected $description = 'Схлопнуть дубли-контракты по номеру для ПОЛНЫХ совпадений (оставить с транзакциями), разные данные не трогать';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Ключи-номера с более чем одним живым контрактом.
        $keys = DB::table('contract')
            ->whereNull('deletedAt')
            ->whereRaw("btrim(coalesce(number,'')) <> ''")
            ->selectRaw('lower(btrim(number)) AS gkey')
            ->groupByRaw('lower(btrim(number))')
            ->havingRaw('count(*) > 1')
            ->pluck('gkey');

        if ($keys->isEmpty()) {
            $this->info('Дублей по номеру не найдено.');
            return self::SUCCESS;
        }

        $rows = DB::table('contract')
            ->whereNull('deletedAt')
            ->whereRaw('lower(btrim(number)) IN ('.implode(',', array_fill(0, $keys->count(), '?')).')', $keys->all())
            ->orderByRaw('lower(btrim(number)), id')
            ->get(['id', 'number', 'client', 'product', 'program', 'ammount', 'currency']);

        $txCounts = DB::table('transaction')
            ->whereIn('contract', $rows->pluck('id')->all())
            ->whereNull('deletedAt')
            ->selectRaw('contract, count(*) as cnt')
            ->groupBy('contract')
            ->pluck('cnt', 'contract');

        $groups = [];
        foreach ($rows as $r) {
            $r->txCount = (int) ($txCounts[$r->id] ?? 0);
            $groups[mb_strtolower(trim((string) $r->number))][] = $r;
        }

        $fullMatchGroups = 0;
        $skippedDifferent = 0;
        $collapsed = 0;
        $movedTxTotal = 0;

        foreach ($groups as $items) {
            $identity = collect($items)
                ->map(fn ($c) => implode('|', [
                    $c->client, $c->product, $c->program, (string) (float) $c->ammount, $c->currency,
                ]))
                ->unique();

            if ($identity->count() !== 1) {
                $skippedDifferent++;
                continue; // разные данные — оставить
            }
            $fullMatchGroups++;

            $sorted = collect($items)->sort(fn ($a, $b) => ($b->txCount <=> $a->txCount) ?: ($a->id <=> $b->id))->values();
            $canonical = $sorted->first();
            $others = $sorted->slice(1)->pluck('id')->all();
            if (! $others) continue;

            if ($dry) {
                $this->line(sprintf(
                    '  № %s: оставить #%d (tx=%d), схлопнуть [%s]',
                    $items[0]->number, $canonical->id, $canonical->txCount, implode(', ', $others),
                ));
                $collapsed += count($others);
                continue;
            }

            $moved = DB::transaction(function () use ($canonical, $others) {
                $m = DB::table('transaction')
                    ->whereIn('contract', $others)
                    ->whereNull('deletedAt')
                    ->update(['contract' => $canonical->id, 'changedAt' => now()]);
                DB::table('contract')->whereIn('id', $others)->update(['deletedAt' => now()]);
                return $m;
            });
            RecomputeTransferChainJob::dispatch('contract', (int) $canonical->id);

            $movedTxTotal += $moved;
            $collapsed += count($others);
            $this->line(sprintf(
                '  ✔ № %s: оставлен #%d, схлопнуто %d (перенесено транз.: %d)',
                $items[0]->number, $canonical->id, count($others), $moved,
            ));
        }

        $this->info(($dry ? '[DRY-RUN] ' : 'Готово. ').sprintf(
            'Полных совпадений: %d, схлопнуто контрактов: %d%s. Разные данные (не тронуто): %d.',
            $fullMatchGroups, $collapsed,
            $dry ? '' : ", перенесено транзакций: {$movedTxTotal}",
            $skippedDifferent,
        ));

        return self::SUCCESS;
    }
}
