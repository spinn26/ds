<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use App\Services\PartnerStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовый бэкфилл: клиенты, оставшиеся на терминированных (3) / исключённых (5)
 * партнёрах, переезжают на ближайшего активного вышестоящего наставника
 * (или «Неизвестного консультанта», если активного вышестоящего нет).
 *
 * Аналог partners:reassign-terminated-contracts, но для client.consultant.
 * Directual раньше двигал клиентов заливкой; при остановке заливок клиенты
 * «застряли» за терминированными ФК. Переносим штатной логикой
 * PartnerStatusService::reassignClientsToUpline (история changeConsultantClient-
 * Log + RecomputeTransferChainJob по открытым периодам).
 *
 * --dry-run: только показать план, без записи.
 */
class ReassignTerminatedClients extends Command
{
    protected $signature = 'partners:reassign-terminated-clients {--dry-run : только показать план}';

    protected $description = 'Перенос клиентов с терминированных/исключённых ФК на ближайшего активного вышестоящего';

    public function handle(PartnerStatusService $status): int
    {
        $dry = (bool) $this->option('dry-run');

        $owners = DB::table('client as cl')
            ->join('consultant as c', 'c.id', '=', 'cl.consultant')
            ->whereIn('c.activity', [PartnerActivity::Terminated->value, PartnerActivity::Excluded->value])
            ->whereNull('cl.dateDeleted')
            ->groupBy('cl.consultant', 'c.personName', 'c.activity')
            ->select('cl.consultant as id', 'c.personName', 'c.activity', DB::raw('count(*) as clients'))
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Терминированных/исключённых ФК с клиентами: {$owners->count()}");

        $totalMoved = 0;
        $totalFallback = 0;
        foreach ($owners as $o) {
            $consultant = Consultant::find($o->id);
            if (! $consultant) {
                continue;
            }

            if ($dry) {
                $targetId = $this->previewTarget((int) $o->id)
                    ?? \App\Services\CommissionCalculator::UNKNOWN_CONSULTANT_ID;
                $fallback = $this->previewTarget((int) $o->id) === null;
                $target = DB::table('consultant')->where('id', $targetId)->value('personName');
                $this->line(sprintf(
                    '  %-32s act=%d клиентов=%-4d → %s (#%d)%s',
                    $o->personName, $o->activity, $o->clients, $target, $targetId,
                    $fallback ? ' [нет вышестоящего]' : ''
                ));
                $totalMoved += $o->clients;
                $fallback && $totalFallback += $o->clients;
                continue;
            }

            $res = $status->reassignClientsToUpline($consultant, 'Бэкфилл: перенос клиентов терминированного ФК');
            $totalMoved += $res['moved'];
            $totalFallback += $res['fallbackUnknown'];
            $this->line(sprintf(
                '  %-32s перенесено=%-4d %s',
                $o->personName, $res['moved'],
                $res['fallbackUnknown'] ? "→ Неизвестный консультант={$res['fallbackUnknown']}" : ''
            ));
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Итого перенесено клиентов: {$totalMoved}, из них на «Неизвестного консультанта» (нет вышестоящего): {$totalFallback}");
        if ($dry) {
            $this->warn('[DRY-RUN] Запись не выполнялась. Убери --dry-run для боевого прогона.');
        }

        return 0;
    }

    /** Ближайший активный вышестоящий для dry-run отчёта. */
    private function previewTarget(int $consultantId): ?int
    {
        $rows = DB::select(
            'WITH RECURSIVE up AS (
                SELECT id, inviter, activity, 0 AS depth FROM consultant WHERE id = ?
                UNION ALL
                SELECT c.id, c.inviter, c.activity, up.depth + 1
                FROM consultant c JOIN up ON c.id = up.inviter
                WHERE up.depth < 25
             )
             SELECT id, activity FROM up WHERE depth > 0 ORDER BY depth',
            [$consultantId]
        );
        foreach ($rows as $r) {
            if (! in_array((int) $r->activity, [
                PartnerActivity::Terminated->value,
                PartnerActivity::Excluded->value,
            ], true)) {
                return (int) $r->id;
            }
        }
        return null;
    }
}
