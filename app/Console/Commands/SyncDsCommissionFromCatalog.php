<?php

namespace App\Console\Commands;

use App\Services\DsCommissionSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Синхронизирует %ДС из каталога «Продукты» (programs_catalog.tariffs, JSONB —
 * то, что редактирует оператор) в legacy-таблицу `dsCommission`, по которой
 * реально считаются комиссии транзакций. Источник истины — Продукты.
 *
 * Матчинг строки каталога → строки dsCommission (в рамках одной legacy-программы):
 *   term      = tariff.term        <-> dsCommission.termContract
 *   год КВ N  = tariff.year_kv      <-> dsCommission.commissionCalcProperty = id, где title = "N год"
 *   свойство  = tariff.property     <-> dsCommission.commissionCalcProperty по алиасу (SF↔СФ, MF↔МФ, upfront↔Апфронт…)
 *   без свойства/года и один ряд у программы → сопоставляем этот единственный ряд.
 *
 * БЕЗОПАСНО: UPDATE comission у однозначных совпадений + INSERT строки, если по
 * ключу её нет вообще (иначе %ДС из карточки не доезжает до калькулятора и
 * расчёт падает «Не найден тариф %ДС»). Ничего не удаляет; неоднозначные
 * (>1 совпадение) пропускает и логирует. Без --apply — dry-run.
 *
 * Логика одна на команду и на авто-синк при сохранении карточки — в
 * App\Services\DsCommissionSync.
 */
class SyncDsCommissionFromCatalog extends Command
{
    protected $signature = 'products:sync-dscommission {--apply : применить изменения (иначе dry-run)} {--program= : только одна legacy-программа} {--fill-gaps : достраивать недостающие ключи и у программ, где строки уже есть (МЕНЯЕТ действующие ставки)}
        {--prune : ОТЧЁТ о строках расчёта, которых нет в карточке (запись НЕ делается — матчер пока неточен)}';

    protected $description = 'Синхронизировать %ДС из каталога Продуктов в dsCommission (источник расчёта комиссий)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyProgram = $this->option('program') !== null ? (int) $this->option('program') : null;
        $fillGaps = (bool) $this->option('fill-gaps');
        $prune = (bool) $this->option('prune');

        $programs = DB::table('programs_catalog')
            ->whereNotNull('legacy_program_id')
            ->where('active', true)
            ->when($onlyProgram !== null, fn ($q) => $q->where('legacy_program_id', $onlyProgram))
            ->get(['legacy_program_id', 'name', 'tariffs']);

        $updated = 0;
        $created = 0;
        $pruned = 0;
        $diffs = [];
        $skips = [];

        foreach ($programs as $pr) {
            $tariffs = json_decode((string) $pr->tariffs, true);
            if (! is_array($tariffs) || ! $tariffs) {
                continue;
            }

            $res = DsCommissionSync::syncFromTariffs((int) $pr->legacy_program_id, $tariffs, $apply, $fillGaps, $prune);

            $updated += $res['updated'];
            $created += $res['created'];
            $pruned += $res['pruned'] ?? 0;
            $name = mb_substr($pr->name, 0, 28);
            foreach ($res['diffs'] as $d) {
                $diffs[] = sprintf('%-28s %s', $name, $d);
            }
            foreach ($res['skips'] as $s) {
                $skips[] = "{$pr->name}: {$s}";
            }
        }

        $this->info(($apply ? 'ПРИМЕНЕНО' : 'DRY-RUN') . " — обновлено: {$updated}, создано: {$created}, погашено: {$pruned}");
        foreach ($diffs as $d) {
            $this->line('  ' . $d);
        }
        if ($skips) {
            $this->warn('Пропущено (неоднозначно/нет ключа): ' . count($skips));
            foreach (array_slice($skips, 0, 40) as $s) {
                $this->line('  · ' . $s);
            }
            if (count($skips) > 40) {
                $this->line('  … и ещё ' . (count($skips) - 40));
            }
        }

        return self::SUCCESS;
    }
}
