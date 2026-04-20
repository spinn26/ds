<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Каноническая спека MLM-расчётов в виде тестов.
 *
 * Источник истины: .claude/specs/commission-spec.md.
 * Тесты здесь проверяют ФОРМУЛЫ (чистую математику), а не привязаны
 * к реализации CommissionCalculator — потому что часть правил
 * (штрафы, лидерский пул) в Laravel пока не реализована и живёт в
 * legacy Directual-системе. Такие правила помечены
 * markTestIncomplete() и служат документацией того, что ждёт порта.
 *
 * Когда соответствующие куски CommissionCalculator / PartnerStatusService
 * появятся — снимай markTestIncomplete и проверяй реальный класс.
 */
class CommissionSpecTest extends TestCase
{
    // ========================================================================
    // §3. Экономика сделки — VAT-формула "/105*100".
    // ========================================================================

    #[Test]
    public function spec_3_vat_without_vat_rub(): void
    {
        // Per spec §3: "Доход ДС без НДС = сумма × %ДС / 105 × 100".
        // Matches VAT-rate = 5% (1.05 divisor). Current code uses
        //   amountNoVat = amountRub / (1 + vatPercent/100)
        // which is equivalent for any vatPercent setting.
        $amount = 100_000;
        $dsPercent = 50;
        $vatPercent = 5;

        $dsIncome = $amount * $dsPercent / 100;
        $dsIncomeNoVat = $dsIncome / (1 + $vatPercent / 100);

        $this->assertEqualsWithDelta(50_000.0, $dsIncome, 0.01, 'Доход ДС');
        $this->assertEqualsWithDelta(47_619.04, $dsIncomeNoVat, 0.01, 'Доход ДС без НДС');
    }

    #[Test]
    public function spec_3_vat_without_vat_usd(): void
    {
        // Per spec §3: "Доход ДС без НДС (валюта) = сумма × %ДС / 105 × 100 × курс USD".
        $amountUsd = 1_000;
        $dsPercent = 50;
        $vatPercent = 5;
        $usdRate = 90;

        $dsIncomeRub = $amountUsd * $dsPercent / 100 * $usdRate;
        $dsIncomeNoVat = $dsIncomeRub / (1 + $vatPercent / 100);

        $this->assertEqualsWithDelta(45_000.0, $dsIncomeRub, 0.01);
        $this->assertEqualsWithDelta(42_857.14, $dsIncomeNoVat, 0.01);
    }

    // ========================================================================
    // §4.1. Вознаграждение за ЛП — "Доход ДС без НДС × %квал".
    // ========================================================================

    #[Test]
    public function spec_4_1_start_partner_earns_15_percent_of_personal_volume(): void
    {
        // Per spec §4.1: Start (15%) sells "Эволюция" for 100 000.
        //   Доход ДС без НДС = 82 857 ₽ (ds% ≈ 87%, vat=5%)
        //   Volume in balls  = 828.57
        //   Commission       = 828.57 × 15% = 124.29 balls = 12 429 ₽
        $dsIncomeNoVat = 82_857.0;
        $qualPercent = 15;

        $volumeBalls = $dsIncomeNoVat / 100;               // §1: 1 балл = 100 ₽
        $commissionBalls = $volumeBalls * $qualPercent / 100;
        $commissionRub = $commissionBalls * 100;

        $this->assertEqualsWithDelta(828.57, $volumeBalls, 0.01);
        $this->assertEqualsWithDelta(124.29, $commissionBalls, 0.01);
        $this->assertEqualsWithDelta(12_429.0, $commissionRub, 1.0);
    }

    // ========================================================================
    // §4.2. Групповое вознаграждение — "объём × (% наставника − % партнёра)".
    // ========================================================================

    #[Test]
    public function spec_4_2_mentor_earns_margin_difference(): void
    {
        // Per spec §4.2, example 1: Start (15%) продал на 828.57 ballов,
        // mentor Expert (25%). Commission = 828.57 × (25−15)% = 82.86 ≈ 8 286 ₽.
        $volumeBalls = 828.57;
        $mentorPercent = 25;
        $partnerPercent = 15;

        $margin = $mentorPercent - $partnerPercent;
        $this->assertSame(10, (int) $margin);

        $bonusBalls = $volumeBalls * $margin / 100;
        $this->assertEqualsWithDelta(82.86, $bonusBalls, 0.01);
        $this->assertEqualsWithDelta(8_286.0, $bonusBalls * 100, 1.0);
    }

    #[Test]
    public function spec_4_2_equal_levels_yield_zero(): void
    {
        // Per spec §4.2, example 2: both mentors Expert (25%), partner Start (15%).
        // First mentor: (25−15)% = 10% → commission.
        // Second mentor above first: (25−25)% = 0 → commission.
        $volumeBalls = 828.57;

        $firstMargin = 25 - 15;
        $secondMargin = 25 - 25;

        $this->assertEqualsWithDelta(8_286.0, $volumeBalls * $firstMargin, 1.0);
        $this->assertSame(0, $volumeBalls * $secondMargin <=> 0);
    }

    #[Test]
    public function spec_4_2_overtaking_mentor_clamps_to_zero(): void
    {
        // Per spec §4.2, example 3: seller Start (15%), mentor-1 Expert (25%),
        // mentor-2 Pro (20%). Mentor-2's margin = (20−25)% = −5% → clamp to 0.
        //
        // CommissionCalculator enforces this by `if ($marginPercent > 0)` gate
        // before creating a commission row (services/CommissionCalculator.php:154).
        $upstreamMentorPercent = 20;   // Pro
        $downstreamMentorPercent = 25; // Expert

        $margin = $upstreamMentorPercent - $downstreamMentorPercent;
        $clamped = max(0, $margin);

        $this->assertSame(-5, $margin);
        $this->assertSame(0, $clamped);
    }

    // ========================================================================
    // §5.1. Штраф «Отрыв >70%» — ×0.5 на ветку.
    // ========================================================================

    #[Test]
    public function spec_5_1_detachment_penalty_halves_branch_commission(): void
    {
        $this->markTestIncomplete(
            'Not implemented in Laravel CommissionCalculator. Detachment (>70%) ' .
            'currently lives in legacy Directual. When the monthly commission ' .
            'finaliser is ported to PHP, apply 0.5× to the single-branch '.
            'commission whose share of group volume exceeds 70%. Points credit ' .
            '100% (only the cash payout is cut).'
        );

        // Per spec §5.1: partner has 3 branches — 7500 / 500 / 2000 balls (75 / 5 / 20%).
        // Branch 1 is detached → commission from branch 1 ×0.5. Others stay 100%.
        // Expected expressed as a pure check below once implemented:
        //
        //   $branchShare = 7500 / (7500 + 500 + 2000);
        //   $this->assertGreaterThan(0.70, $branchShare);
        //   $adjusted = $branch1Commission * 0.5;
        //   $this->assertEquals($branch1Commission / 2, $adjusted);
    }

    // ========================================================================
    // §5.2. Штраф «Недобор ОП» — ×0.8 ко всем ГП-комиссиям партнёра.
    // ========================================================================

    #[Test]
    public function spec_5_2_failed_op_cuts_group_commission_by_20_percent(): void
    {
        $this->markTestIncomplete(
            'Not implemented in Laravel CommissionCalculator. ОП threshold is ' .
            'stored in status_levels (spec §2 column "ОП по ГП"), but no code ' .
            'currently compares monthly ГП to the threshold and applies the 0.8× ' .
            'multiplier. Personal (ЛП) commissions are never affected.'
        );

        // Per spec §5.2, example (FC): earned ЛП 10 000, ГП 35 000; ОП failed.
        //   ЛП unchanged = 10 000.
        //   ГП ×0.8 = 28 000.
        //   Total 38 000.
        $lp = 10_000;
        $gp = 35_000;
        $gpAfter = $gp * 0.8;
        $this->assertEqualsWithDelta(28_000.0, $gpAfter, 0.01);
        $this->assertEqualsWithDelta(38_000.0, $lp + $gpAfter, 0.01);
    }

    // ========================================================================
    // §5.3. Комбо-штраф (отрыв + недобор ОП) — сначала отрыв к ветке, потом ОП к итогу ГП.
    // ========================================================================

    #[Test]
    public function spec_5_3_combo_penalty_order_is_detachment_then_op(): void
    {
        $this->markTestIncomplete(
            'Order matters: detachment (×0.5 per branch) must be applied FIRST, ' .
            'then the OP penalty (×0.8) to the resulting group total. Reversing ' .
            'the order yields a different number. Not in Laravel code yet.'
        );

        // Per spec §5.3, example (FC, ОП failed): ЛП 1000; Ветка 1 = 10 000 (77%, detached);
        // Ветка 2 = 1000; Ветка 3 = 1000.
        //   Step 1 (detachment on branch 1): 10 000 / 2 = 5 000.
        //     GP total = 5000 + 1000 + 1000 = 7 000.
        //   Step 2 (ОП penalty on the total): 7000 × 0.8 = 5 600.
        //   Final payout = ЛП 1000 + ГП 5600 = 6 600.
        $branch1 = 10_000 / 2;
        $gpTotal = $branch1 + 1_000 + 1_000;
        $gpAfterOp = $gpTotal * 0.8;
        $total = 1_000 + $gpAfterOp;

        $this->assertEqualsWithDelta(5_000.0, $branch1, 0.01);
        $this->assertEqualsWithDelta(7_000.0, $gpTotal, 0.01);
        $this->assertEqualsWithDelta(5_600.0, $gpAfterOp, 0.01);
        $this->assertEqualsWithDelta(6_600.0, $total, 0.01);
    }

    // ========================================================================
    // §6. Лидерский пул.
    // ========================================================================

    #[Test]
    public function spec_6_1_pool_fund_is_one_percent_of_revenue(): void
    {
        // Per spec §6.1: fund per leader level = 1% of VAT-exclusive revenue.
        $revenueNoVat = 100_000_000;
        $poolPercent = 1;

        $fund = $revenueNoVat * $poolPercent / 100;
        $this->assertSame(1_000_000, (int) $fund);
    }

    #[Test]
    public function spec_6_2_share_value_divides_fund_by_nominal_headcount(): void
    {
        // Per spec §6.2: share = fund / nominal headcount (qualifying + not).
        $fund = 1_000_000;

        $this->assertSame(50_000, (int) ($fund / 20));   // TOP FC
        $this->assertSame(100_000, (int) ($fund / 10));  // Silver
        $this->assertSame(200_000, (int) ($fund / 5));   // Gold
        $this->assertSame(250_000, (int) ($fund / 4));   // Platinum
    }

    #[Test]
    public function spec_6_3_matryoshka_stacks_all_lower_leader_shares(): void
    {
        // Per spec §6.3: Platinum partner who qualified gets his own share
        // plus every lower leader share.
        $topfc = 50_000;
        $silver = 100_000;
        $gold = 200_000;
        $platinum = 250_000;

        $total = $topfc + $silver + $gold + $platinum;
        $this->assertSame(600_000, $total);
    }

    #[Test]
    public function spec_6_4_pool_forfeit_is_a_manual_toggle_not_an_automatic_filter(): void
    {
        $this->markTestIncomplete(
            'Per ./.claude/specs/✅Пул.md: the pool screen lists every partner ' .
            'level ≥ 6 with a default-checked «Участвует» toggle. The operator ' .
            'manually UN-checks anyone who failed ОП or breached the 90%-branch ' .
            'detachment rule — the system does NOT auto-filter on 90%. So the ' .
            'test target is not "does the code compute >90 detachment", but ' .
            '"does the pool writer respect the manual toggle and zero out the ' .
            'share for unchecked partners". Laravel has no pool writer at all ' .
            'today (only read-only AdminFinanceController::pool), so this is ' .
            'a port target, not a regression.'
        );

        // Different thresholds must remain distinct in our heads:
        $ordinaryCommissionDetachThreshold = 0.70; // §5.1, auto-applied
        $poolManualForfeitHintThreshold    = 0.90; // §6.4, operator guidance
        $this->assertNotEquals($ordinaryCommissionDetachThreshold, $poolManualForfeitHintThreshold);
    }

    #[Test]
    public function spec_6_5_forfeited_shares_are_not_redistributed(): void
    {
        $this->markTestIncomplete(
            'Spec §6.5: forfeited shares stay with the company, they are NOT ' .
            'redistributed among qualifying peers. If a future pool calculator ' .
            'divides (fund − forfeited) / qualifying_count instead of paying ' .
            'each qualifier exactly one share — that is a bug.'
        );

        // Per spec example: 10 Silver, 2 failed ОП, fund 1 000 000 (share 100 000).
        //   Correct outcome: 8 qualifiers each get 100 000, 200 000 stays with DS.
        //   Wrong outcome (redistribution): 8 qualifiers each get 125 000.
        $share = 100_000;
        $qualifyingCount = 8;
        $correctPayout = $share;
        $wrongPayout = 1_000_000 / $qualifyingCount;

        $this->assertNotEquals($wrongPayout, $correctPayout);
    }

    // ========================================================================
    // §7. Смена квалификации применяется с 1-го числа следующего месяца.
    // ========================================================================

    #[Test]
    public function spec_7_qualification_rate_change_applies_next_month(): void
    {
        $this->markTestIncomplete(
            'CommissionCalculator::getQualificationLevel reads consultant.status_and_lvl ' .
            'without any date filter (services/CommissionCalculator.php:227-245). If ' .
            'status_and_lvl flips inside the month when НГП crosses the threshold, ' .
            'transactions dated after the flip but before the month-end will be ' .
            'calculated at the NEW rate, not the OLD one. Per spec §7 that is wrong — ' .
            'the current month must finish at the old rate and the new one takes effect ' .
            'from the 1st of the next month.'
        );

        // Per spec §7 example: Start (15%), крест в феврале НГП 2500 (>= 2000 Pro).
        //   Весь февраль = 15%. Март = 20%.
    }

    // ========================================================================
    // §8. Реестр выплат — сборка баланса.
    // ========================================================================

    #[Test]
    public function spec_8_balance_ledger_accumulates_remainder_to_next_month(): void
    {
        // Per spec §8: «Итого начислено = Сальдо + Начислено + Прочие + Пул».
        $saldoIn = 2_000;
        $accrued = 50_000;
        $other = -5_000;
        $pool = 0;

        $totalAccrued = $saldoIn + $accrued + $other + $pool;
        $this->assertSame(47_000, $totalAccrued);

        $paid = 45_000;
        $remainder = $totalAccrued - $paid;
        $this->assertSame(2_000, $remainder, 'Остаток → сальдо следующего месяца');
    }

    // ========================================================================
    // Invariants from the larger spec set (added 2026-04-20).
    // ========================================================================

    #[Test]
    public function invariant_single_monthly_qualification(): void
    {
        $this->markTestIncomplete(
            'Per ./.claude/specs/✅Квалификации.md Part 2 §2: partners have ' .
            'ONE qualification per month — the old nominal-vs-calculated split ' .
            'has been retired. `qualificationLog` in the legacy DB still has ' .
            'both columns (nominalLevel, calculationLevel), so a read path ' .
            'that surfaces both into the UI would be a regression against ' .
            'the new rule. Target: the "Квалификации" screen should project ' .
            'a single value per month (prefer calculationLevel if available, ' .
            'else nominalLevel).'
        );
    }

    #[Test]
    public function invariant_closed_periods_are_frozen(): void
    {
        $this->markTestIncomplete(
            'Per ./.claude/specs/✅Комиссии .md Part 2 §1: once a month is ' .
            'closed (grey indicator in the transactions table), no commission / ' .
            'transaction / qualificationLog / poolLog row for that month may ' .
            'be edited or deleted. Corrections go through «Прочие начисления» ' .
            'only. Target: a PHPUnit feature test that tries to PUT a closed ' .
            'transaction and asserts 422/403.'
        );
    }

    #[Test]
    public function invariant_other_accruals_are_reversible(): void
    {
        $this->markTestIncomplete(
            'Per ./.claude/specs/✅Прочие начисления.md Part 2 §4: deleting a ' .
            'manual accrual must run the reverse transaction against the ' .
            'partner balance (+100 ₽ credit → delete = −100 ₽). The current ' .
            'other_accruals table stores the amount but no reversal trigger ' .
            'is wired up in PHP (confirmed via grep). Target: wrap ' .
            'AdminFinanceController::deleteCharge in a service that adjusts ' .
            'the matching balance column (финансовый баланс for тип=Рубли, ' .
            'personalVolume/НГП for тип=Баллы).'
        );
    }

    #[Test]
    public function invariant_vat_rate_is_historical_and_applied_per_transaction(): void
    {
        // Per ./.claude/specs/✅Валюты и НДС.md Part 2 §2.2: `vat` stores
        // an active row plus a closed history. New transactions pick the
        // row whose dateFrom ≤ txDate ≤ dateTo. CommissionCalculator:74-77
        // already does exactly this lookup, so this test just pins the
        // formula equivalence between spec wording "/105*100" and code's
        // "/(1 + vat%/100)" for the canonical 5% rate.
        $vatPercent = 5;
        $divisorFromCode = 1 + $vatPercent / 100;
        $divisorFromSpec = 105 / 100;

        $this->assertEqualsWithDelta($divisorFromSpec, $divisorFromCode, 1e-9);
    }

    #[Test]
    public function invariant_manual_status_override_is_audit_logged(): void
    {
        $this->markTestIncomplete(
            'Per ./.claude/specs/✅Статусы партнеров.md Part 3: any manual ' .
            'date/status change must write an audit row (operator id, ts, ' .
            'old value, new value, comment). The project already has ' .
            'Spatie\\Activitylog wired on User and Contract, but not on ' .
            'activity / dateActivity / dateDeterministic columns of ' .
            'consultant. Target: extend Consultant model ' .
            '`getActivitylogOptions()` to log those columns too.'
        );
    }
}
