<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

abstract class AbstractReportType implements ReportTypeContract
{
    /** Хелпер: округлить и привести к скаляру для CSV. */
    protected function n($value, int $decimals = 2): float
    {
        return round((float) $value, $decimals);
    }

    /**
     * Σ комиссий цепочки по каждой транзакции (дедуп по transaction/consultant/
     * chainOrder — самая свежая по id), как в AdminFinanceController::transactions.
     * Возвращает [txId => totalCommissionRub]. Нужно, чтобы колонка «Комиссия» в
     * отчётах совпадала с одноимённой колонкой на странице «Комиссии».
     */
    protected function chainCommissionByTx(array $txIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_filter($txIds)), 5000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::select("
                SELECT d.transaction AS tx, SUM(d.\"amountRUB\") AS total FROM (
                    SELECT DISTINCT ON (cm.transaction, cm.consultant, cm.\"chainOrder\")
                        cm.transaction AS transaction, cm.\"amountRUB\" AS \"amountRUB\"
                    FROM commission cm
                    WHERE cm.transaction IN ($ph) AND cm.\"deletedAt\" IS NULL
                    ORDER BY cm.transaction, cm.consultant, cm.\"chainOrder\", cm.id DESC
                ) d GROUP BY d.transaction
            ", $chunk);
            foreach ($rows as $r) {
                $out[(int) $r->tx] = (float) ($r->total ?? 0);
            }
        }
        return $out;
    }
}
