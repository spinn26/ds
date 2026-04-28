<?php

namespace App\Services\Reports;

/**
 * Регистр всех типов отчётов. ReportGenerator делегирует ему по
 * machine key из request.
 */
class ReportTypeRegistry
{
    /** @var array<string,ReportTypeContract> */
    private array $types = [];

    public function __construct(
        RevenueExpensesReport $revenueExpenses,
        PartnerStatusReport $partnerStatus,
        QualificationsReport $qualifications,
        CommissionsReport $commissions,
        FinrezTransactionsReport $finrezTx,
        FinrezCommissionsReport $finrezCm,
        PaymentRegistryReport $paymentRegistry,
    ) {
        foreach ([$revenueExpenses, $partnerStatus, $qualifications, $commissions, $finrezTx, $finrezCm, $paymentRegistry] as $t) {
            $this->types[$t->key()] = $t;
        }
    }

    public function get(string $key): ?ReportTypeContract
    {
        return $this->types[$key] ?? null;
    }

    /** @return array<string,ReportTypeContract> */
    public function all(): array
    {
        return $this->types;
    }
}
