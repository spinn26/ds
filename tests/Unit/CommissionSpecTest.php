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
        // Per spec §5.1: 3 ветки 7500/500/2000, первая ветка = 75% → ×0.5.
        $finaliser = new \App\Services\MonthlyFinaliser();
        $mults = $finaliser->detachmentMultipliers([
            1 => 7500,
            2 => 500,
            3 => 2000,
        ]);

        $this->assertSame(0.5, $mults[1], 'Ветка 1 дала >70% — режется вдвое');
        $this->assertSame(1.0, $mults[2]);
        $this->assertSame(1.0, $mults[3]);
    }

    // ========================================================================
    // §5.2. Штраф «Недобор ОП» — ×0.8 ко всем ГП-комиссиям партнёра.
    // ========================================================================

    #[Test]
    public function spec_5_2_failed_op_cuts_group_commission_by_20_percent(): void
    {
        // Per spec §5.2 (FC, ОП=3000): делают 2800. ГП ×0.8. ЛП нетронут.
        $finaliser = new \App\Services\MonthlyFinaliser();
        $mult = $finaliser->opMultiplier(actualGroupVolume: 2800, requiredOpVolume: 3000);
        $this->assertSame(0.8, $mult, 'ОП недобор → ×0.8 к ГП');

        $ok = $finaliser->opMultiplier(actualGroupVolume: 3500, requiredOpVolume: 3000);
        $this->assertSame(1.0, $ok, 'ОП выполнен → без штрафа');

        // ЛП не участвует: сервис просто не трогает ЛП, caller сам решает.
        $lp = 10_000;
        $gp = 35_000;
        $finalGp = $gp * $mult;
        $this->assertEqualsWithDelta(28_000.0, $finalGp, 0.01);
        $this->assertEqualsWithDelta(38_000.0, $lp + $finalGp, 0.01);
    }

    // ========================================================================
    // §5.3. Комбо-штраф (отрыв + недобор ОП) — сначала отрыв к ветке, потом ОП к итогу ГП.
    // ========================================================================

    #[Test]
    public function spec_5_3_combo_penalty_order_is_detachment_then_op(): void
    {
        // Per spec §5.3 example (FC, ОП failed): ЛП 1000;
        //   Ветка 1 (77%, detached) = 10 000, Ветка 2 = 1000, Ветка 3 = 1000.
        // Expected: gp after combo = 5600, total with ЛП = 6600.
        // Volumes mirror commissions 1:1 in this example so we pass both as the same shape.
        $finaliser = new \App\Services\MonthlyFinaliser();
        $result = $finaliser->applyCombo(
            branchCommissionsRub: [1 => 10_000, 2 => 1_000, 3 => 1_000],
            branchVolumes:        [1 => 10_000, 2 => 1_000, 3 => 1_000],
            actualGroupVolume:    12_000,   // failed OP (assume threshold higher)
            requiredOpVolume:     20_000,
        );

        $this->assertSame(0.5, $result['detachmentMultipliers'][1], 'Ветка 1 обрезана');
        $this->assertSame(0.8, $result['opMultiplier'], 'ОП штраф 20%');
        $this->assertEqualsWithDelta(5_000.0, $result['afterDetachment'][1], 0.01);
        $this->assertEqualsWithDelta(7_000.0, $result['groupTotalRub'], 0.01, 'ГП до ОП = 7000');
        $this->assertEqualsWithDelta(5_600.0, $result['afterOp'], 0.01, 'ГП после ОП = 5600');

        $lp = 1_000;
        $this->assertEqualsWithDelta(6_600.0, $lp + $result['afterOp'], 0.01);
    }

    // ========================================================================
    // §6. Лидерский пул.
    // ========================================================================

    #[Test]
    public function spec_6_1_pool_fund_is_one_percent_of_revenue(): void
    {
        // Per spec §6.1: fund per leader level = 1% of VAT-exclusive revenue.
        $pool = new \App\Services\PoolCalculator();
        $shares = $pool->shareValues(100_000_000, [6 => 20]);

        // 1% of 100M = 1M, divided by 20 heads at Top FC = 50k per share.
        $this->assertSame(50_000.0, $shares[6]);
    }

    #[Test]
    public function spec_6_2_share_value_divides_fund_by_nominal_headcount(): void
    {
        // Per spec §6.2: share = fund / nominal headcount (qualifying + not).
        $pool = new \App\Services\PoolCalculator();
        $shares = $pool->shareValues(100_000_000, [
            6 => 20,
            7 => 10,
            8 => 5,
            9 => 4,
        ]);

        $this->assertSame(50_000.0, $shares[6]);   // TOP FC
        $this->assertSame(100_000.0, $shares[7]);  // Silver
        $this->assertSame(200_000.0, $shares[8]);  // Gold
        $this->assertSame(250_000.0, $shares[9]);  // Platinum
    }

    #[Test]
    public function spec_6_3_matryoshka_stacks_all_lower_leader_shares(): void
    {
        // Per spec §6.3: Platinum partner who qualified gets own share plus every lower leader share.
        $pool = new \App\Services\PoolCalculator();
        $shares = [6 => 50_000.0, 7 => 100_000.0, 8 => 200_000.0, 9 => 250_000.0];

        $this->assertSame(50_000.0, $pool->matryoshkaForLevel(6, $shares));
        $this->assertSame(150_000.0, $pool->matryoshkaForLevel(7, $shares));
        $this->assertSame(350_000.0, $pool->matryoshkaForLevel(8, $shares));
        $this->assertSame(600_000.0, $pool->matryoshkaForLevel(9, $shares));
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
        // Per spec §6.5: 10 Silver, 2 failed ОП, fund 1 000 000 (share 100 000).
        //   Correct outcome: 8 qualifiers each get 100 000, 200 000 stays with DS.
        //   Wrong outcome (redistribution): 8 qualifiers each get 125 000.
        $pool = new \App\Services\PoolCalculator();

        // Ten Silver partners (level 7), two with participates=false.
        $partners = [];
        for ($i = 1; $i <= 8; $i++) {
            $partners[] = ['id' => $i, 'level' => 7, 'participates' => true];
        }
        for ($i = 9; $i <= 10; $i++) {
            $partners[] = ['id' => $i, 'level' => 7, 'participates' => false];
        }

        // No lower-level leaders here, so Silver matryoshka is just share(6)+share(7).
        // Zero Top-FC head-count makes share(6)=0; Silver share alone drives the payout.
        $distribution = $pool->distribute(100_000_000, [6 => 0, 7 => 10], $partners);

        $payouts = array_column($distribution, 'payoutRub');
        $sumPaidOut = array_sum($payouts);

        $this->assertSame(100_000.0, $distribution[0]['payoutRub'], 'Qualifying partner gets one share');
        $this->assertSame(0.0, $distribution[8]['payoutRub'], 'Non-participating partner gets zero');
        $this->assertSame(800_000.0, $sumPaidOut, 'Only 8×100k paid; 200k stays with DS');
    }

    // ========================================================================
    // §7. Смена квалификации применяется с 1-го числа следующего месяца.
    // ========================================================================

    #[Test]
    public function spec_7_qualification_rate_change_applies_next_month(): void
    {
        // Per spec §7 example: Start (15%), крест в феврале НГП 2500 (≥ 2000 Pro).
        //   Весь февраль = 15%. Март = 20%.
        //
        // The rule is enforced in CommissionCalculator::getQualificationLevel
        // via a `where('date', '<', startOfTxMonth)` filter against
        // qualificationLog. This pure-math check pins the date arithmetic
        // the resolver relies on: for a transaction on 15 Feb the "valid"
        // qualificationLog rows are anything dated strictly before 1 Feb.
        $txDate = '2026-02-15';
        $startOfTxMonth = \Carbon\Carbon::parse($txDate)->startOfMonth()->toDateString();

        $this->assertSame('2026-02-01', $startOfTxMonth);
        $this->assertTrue('2026-01-31' < $startOfTxMonth, 'January log row stays valid for Feb transactions');
        $this->assertFalse('2026-02-01' < $startOfTxMonth, 'A February-1 threshold crossing does NOT apply to Feb txs');
        $this->assertFalse('2026-02-20' < $startOfTxMonth, 'A mid-February log row does NOT apply to Feb txs either');
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
        // Per ./.claude/specs/✅Комиссии .md Part 2 §1: closed months are
        // frozen. Implementation: PeriodFreezeService backed by the
        // period_closures table. Guards are wired into:
        //   - CommissionCalculator::calculateInTransaction
        //   - TransactionImportController::rollback
        // Admin endpoints: GET/POST /admin/periods*.
        //
        // This is a structural assertion — the service exists, exposes
        // isFrozen/guard/close/reopen, and is referenced from the two
        // write-paths above. Full feature-level coverage (PUT to closed
        // month returns 422) waits for a test DB with the legacy
        // `transaction` table seeded — not cheap without migrations for
        // the 286 legacy tables.
        $this->assertTrue(class_exists(\App\Services\PeriodFreezeService::class));
        $this->assertTrue(method_exists(\App\Services\PeriodFreezeService::class, 'isFrozen'));
        $this->assertTrue(method_exists(\App\Services\PeriodFreezeService::class, 'guard'));
        $this->assertTrue(method_exists(\App\Services\PeriodFreezeService::class, 'close'));
        $this->assertTrue(method_exists(\App\Services\PeriodFreezeService::class, 'reopen'));

        // The calculator must accept the freeze service — the constructor
        // signature is what makes the guard impossible to forget.
        $refl = new \ReflectionClass(\App\Services\CommissionCalculator::class);
        $ctor = $refl->getConstructor();
        $types = array_map(
            fn ($p) => $p->getType()?->getName(),
            $ctor->getParameters()
        );
        $this->assertContains(\App\Services\PeriodFreezeService::class, $types);
    }

    #[Test]
    public function invariant_other_accruals_are_reversible(): void
    {
        // Per ./.claude/specs/✅Прочие начисления.md Part 2 §3-§4:
        // - storeCharge writes points into consultant.personalVolume +
        //   groupVolumeCumulative inside a DB::transaction.
        // - deleteCharge reads the row first, applies the inverse, then
        //   removes the row, also inside a transaction.
        //
        // Structural assertions — we can't run the write against an empty
        // sqlite :memory: without the 286 legacy tables, but we can pin:
        //   1) both controller methods exist;
        //   2) deleteCharge no longer does the old one-liner delete;
        //   3) the source of the method includes the reversal clause and
        //      the DB::transaction wrapper.
        $refl = new \ReflectionClass(\App\Http\Controllers\Api\AdminFinanceController::class);
        $storeSrc = $this->methodSource($refl, 'storeCharge');
        $deleteSrc = $this->methodSource($refl, 'deleteCharge');

        $this->assertStringContainsString('DB::transaction', $storeSrc, 'storeCharge must be atomic');
        $this->assertStringContainsString('personalVolume', $storeSrc, 'storeCharge updates personalVolume for points');
        $this->assertStringContainsString('groupVolumeCumulative', $storeSrc, 'storeCharge updates НГП for points');

        $this->assertStringContainsString('DB::transaction', $deleteSrc, 'deleteCharge must be atomic');
        $this->assertStringContainsString('- {$points}', $deleteSrc, 'deleteCharge reverses the points');
    }

    /** Helper: read a method body as source for structural checks. */
    private function methodSource(\ReflectionClass $cls, string $method): string
    {
        $m = $cls->getMethod($method);
        $file = file($m->getFileName());
        return implode('', array_slice(
            $file,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));
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
        // Per ./.claude/specs/✅Статусы партнеров.md Part 3: any manual
        // date/status change must leave a Spatie Activitylog row. The list of
        // tracked columns on Consultant should cover every field the edit
        // modal can touch — any new field that ships in the UI needs a mirror
        // entry here, or audit will go dark for it.
        $tracked = (new \App\Models\Consultant())->getActivitylogOptions()->logAttributes;

        $mustTrack = [
            'activity',
            'dateActivity',
            'dateDeactivity',
            'dateDeleted',
            'activationDeadline',
            'yearPeriodEnd',
            'terminationCount',
            'status_and_lvl',
        ];

        foreach ($mustTrack as $column) {
            $this->assertContains(
                $column,
                $tracked,
                "Consultant::getActivitylogOptions() must log `{$column}` — any override of it has to end up in activity_log."
            );
        }
    }
}
