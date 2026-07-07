<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use App\Services\CommissionCalculator;
use App\Services\PartnerStatusService;
use App\Support\LegacyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Массовая терминация партнёров из файла-сверки.
 *
 * Файл: по одному ФИО на строку (btrim-match к consultant.personName,
 * ровно 1 не-удалённый матч). Классификация по текущему activity:
 *   Active/Registered → terminate() (штатно, terminationCount++);
 *   Excluded(5)       → forceTerminate() только с --force-excluded;
 *   Terminated(3)     → пропуск.
 *
 * «Ветки вниз»: у каждого терминируемого активный даунлайн (прямые дети,
 * inviter=он) репойнтится на его наставника (inviter), событие пишется в
 * changeConsultantInviterLog (история перестановок).
 *
 * --recompute-june: после терминаций пересчитывает ТОЛЬКО июнь
 * (calculateForTransaction сам пропускает исторические/закрытые месяцы) +
 * ребилд consultantBalance затронутых партнёров.
 *
 * --dry-run: только отчёт, без записи.
 */
class TerminatePartnersFromFile extends Command
{
    protected $signature = 'partners:terminate-from-file
        {file : путь к txt со списком ФИО (по одному на строку)}
        {--force-excluded : уже «Исключён» (5) тоже перевести в «Терминирован» (3)}
        {--recompute-june : пересчитать июнь (комиссии+балансы) после терминаций}
        {--dry-run : только показать что изменится, без записи}';

    protected $description = 'Терминация партнёров из файла-сверки + перенос даунлайна в историю перестановок + пересчёт июня';

    public function handle(PartnerStatusService $status, CommissionCalculator $calc): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("Файл не найден: {$file}");
            return 1;
        }
        $dry = (bool) $this->option('dry-run');
        $forceExcluded = (bool) $this->option('force-excluded');

        $names = collect(preg_split('/\r?\n/', (string) file_get_contents($file)))
            ->map(fn ($s) => trim((string) $s))->filter()->unique()->values();

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Имён в файле: {$names->count()}");

        /** @var array<Consultant> $toTerminate */
        $toTerminate = [];
        /** @var array<Consultant> $toForce */
        $toForce = [];
        $skip = [];
        $ambiguous = [];

        foreach ($names as $nm) {
            $matches = Consultant::whereNull('dateDeleted')
                ->whereRaw('btrim("personName") = ?', [$nm])->get();
            if ($matches->count() !== 1) {
                $ambiguous[] = "{$nm} (матчей: {$matches->count()})";
                continue;
            }
            $c = $matches->first();
            $act = (int) $c->activity;
            if (in_array($act, [PartnerActivity::Active->value, PartnerActivity::Registered->value], true)) {
                $toTerminate[] = $c;
            } elseif ($act === PartnerActivity::Excluded->value) {
                $forceExcluded ? $toForce[] = $c : $skip[] = "{$nm} (Исключён — нет --force-excluded)";
            } elseif ($act === PartnerActivity::Terminated->value) {
                $skip[] = "{$nm} (уже Терминирован)";
            } else {
                $skip[] = "{$nm} (activity={$act})";
            }
        }

        $this->line('К терминации (Active/Registered): ' . count($toTerminate));
        $this->line('Форс-терминация (Исключён→Терминирован): ' . count($toForce));
        $this->line('Пропуск: ' . count($skip));
        foreach ($skip as $s) {
            $this->line("  - {$s}");
        }
        if ($ambiguous) {
            $this->warn('НЕОДНОЗНАЧНЫЕ / не найдены: ' . count($ambiguous));
            foreach ($ambiguous as $a) {
                $this->line("  - {$a}");
            }
        }

        // Даунлайн терминируемых.
        $allTargets = collect($toTerminate)->merge($toForce);
        $downlineMoves = [];
        foreach ($allTargets as $c) {
            $children = Consultant::whereNull('dateDeleted')
                ->where('inviter', $c->id)
                ->whereNotIn('activity', [PartnerActivity::Terminated->value, PartnerActivity::Excluded->value])
                ->get();
            foreach ($children as $ch) {
                $downlineMoves[] = [$ch, $c];
            }
        }
        $this->line('Переносов даунлайна (ветки вниз → наставнику): ' . count($downlineMoves));

        if ($dry) {
            $affectedJune = DB::table('commission')
                ->where('deletedAt', null)->where('dateMonth', '2026-06')
                ->whereIn('consultant', $allTargets->pluck('id'))
                ->distinct()->count('transaction');
            $this->warn("[DRY-RUN] Июньских транзакций с этими партнёрами в цепочке: {$affectedJune}");
            $this->warn('[DRY-RUN] Запись НЕ выполнялась. Убери --dry-run для боевого прогона.');
            return 0;
        }

        // === WRITE ===
        $done = 0;
        $forced = 0;
        foreach ($toTerminate as $c) {
            $status->terminate($c, 'Сверка файла терминаций');
            $done++;
        }
        foreach ($toForce as $c) {
            $status->forceTerminate($c, 'Сверка файла терминаций');
            $forced++;
        }

        $movedLog = 0;
        foreach ($downlineMoves as [$ch, $old]) {
            $newInviterId = $old->inviter;
            $newInviter = $newInviterId ? Consultant::find($newInviterId) : null;
            DB::transaction(function () use ($ch, $old, $newInviter, $newInviterId, &$movedLog) {
                DB::table('consultant')->where('id', $ch->id)->update([
                    'inviter' => $newInviterId,
                    'inviterName' => $newInviter?->personName,
                ]);
                DB::table('changeConsultantInviterLog')->insert([
                    'id' => LegacyId::next('changeConsultantInviterLog'),
                    'dateCreated' => now(),
                    'webUser' => null,
                    'consultant' => $ch->id,
                    'consultantName' => $ch->personName,
                    'inviterOld' => $old->id,
                    'inviterOldName' => $old->personName,
                    'inviterNew' => $newInviterId,
                    'inviterNewName' => $newInviter?->personName,
                    'triggeredBy' => 'Терминация наставника',
                ]);
                $movedLog++;
            });
        }

        $this->info("Терминировано: {$done}, форс: {$forced}, переносов даунлайна: {$movedLog}");

        // === ПЕРЕСЧЁТ ИЮНЯ ===
        if ($this->option('recompute-june')) {
            $juneTx = DB::table('transaction')->whereNull('deletedAt')->where('dateMonth', '2026-06')->pluck('id');
            $affected = collect();
            $ok = 0;
            $skipped = 0;
            foreach ($juneTx as $txId) {
                $before = DB::table('commission')->where('transaction', $txId)->whereNull('deletedAt')->pluck('consultant');
                $affected = $affected->merge($before);
                $res = $calc->calculateForTransaction((int) $txId);
                if (! empty($res['error'])) {
                    $skipped++;
                    continue;
                }
                $ok++;
                $after = DB::table('commission')->where('transaction', $txId)->whereNull('deletedAt')->pluck('consultant');
                $affected = $affected->merge($after);
            }
            $affected = $affected->merge($allTargets->pluck('id'))->filter()->unique();
            foreach ($affected as $cid) {
                $calc->rebuildBalanceFor((int) $cid, '2026-06', '2026');
            }
            $this->info("Июнь: tx ok={$ok} skip={$skipped}, балансов пересобрано={$affected->count()}");
        }

        return 0;
    }
}
