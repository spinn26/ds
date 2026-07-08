<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use App\Services\PartnerStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовый бэкфилл: контракты, оставшиеся на терминированных (3) / исключённых
 * (5) партнёрах, переезжают на ближайшего активного вышестоящего наставника.
 *
 * Directual раньше переносил контракты заливкой; при остановке заливок
 * контракты «застряли» на терминированных ФК → расчёт шёл терминированному,
 * а комиссия не поднималась к активному наставнику. Переносим штатной логикой
 * PartnerStatusService::reassignContractsToUpline (история changeConsultant-
 * ContractLog + RecomputeTransferChainJob по открытым периодам).
 *
 * Партнёры без активного вышестоящего (напр. Шиндлер) пропускаются и выводятся
 * отдельно — им нужен ручной выбор владельца.
 *
 * --dry-run: только показать план, без записи.
 */
class ReassignTerminatedContracts extends Command
{
    protected $signature = 'partners:reassign-terminated-contracts {--dry-run : только показать план}';

    protected $description = 'Перенос контрактов с терминированных/исключённых ФК на ближайшего активного вышестоящего';

    public function handle(PartnerStatusService $status): int
    {
        $dry = (bool) $this->option('dry-run');

        $owners = DB::table('contract as co')
            ->join('consultant as c', 'c.id', '=', 'co.consultant')
            ->whereIn('c.activity', [PartnerActivity::Terminated->value, PartnerActivity::Excluded->value])
            ->whereNull('co.deletedAt')
            ->groupBy('co.consultant', 'c.personName', 'c.activity')
            ->select('co.consultant as id', 'c.personName', 'c.activity', DB::raw('count(*) as contracts'))
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Терминированных/исключённых ФК с контрактами: {$owners->count()}");

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
                    '  %-32s act=%d контрактов=%-4d → %s (#%d)%s',
                    $o->personName, $o->activity, $o->contracts, $target, $targetId,
                    $fallback ? ' [нет вышестоящего]' : ''
                ));
                $totalMoved += $o->contracts;
                $fallback && $totalFallback += $o->contracts;
                continue;
            }

            $res = $status->reassignContractsToUpline($consultant, 'Бэкфилл: перенос контрактов терминированного ФК');
            $totalMoved += $res['moved'];
            $totalFallback += $res['fallbackUnknown'];
            $this->line(sprintf(
                '  %-32s перенесено=%-4d %s',
                $o->personName, $res['moved'],
                $res['fallbackUnknown'] ? "→ Неизвестный консультант={$res['fallbackUnknown']}" : ''
            ));
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Итого перенесено: {$totalMoved}, из них на «Неизвестного консультанта» (нет вышестоящего): {$totalFallback}");
        if ($dry) {
            $this->warn('[DRY-RUN] Запись не выполнялась. Убери --dry-run для боевого прогона.');
        } else {
            $this->info('RecomputeTransferChainJob поставлены в очередь — пересчёт комиссий по открытым периодам идёт асинхронно.');
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
