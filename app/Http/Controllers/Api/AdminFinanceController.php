<?php

namespace App\Http\Controllers\Api;

use App\Services\QualificationsListingService;
use App\Services\TransactionsListingService;
use App\Http\Controllers\Api\Concerns\AppliesSorting;
use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Support\LegacyId;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\Admin\StoreChargeRequest;
use App\Http\Requests\Api\Admin\StoreCurrencyRatesRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinanceController extends Controller
{
    use PaginatesRequests;
    use AppliesSorting;

    public function __construct(
        private readonly TransactionsListingService $transactionsListing,
        private readonly QualificationsListingService $qualificationsListing,
    ) {}

    /**
     * Транзакции (per spec ✅Комиссии §1.2). Расширенный набор колонок:
     *  - Индикатор периода (frozen синий/серый)
     *  - № контракта, Открыт (контракта), Клиент, Партнёр
     *  - Дата транзакции, Комментарий
     *  - Свойство (commissionCalcProperty.title)
     *  - Год контракта (contract.term)
     *  - Год выплаты КВ (transaction.score)
     *  - Транзакция (исх валюта) + Транзакция в RUB
     *  - %DS, Доход DS, Доход DS RUB/USD
     *  - Без НДС RUB / USD
     */
    public function transactions(Request $request, \App\Services\PeriodFreezeService $freeze): JsonResponse
    {
        // Фильтры, итоги и сборка строк — в TransactionsListingService.
        // Метод занимал 445 строк. Сортировка и пагинация остаются за
        // контроллером: они приходят из его трейтов и общие для раздела.
        return response()->json($this->transactionsListing->build(
            $request,
            $freeze,
            fn ($query, array $map, string $default, string $dir) => $this->applySorting($query, $request, $map, $default, $dir),
            fn ($query) => $query
                ->offset($this->paginationOffset($request))
                ->limit($this->paginationPerPage($request)),
        ));
    }

    /** Комиссии */
    public function commissions(Request $request): JsonResponse
    {
        $query = DB::table('commission')->whereNull('deletedAt');

        if ($request->filled('consultant')) {
            $query->where('consultant', $request->consultant);
        }
        if ($request->filled('month')) {
            $query->where('dateMonth', $request->month);
        }
        if ($request->filled('search')) {
            // commission has no name column — match consultants by personName.
            $query->whereIn('consultant', function ($sub) use ($request) {
                $sub->select('id')->from('consultant')
                    ->where('personName', 'ilike', '%' . $request->search . '%');
            });
        }
        // hide_zero=1 — скрываем строки уплайн-наставников с margin=0
        // (та же квалификация, что у нижестоящего → 0 ₽). Per user feedback —
        // включено по умолчанию из UI, чтобы убрать «шум» из тысяч 0,00-строк.
        if ($request->boolean('hide_zero')) {
            $query->where('amountRUB', '>', 0);
        }

        $total = $query->count();
        $this->applySorting($query, $request, [
            'date' => 'date',
            'amountRUB' => '"amountRUB"',
            'personalVolume' => '"personalVolume"',
            'groupVolume' => '"groupVolume"',
            'groupBonusRub' => '"groupBonusRub"',
        ], 'date', 'desc');
        $rows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch load consultant names
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();

        $data = $rows->map(fn ($c) => [
                'id' => $c->id,
                'consultant' => $c->consultant,
                'consultantName' => $c->consultant ? ($consultantNames[$c->consultant] ?? null) : null,
                'type' => $c->type,
                'amountRUB' => round((float) ($c->amountRUB ?? 0), 2),
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($c->groupVolume ?? 0), 2),
                'groupBonusRub' => round((float) ($c->groupBonusRub ?? 0), 2),
                'percent' => $c->percent,
                'date' => $c->date,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Пул */
    public function pool(Request $request): JsonResponse
    {
        $query = DB::table('poolLog');

        if ($request->filled('month')) {
            $year  = $request->integer('year', (int) date('Y'));
            $month = $request->integer('month');
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-t', strtotime($start));
            $query->whereBetween('date', [$start, $end]);
        }

        $total = $query->count();
        $data = $query->orderByDesc('date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Квалификации — сводка по месяцу. Логика в QualificationsListingService. */
    public function qualifications(Request $request): JsonResponse
    {
        return response()->json($this->qualificationsListing->build($request));
    }


    /** История квалификаций партнёра — все месяцы по убыванию даты. */
    public function qualificationHistory(int $consultantId): JsonResponse
    {
        $rows = DB::table('qualificationLog')
            ->where('consultant', $consultantId)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->get();

        $levels = DB::table('status_levels')->get()->keyBy('id');

        $data = $rows->map(function ($r) use ($levels) {
            $a = $r->nominalLevel ? ($levels[$r->nominalLevel] ?? null) : null;
            $b = $r->calculationLevel ? ($levels[$r->calculationLevel] ?? null) : null;
            $level = (! $a) ? $b : ((! $b) ? $a : (($a->level >= $b->level) ? $a : $b));
            return [
                'date' => substr((string) $r->date, 0, 7),
                'personalVolume' => round((float) ($r->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($r->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($r->groupVolumeCumulative ?? 0), 2),
                'levelNum' => $level?->level,
                'levelTitle' => $level?->title,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/commissions/chain/{transactionId}
     * Цепочка commission rows для одной транзакции (для аккордеона на
     * странице Комиссии per spec ✅Комиссии §1.3).
     */
    public function commissionChain(int $transactionId): JsonResponse
    {
        // ORDER BY chainOrder ASC + id DESC — в комбинации с unique() ниже
        // оставит самую свежую строку (max id) для каждой пары (consultant,
        // chainOrder). В commission встречаются дубли после повторного
        // пересчёта (старые версии не помечались deletedAt), без dedupe
        // в Цепочке выплат строки задваиваются — баг от 2026-05-26.
        $rows = DB::table('commission as cm')
            ->leftJoin('consultant as c', 'c.id', '=', 'cm.consultant')
            ->leftJoin('status_levels as sl', 'sl.id', '=', 'cm.calculationLevel')
            ->where('cm.transaction', $transactionId)
            ->whereNull('cm.deletedAt')
            ->orderBy('cm.chainOrder')
            ->orderByDesc('cm.id')
            ->select([
                'cm.id', 'cm.consultant', 'c.personName as consultantName',
                'cm.chainOrder', 'cm.percent',
                'cm.personalVolume', 'cm.groupVolume',
                'cm.groupBonus', 'cm.amountRUB',
                'sl.title as levelTitle', 'sl.level as levelNum',
                'sl.percent as levelPercent',
            ])
            ->get()
            ->unique(fn ($r) => $r->consultant . '-' . ($r->chainOrder ?? 0))
            ->values();

        $tx = DB::table('transaction')->where('id', $transactionId)
            ->first(['netRevenueRUB', 'amountRUB', 'profitRUB']);
        $totalCommission = $rows->sum(fn ($r) => (float) ($r->amountRUB ?? 0));
        $profitDS = (float) ($tx?->profitRUB ?? (($tx?->netRevenueRUB ?? 0) - $totalCommission));

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'consultantId' => $r->consultant,
                'consultantName' => $r->consultantName,
                'chainOrder' => (int) ($r->chainOrder ?? 0),
                'percent' => (float) ($r->percent ?? 0),
                // Полный % квалификации уровня (из матрицы), а не маржинальная
                // разница, что хранится в cm.percent для вышестоящих. Для строк
                // без уровня (стартовый %) уровня нет — фолбэк на cm.percent.
                'levelPercent' => (float) ($r->levelPercent ?? $r->percent ?? 0),
                'levelTitle' => $r->levelTitle,
                'levelNum' => $r->levelNum,
                'personalVolume' => round((float) ($r->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($r->groupVolume ?? 0), 2),
                'groupBonus' => round((float) ($r->groupBonus ?? 0), 2),
                'amountRUB' => round((float) ($r->amountRUB ?? 0), 2),
            ])->all(),
            'profitDS' => round($profitDS, 2),
            'totalCommission' => round($totalCommission, 2),
        ]);
    }

    /**
     * Прочие начисления — CRUD.
     *
     * Источников данных два:
     * 1. `other_accruals` — новая таблица для ручных операций (источник
     *    «manual», полный CRUD).
     * 2. `commission` WHERE type='nonTransactional' — legacy-история
     *    «Прочих начислений» из Directual (источник «legacy», read-only).
     *
     * Для UI оба источника объединяются. Legacy-строки помечаются
     * `editable=false`, чтобы фронт скрывал кнопки edit/delete.
     */
    public function charges(Request $request): JsonResponse
    {
        // 1. Новая таблица — manual entries.
        // Postgres folds unquoted identifiers to lowercase, поэтому в
        // UNION-алиасах используем snake_case (consultant_name, accrual_date,
        // created_at) — иначе ORDER BY/SELECT в обёрточном fromSub() ломается.
        $newQuery = DB::table('other_accruals as oa')
            ->leftJoin('consultant as c', 'oa.consultant', '=', 'c.id')
            ->select([
                'oa.id', 'oa.consultant', DB::raw("'manual' as source"),
                DB::raw('c."personName" as consultant_name'),
                'oa.type', 'oa.amount', 'oa.points', 'oa.comment',
                DB::raw('oa.accrual_date as accrual_date'),
                DB::raw('oa.created_at as created_at'),
            ]);

        if ($request->filled('search')) $newQuery->where('c.personName', 'ilike', '%' . $request->search . '%');
        if ($request->filled('comment')) $newQuery->where('oa.comment', 'ilike', '%' . $request->comment . '%');
        if ($request->filled('type')) $newQuery->where('oa.type', $request->type);
        if ($request->filled('date_from')) $newQuery->where('oa.accrual_date', '>=', $request->date_from);
        if ($request->filled('date_to')) $newQuery->where('oa.accrual_date', '<=', $request->date_to);
        if ($request->filled('year')) $newQuery->whereRaw('EXTRACT(YEAR FROM oa.accrual_date) = ?', [(int) $request->year]);
        if ($request->filled('month')) $newQuery->whereRaw('EXTRACT(MONTH FROM oa.accrual_date) = ?', [(int) $request->month]);

        // 2. Legacy commission.type='nonTransactional' — history (read-only).
        $legacyQuery = DB::table('commission as cm')
            ->leftJoin('consultant as c', 'cm.consultant', '=', 'c.id')
            ->where('cm.type', 'nonTransactional')
            ->whereNull('cm.deletedAt')
            ->select([
                'cm.id', 'cm.consultant', DB::raw("'legacy' as source"),
                DB::raw('c."personName" as consultant_name'),
                DB::raw("'rub' as type"),
                DB::raw('COALESCE(cm."amountRUB", cm.amount, 0) as amount'),
                DB::raw('COALESCE(cm."personalVolume", 0) as points'),
                'cm.comment',
                DB::raw('cm.date as accrual_date'),
                DB::raw('cm."createdAt" as created_at'),
            ]);

        if ($request->filled('search')) $legacyQuery->where('c.personName', 'ilike', '%' . $request->search . '%');
        if ($request->filled('comment')) $legacyQuery->where('cm.comment', 'ilike', '%' . $request->comment . '%');
        if ($request->filled('type') && $request->type === 'points') {
            $legacyQuery->whereRaw('1=0');
        }
        if ($request->filled('date_from')) $legacyQuery->where('cm.date', '>=', $request->date_from);
        // cm.date is a TIMESTAMP — a bare date would cut off the last day.
        if ($request->filled('date_to')) $legacyQuery->where('cm.date', '<=', $request->date_to . ' 23:59:59');
        if ($request->filled('year')) $legacyQuery->whereRaw('EXTRACT(YEAR FROM cm.date) = ?', [(int) $request->year]);
        if ($request->filled('month')) $legacyQuery->whereRaw('EXTRACT(MONTH FROM cm.date) = ?', [(int) $request->month]);

        $union = $newQuery->unionAll($legacyQuery);
        $sub = DB::query()->fromSub($union, 'u');
        $total = (clone $sub)->count();

        // Сортировка по клику на заголовок таблицы. Whitelist маппит
        // фронтовые ключи на колонки UNION-подзапроса (snake_case
        // обязателен — Postgres иначе складывает в lowercase).
        $this->applySorting($sub, $request, [
            'consultantName' => 'consultant_name',
            'accrualDate'    => 'accrual_date',
            'amount'         => 'amount',
            'comment'        => 'comment',
        ], 'accrual_date', 'desc');

        $rows = $sub->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'source' => $r->source,
            'editable' => $r->source === 'manual',
            'consultantName' => $r->consultant_name ?: ('Консультант #' . $r->consultant),
            'consultant' => $r->consultant,
            'type' => $r->type,
            'amount' => round((float) $r->amount, 2),
            'points' => round((float) $r->points, 2),
            'comment' => $r->comment,
            'accrualDate' => $r->accrual_date,
            'createdAt' => $r->created_at,
        ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * Создать начисление. Если в запросе есть баллы — они сразу
     * прибавляются к consultant.personalVolume и
     * consultant.groupVolumeCumulative (spec ✅Прочие начисления Part 2 §3).
     * Рубли (`amount`) остаются только в other_accruals и влияют на
     * финансовый баланс через агрегацию в реестре выплат.
     *
     * Баллы НЕ каскадятся по inviter-цепочке по прямому указанию спеки:
     * "не должны генерировать финансовую комиссию для вышестоящих
     * наставников, как это происходит при обычной продаже".
     */
    public function storeCharge(StoreChargeRequest $request): JsonResponse
    {
        $consultantId = (int) $request->consultant;
        $type = $request->type;
        $value = (float) $request->amount;

        // Маршрутизация per spec §3: Рубли → amount, Баллы → points.
        $isPoints = $type === 'points';
        $amountRub = $isPoints ? 0.0 : $value;
        $points = $isPoints ? $value : 0.0;

        $id = DB::transaction(function () use ($request, $consultantId, $type, $amountRub, $points) {
            $id = DB::table('other_accruals')->insertGetId([
                'consultant' => $consultantId,
                'type' => $type,
                'amount' => $amountRub,
                'points' => $points,
                'comment' => $request->comment,
                'accrual_date' => $request->input('accrual_date', now()),
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($points != 0.0) {
                DB::table('consultant')
                    ->where('id', $consultantId)
                    ->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$points}"),
                    ]);
            }

            return $id;
        });

        return response()->json(['message' => 'Начисление создано', 'id' => $id], 201);
    }

    /**
     * Обновить начисление. Если баллы изменились — корректируем
     * personalVolume/groupVolumeCumulative на разницу (delta).
     */
    public function updateCharge(StoreChargeRequest $request, int $id): JsonResponse
    {
        $row = DB::table('other_accruals')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Начисление не найдено'], 404);
        }

        $type = $request->type;
        $value = (float) $request->amount;
        $isPoints = $type === 'points';
        $newAmountRub = $isPoints ? 0.0 : $value;
        $newPoints = $isPoints ? $value : 0.0;

        $oldPoints = (float) ($row->points ?? 0);
        $delta = $newPoints - $oldPoints;

        DB::transaction(function () use ($request, $id, $row, $type, $newAmountRub, $newPoints, $delta) {
            DB::table('other_accruals')->where('id', $id)->update([
                'consultant' => $request->consultant,
                'type' => $type,
                'amount' => $newAmountRub,
                'points' => $newPoints,
                'comment' => $request->comment,
                'accrual_date' => $request->input('accrual_date', $row->accrual_date),
                'updated_at' => now(),
            ]);

            // Если консультант не менялся — просто прибавляем дельту по баллам.
            // Если поменялся — у старого вычитаем oldPoints, у нового добавляем newPoints.
            if ($request->consultant == $row->consultant) {
                if ($delta != 0.0) {
                    DB::table('consultant')->where('id', $row->consultant)->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$delta}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$delta}"),
                    ]);
                }
            } else {
                if ($row->points != 0.0) {
                    DB::table('consultant')->where('id', $row->consultant)->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) - {$row->points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) - {$row->points}"),
                    ]);
                }
                if ($newPoints != 0.0) {
                    DB::table('consultant')->where('id', $request->consultant)->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$newPoints}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$newPoints}"),
                    ]);
                }
            }
        });

        return response()->json(['message' => 'Начисление обновлено']);
    }

    /**
     * Удалить начисление с реверсивной транзакцией.
     * Per ✅Прочие начисления Part 2 §4: удаление должно откатить
     * изменения баланса (+100 баллов → удалили → −100 баллов обратно).
     */
    public function deleteCharge(int $id, Request $request): JsonResponse
    {
        // source=legacy → soft-delete commission row (Directual-история).
        // source=manual (default) → удалить из other_accruals + откатить баллы.
        $source = $request->query('source', 'manual');

        if ($source === 'legacy') {
            $row = DB::table('commission')->where('id', $id)->where('type', 'nonTransactional')->first();
            if (! $row) {
                return response()->json(['message' => 'Legacy-начисление не найдено'], 404);
            }
            DB::table('commission')->where('id', $id)->update([
                'deletedAt' => now(),
            ]);
            return response()->json([
                'message' => 'Legacy-начисление помечено удалённым (soft-delete)',
            ]);
        }

        $row = DB::table('other_accruals')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Начисление не найдено'], 404);
        }

        DB::transaction(function () use ($row) {
            $points = (float) ($row->points ?? 0);

            if ($points != 0.0) {
                DB::table('consultant')
                    ->where('id', $row->consultant)
                    ->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) - {$points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) - {$points}"),
                    ]);
            }

            DB::table('other_accruals')->where('id', $row->id)->delete();
        });

        return response()->json(['message' => 'Начисление удалено, баланс откатан']);
    }

    /** Реестр выплат */
    public function payments(Request $request): JsonResponse
    {
        $query = DB::table('consultantPayment')
            ->join('consultantBalance', 'consultantPayment.consultantBalance', '=', 'consultantBalance.id')
            ->join('consultant', 'consultantBalance.consultant', '=', 'consultant.id')
            ->select(
                'consultantPayment.id',
                'consultantPayment.amount',
                'consultantPayment.paymentDate',
                'consultantPayment.status',
                'consultantPayment.comment',
                'consultant.personName',
                'consultant.id as consultantId'
            );

        if ($request->filled('search')) {
            $query->where('consultant.personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('status')) {
            $query->where('consultantPayment.status', (int) $request->status);
        }

        $total = $query->count();
        // Default — paymentDate DESC NULLS LAST (см. ниже). Если фронт
        // прислал sort_by/sort_dir — применяем их через whitelist.
        // Postgres folds unquoted identifiers to lowercase, поэтому
        // camelCase идентификаторы (таблица consultantPayment, колонки
        // personName / paymentDate) обязаны быть в двойных кавычках —
        // applySorting подставляет значения буквально через orderByRaw.
        $this->applySorting($query, $request, [
            'consultantName' => 'consultant."personName"',
            'paymentDate'    => '"consultantPayment"."paymentDate"',
            'amount'         => '"consultantPayment".amount',
            'status'         => '"consultantPayment".status',
            'comment'        => '"consultantPayment".comment',
        ], '"consultantPayment"."paymentDate"', 'desc');

        $data = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'consultantName' => $p->personName,
                'amount' => round((float) $p->amount, 2),
                'paymentDate' => $p->paymentDate,
                'status' => $p->status,
                'comment' => $p->comment,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * Валюты и НДС (per spec ✅Валюты и НДС.md):
     * - currencyRates: помесячные курсы (последние 24 месяца), с периодом
     *   и кодом валюты для редактирования.
     * - vat: история ставок, текущая (dateTo > now или max value) маркируется
     *   isCurrent для отображения «настоящее время».
     */
    public function currencies(): JsonResponse
    {
        $currencyMeta = DB::table('currency')->orderBy('id')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->nameRu ?? $c->nameEn ?? $c->currencyName ?? '',
                'symbol' => $c->symbol,
            ])->keyBy('id');

        // Курсы за последние 24 месяца, отсортированы по убыванию даты
        $minDate = now()->subMonths(24)->startOfMonth();
        $rates = DB::table('currencyRate')
            ->where('date', '>=', $minDate)
            ->orderByDesc('date')
            ->orderBy('currency')
            ->get()
            ->map(function ($r) use ($currencyMeta) {
                $meta = $currencyMeta[$r->currency] ?? null;
                return [
                    'id' => $r->id,
                    'currencyId' => $r->currency,
                    'symbol' => $meta['symbol'] ?? '',
                    'currencyName' => $meta['name'] ?? '',
                    'rate' => round((float) $r->rate, 8),
                    'date' => $r->date,
                    'period' => $r->date ? substr((string) $r->date, 0, 7) : null,
                ];
            });

        // VAT история
        $vatRows = DB::table('vat')->orderBy('dateFrom')->get();
        $now = now();
        $vat = $vatRows->map(function ($v) use ($now) {
            $isCurrent = $v->dateFrom <= $now && (! $v->dateTo || $v->dateTo >= $now->copy()->addYears(10));
            return [
                'id' => $v->id,
                'value' => (float) $v->value,
                'dateFrom' => $v->dateFrom,
                'dateTo' => $v->dateTo,
                'isCurrent' => $isCurrent,
            ];
        });

        return response()->json([
            'currencies' => $currencyMeta->values(),
            'currencyRates' => $rates,
            'vat' => $vat,
        ]);
    }

    /**
     * PATCH /admin/currencies/rates/{id} — обновить курс за период
     * + автоматический пересчёт всех валютных транзакций этого месяца
     * (per spec ✅Валюты и НДС §2.1 шаг 3 «Глобальный пересчёт»).
     */
    public function updateCurrencyRate(Request $request, int $id, \App\Services\CurrencyRecalculator $recalc): JsonResponse
    {
        $request->validate(['rate' => 'required|numeric|min:0']);
        $row = DB::table('currencyRate')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Курс не найден'], 404);

        DB::table('currencyRate')->where('id', $id)->update(['rate' => $request->rate]);

        // 1) Рублёвые эквиваленты транзакций этого месяца/валюты — сразу.
        $stats = $recalc->recalcForRate($id);

        // 2) Комиссии зависят от amountRUB → пересчитываем автоматически в фоне
        // (переприменит и все курсы, и комиссии открытых периодов). Раньше
        // курс менялся, но доход ДС/комиссии оставались по старому эквиваленту.
        $recompute = ! empty($stats['updated']) || ! empty($stats['commissionsAffected']);
        if ($recompute) {
            \App\Jobs\RecalculateAllCommissionsJob::dispatch();
        }

        return response()->json([
            'message' => $recompute
                ? 'Курс обновлён. Запущен пересчёт комиссий по новому курсу (в фоне).'
                : 'Курс обновлён.',
            'recalculation' => $stats,
        ]);
    }

    /**
     * POST /admin/currencies/rates — завести курсы валют за месяц.
     *
     * Раньше строки за новый месяц создавал только планировщик
     * (currencies:copy-monthly-rates), а в админке была лишь правка карандашом.
     * Планировщик при этом ПАДАЛ на каждом запуске: `currencyRate` — legacy-
     * таблица Directual без сиквенса на id, а команда вставляла строку без
     * него → 23502 not-null. Вывод крона уходил в /dev/null, поэтому пропажа
     * была не видна (на проде так не появились июль и август 2026, а
     * CurrencyRates::forDate() молча брал последний доступный курс за более
     * ранний месяц). Теперь месяц заводится кнопкой, id — через LegacyId.
     *
     * Идемпотентно: существующие пары (валюта, месяц) не трогаем и возвращаем
     * в skipped — кнопка не должна затирать уже проставленный курс.
     */
    public function storeCurrencyRates(StoreCurrencyRatesRequest $request): JsonResponse
    {
        $data = $request->validated();

        $monthStart = $data['period'] . '-01';

        $existing = DB::table('currencyRate')
            ->whereRaw("date_trunc('month', date::timestamp) = ?::timestamp", [$monthStart])
            ->pluck('currency')
            ->map(fn ($c) => (int) $c)
            ->all();

        $created = 0;
        $skipped = [];
        foreach ($data['rates'] as $row) {
            $currencyId = (int) $row['currencyId'];
            if (in_array($currencyId, $existing, true)) {
                $skipped[] = $currencyId;
                continue;
            }
            DB::table('currencyRate')->insert([
                // У currencyRate нет сиквенса (наследие Directual) — id явно.
                'id' => LegacyId::next('currencyRate'),
                'currency' => $currencyId,
                'rate' => $row['rate'],
                'date' => $monthStart,
            ]);
            $existing[] = $currencyId;
            $created++;
        }

        // Статический кэш курсов живёт в рамках запроса, но джобы пересчёта
        // могут стартовать из этого же процесса — сбрасываем, чтобы новый курс
        // не потерялся за уже закэшированным значением прошлого месяца.
        \App\Support\CurrencyRates::flush();

        // Заведение курса за месяц, в котором уже есть валютные сделки, само
        // по себе ничего не пересчитывает: amountRUB зафиксирован при импорте
        // по курсу, который был на тот момент (для июля 2026 — июньский).
        // Правка курса карандашом пересчёт запускает, а добавление — нет, и
        // операторы упирались в throttle кнопки «Пересчитать». Ставим сами.
        $recalcQueued = false;
        if ($created && ! \App\Jobs\RecalculateAllCommissionsJob::isRunning()) {
            \App\Jobs\RecalculateAllCommissionsJob::dispatchRecalculation();
            $recalcQueued = true;
        }

        $message = $created
            ? "Добавлено курсов: {$created}"
            : 'Новых курсов нет — за этот месяц они уже заведены';
        if ($skipped) {
            $message .= '. Пропущено (уже были): ' . count($skipped);
        }
        if ($recalcQueued) {
            $message .= '. Запущен пересчёт по новым курсам (в фоне) — жать «Пересчитать» не нужно.';
        }

        return response()->json([
            'message' => $message,
            'created' => $created,
            'skipped' => count($skipped),
            'recalculationQueued' => $recalcQueued,
        ], $created ? 201 : 200);
    }

    /**
     * POST /admin/currencies/vat — добавить новую ставку НДС с указанной даты.
     * Закрывает предыдущую ставку (выставляет dateTo в день перед новой dateFrom).
     */
    public function addVatRate(Request $request): JsonResponse
    {
        $request->validate([
            'value' => 'required|numeric|min:0',
            'dateFrom' => 'required|date',
        ]);

        DB::transaction(function () use ($request) {
            // Закрываем самую свежую активную ставку
            $current = DB::table('vat')->orderByDesc('dateFrom')->first();
            $newFrom = $request->dateFrom;
            $closeDate = (new \DateTime($newFrom))->modify('-1 day')->format('Y-m-d 23:59:59');
            if ($current) {
                DB::table('vat')->where('id', $current->id)->update(['dateTo' => $closeDate]);
            }
            // Legacy-таблица vat без серийного default → нужен явный id.
            DB::table('vat')->insert([
                'id' => LegacyId::next('vat'),
                'value' => $request->value,
                'dateFrom' => $newFrom,
                'dateTo' => '2050-01-01 00:00:00', // дальняя дата = «настоящее время»
            ]);
        });

        // Кэш ставок живёт в пределах запроса, но пересчёт может пойти сразу
        // следом в этом же процессе — сбрасываем, как и с курсами валют.
        \App\Support\VatRate::flush();

        return response()->json(['message' => 'Ставка НДС добавлена']);
    }

    /**
     * GET /admin/currencies/management-rates — курсы справочника руководителей.
     * Возвращает последние 24 месяца.
     */
    public function managementCurrencies(): JsonResponse
    {
        $currencyMeta = DB::table('currency')->orderBy('id')->get()
            ->map(fn ($c) => [
                'id'   => $c->id,
                'name' => $c->nameRu ?? $c->nameEn ?? $c->currencyName ?? '',
                'symbol' => $c->symbol,
            ])->keyBy('id');

        $minDate = now()->subMonths(24)->startOfMonth();
        $rates = DB::table('management_currency_rate')
            ->where('date', '>=', $minDate)
            ->orderByDesc('date')
            ->orderBy('currency')
            ->get()
            ->map(function ($r) use ($currencyMeta) {
                $meta = $currencyMeta[$r->currency] ?? null;
                return [
                    'id'           => $r->id,
                    'currencyId'   => $r->currency,
                    'symbol'       => $meta['symbol'] ?? '',
                    'currencyName' => $meta['name'] ?? '',
                    'rate'         => round((float) $r->rate, 8),
                    'date'         => $r->date,
                    'period'       => $r->date ? substr((string) $r->date, 0, 7) : null,
                ];
            });

        return response()->json([
            'currencies'     => $currencyMeta->values(),
            'currencyRates'  => $rates,
        ]);
    }

    /**
     * PATCH /admin/currencies/management-rates/{id} — обновить курс руководителей.
     * В отличие от основного справочника, пересчёт транзакций НЕ запускается.
     */
    public function updateManagementCurrencyRate(Request $request, int $id): JsonResponse
    {
        $request->validate(['rate' => 'required|numeric|min:0']);
        $row = DB::table('management_currency_rate')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Курс не найден'], 404);

        DB::table('management_currency_rate')->where('id', $id)->update([
            'rate'       => $request->rate,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Курс обновлён']);
    }

    /**
     * POST /admin/currencies/management-rates — добавить строку курса вручную.
     * Нужно при первом заполнении (нет истории для копирования).
     */
    public function storeManagementCurrencyRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => 'required|integer|exists:currency,id',
            'rate'     => 'required|numeric|min:0',
            'date'     => 'required|date',
        ]);

        $exists = DB::table('management_currency_rate')
            ->where('currency', $data['currency'])
            ->whereDate('date', $data['date'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Курс для этой валюты за этот период уже существует'], 422);
        }

        $id = DB::table('management_currency_rate')->insertGetId([
            'currency'   => $data['currency'],
            'rate'       => $data['rate'],
            'date'       => $data['date'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Курс добавлен', 'id' => $id], 201);
    }

    /**
     * POST /admin/currencies/management-rates/copy-from-main
     * Копирует курсы из основного справочника (currencyRate) в управленческий
     * (management_currency_rate) за указанный период.
     * Идемпотентно: если запись уже есть — пропускает.
     */
    public function copyManagementRatesFromMain(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'required|date_format:Y-m', // e.g. "2026-05"
        ]);

        $period     = $request->period; // "2026-05"
        $monthStart = $period . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        // Берём последний курс каждой валюты за этот месяц из основного справочника
        $rows = DB::select(
            'SELECT DISTINCT ON (currency) currency, rate, date
               FROM "currencyRate"
              WHERE date BETWEEN ? AND ?
              ORDER BY currency, date DESC',
            [$monthStart, $monthEnd]
        );

        if (empty($rows)) {
            return response()->json(['message' => 'В основном справочнике нет курсов за этот период', 'copied' => 0, 'skipped' => 0]);
        }

        $copied  = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $exists = DB::table('management_currency_rate')
                ->where('currency', $row->currency)
                ->whereDate('date', $monthStart)
                ->exists();
            if ($exists) {
                $skipped++;
                continue;
            }
            DB::table('management_currency_rate')->insert([
                'currency'   => $row->currency,
                'rate'       => $row->rate,
                'date'       => $monthStart,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $copied++;
        }

        return response()->json([
            'message' => "Скопировано: {$copied}, пропущено (уже есть): {$skipped}",
            'copied'  => $copied,
            'skipped' => $skipped,
        ]);
    }

    /** Импорт транзакций — placeholder */
    public function transactionImport(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }

    /**
     * Архив отчётов (per spec ✅Отчеты.md §1.2).
     */
    public function reportArchive(): JsonResponse
    {
        $rows = DB::table('report_archive')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'type' => $r->type,
            'status' => $r->status,
            'dateFrom' => $r->date_from,
            'dateTo' => $r->date_to,
            'createdAt' => $r->created_at,
            'fileUrl' => $r->status === 'ready' ? url('/api/v1/admin/reports/' . $r->id . '/download') : null,
            'errorMessage' => $r->error_message,
        ]);
        return response()->json(['data' => $data]);
    }

    /**
     * Запуск генерации отчёта (per spec ✅Отчеты §2.1 — async).
     * Создаём запись «generating» и диспатчим GenerateReportJob.
     * Воркер (queue:work) обработает её в фоне.
     */
    public function generateReport(\Illuminate\Http\Request $request, \App\Services\ReportGenerator $gen): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|max:60',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'activity' => 'nullable|integer',
        ]);

        $filters = array_filter(['activity' => $data['activity'] ?? null]);
        $id = $gen->reserveArchive(
            (string) $data['type'],
            (string) $data['date_from'],
            (string) $data['date_to'],
            $filters,
            $request->user()?->id,
        );

        \App\Jobs\GenerateReportJob::dispatch($id);

        return response()->json(['message' => 'Отчёт поставлен в очередь', 'id' => $id]);
    }

    /** Скачать готовый отчёт. */
    public function downloadReport(int $id)
    {
        $row = DB::table('report_archive')->where('id', $id)->first();
        if (! $row) {
            abort(404, 'Архив не найден');
        }
        if ($row->status !== 'ready') {
            abort(409, "Файл ещё не готов (статус: {$row->status})");
        }
        if (! $row->file_path) {
            abort(404, 'У записи отсутствует file_path');
        }

        // Резолвим путь напрямую через storage_path — так избегаем
        // расхождений между local/private диском Laravel 11 и тем, что
        // у нас в БД сохранён legacy-путь без префикса private/.
        $candidates = [
            \Storage::disk('local')->path($row->file_path),
            storage_path('app/' . $row->file_path),
            storage_path('app/private/' . $row->file_path),
        ];
        $absPath = null;
        foreach ($candidates as $p) {
            if (file_exists($p)) { $absPath = $p; break; }
        }
        if (! $absPath) {
            \Log::warning('downloadReport: файл не найден ни по одному пути', [
                'id' => $id, 'file_path' => $row->file_path, 'tried' => $candidates,
            ]);
            abort(404, 'Файл отсутствует на диске');
        }

        // Расширение и Content-Type определяем по реальному файлу в архиве —
        // новые отчёты идут как XLSX, исторические CSV скачиваются как CSV
        // без потери совместимости.
        $ext = strtolower(pathinfo($row->file_path, PATHINFO_EXTENSION)) ?: 'xlsx';
        $contentType = $ext === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=utf-8';

        $filename = sprintf(
            'report-%s-%s-%s.%s',
            preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $row->type),
            substr((string) $row->date_from, 0, 10),
            substr((string) $row->date_to, 0, 10),
            $ext,
        );

        return response()->download($absPath, $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    /**
     * Месячная финализация: применить штрафы Отрыв/ОП к комиссиям месяца.
     * Идемпотентно. Защищено от запуска по закрытому периоду.
     */
    public function finalizeMonth(Request $request, \App\Services\MonthlyFinalisationRunner $runner): JsonResponse
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $stats = $runner->applyForMonth((int) $request->year, (int) $request->month);
        return response()->json($stats);
    }

    /**
     * Полный перерасчёт комиссий по всем транзакциям открытых периодов —
     * резолвит %ДС из матрицы dsCommission, пересобирает цепочки и балансы.
     * Тяжёлая операция → уходит в очередь. Роль admin/calculations.
     */
    public function recalculateAll(): JsonResponse
    {
        $cutoff = \App\Services\CommissionCalculator::HISTORICAL_CUTOFF;

        // Уже идёт — второй такой же джоб не нужен: он бы гонял те же
        // транзакции параллельно. Отвечаем успехом, а не ошибкой: оператор
        // нажал кнопку, и пересчёт для него действительно выполняется.
        if (\App\Jobs\RecalculateAllCommissionsJob::isRunning()) {
            return response()->json([
                'message' => 'Полный перерасчёт уже идёт — дождитесь окончания, повторный запуск не требуется.',
                'alreadyRunning' => true,
            ]);
        }

        $count = DB::table('transaction')
            ->whereNull('deletedAt')
            ->where(function ($q) use ($cutoff) {
                $q->where('date', '>=', $cutoff)->orWhereNull('date');
            })
            ->count();

        \App\Jobs\RecalculateAllCommissionsJob::dispatchRecalculation();

        return response()->json([
            'message' => "Полный перерасчёт запущен: {$count} транзакций открытых периодов (с {$cutoff}). "
                . 'Идёт в фоне — цифры обновятся по мере обработки.',
            'count' => $count,
        ]);
    }
}
