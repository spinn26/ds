<?php

namespace App\Jobs;

use App\Services\CommissionCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Пересчёт комиссионной цепочки после ручной перестановки
 * (партнёр-наставник / контракт / клиент).
 *
 * Цепочка commission «запекается» на момент расчёта: прямой партнёр =
 * contract.consultant (chainOrder=1), дальше каскад вверх по
 * consultant.inviter. Смена inviter/владельца сама по себе не двигает уже
 * посчитанные строки — этот job пересчитывает затронутые транзакции.
 *
 * ТОЛЬКО открытые периоды: calculateForTransaction сам пропускает
 * исторические (< HISTORICAL_CUTOFF) и закрытые (period_closures) месяцы,
 * возвращая error — такие транзакции просто считаем skipped.
 *
 * NB: клиентская перестановка меняет client.consultant, а комиссия идёт по
 * contract.consultant — поэтому для 'client' пересчёт контрактов клиента
 * меняет цепочку только если контракты действительно принадлежат этому
 * клиенту и их владелец сменился (иначе no-op).
 */
class RecomputeTransferChainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    /** @param string $subject 'partner'|'contract'|'client' */
    public function __construct(
        public readonly string $subject,
        public readonly int $subjectId,
    ) {}

    public function handle(CommissionCalculator $calculator): void
    {
        $txIds = $this->affectedTransactionIds();
        if (empty($txIds)) {
            Log::info('RecomputeTransferChainJob: нет транзакций к пересчёту', [
                'subject' => $this->subject, 'subjectId' => $this->subjectId,
            ]);
            return;
        }

        $recomputed = 0;
        $skipped = 0;
        foreach ($txIds as $txId) {
            // До пересчёта запоминаем консультантов старой цепочки этой tx:
            // после пересчёта кого-то из них может не стать в новой цепочке, и
            // их consultantBalance нужно пересобрать вручную —
            // calculateForTransaction пересобирает только новую цепочку.
            $oldPairs = DB::table('commission')
                ->where('transaction', $txId)
                ->whereNull('deletedAt')
                ->select('consultant', 'dateMonth', 'dateYear')
                ->distinct()
                ->get();

            $res = $calculator->calculateForTransaction((int) $txId);
            if (! empty($res['error'])) {
                $skipped++;
                continue;
            }
            $recomputed++;

            foreach ($oldPairs as $p) {
                if (! $p->consultant || ! $p->dateMonth) continue;
                try {
                    $calculator->rebuildBalanceFor(
                        (int) $p->consultant,
                        (string) $p->dateMonth,
                        (string) ($p->dateYear ?? substr((string) $p->dateMonth, 0, 4)),
                    );
                } catch (\Throwable $e) {
                    Log::warning('RecomputeTransferChainJob: rebuild old-chain balance failed', [
                        'tx' => $txId, 'consultant' => $p->consultant, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('RecomputeTransferChainJob завершён', [
            'subject' => $this->subject, 'subjectId' => $this->subjectId,
            'transactions' => count($txIds), 'recomputed' => $recomputed, 'skipped' => $skipped,
        ]);
    }

    /** @return array<int> id затронутых транзакций (открытые периоды). */
    private function affectedTransactionIds(): array
    {
        $cutoff = CommissionCalculator::HISTORICAL_CUTOFF;

        // Базовый фильтр: не удалённые, дата >= cutoff (закрытые месяцы
        // внутри cutoff-окна отсечёт сам calculateForTransaction).
        $base = fn () => DB::table('transaction as t')
            ->whereNull('t.deletedAt')
            ->where(function ($q) use ($cutoff) {
                $q->where('t.date', '>=', $cutoff)->orWhereNull('t.date');
            });

        return match ($this->subject) {
            'contract' => $base()
                ->where('t.contract', $this->subjectId)
                ->pluck('t.id')->map(fn ($v) => (int) $v)->all(),

            'client' => $base()
                ->whereIn('t.contract', function ($sub) {
                    $sub->select('id')->from('contract')
                        ->where('client', $this->subjectId)
                        ->whereNull('deletedAt');
                })
                ->pluck('t.id')->map(fn ($v) => (int) $v)->all(),

            'partner' => $base()
                ->whereIn('t.contract', function ($sub) {
                    $sub->select('id')->from('contract')
                        ->whereIn('consultant', $this->subtreeConsultantIds())
                        ->whereNull('deletedAt');
                })
                ->pluck('t.id')->map(fn ($v) => (int) $v)->all(),

            default => [],
        };
    }

    /**
     * Поддерево консультанта (сам + все потомки) через recursive CTE.
     * UNION (а не UNION ALL) — защита от циклов в legacy-структуре.
     *
     * @return array<int>
     */
    private function subtreeConsultantIds(): array
    {
        $rows = DB::select(
            'WITH RECURSIVE sub AS (
                SELECT id FROM consultant WHERE id = ?
                UNION
                SELECT c.id FROM consultant c JOIN sub ON c.inviter = sub.id
             ) SELECT id FROM sub',
            [$this->subjectId]
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }
}
