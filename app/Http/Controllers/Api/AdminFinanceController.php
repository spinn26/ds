<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinanceController extends Controller
{
    use PaginatesRequests;

    /** Транзакции */
    public function transactions(Request $request): JsonResponse
    {
        $query = DB::table('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->whereNull('t.deletedAt');

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($w) use ($term) {
                $w->where('t.id', 'ilike', $term)
                  ->orWhere('c.number', 'ilike', $term)
                  ->orWhere('c.clientName', 'ilike', $term);
            });
        }
        if ($request->filled('month')) {
            $query->where('t.dateMonth', $request->month);
        }

        $total = $query->count();
        $rows = $query->orderByDesc('t.date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get([
                't.*',
                'c.number as contractNumber',
                'c.clientName as clientName',
                'c.consultantName as consultantName',
            ]);

        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        $data = $rows->map(fn ($t) => [
                'id' => $t->id,
                'contract' => $t->contract,
                'contractNumber' => $t->contractNumber,
                'clientName' => $t->clientName,
                'consultantName' => $t->consultantName,
                'amount' => round((float) ($t->amount ?? 0), 2),
                'amountRUB' => round((float) ($t->amountRUB ?? 0), 2),
                'amountUSD' => round((float) ($t->amountUSD ?? 0), 2),
                'date' => $t->date,
                'currencySymbol' => $t->currency ? ($currencies[$t->currency] ?? null) : null,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
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

        $total = $query->count();
        $rows = $query->orderByDesc('date')
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
            // filter by date range if month provided
            $start = $request->month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $query->whereBetween('date', [$start, $end]);
        }

        $total = $query->count();
        $data = $query->orderByDesc('date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Квалификации */
    /**
     * Per spec ✅Квалификации.md §2: «Единая квалификация — у партнёра в
     * месяц только ОДИН показатель статуса». Возвращаем выбранный месяц +
     * сравнение с предыдущим месяцем (Блок 2 + Блок 3 в спеке).
     */
    public function qualifications(Request $request): JsonResponse
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
            $consultantQuery->where('consultantPersonName', 'ilike', '%' . $request->search . '%');
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
            return response()->json(['data' => [], 'total' => 0, 'monthLabel' => $month, 'prevMonthLabel' => substr($prevStart, 0, 7)]);
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
            $byConsultant[$l->consultant][$bucket] = [
                'id' => $l->id,
                'personalVolume' => round((float) ($l->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($l->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($l->groupVolumeCumulative ?? 0), 2),
                'levelId' => $level?->id,
                'levelTitle' => $level?->title,
                'levelNum' => $level?->level,
                'mandatoryGP' => (float) ($level?->mandatoryGP ?? 0),
                'date' => $l->date,
            ];
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

        return response()->json([
            'data' => $data,
            'total' => $total,
            'monthLabel' => $month,
            'prevMonthLabel' => substr($prevStart, 0, 7),
        ]);
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

    /** Прочие начисления — CRUD */
    public function charges(Request $request): JsonResponse
    {
        $query = DB::table('other_accruals')
            ->join('consultant', 'other_accruals.consultant', '=', 'consultant.id')
            ->select(
                'other_accruals.*',
                'consultant.personName as consultantName'
            );

        if ($request->filled('search')) {
            $query->where('consultant.personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('comment')) {
            $query->where('other_accruals.comment', 'ilike', '%' . $request->comment . '%');
        }
        if ($request->filled('type')) {
            $query->where('other_accruals.type', $request->type);
        }
        if ($request->filled('date_from')) {
            $query->where('other_accruals.accrual_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('other_accruals.accrual_date', '<=', $request->date_to);
        }

        $total = $query->count();
        $data = $query->orderByDesc('other_accruals.created_at')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'consultantName' => $r->consultantName,
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
    public function storeCharge(Request $request): JsonResponse
    {
        $request->validate([
            'consultant' => 'required|integer|exists:consultant,id',
            'type' => 'required|in:bonus,penalty,compensation',
            'amount' => 'required|numeric',
            'points' => 'nullable|numeric',
        ]);

        $consultantId = (int) $request->consultant;
        $points = (float) $request->input('points', 0);

        $id = DB::transaction(function () use ($request, $consultantId, $points) {
            $id = DB::table('other_accruals')->insertGetId([
                'consultant' => $consultantId,
                'type' => $request->type,
                'amount' => $request->amount,
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
    public function updateCharge(Request $request, int $id): JsonResponse
    {
        $row = DB::table('other_accruals')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Начисление не найдено'], 404);
        }

        $request->validate([
            'consultant' => 'required|integer|exists:consultant,id',
            'type' => 'required|in:bonus,penalty,compensation',
            'amount' => 'required|numeric',
            'points' => 'nullable|numeric',
        ]);

        $newPoints = (float) $request->input('points', 0);
        $oldPoints = (float) ($row->points ?? 0);
        $delta = $newPoints - $oldPoints;

        DB::transaction(function () use ($request, $id, $row, $delta) {
            DB::table('other_accruals')->where('id', $id)->update([
                'consultant' => $request->consultant,
                'type' => $request->type,
                'amount' => $request->amount,
                'points' => $request->input('points', 0),
                'comment' => $request->comment,
                'accrual_date' => $request->input('accrual_date', $row->accrual_date),
                'updated_at' => now(),
            ]);

            // Если консультант не менялся — просто прибавляем дельту.
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
                if ($request->input('points', 0) != 0.0) {
                    $newP = (float) $request->input('points', 0);
                    DB::table('consultant')->where('id', $request->consultant)->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$newP}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$newP}"),
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
    public function deleteCharge(int $id): JsonResponse
    {
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
        // Postgres sorts NULLs first on DESC by default; half the legacy rows
        // have no paymentDate, so the first 25 were a wall of prochеrк's.
        // Push real dates to the top; fall back to id for deterministic order.
        $data = $query->orderByRaw('"paymentDate" DESC NULLS LAST')
            ->orderByDesc('consultantPayment.id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
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

    /** Отчёты */
    public function reports(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }

    /** Доступность отчётов */
    public function reportAvailability(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
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

    /** PATCH /admin/currencies/rates/{id} — обновить курс за период. */
    public function updateCurrencyRate(Request $request, int $id): JsonResponse
    {
        $request->validate(['rate' => 'required|numeric|min:0']);
        $row = DB::table('currencyRate')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Курс не найден'], 404);

        DB::table('currencyRate')->where('id', $id)->update(['rate' => $request->rate]);
        return response()->json(['message' => 'Курс обновлён']);
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
            DB::table('vat')->insert([
                'value' => $request->value,
                'dateFrom' => $newFrom,
                'dateTo' => '2050-01-01 00:00:00', // дальняя дата = «настоящее время»
            ]);
        });

        return response()->json(['message' => 'Ставка НДС добавлена']);
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
     * Запуск генерации отчёта (per spec §1.1 + §3).
     * V1 — синхронная генерация. Async-очередь добавится в дальнейшем.
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
        $id = $gen->generate(
            (string) $data['type'],
            (string) $data['date_from'],
            (string) $data['date_to'],
            $filters,
            $request->user()?->id,
        );

        return response()->json(['message' => 'Отчёт сгенерирован', 'id' => $id]);
    }

    /** Скачать готовый отчёт. */
    public function downloadReport(int $id)
    {
        $row = DB::table('report_archive')->where('id', $id)->first();
        if (! $row || $row->status !== 'ready' || ! $row->file_path) {
            abort(404, 'Файл не найден или не готов');
        }
        if (! \Storage::disk('local')->exists($row->file_path)) {
            abort(404, 'Файл отсутствует на диске');
        }
        return \Storage::disk('local')->download($row->file_path, "report-{$row->type}-{$row->date_from}-{$row->date_to}.csv");
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
}
