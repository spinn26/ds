<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Раздел «Квалификации» (/admin/qualifications).
 *
 * Вынесено из AdminFinanceController (метод занимал 177 строк плюс расчёт
 * живых объёмов). Код перенесён дословно.
 *
 * Три места, которые уже приводили к неверным цифрам на экране:
 *   - поиск идёт по ЖИВОМУ имени из consultant: у части legacy-строк
 *     денормализованное имя в логе указывает на другого человека;
 *   - НГП держится carry-forward — строка финализа Отрыв/ОП приходит с
 *     пустым накопительным ГП и иначе обнуляла бы показатель;
 *   - открытый месяц считается живьём из транзакций, закрытый и
 *     исторический берутся снимком.
 *
 * ⚠ Известный дефект, зафиксированный тестом и НЕ исправленный: строка,
 * датированная последним днём месяца, попадает в колонку предыдущего —
 * принадлежность месяцу определяется сравнением строк, а в колонке лежит
 * timestamp. Правка сдвигает цифры на финансовом экране.
 */
class QualificationsListingService
{
    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        $month = $request->input('month', now()->format('Y-m'));
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
        $prevEnd = date('Y-m-t', strtotime($prevStart));

        // Все consultant_id с записью за выбранный или предыдущий месяц
        $consultantQuery = DB::table('qualificationLog')
            ->whereNull('dateDeleted')
            ->where(function ($w) use ($start, $end, $prevStart, $prevEnd) {
                $w->whereBetween('date', [$start, $end])
                  ->orWhereBetween('date', [$prevStart, $prevEnd]);
            });

        if ($request->filled('search')) {
            // Ищем по ЖИВОМУ имени из consultant (источник истины), а не по
            // денормализованному qualificationLog.consultantPersonName: у части
            // legacy-строк (Directual CSV↔платформа id-swap) денорм-имя указывает
            // на другого человека, чем текущее consultant.personName, которое мы
            // и показываем. Иначе «поиск X → показан Y» (Березнева→Зеленков и т.п.).
            $consultantQuery->whereIn('consultant', function ($sub) use ($request) {
                $sub->select('id')->from('consultant')
                    ->where('personName', 'ilike', '%' . $request->search . '%');
            });
        }

        // Фильтр по статусу активности — переносим на server-side, чтобы
        // pagination и total были консистентны (раньше фильтровалось
        // в массиве на фронте поверх 25-row страницы — total врал).
        if ($request->filled('activity')) {
            $activityMap = ['active' => 1, 'terminated' => 3, 'registered' => 4, 'excluded' => 5];
            $activityId = $activityMap[$request->activity] ?? null;
            if ($activityId !== null) {
                $consultantQuery->whereIn('consultant', function ($sub) use ($activityId) {
                    $sub->select('id')->from('consultant')->where('activity', $activityId);
                });
            }
        }

        $consultantIds = $consultantQuery->distinct()->pluck('consultant')->all();

        // Фильтр «только ненулевые логи»
        if ($request->boolean('non_zero_only')) {
            $nonZeroIds = DB::table('qualificationLog')
                ->whereNull('dateDeleted')
                ->whereIn('consultant', $consultantIds)
                ->where(function ($w) {
                    $w->where('personalVolume', '>', 0)
                      ->orWhere('groupVolume', '>', 0);
                })
                ->pluck('consultant')
                ->unique()
                ->all();
            $consultantIds = array_values(array_intersect($consultantIds, $nonZeroIds));
        }

        $total = count($consultantIds);
        $offset = ($request->input('page', 1) - 1) * 25;
        $pageIds = array_slice($consultantIds, $offset, 25);

        if (empty($pageIds)) {
            return ['data' => [], 'total' => 0, 'monthLabel' => $month, 'prevMonthLabel' => substr($prevStart, 0, 7)];
        }

        // Вытаскиваем все нужные строки одним запросом
        $logs = DB::table('qualificationLog')
            ->whereNull('dateDeleted')
            ->whereIn('consultant', $pageIds)
            ->where(function ($w) use ($start, $end, $prevStart, $prevEnd) {
                $w->whereBetween('date', [$start, $end])
                  ->orWhereBetween('date', [$prevStart, $prevEnd]);
            })
            ->get();

        $consultants = DB::table('consultant')
            ->whereIn('id', $pageIds)
            ->get(['id', 'personName', 'activity'])
            ->keyBy('id');

        // status_levels lookup
        $levels = DB::table('status_levels')->get()->keyBy('id');

        $resolveLevel = function ($nominal, $calculation) use ($levels) {
            $a = $nominal ? ($levels[$nominal] ?? null) : null;
            $b = $calculation ? ($levels[$calculation] ?? null) : null;
            if (! $a && ! $b) return null;
            if (! $a) return $b;
            if (! $b) return $a;
            return ($a->level >= $b->level) ? $a : $b;
        };

        $byConsultant = [];
        foreach ($logs as $l) {
            $isCurrent = $l->date >= $start && $l->date <= $end;
            $bucket = $isCurrent ? 'current' : 'previous';
            $level = $resolveLevel($l->nominalLevel, $l->calculationLevel);
            // НГП (cumulative) держим как последний НЕ-NULL (carry-forward):
            // penalty-строка финализа Отрыв/ОП имеет date=конец месяца и NULL
            // cumulative, и, будучи самой свежей в выборке, иначе занулила бы
            // НГП на админ-странице финансов. Остальные поля берём из текущей
            // строки (last-wins, как раньше). Sticky-логика order-independent.
            $prevCum = $byConsultant[$l->consultant][$bucket]['groupVolumeCumulative'] ?? null;
            $rowCum = $l->groupVolumeCumulative !== null ? (float) $l->groupVolumeCumulative : null;
            $byConsultant[$l->consultant][$bucket] = [
                'id' => $l->id,
                'personalVolume' => round((float) ($l->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($l->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($rowCum ?? $prevCum ?? 0), 2),
                'levelId' => $level?->id,
                'levelTitle' => $level?->title,
                'levelNum' => $level?->level,
                'mandatoryGP' => (float) ($level->mandatoryGP ?? 0),
                'date' => $l->date,
            ];
        }

        // LIVE-режим для ОТКРЫТОГО месяца (спека «Открытый период», Сценарий А):
        // раздел «Квалификации» = живой мониторинг, показатели считаются на лету
        // из транзакций. Для ЗАКРЫТОГО месяца остаётся зафиксированный снимок
        // ($byConsultant) — он же кормит раздел «Комиссии» (Сценарий Б).
        //
        // Разделение с решением 2026-06-05 «расчёты по кнопке»: по кнопке остаются
        // ДЕНЬГИ (комиссии, удержания, пул). Мониторинг НГП/ЛП/ГП — живой.
        $isFrozen = app(\App\Services\PeriodFreezeService::class)
            ->isFrozen((int) substr($month, 0, 4), (int) substr($month, 5, 2));
        $isHistorical = \App\Services\CommissionCalculator::isHistorical($month);
        if (! $isFrozen && ! $isHistorical) {
            $live = $this->liveQualificationVolumes($pageIds, $month);
            $calc = app(\App\Services\CommissionCalculator::class);
            foreach ($live as $cid => $vals) {
                // Уровень месяца = ВХОДНОЙ уровень (итог предыдущего), тем же
                // резолвером, что и комиссии (getQualificationLevel). Не выводим по
                // НГП: повышение зависит и от ОП/отрыва. Если снимок current уже
                // есть — оставляем его уровень; иначе резолвим входной.
                $snap = $byConsultant[$cid]['current'] ?? null;
                if ($snap && $snap['levelId']) {
                    $levelBlock = [
                        'levelId' => $snap['levelId'], 'levelTitle' => $snap['levelTitle'],
                        'levelNum' => $snap['levelNum'], 'mandatoryGP' => $snap['mandatoryGP'],
                    ];
                } else {
                    $lvId = $calc->resolveLevelForPreview((int) $cid, $month . '-01')['levelId'] ?? null;
                    $lv = $lvId ? ($levels[$lvId] ?? null) : null;
                    $levelBlock = [
                        'levelId' => $lv?->id, 'levelTitle' => $lv?->title,
                        'levelNum' => $lv?->level, 'mandatoryGP' => $lv ? (float) $lv->mandatoryGP : 0.0,
                    ];
                }
                $byConsultant[$cid]['current'] = array_merge(
                    ['date' => $month . '-01'], $levelBlock, $vals, ['live' => true]
                );
            }
        }

        $activityMap = [1 => 'active', 3 => 'terminated', 4 => 'registered', 5 => 'excluded'];

        $data = [];
        foreach ($pageIds as $cid) {
            $cons = $consultants[$cid] ?? null;
            if (! $cons) continue;
            $data[] = [
                'consultant' => (int) $cid,
                'consultantName' => $cons->personName,
                'activity' => $activityMap[$cons->activity ?? 0] ?? 'unknown',
                'current' => $byConsultant[$cid]['current'] ?? null,
                'previous' => $byConsultant[$cid]['previous'] ?? null,
            ];
        }

        return [
            'data' => $data,
            'total' => $total,
            'monthLabel' => $month,
            'prevMonthLabel' => substr($prevStart, 0, 7),
        ];
    }

    /**
     * LIVE-показатели раздела «Квалификации» для ОТКРЫТОГО месяца.
     *
     * Спека «Бизнес-логика расчётов (Открытый период)», Часть 1 + Сценарий А:
     * раздел «Квалификации» показывает ФАКТИЧЕСКИЙ статус на текущую секунду из
     * транзакций, а не снимок qualificationLog. Снимок (по кнопке «Закрытие
     * периода») используется только разделом «Комиссии» для фиксации выплат.
     *
     * Формулы:
     *   ЛП = Σ commission.personalVolume за месяц (каскадные строки chainOrder>=2
     *        пишут personalVolume=0, поэтому суммой не задваивается);
     *   ГП = ЛП партнёра + Σ ЛП всей нижестоящей структуры;
     *   НГП = база строго ДО начала месяца (последний снимок groupVolumeCumulative)
     *         + ГП месяца.
     *
     * УРОВЕНЬ здесь НЕ пересчитывается: повышение зависит не только от НГП (ещё
     * ОП, отрыв, ручное подтверждение), а авто-повышение по НГП — отдельная
     * нереализованная логика. Живым мониторингом являются ОБЪЁМЫ; уровень
     * вызывающий берёт из зафиксированного снимка.
     *
     * @param  int[]  $pageIds
     * @return array<int, array{personalVolume: float, groupVolume: float, groupVolumeCumulative: float}>
     */
    private function liveQualificationVolumes(array $pageIds, string $month): array
    {
        if (empty($pageIds)) {
            return [];
        }
        $monthStart = $month . '-01';

        // Поддеревья всех партнёров страницы одним рекурсивным CTE:
        // (root, node) — root и каждый его потомок (включая самого root).
        $ids = implode(',', array_map('intval', $pageIds));
        $tree = DB::select(<<<SQL
            WITH RECURSIVE tree(root, node, depth) AS (
                SELECT id, id, 0 FROM consultant WHERE id IN ($ids)
                UNION ALL
                SELECT t.root, c.id, t.depth + 1
                FROM consultant c
                JOIN tree t ON c.inviter = t.node
                WHERE c."dateDeleted" IS NULL AND t.depth < 20
            )
            SELECT root, node FROM tree
        SQL);

        // node -> список root'ов, в чей ГП он входит (у node может быть несколько
        // предков среди партнёров страницы). Для ЛП root входит только «в себя».
        $rootsByNode = [];
        foreach ($tree as $r) {
            $rootsByNode[(int) $r->node][] = (int) $r->root;
        }
        $allNodes = array_keys($rootsByNode);

        // ЛП каждого узла = Σ personalVolume его commission за месяц +
        // ручные баллы из «Прочих» (спека §3 — часть ЛП, а значит и ГП/НГП).
        $lpByNode = DB::table('commission')
            ->whereIn('consultant', $allNodes)
            ->where('dateMonth', $month)
            ->whereNull('deletedAt')
            ->selectRaw('consultant, COALESCE(SUM("personalVolume"),0) lp')
            ->groupBy('consultant')
            ->pluck('lp', 'consultant');
        foreach (\App\Support\ManualPoints::byMonth($allNodes, $month) as $node => $pts) {
            $lpByNode[$node] = (float) ($lpByNode[$node] ?? 0) + $pts;
        }

        // База НГП: последний снимок cumulative строго ДО начала месяца.
        $baseNgp = [];
        $baseRows = DB::select(<<<SQL
            SELECT DISTINCT ON (consultant) consultant, "groupVolumeCumulative" ngp
            FROM "qualificationLog"
            WHERE consultant IN ($ids) AND "dateDeleted" IS NULL
              AND "groupVolumeCumulative" IS NOT NULL
              AND date::timestamp < ?::timestamp
            ORDER BY consultant, date::timestamp DESC
        SQL, [$monthStart]);
        foreach ($baseRows as $b) {
            $baseNgp[(int) $b->consultant] = (float) $b->ngp;
        }

        // Раскидываем ЛП узлов по root'ам: ЛП root = lp[root]; ГП root = Σ lp[node] по поддереву.
        $lp = array_fill_keys($pageIds, 0.0);
        $gp = array_fill_keys($pageIds, 0.0);
        foreach ($rootsByNode as $node => $roots) {
            $nodeLp = (float) ($lpByNode[$node] ?? 0);
            if ($nodeLp === 0.0) {
                continue;
            }
            foreach ($roots as $root) {
                $gp[$root] += $nodeLp;
                if ($node === $root) {
                    $lp[$root] += $nodeLp;
                }
            }
        }

        $out = [];
        foreach ($pageIds as $cid) {
            $out[$cid] = [
                'personalVolume' => round($lp[$cid], 2),
                'groupVolume' => round($gp[$cid], 2),
                'groupVolumeCumulative' => round(($baseNgp[$cid] ?? 0) + $gp[$cid], 2),
            ];
        }

        return $out;
    }
}
