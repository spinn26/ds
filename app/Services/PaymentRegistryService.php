<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Реестр выплат (/admin/payment-registry): что и сколько платить партнёрам.
 *
 * Вынесено из AdminPaymentRegistryController (метод занимал 266 строк). Код
 * перенесён дословно.
 *
 * ⚠ Четыре правила, расхождение по которым уже разводило реестр с
 * бухгалтерским экспортом, — их нельзя трогать без сверки с
 * PaymentRegistryTest:
 *   - «Сальдо» = остаток ПРЕДЫДУЩЕГО периода, а не balance текущего месяца:
 *     ночной перенос remaining в balance запаздывает;
 *   - «Начислено» и «Пул» читаются ТОЛЬКО из снимка consultantBalance,
 *     который обновляется кнопкой пересчёта (решение 2026-06-05 «деньги
 *     считаются по кнопке»); живьём читаются лишь ручные начисления;
 *   - удержания месяца суммируются построчно из commission — одноимённые
 *     колонки снимка не пишет ни один раннер, они всегда нулевые;
 *   - итоги шапки собираются из СТРОК, иначе шапка расходится с таблицей.
 */
class PaymentRegistryService
{
    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        $params = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
            'search' => 'nullable|string|max:200',
            'status' => 'nullable|string',
            'activity' => 'nullable|integer',
            'nonZero' => 'nullable|boolean',
            'withDetachment' => 'nullable|boolean',
            'withOp' => 'nullable|boolean',
        ]);

        $year = (int) $params['year'];
        $month = (int) $params['month'];
        $dm = sprintf('%04d-%02d', $year, $month);

        $rows = $this->rows($params, $year, $month, $dm);
        $items = $this->present($rows, $year, $month, $dm);

        return [
            'year' => $year,
            'month' => $month,
            'items' => $items,
            'totals' => $this->totals($items),
            'activityOptions' => DB::table('directory_of_activities')->get()
                ->map(fn ($a) => ['title' => $a->name, 'value' => $a->id])->values(),
        ];
    }

    /** Строки реестра: фильтры и выборка. */
    private function rows(array $params, int $year, int $month, string $dm)
    {
        $q = DB::table('consultantBalance as b')
            ->leftJoin('consultant as c', 'c.id', '=', 'b.consultant')
            // Soft-deleted ФК не участвуют в реестре выплат — единообразно с
            // экспортным PaymentRegistryReport (whereNull('dateDeleted')) и с
            // Directual (там служебные аккаунты помечены test=true и исключены).
            // Без этого удалённый служебный аккаунт (напр. Сидоров, backoffice,
            // сальдо −1000) тянул свой остаток в «Сальдо» и занижал итог.
            // Orphan-строки без consultant (c.id IS NULL) сохраняем — у них
            // dateDeleted тоже NULL, фильтр их не трогает.
            ->whereNull('c.dateDeleted')
            ->where(function ($q) use ($dm, $year, $month) {
                $q->where('b.dateMonth', $dm)
                  ->orWhere(function ($qq) use ($year, $month) {
                      $qq->where('b.dateYear', (string) $year)
                         ->where('b.dateMonth', sprintf('%02d', $month));
                  });
            })
            ->select([
                'b.id',
                'b.consultant',
                'b.consultantPersonName',
                'b.status',
                'b.balance',
                'b.accruedTransactional',
                'b.accruedNonTransactional',
                'b.accruedPool',
                'b.accruedTotal',
                'b.totalPayable',
                'b.payed',
                'b.remaining',
                'b.withheldForGap',
                'b.withheldForCommissions',
                'c.activity as activityId',
                'c.personName',
                // Дата рождения — приоритет WebUser, фолбэк на легаси-колонку
                // consultant (там varchar из Directual). Тем же COALESCE её
                // берёт выгрузка в Google Sheets, чтобы значения совпадали.
                DB::raw('COALESCE(wu."birthDate"::text, c."birthDate") AS birth_date'),
            ])
            ->leftJoin('WebUser as wu', 'wu.id', '=', 'c.webUser');

        if ($params['search'] ?? false) {
            $s = '%' . mb_strtolower($params['search']) . '%';
            // Ищем по ЖИВОМУ имени consultant (источник истины). Денорм
            // consultantBalance.consultantPersonName у части строк битый (склейка
            // из Directual id-swap, напр. «Бикбулатов А А Бикбулатов Артур…»),
            // фолбэк на него — только если живого имени нет.
            $q->whereRaw('LOWER(COALESCE(c."personName", b."consultantPersonName")) LIKE ?', [$s]);
        }
        if ($params['status'] ?? false) {
            $q->where('b.status', $params['status']);
        }
        if ($params['activity'] ?? false) {
            $q->where('c.activity', (int) $params['activity']);
        }
        if (! empty($params['nonZero'])) {
            $q->where(function ($qq) {
                $qq->where('b.accruedTotal', '!=', 0)
                   ->orWhere('b.totalPayable', '!=', 0)
                   ->orWhere('b.balance', '!=', 0);
            });
        }
        // Фильтр «ФК с отрывом»: ищем партнёров, у которых в commission
        // за выбранный месяц есть строки с reduction > 0 (правило отрыва
        // 70% сработало). Раньше фильтр опирался на consultantBalance
        // .withheldForGap, но это поле в legacy-данных почти всегда NULL
        // (только 22 строки из 36810). reduction живёт прямо в commission
        // и заполнен корректно (7687 строк).
        // Используем денормализованные dateYear/dateMonth (есть индекс
        // commission_dateyear_datemonth_idx), а не whereYear/whereMonth —
        // последние делают seq-scan по всему commission (533k строк).
        $dm = sprintf('%04d-%02d', $year, $month);
        if (! empty($params['withDetachment'])) {
            // Именно ОТРЫВ (withheldForGap > 0), а не любое удержание: раньше
            // фильтр брал reduction > 0 и попадал сюда партнёр только с ОП-штрафом
            // (без отрыва). withheldForGap заполняется финализацией корректно.
            $q->whereIn('b.consultant', function ($sub) use ($dm) {
                $sub->select('consultant')->from('commission')
                    ->where('withheldForGap', '>', 0)
                    ->whereNull('deletedAt')
                    ->where('dateMonth', $dm);
            });
        }
        // Аналогично для ОП — опираемся на withheldForCommission в commission
        // (это per-row penalty за невыполнение плана продаж).
        if (! empty($params['withOp'])) {
            $q->whereIn('b.consultant', function ($sub) use ($dm) {
                $sub->select('consultant')->from('commission')
                    ->where('withheldForCommission', '>', 0)
                    ->whereNull('deletedAt')
                    ->where('dateMonth', $dm);
            });
        }

        return $q->orderByDesc('b.totalPayable')->limit(2000)->get();
    }

    /**
     * Всё связанное одной пачкой.
     *
     * ⚠ Сальдо — остаток ПРЕДЫДУЩЕГО периода, а удержания месяца берутся
     * построчно из commission: одноимённые колонки снимка не пишет ни один
     * раннер, они всегда нулевые.
     *
     * @return array<string, mixed>
     */
    private function related($rows, int $year, int $month, string $dm): array
    {

        // Прочие начисления (other_accruals) — отдельная таблица для ручных
        // бонусов/штрафов, заведённых через /manage/charges. consultantBalance
        // её не агрегирует (это denormalized легаси-снимок Directual), так что
        // тянем сумму по месяцу батчем и складываем в колонку «Прочее».
        $periodFrom = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $periodTo = \Carbon\Carbon::parse($periodFrom)->endOfMonth()->format('Y-m-d 23:59:59');
        $extraByCons = DB::table('other_accruals')
            ->whereBetween('accrual_date', [$periodFrom, $periodTo])
            ->select('consultant', DB::raw('SUM(COALESCE(amount, 0)) as extra'))
            ->groupBy('consultant')
            ->pluck('extra', 'consultant');

        // ⛔ LIVE-ПЕРЕСЧЁТ УБРАН (2026-06-05): «Начислено»/«Пул» читаются ТОЛЬКО
        // из снимка consultantBalance (accruedTransactional/accruedNonTransactional/
        // accruedPool), который обновляется по кнопке пересчёта руководителем
        // расчётов. Раньше тут был max(снимок, live SUM(commission/poolLog)) —
        // он показывал свежие транзакции до пересчёта, но это и есть «лайв-расчёт».
        // Ручные other_accruals (extraByCons) остаются live — это не расчёт, а
        // отображение введённых начислений.

        // Сальдо (входящий остаток) = remaining последнего периода ДО выбранного
        // месяца — per spec ✅Реестр выплат и единообразно с экспортным
        // PaymentRegistryReport. Раньше брали b.balance текущего месяца, но
        // ночной перенос remaining→balance запаздывает (напр. июнь: balance=0
        // при remaining≈50k у прошлого месяца) → UI показывал Сальдо=0,
        // расходясь с бухгалтерским экспорт-отчётом.
        // DISTINCT ON — single-pass instead of N correlated subqueries (prev version timed out on prod).
        // Picks the most recent row per consultant where dateMonth < selected month.
        $incomingByCons = collect(DB::select(
            'SELECT DISTINCT ON (consultant) consultant, remaining
             FROM "consultantBalance"
             WHERE "dateMonth" < ?
             ORDER BY consultant, "dateMonth" DESC',
            [$dm]
        ))->pluck('remaining', 'consultant');

        // Batch-load requisite verification for every partner in the result.
        $consultantIds = $rows->pluck('consultant')->filter()->unique()->values()->all();
        $verified = [];
        // Налоговый режим — из тех же реквизитов. Берём ВЕРИФИЦИРОВАННЫЕ:
        // именно по ним идёт выплата, а у партнёра может лежать ещё и черновик
        // на перепроверке с другим режимом.
        $taxRegime = [];
        if ($consultantIds) {
            foreach (DB::table('requisites')
                ->whereIn('consultant', $consultantIds)
                ->whereNull('deletedAt')
                ->where('verified', true)
                ->orderBy('id')
                ->get(['consultant', 'tax_regime']) as $rq) {
                $verified[$rq->consultant] = $rq->consultant;
                if ($rq->tax_regime !== null && trim((string) $rq->tax_regime) !== '') {
                    $taxRegime[$rq->consultant] = (string) $rq->tax_regime;
                }
            }
        }

        // Partners with suspended payouts — their requisites must NOT surface in
        // the registry (popup blocked, payment blocked), so the payout operator
        // doesn't have to cross-check the suspension flag manually.
        $suspended = [];
        if ($consultantIds) {
            $suspended = DB::table('consultant')
                ->whereIn('id', $consultantIds)
                ->where('payments_suspended', true)
                ->pluck('id', 'id')
                ->toArray();
        }

        // Удержания месяца — ТОЛЬКО из commission (построчно), а не из
        // consultantBalance.withheldForGap: эту колонку не пишет ни один раннер,
        // она всегда 0. Из-за этого «Начислено до отрыва» было тождественно равно
        // «Начислено за транзакции» (обе колонки показывали ПОСТ-штрафную сумму) и
        // расходилось с отчётом «Комиссии» ровно на сумму удержаний.
        //
        // Оба раннера затирают commission.amountRUB уже урезанным значением, а
        // accruedTransactional набирается из него, поэтому «до удержаний» =
        // accrued + withheldForGap + withheldForCommission.
        $withheldByCons = [];
        if ($consultantIds) {
            $withheldByCons = DB::table('commission')
                ->whereIn('consultant', $consultantIds)
                ->where('dateMonth', $dm)
                ->whereNull('deletedAt')
                ->selectRaw('consultant,
                    COALESCE(SUM("withheldForGap"), 0)        AS gap,
                    COALESCE(SUM("withheldForCommission"), 0) AS op')
                ->groupBy('consultant')
                ->get()
                ->keyBy('consultant');
        }

        // Activity name lookup for partner-status filter UI.
        $activityNames = DB::table('directory_of_activities')->pluck('name', 'id');


        return ['extraByCons' => $extraByCons, 'incomingByCons' => $incomingByCons, 'verified' => $verified, 'taxRegime' => $taxRegime, 'suspended' => $suspended, 'withheldByCons' => $withheldByCons, 'activityNames' => $activityNames];
    }

    /** Строки → массив ответа. */
    private function present($rows, int $year, int $month, string $dm)
    {
        ['extraByCons' => $extraByCons, 'incomingByCons' => $incomingByCons, 'verified' => $verified, 'taxRegime' => $taxRegime, 'suspended' => $suspended, 'withheldByCons' => $withheldByCons, 'activityNames' => $activityNames] = $this->related($rows, $year, $month, $dm);

        $items = $rows->map(function ($r) use ($verified, $taxRegime, $suspended, $activityNames, $extraByCons, $incomingByCons, $withheldByCons) {
            $wh = $withheldByCons[$r->consultant] ?? null;
            $withheldGap = (float) ($wh->gap ?? 0);
            $withheldOp = (float) ($wh->op ?? 0);
            // Только снимок (без live-пересчёта). Обновляется кнопкой пересчёта.
            $accrued = (float) ($r->accruedTransactional ?? 0);
            $pool = (float) ($r->accruedPool ?? 0);

            // «Прочее» = снимок nonTransactional + ручные other_accruals (live-чтение
            // введённых начислений, не расчёт).
            $extra = (float) ($extraByCons[$r->consultant] ?? 0);
            $other = (float) ($r->accruedNonTransactional ?? 0) + $extra;
            $accruedTotal = $accrued + $other + $pool;
            // Сальдо = входящий остаток (remaining прошлого периода), а не
            // запаздывающий b.balance текущего месяца — см. $incomingByCons.
            $balance = (float) ($incomingByCons[$r->consultant] ?? 0);
            $totalPayable = $balance + $accruedTotal;
            $payed = (float) ($r->payed ?? 0);
            $remaining = $totalPayable - $payed;
            return [
                'id' => $r->id,
                'consultantId' => $r->consultant,
                // Живое имя consultant первым — денорм consultantPersonName у
                // части строк битый (Directual id-swap); фолбэк только если
                // живого нет.
                'personName' => $r->personName ?? $r->consultantPersonName ?? '—',
                'activityId' => $r->activityId,
                'activityName' => $r->activityId ? ($activityNames[$r->activityId] ?? null) : null,
                'status' => $r->status,
                'balance' => $balance,
                'accrued' => $accrued,
                'other' => $other,
                'pool' => $pool,
                'accruedTotal' => $accruedTotal,
                'totalPayable' => $totalPayable,
                'payed' => $payed,
                'remaining' => $remaining,
                'withheldForGap' => $withheldGap,
                'withheldForCommissions' => $withheldOp,
                // «Начислено до удержаний» = начислено + отрыв + ОП. Именно эту
                // величину показывает колонка «Комиссия до отрыва» в отчёте
                // «Комиссии» (transaction.commissionAmountRubBeforeGapReduction).
                'accruedBeforeGap' => $accrued + $withheldGap + $withheldOp,
                'verifiedRequisites' => isset($verified[$r->consultant]),
                // Три колонки по запросу финансистов (задача 832705): отметка
                // о верификации уже была флагом у ФИО, теперь выведена явно.
                'birthDate' => $r->birth_date ?: null,
                'taxRegime' => $taxRegime[$r->consultant] ?? null,
                'paymentsSuspended' => isset($suspended[$r->consultant]),
            ];
        });

        // Totals агрегируем из items (которые уже содержат live-корректировки),
        // а не из исходных rows — иначе цифры в шапке расходятся со строками.

        return $items;
    }

    /**
     * Итоги шапки. Считаются из СТРОК, а не из исходной выборки — иначе
     * шапка расходится с таблицей.
     *
     * @return array<string, mixed>
     */
    private function totals($items): array
    {
        $totals = [
            'rows' => $items->count(),
            'balance' => (float) $items->sum('balance'),
            'accruedBeforeGap' => (float) $items->sum('accruedBeforeGap'),
            'accruedTransactional' => (float) $items->sum('accrued'),
            'accruedNonTransactional' => (float) $items->sum('other'),
            'accruedPool' => (float) $items->sum('pool'),
            'accruedTotal' => (float) $items->sum('accruedTotal'),
            'totalPayable' => (float) $items->sum('totalPayable'),
            'payed' => (float) $items->sum('payed'),
            'remaining' => (float) $items->sum('remaining'),
            'withheldForGap' => (float) $items->sum('withheldForGap'),
            'withheldForCommissions' => (float) $items->sum('withheldForCommissions'),
        ];

        return $totals;
    }
}
