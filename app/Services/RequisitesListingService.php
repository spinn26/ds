<?php

namespace App\Services;

use App\Models\BankRequisite;
use App\Models\Requisite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Список реквизитов (/admin/requisites): фильтры, дедуп, сборка строк.
 *
 * Вынесено из AdminDataController (метод занимал 195 строк).
 *
 * ⚠ Порядок шагов значим: сначала ФИЛЬТРЫ, потом ДЕДУП. У партнёра может быть
 * несколько реквизитов, и победитель выбирается уже среди отфильтрованных —
 * с verified=false подтверждённый отсеивается, и остаётся свежий из
 * неподтверждённых. Переставить шаги местами нельзя, это меняет выдачу.
 */
class RequisitesListingService
{
    /** @var list<string> */
    public const FILTERS = ['verified', 'status', 'partner_status', 'suspend', 'search'];

    /**
     * Запрос с фильтрами, ДО дедупа и сортировки.
     *
     * @param array<string, mixed> $filters только заполненные значения
     */
    public function query(array $filters): Builder
    {
        $query = Requisite::query();

        $query = Requisite::whereNull('deletedAt');

        if (isset($filters['verified'])) {
            $query->where('verified', $filters['verified'] === 'true');
        }
        // Per spec ✅Реквизиты партнёров: status фильтр от UI присылается
        // как 'verified' / 'pending' / 'rejected'. Маппим на колонки
        // `verified` (boolean) + `status` (1=backoffice, 2=consultant-возврат, 3=verified).
        if (isset($filters['status'])) {
            switch ($filters['status']) {
                case 'verified':
                    // Подтверждён = И ИП, И банковская строка. Иначе строка с
                    // «банк на перепроверке» пряталась бы в «Подтверждено» и
                    // никогда не попадала в работу (баг 2026-08-20).
                    $query->where('verified', true)
                          ->whereNot(fn ($q) => $this->whereBankPending($q));
                    break;
                case 'rejected':
                    // Отклонено = есть причина отказа (rejection_reason) и не
                    // верифицировано. NB: status=2 ставится на ЛЮБОЕ сохранение
                    // (и «на проверке» тоже) — отличаем именно по причине отказа.
                    $query->where('verified', false)
                          ->whereNotNull('rejection_reason')->where('rejection_reason', '!=', '');
                    break;
                case 'pending':
                    // На проверке = ИП не верифицирован и БЕЗ причины отказа,
                    // ЛИБО ИП уже подтверждён, а банковский счёт партнёр сменил
                    // и он ждёт перепроверки (см. whereBankPending).
                    $query->where(function ($outer) {
                        $outer->where(function ($q) {
                            $q->where('verified', false)->where(function ($q2) {
                                $q2->whereNull('rejection_reason')->orWhere('rejection_reason', '');
                            });
                        })->orWhere(function ($q) {
                            $q->where('verified', true);
                            $this->whereBankPending($q);
                        });
                    });
                    break;
            }
        }
        // Фильтр по статусу партнёра (consultant.activity): 1 Активен,
        // 3 Терминирован, 4 Зарегистрирован, 5 Исключён. Legacy 2 = «Активен».
        if (isset($filters['partner_status'])) {
            $statuses = array_map('intval', (array) $filters['partner_status']);
            if (in_array(1, $statuses, true)) {
                $statuses[] = 2;
            }
            $ids = DB::table('consultant')->whereIn('activity', $statuses)->pluck('id')->all();
            $query->whereIn('consultant', $ids ?: [-1]);
        }
        // Фильтр по приостановке выплат: 'request' — партнёр сам подал запрос на
        // смену реквизитов (есть pending-запрос), 'manual' — Катя проставила
        // галочку вручную (приостановлен, но активного запроса нет).
        if (isset($filters['suspend'])) {
            $pendingIds = DB::table('bank_requisite_change_requests')
                ->where('status', 'pending')->distinct()->pluck('consultant')->all();
            if ($filters['suspend'] === 'request') {
                $query->whereIn('consultant', $pendingIds ?: [-1]);
            } elseif ($filters['suspend'] === 'manual') {
                $manualIds = DB::table('consultant')
                    ->where('payments_suspended', true)
                    ->when(! empty($pendingIds), fn ($q) => $q->whereNotIn('id', $pendingIds))
                    ->pluck('id')->all();
                $query->whereIn('consultant', $manualIds ?: [-1]);
            }
        }
        if (isset($filters['search'])) {
            $s = trim((string) $filters['search']);
            $isNumericLike = preg_match('/^\d{4,}$/', $s) === 1;
            if ($isNumericLike) {
                // Похоже на ИНН → ищем строго по нему.
                $query->where('inn', 'ilike', "%{$s}%");
            } else {
                // Текст → ищем ТОЛЬКО по ФИО консультанта-владельца.
                // Раньше OR'или с individualEntrepreneur, что давало дубли:
                // если ИП Зарипова используют 5 партнёров, поиск «Зарипов»
                // возвращал все 5 строк, а нужно только Зарипова. По правкам
                // 2026-05-05 — только ФИО владельца ИП.
                $consultantIds = DB::table('consultant')
                    ->where('personName', 'ilike', "%{$s}%")
                    ->pluck('id');
                if ($consultantIds->isNotEmpty()) {
                    $query->whereIn('consultant', $consultantIds);
                } else {
                    // Не нашли консультанта — пустой результат, чтобы фильтр
                    // не «съезжал» на другие совпадения.
                    $query->whereRaw('1 = 0');
                }
            }
        }


        return $query;
    }

    /**
     * «У реквизита есть живая банковская строка, ждущая проверки».
     *
     * NULL в bankrequisites.verified трактуем как «не подтверждён» (колонка
     * nullable), поэтому IS NOT TRUE, а не `= false`.
     *
     * Публичный: этим же условием NotifyOverdueRequisites добирает кандидатов
     * на SLA-уведомление — определение должно быть одно.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Requisite>|\Illuminate\Database\Query\Builder $query
     */
    public function whereBankPending($query): void
    {
        $query->whereExists(function ($sub) {
            $sub->from('bankrequisites')
                ->whereColumn('bankrequisites.requisites', 'requisites.id')
                ->whereNull('bankrequisites.deletedAt')
                ->whereRaw('bankrequisites.verified IS NOT TRUE');
        });
    }

    /**
     * Дедуп: один реквизит на партнёра. Приоритет — verified=true, затем самая
     * свежая запись (id DESC). Раньше у одного партнёра висело четыре строки
     * (3 неподтверждённых + 1 подтверждённая).
     *
     * Белый список id берём подзапросом, чтобы пагинация и count работали
     * корректно вместе с фильтрами.
     */
    public function deduplicate(Builder $filtered): Builder
    {
        $primaryIds = (clone $filtered)
            ->select(DB::raw('DISTINCT ON (consultant) id'))
            ->orderBy('consultant')
            ->orderByDesc('verified')
            ->orderByDesc('id')
            ->pluck('id');

        return Requisite::whereIn('id', $primaryIds);
    }

    /** Строки страницы → массив для ответа. Связанное грузится пачками. */
    public function present(Collection $rows): Collection
    {
        // Batch load consultant names + флаг приостановки выплат (для подсветки)
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();
        $suspendedMap = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('payments_suspended', 'id')
            : collect();
        // Партнёры с активным запросом на смену реквизитов (сами подали).
        $pendingChangeSet = $consultantIds->isNotEmpty()
            ? DB::table('bank_requisite_change_requests')->where('status', 'pending')
                ->whereIn('consultant', $consultantIds)->distinct()->pluck('consultant')->flip()
            : collect();

        // Batch load bank requisites
        $reqIds = $rows->pluck('id')->filter()->unique();
        $bankReqs = $reqIds->isNotEmpty()
            ? BankRequisite::whereIn('requisites', $reqIds)->whereNull('deletedAt')->get()->keyBy('requisites')
            : collect();


        return $rows->map(function ($r) use ($consultantNames, $bankReqs, $suspendedMap, $pendingChangeSet) {
                $bankReq = $bankReqs[$r->id] ?? null;

                // Резолвим verificationStatus для UI: verified / pending / rejected.
                // «Отклонено» — только когда есть причина отказа (rejection_reason),
                // т.к. status=2 ставится и при обычном сохранении («на проверке»).
                // Банк ждёт проверки: строка есть, но не подтверждена. NULL в
                // verified — тоже «не подтверждён» (колонка nullable).
                $bankPending = $bankReq !== null && $bankReq->verified !== true;

                $verificationStatus = 'pending';
                // Что именно на проверке: 'full' — вся карточка, 'bank' — только
                // счёт (ИП уже подтверждён, партнёр сменил банковские реквизиты
                // через форму профиля — ProfileController::updateBankRequisites
                // сбрасывает verified и закрывает платёжный гейт). Раньше такая
                // строка показывалась как «Подтверждено» и выпадала из очереди:
                // партнёр вечно видел «проверяется финменеджером», а выплаты
                // стояли (баг 2026-08-20, 8 партнёров).
                $pendingScope = 'full';
                if ($r->verified) {
                    $verificationStatus = $bankPending ? 'pending' : 'verified';
                    $pendingScope = $bankPending ? 'bank' : null;
                } elseif (filled($r->rejection_reason)) {
                    $verificationStatus = 'rejected';
                    $pendingScope = null;
                }

                // Дата поступления на проверку = последняя отправка реквизитов
                // (dateChange); для старых записей без dateChange — createdAt.
                // Для «на проверке только банк» отсчёт SLA идёт от смены счёта,
                // иначе таймер считался бы от давней верификации ИП и строка
                // была бы просрочена в момент появления в очереди.
                $submittedAt = $pendingScope === 'bank'
                    ? ($bankReq->dateChange ?: $r->dateChange)
                    : $r->dateChange;
                $submittedAt = $submittedAt
                    ?: ($r->createdAt ? \Illuminate\Support\Carbon::parse($r->createdAt) : null);
                if (is_string($submittedAt)) {
                    $submittedAt = \Illuminate\Support\Carbon::parse($submittedAt);
                }
                // Просрочка считается только пока реквизиты «на проверке».
                $overdue = $verificationStatus === 'pending'
                    && \App\Support\RequisiteSla::isOverdue($submittedAt);

                return [
                    'id' => $r->id,
                    'consultant' => $r->consultant,
                    'consultantId' => $r->consultant,
                    'consultantName' => $r->consultant ? ($consultantNames[$r->consultant] ?? null) : null,
                    'partnerName' => $r->consultant ? ($consultantNames[$r->consultant] ?? null) : null,
                    'individualEntrepreneur' => $r->individualEntrepreneur,
                    'inn' => $r->inn,
                    // Полные поля ИП — диалог верификации читает их из строки
                    // списка. Раньше не отдавались → ОГРН/Адрес/Email/Телефон и
                    // банк показывались прочерками даже при заполненных данных.
                    'ogrn' => $r->ogrn,
                    'address' => $r->address,
                    'email' => $r->email,
                    'phone' => $r->phone,
                    'taxRegime' => $r->tax_regime,
                    'bankName' => $bankReq?->bankName,
                    'bankBik' => $bankReq?->bankBik,
                    'accountNumber' => $bankReq?->accountNumber,
                    'correspondentAccount' => $bankReq?->correspondentAccount,
                    'beneficiaryName' => $bankReq?->beneficiaryName,
                    'verified' => (bool) $r->verified,
                    'verificationStatus' => $verificationStatus,
                    // 'full' | 'bank' | null — см. выше.
                    'pendingScope' => $pendingScope,
                    'rejectionReason' => $r->rejection_reason,
                    'hasBankRequisites' => $bankReq !== null,
                    // Без `?->`: слева от `??` он лишний (обращение к свойству
                    // null внутри `??` безопасно), и анализатор на это ругается.
                    // Приведение к bool НЕ добавляем — значение отдаётся как
                    // было, иначе поменялся бы тип в JSON.
                    'bankVerified' => $bankReq->verified ?? false,
                    'submittedAt' => $submittedAt?->toIso8601String(),
                    'overdue' => $overdue,
                    'paymentsSuspended' => (bool) ($suspendedMap[$r->consultant] ?? false),
                    // Источник приостановки: 'request' — партнёр сам подал запрос
                    // на смену; 'manual' — Катя проставила вручную; null — нет.
                    'suspendSource' => $pendingChangeSet->has($r->consultant)
                        ? 'request'
                        : (($suspendedMap[$r->consultant] ?? false) ? 'manual' : null),
                ];
            });

    }
}
