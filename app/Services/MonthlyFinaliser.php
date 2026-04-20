<?php

namespace App\Services;

/**
 * Pure-math calculator for end-of-month penalties (спека §5).
 *
 * Design choice: this class holds **no DB I/O**. Callers read whatever
 * they need (commission rows, status_levels threshold, branch tree)
 * and hand the data in as arrays. The service returns multipliers and
 * deltas; the caller decides whether to actually mutate the DB or just
 * preview.
 *
 * Rationale — these rules historically lived in Directual and wrote
 * into `commission.reduction`, `commission.withheldForGap`,
 * `qualificationLog.gap` etc. Until a staging dry-run confirms we want
 * to take those writes over from Directual, applying this logic blind
 * would corrupt financial data. Keeping the math pure lets us unit-test
 * the rules now and wire the write path in a separate step.
 */
class MonthlyFinaliser
{
    public const DETACHMENT_THRESHOLD = 0.70;   // §5.1
    public const DETACHMENT_PENALTY   = 0.50;   // half commission for the offending branch
    public const OP_PENALTY           = 0.20;   // 20% off the group commission total
    public const LEADER_LEVEL_MIN     = 6;      // Top FC and up carry the rules
    public const EXPERT_LEVEL_MIN     = 3;      // §5 applies from Expert (level 3)

    /**
     * Detachment penalty per branch.
     *
     * @param array<int,float> $branchVolumes  branchKey => group volume in points
     * @return array<int,float> branchKey => multiplier (1.0 or 0.5)
     */
    public function detachmentMultipliers(array $branchVolumes): array
    {
        $total = array_sum($branchVolumes);
        if ($total <= 0) {
            return array_fill_keys(array_keys($branchVolumes), 1.0);
        }

        $multipliers = [];
        foreach ($branchVolumes as $key => $volume) {
            $share = $volume / $total;
            $multipliers[$key] = $share > self::DETACHMENT_THRESHOLD
                ? self::DETACHMENT_PENALTY
                : 1.0;
        }
        return $multipliers;
    }

    /**
     * OP shortfall penalty on the group-commission total.
     *
     * @return float 1.0 (no penalty) or 0.8 (OP failed)
     */
    public function opMultiplier(float $actualGroupVolume, float $requiredOpVolume): float
    {
        if ($requiredOpVolume <= 0) return 1.0;
        return $actualGroupVolume >= $requiredOpVolume ? 1.0 : (1.0 - self::OP_PENALTY);
    }

    /**
     * Combo (spec §5.3): detachment per branch first, OP on the sum.
     *
     * @param array<int,float> $branchCommissionsRub  branchKey => commission in rubles
     * @param array<int,float> $branchVolumes         branchKey => group volume in points
     * @return array{
     *   afterDetachment: array<int,float>,
     *   groupTotalRub: float,
     *   afterOp: float,
     *   detachmentMultipliers: array<int,float>,
     *   opMultiplier: float
     * }
     */
    public function applyCombo(
        array $branchCommissionsRub,
        array $branchVolumes,
        float $actualGroupVolume,
        float $requiredOpVolume,
    ): array {
        $dMults = $this->detachmentMultipliers($branchVolumes);

        $afterDetach = [];
        foreach ($branchCommissionsRub as $key => $rub) {
            $afterDetach[$key] = $rub * ($dMults[$key] ?? 1.0);
        }

        $groupTotalRub = array_sum($afterDetach);
        $opMult = $this->opMultiplier($actualGroupVolume, $requiredOpVolume);
        $afterOp = $groupTotalRub * $opMult;

        return [
            'afterDetachment' => $afterDetach,
            'groupTotalRub' => $groupTotalRub,
            'afterOp' => $afterOp,
            'detachmentMultipliers' => $dMults,
            'opMultiplier' => $opMult,
        ];
    }
}
