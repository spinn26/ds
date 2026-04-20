<?php

namespace App\Services;

/**
 * Leader-pool math (спека §6).
 *
 * Pure math, no DB I/O — same rationale as MonthlyFinaliser.
 * Caller supplies the monthly VAT-exclusive revenue and the
 * nominal head-count per leader level (6..10). Service returns
 * per-share values and per-qualifying-partner payouts using the
 * matryoshka stacking rule.
 */
class PoolCalculator
{
    public const POOL_PERCENT = 0.01;           // 1% from vat-exclusive revenue (§6.1)
    public const LEADER_LEVEL_MIN = 6;          // TOP FC
    public const LEADER_LEVEL_MAX = 10;         // Co-founder DS

    /**
     * Share value per level: fund(1%) / nominal head-count.
     *
     * @param array<int,int> $nominalCounts  level => count of partners at that level (qualifying + not)
     * @return array<int,float> level => share in rubles
     */
    public function shareValues(float $vatExclusiveRevenue, array $nominalCounts): array
    {
        $fund = $vatExclusiveRevenue * self::POOL_PERCENT;
        $shares = [];
        foreach ($nominalCounts as $level => $count) {
            if ($level < self::LEADER_LEVEL_MIN || $level > self::LEADER_LEVEL_MAX) continue;
            $shares[$level] = $count > 0 ? $fund / $count : 0.0;
        }
        return $shares;
    }

    /**
     * Matryoshka payout: a qualifying partner at level L receives
     * share(L) + share(L-1) + ... + share(LEADER_LEVEL_MIN).
     *
     * @param array<int,float> $shareValues from shareValues()
     */
    public function matryoshkaForLevel(int $level, array $shareValues): float
    {
        if ($level < self::LEADER_LEVEL_MIN) return 0.0;
        $sum = 0.0;
        for ($l = self::LEADER_LEVEL_MIN; $l <= $level; $l++) {
            $sum += $shareValues[$l] ?? 0.0;
        }
        return $sum;
    }

    /**
     * Distribute the pool. Each partner either gets the matryoshka sum
     * (if `participates` is true) or 0. Forfeited shares stay with the
     * company — NOT redistributed to qualifying peers (§6.5).
     *
     * @param list<array{id:int,level:int,participates:bool}> $partners
     * @return list<array{id:int,level:int,payoutRub:float}>
     */
    public function distribute(float $vatExclusiveRevenue, array $nominalCounts, array $partners): array
    {
        $shares = $this->shareValues($vatExclusiveRevenue, $nominalCounts);
        $out = [];
        foreach ($partners as $p) {
            $payout = $p['participates']
                ? $this->matryoshkaForLevel($p['level'], $shares)
                : 0.0;
            $out[] = [
                'id' => $p['id'],
                'level' => $p['level'],
                'payoutRub' => $payout,
            ];
        }
        return $out;
    }
}
