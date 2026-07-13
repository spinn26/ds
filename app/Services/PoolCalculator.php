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
     * Стоимость доли уровня = фонд(1%) / номинальное число партнёров РОВНО
     * этого уровня.
     *
     * Per spec ✅Расчет пула (числовой пример, выручка 100 млн):
     *   TOP FC (20 чел.)     = 100 000 000 × 1% / 20 = 50 000 ₽
     *   Silver DS (10 чел.)  = 100 000 000 × 1% / 10 = 100 000 ₽
     *   Gold DS (5 чел.)     = 100 000 000 × 1% / 5  = 200 000 ₽
     *   Platinum DS (3 чел.) = 100 000 000 × 1% / 3  = 333 300 ₽
     *   Co-founder (2 чел.)  = 100 000 000 × 1% / 2  = 500 000 ₽
     *   Бонус Co-founder (матрёшка) = сумма всех пяти = 1 183 300 ₽
     *
     * ⚠ Раньше делили на count(L+) («уровень L и выше») со ссылкой на
     * «бизнес-кейс декабря 2025», которого нет ни в одном документе. Это
     * занижало доли лидеров примерно вдвое (июнь 2026: 129 563 ₽ вместо
     * 268 997 ₽). Восстановлено по спеке 2026-07-13.
     *
     * @param array<int,int> $nominalCounts  level => номинальное число партнёров этого уровня
     *                                       (включая неподтвердивших и со снятой галочкой)
     * @return array<int,float> level => стоимость доли в рублях
     */
    public function shareValues(float $vatExclusiveRevenue, array $nominalCounts): array
    {
        // Процент пула настраивается из админки (pool.percent), фолбэк — константа.
        $poolPercent = (float) \App\Models\SystemSetting::value('pool.percent', self::POOL_PERCENT);
        $fund = $vatExclusiveRevenue * $poolPercent;
        $shares = [];
        for ($level = self::LEADER_LEVEL_MIN; $level <= self::LEADER_LEVEL_MAX; $level++) {
            $count = (int) ($nominalCounts[$level] ?? 0);
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
