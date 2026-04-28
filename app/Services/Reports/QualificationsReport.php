<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.4 — рост и объёмы партнёров (2 месяца). */
class QualificationsReport extends AbstractReportType
{
    public function key(): string { return 'qualifications'; }
    public function headers(): array
    {
        return ['Партнёр', 'Активность',
            'Кв. (пред)', 'ЛП (пред)', 'ГП (пред)', 'НГП (пред)',
            'Кв. (тек)', 'ЛП (тек)', 'ГП (тек)', 'НГП (тек)'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $prevFrom = (new \DateTime($from))->modify('-1 month')->format('Y-m-01');
        $prevTo = (new \DateTime($prevFrom))->modify('last day of this month')->format('Y-m-d');

        $logs = DB::table('qualificationLog')
            ->whereNull('dateDeleted')
            ->where(function ($w) use ($from, $to, $prevFrom, $prevTo) {
                $w->whereBetween('date', [$from, $to])
                  ->orWhereBetween('date', [$prevFrom, $prevTo]);
            })
            ->limit(60000)
            ->get();

        $consultantIds = $logs->pluck('consultant')->filter()->unique()->all();
        $consultants = DB::table('consultant')->whereIn('id', $consultantIds)
            ->get(['id', 'personName', 'activity'])->keyBy('id');
        $names = DB::table('directory_of_activities')->pluck('name', 'id');
        $levels = DB::table('status_levels')->get()->keyBy('id');

        $resolveLevel = function ($n, $c) use ($levels) {
            $a = $n ? ($levels[$n] ?? null) : null;
            $b = $c ? ($levels[$c] ?? null) : null;
            if (! $a && ! $b) return null;
            if (! $a) return $b;
            if (! $b) return $a;
            return $a->level >= $b->level ? $a : $b;
        };

        $byCons = [];
        foreach ($logs as $l) {
            $isCurrent = $l->date >= $from && $l->date <= $to;
            $bucket = $isCurrent ? 'current' : 'previous';
            $level = $resolveLevel($l->nominalLevel, $l->calculationLevel);
            $byCons[$l->consultant][$bucket] = [
                'levelTitle' => $level?->title,
                'lp' => $this->n($l->personalVolume),
                'gp' => $this->n($l->groupVolume),
                'ngp' => $this->n($l->groupVolumeCumulative),
            ];
        }

        $rows = [];
        foreach ($byCons as $cid => $data) {
            $cons = $consultants[$cid] ?? null;
            if (! $cons) continue;
            $prev = $data['previous'] ?? ['levelTitle' => '', 'lp' => 0, 'gp' => 0, 'ngp' => 0];
            $cur = $data['current'] ?? ['levelTitle' => '', 'lp' => 0, 'gp' => 0, 'ngp' => 0];
            $rows[] = [
                $cons->personName,
                $cons->activity ? ($names[$cons->activity] ?? '') : '',
                $prev['levelTitle'], $prev['lp'], $prev['gp'], $prev['ngp'],
                $cur['levelTitle'], $cur['lp'], $cur['gp'], $cur['ngp'],
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0] ?? '', $b[0] ?? ''));
        return $rows;
    }
}
