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
     * Share value per level: fund(1%) / count of leaders at or above the level.
     *
     * Уточнение по спеке (см. бизнес-кейс декабря 2025 / января 2026):
     * долю уровня L получают ВСЕ партнёры уровня L и выше (через матрёшку),
     * поэтому фонд этого уровня делится на **общее** число его получателей,
     * а не только на партнёров ровно уровня L.
     *
     *   share(6)  = fund / count(6+) = fund / (n6 + n7 + n8 + n9 + n10)
     *   share(7)  = fund / count(7+) = fund / (n7 + n8 + n9 + n10)
     *   share(8)  = fund / count(8+) = fund / (n8 + n9 + n10)
     *   share(9)  = fund / count(9+) = fund / (n9 + n10)
     *   share(10) = fund / count(10+) = fund / n10
     *
     * Партнёр уровня L получает sum(share(6)..share(L)) — это уже шире, чем
     * у него «свой» уровень.
     *
     * @param array<int,int> $nominalCounts  level => count of partners at that level (qualifying + not)
     * @return array<int,float> level => share in rubles
     */
    public function shareValues(float $vatExclusiveRevenue, array $nominalCounts): array
    {
        $fund = $vatExclusiveRevenue * self::POOL_PERCENT;
        $shares = [];
        for ($level = self::LEADER_LEVEL_MIN; $level <= self::LEADER_LEVEL_MAX; $level++) {
            $cumulative = 0;
            for ($l = $level; $l <= self::LEADER_LEVEL_MAX; $l++) {
                $cumulative += (int) ($nominalCounts[$l] ?? 0);
            }
            $shares[$level] = $cumulative > 0 ? $fund / $cumulative : 0.0;
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
