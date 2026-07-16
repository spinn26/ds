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
     * Стоимость доли уровня = фонд(1%) / число партнёров уровня L И ВЫШЕ
     * (накопительный делитель).
     *
     * Эталон — расчётная таблица бизнеса (июнь 2026, получена 2026-07-16):
     *   выручка 10 365 287,40, фонд уровня 103 652,87;
     *   6 ТОП ФК:     / 14 (все лидеры 6+)        = 7 403,78 ₽
     *   7 Сильвер ДС: / 7  (3 сильвер+2 голд+1+1) = 14 807,55 ₽
     *   8 Голд ДС:    / 4                         = 25 913,22 ₽
     *   9 Платинум:   / 2                         = 51 826,44 ₽
     *   10 Кофаундер: / 1                         = 103 652,87 ₽
     *   Рахманов (9, матрёшка) = 7 403,78+14 807,55+25 913,22+51 826,44
     *                          = 99 950,99 ₽; итого выплачено 129 566,09 ₽.
     *
     * Счётчики в примере Yonote-спеки («TOP FC 20, Silver 10, Gold 5,
     * Platinum 3, Co-founder 2») — ВЛОЖЕННЫЕ (уровень и выше), что
     * подтверждено эталонной таблицей.
     *
     * ⚠ История правок: изначально код делил на count(L+); аудит 2026-07-13
     * сменил делитель на «ровно уровня», прочитав счётчики спеки как
     * эксклюзивные (июнь стал 268 998 ₽ вместо 129 566 ₽). 2026-07-16
     * бизнес подтвердил эталонной таблицей: правильно — накопительный
     * count(L+). Возвращено.
     *
     * @param array<int,int> $nominalCounts  level => номинальное число партнёров РОВНО этого
     *                                       уровня (включая неподтвердивших и со снятой галочкой)
     * @return array<int,float> level => стоимость доли в рублях
     */
    public function shareValues(float $vatExclusiveRevenue, array $nominalCounts): array
    {
        // Процент пула настраивается из админки (pool.percent), фолбэк — константа.
        $poolPercent = (float) \App\Models\SystemSetting::value('pool.percent', self::POOL_PERCENT);
        $fund = $vatExclusiveRevenue * $poolPercent;
        $shares = [];
        for ($level = self::LEADER_LEVEL_MIN; $level <= self::LEADER_LEVEL_MAX; $level++) {
            $countAtOrAbove = 0;
            for ($l = $level; $l <= self::LEADER_LEVEL_MAX; $l++) {
                $countAtOrAbove += (int) ($nominalCounts[$l] ?? 0);
            }
            $shares[$level] = $countAtOrAbove > 0 ? $fund / $countAtOrAbove : 0.0;
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
