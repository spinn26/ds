<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\NotificationController;
use App\Models\Consultant;
use App\Support\LegacyId;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartnerStatusService
{
    /**
     * Регистрация нового партнёра: ставит статус «Зарегистрирован»
     * и рассчитывает дедлайн активации (90 дней).
     */
    public function register(Consultant $consultant): void
    {
        $consultant->activity = PartnerActivity::Registered;
        $consultant->activationDeadline = Carbon::now()->addDays(PartnerActivity::activationDays());
        $consultant->terminationCount = $consultant->terminationCount ?? 0;
        $consultant->save();

        $this->logStatusChange($consultant, null, PartnerActivity::Registered, 'Регистрация');
    }

    /**
     * Recompute consultant.personalVolume from transaction rows for the current
     * period, and auto-activate if the threshold is crossed. Safe to call after
     * every commission calculation — it only writes when the value changes,
     * and activate() is a no-op for non-Registered partners.
     *
     * Returns true if the partner was activated by this call.
     */
    public function recomputeVolumeAndActivate(int $consultantId): bool
    {
        // Lock the consultant row for the read-modify-write: real-time commission
        // calc + nightly sweep + queued import workers can otherwise interleave
        // and lost-update personalVolume / double-activate. activate() opens its
        // own (nested) transaction — that's a savepoint, safe.
        return DB::transaction(function () use ($consultantId) {
            $consultant = Consultant::whereKey($consultantId)->lockForUpdate()->first();
            if (! $consultant) {
                return false;
            }

        // Sum personalVolume across all non-deleted transactions for contracts
        // owned by this consultant. For Active partners the period resets on
        // yearPeriodEnd, so we only count transactions after the previous
        // period end (= yearPeriodEnd - 1y); for Registered we count since
        // dateCreated (activation window).
        $periodStart = $consultant->activity === PartnerActivity::Active && $consultant->yearPeriodEnd
            ? Carbon::parse($consultant->yearPeriodEnd)->subYear()
            : ($consultant->dateCreated ?: Carbon::now()->subYears(10));

        $lp = (float) DB::table('transaction as t')
            ->join('contract as c', 'c.id', '=', 't.contract')
            ->where('c.consultant', $consultantId)
            ->whereNull('t.deletedAt')
            ->whereNull('c.deletedAt')
            ->where('t.date', '>=', $periodStart)
            ->sum('t.personalVolume');

        if ((float) ($consultant->personalVolume ?? 0) !== $lp) {
            $consultant->personalVolume = $lp;
            $consultant->save();
        }

            return $this->activate($consultant);
        });
    }

    /**
     * Сгенерировать уникальный participantCode. 6 символов A-Z0-9,
     * исключая легко путающиеся (0, O, 1, I, L). Проверяется уникальность.
     */
    private function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $exists = DB::table('consultant')->where('participantCode', $code)->exists();
            if (! $exists) return $code;
        }
        throw new \RuntimeException('Не удалось сгенерировать уникальный participantCode за 20 попыток');
    }

    /**
     * Активация партнёра: проверяет ЛП >= 500 и переводит в «Активен».
     * Вызывается при достижении порога ЛП или по событию.
     */
    public function activate(Consultant $consultant): bool
    {
        if ($consultant->activity !== PartnerActivity::Registered) {
            return false;
        }

        $personalVolume = (float) ($consultant->personalVolume ?? 0);
        if ($personalVolume < PartnerActivity::activationPoints()) {
            return false;
        }

        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Active;
            $consultant->active = true;
            $consultant->dateActivity = Carbon::now();
            $consultant->yearPeriodEnd = Carbon::now()->addYear();

            // Генерируем participantCode если ещё нет. Код нужен активному
            // партнёру для выдачи реф-ссылки; без него /register?ref=... не
            // сработает. 6 символов A-Z0-9 даёт 2.1 млрд комбинаций —
            // collision-проверка защищает даже при экстремально больших
            // выборках.
            if (empty($consultant->participantCode)) {
                $consultant->participantCode = $this->generateUniqueCode();
            }

            $consultant->save();
        });

        $this->logStatusChange($consultant, $previousActivity, PartnerActivity::Active, 'Активация: ЛП >= 500');

        return true;
    }

    /**
     * Принудительная активация администратором из карточки партнёра.
     *
     * В отличие от activate(), НЕ проверяет текущий статус и порог ЛП: это
     * ручное управленческое решение (аналог override, спека «Статусы
     * партнёров» §3). Разрешает активировать в т.ч. «Терминирован» /
     * «Исключён». Выставляет тот же набор полей, что и штатная активация
     * (dateActivity, yearPeriodEnd, participantCode), чтобы вручную
     * активированный партнёр был неотличим от активного, и пишет запись в
     * аудит-лог с указанием причины (source=manual).
     *
     * Строгий activate() намеренно оставлен для авто-активации по порогу ЛП
     * и bulk-операций — их гейт не ослабляется.
     */
    public function forceActivate(Consultant $consultant, string $comment = ''): bool
    {
        if ($consultant->activity === PartnerActivity::Active) {
            return false; // уже активен — нечего делать
        }

        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Active;
            $consultant->active = true;
            $consultant->dateActivity = Carbon::now();
            $consultant->yearPeriodEnd = Carbon::now()->addYear();

            if (empty($consultant->participantCode)) {
                $consultant->participantCode = $this->generateUniqueCode();
            }

            $consultant->save();
        });

        $note = trim('Ручная активация администратором. '.$comment);
        $this->logStatusChange($consultant, $previousActivity, PartnerActivity::Active, $note, 'manual');

        return true;
    }

    /**
     * Терминация партнёра. Увеличивает счётчик, при 3-й — исключает.
     */
    public function terminate(Consultant $consultant, string $reason = ''): PartnerActivity
    {
        if (! $consultant->canBeTerminated()) {
            return $consultant->activity;
        }

        $previousActivity = $consultant->activity;
        $newCount = ($consultant->terminationCount ?? 0) + 1;

        $result = DB::transaction(function () use ($consultant, $previousActivity, $newCount, $reason) {
            $consultant->terminationCount = $newCount;
            $consultant->active = false;
            $consultant->dateDeactivity = Carbon::now();

            if ($newCount >= PartnerActivity::maxTerminations()) {
                // 3-я терминация → Исключен
                $consultant->activity = PartnerActivity::Excluded;
                $consultant->save();

                $this->logStatusChange(
                    $consultant,
                    $previousActivity,
                    PartnerActivity::Excluded,
                    "Исключение после {$newCount} терминаций. {$reason}"
                );

                return PartnerActivity::Excluded;
            }

            $consultant->activity = PartnerActivity::Terminated;
            $consultant->save();

            $this->logStatusChange(
                $consultant,
                $previousActivity,
                PartnerActivity::Terminated,
                "Терминация #{$newCount}. {$reason}"
            );

            return PartnerActivity::Terminated;
        });

        // Авто-правило: контракты терминированного/исключённого партнёра
        // переезжают на ближайшего активного вышестоящего (Directual делал это
        // заливкой — теперь обязана платформа). Вне транзакции статуса, чтобы
        // RecomputeTransferChainJob диспатчился по уже зафиксированному статусу.
        $this->reassignContractsToUpline($consultant, "Авто-перенос при терминации #{$newCount}");
        $this->reassignClientsToUpline($consultant, "Авто-перенос при терминации #{$newCount}");

        return $result;
    }

    /**
     * Принудительно выставить статус «Терминирован» (минуя canBeTerminated).
     * Нужно для сверки-файла: партнёры, уже помеченные «Исключён» на платформе,
     * но в эталоне значатся «Терминирован». terminationCount НЕ трогаем
     * (у исключённого он уже на максимуме). Пишем в лог статусов.
     */
    public function forceTerminate(Consultant $consultant, string $reason = ''): void
    {
        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Terminated;
            $consultant->active = false;
            $consultant->dateDeactivity = Carbon::now();
            $consultant->save();
        });

        $this->logStatusChange(
            $consultant,
            $previousActivity,
            PartnerActivity::Terminated,
            "Форс-терминация (сверка файла). {$reason}"
        );

        $this->reassignContractsToUpline($consultant, 'Авто-перенос при форс-терминации');
        $this->reassignClientsToUpline($consultant, 'Авто-перенос при форс-терминации');
    }

    /**
     * Исключение вручную (за нарушение правил).
     */
    public function exclude(Consultant $consultant, string $reason = ''): void
    {
        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Excluded;
            $consultant->active = false;
            $consultant->dateDeactivity = Carbon::now();
            $consultant->save();
        });

        $this->logStatusChange(
            $consultant,
            $previousActivity,
            PartnerActivity::Excluded,
            "Исключение вручную. {$reason}"
        );

        $this->reassignContractsToUpline($consultant, 'Авто-перенос при исключении');
        $this->reassignClientsToUpline($consultant, 'Авто-перенос при исключении');
    }

    /**
     * Перенести все контракты партнёра на ближайшего активного вышестоящего
     * наставника (вверх по inviter, пропуская терминированных/исключённых).
     * Каждый перенос пишется в changeConsultantContractLog тем же форматом,
     * что ручное перезакрепление, и диспатчит RecomputeTransferChainJob —
     * пересчёт комиссий контракта за ОТКРЫТЫЕ периоды (исторические/закрытые
     * calculateForTransaction пропустит сам).
     *
     * Если активного вышестоящего в цепочке нет (корневой партнёр / вся ветка
     * терминирована) — контракты уходят на «Неизвестного консультанта»
     * (UNKNOWN_CONSULTANT_ID): 0%, без каскада, доля остаётся у компании. Так
     * контракт никогда не «зависает» на терминированном ФК.
     *
     * @return array{moved:int, target:?int, fallbackUnknown:int}
     */
    public function reassignContractsToUpline(Consultant $consultant, string $triggeredBy = 'Авто-перенос при терминации'): array
    {
        $contracts = DB::table('contract')
            ->where('consultant', $consultant->id)
            ->whereNull('deletedAt')
            ->get(['id', 'number', 'consultant', 'consultantName']);

        if ($contracts->isEmpty()) {
            return ['moved' => 0, 'target' => null, 'fallbackUnknown' => 0];
        }

        $targetId = $this->nearestActiveUplineId((int) $consultant->id);
        $usedFallback = false;
        if (! $targetId) {
            $targetId = \App\Services\CommissionCalculator::UNKNOWN_CONSULTANT_ID;
            $usedFallback = true;
            $triggeredBy .= ' (нет вышестоящего → Неизвестный консультант)';
        }
        $newCons = DB::table('consultant')->where('id', $targetId)->first();

        $moved = 0;
        foreach ($contracts as $c) {
            DB::transaction(function () use ($c, $newCons, $triggeredBy, &$moved) {
                DB::table('contract')->where('id', $c->id)->update([
                    'consultant'     => $newCons->id,
                    'consultantName' => $newCons->personName,
                ]);
                DB::table('changeConsultantContractLog')->insert([
                    'id'                => LegacyId::next('changeConsultantContractLog'),
                    'dateCreated'       => now(),
                    'webUser'           => null,
                    'contract'          => $c->id,
                    'contractNumber'    => $c->number,
                    'consultantOld'     => $c->consultant,
                    'consultantOldName' => $c->consultantName,
                    'consultantNew'     => $newCons->id,
                    'consultantNewName' => $newCons->personName,
                    'triggeredBy'       => $triggeredBy,
                ]);
                $moved++;
            });
            \App\Jobs\RecomputeTransferChainJob::dispatch('contract', (int) $c->id);
        }

        return ['moved' => $moved, 'target' => $targetId, 'fallbackUnknown' => $usedFallback ? $moved : 0];
    }

    /**
     * Перенести всех клиентов терминированного/исключённого партнёра на
     * ближайшего активного вышестоящего наставника (тот же резолвинг, что и для
     * контрактов). Клиент не должен «числиться» за терминированным ФК.
     *
     * Пишет историю в changeConsultantClientLog (формат ручного перезакрепления
     * createClientTransfer) и диспатчит RecomputeTransferChainJob('client') —
     * пересчёт контрактов клиента за открытые периоды. NB: деньги идут по
     * contract.consultant, поэтому реальная смена цепочки — только если у клиента
     * есть контракты (обычно контракты переносятся отдельно reassignContracts...).
     *
     * Нет активного вышестоящего → «Неизвестный консультант» (UNKNOWN_CONSULTANT_ID).
     *
     * @return array{moved:int, target:?int, fallbackUnknown:int}
     */
    public function reassignClientsToUpline(Consultant $consultant, string $triggeredBy = 'Авто-перенос при терминации'): array
    {
        $clients = DB::table('client')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->get(['id', 'personName', 'consultant', 'consultantName']);

        if ($clients->isEmpty()) {
            return ['moved' => 0, 'target' => null, 'fallbackUnknown' => 0];
        }

        $targetId = $this->nearestActiveUplineId((int) $consultant->id);
        $usedFallback = false;
        if (! $targetId) {
            $targetId = \App\Services\CommissionCalculator::UNKNOWN_CONSULTANT_ID;
            $usedFallback = true;
            $triggeredBy .= ' (нет вышестоящего → Неизвестный консультант)';
        }
        $newCons = DB::table('consultant')->where('id', $targetId)->first();

        $moved = 0;
        foreach ($clients as $cl) {
            DB::transaction(function () use ($cl, $newCons, $triggeredBy, &$moved) {
                DB::table('client')->where('id', $cl->id)->update([
                    'consultant'     => $newCons->id,
                    'consultantName' => $newCons->personName,
                ]);
                DB::table('changeConsultantClientLog')->insert([
                    'id'                => LegacyId::next('changeConsultantClientLog'),
                    'dateCreated'       => now(),
                    'webUser'           => null,
                    'client'            => $cl->id,
                    'clientName'        => $cl->personName,
                    'consultantOld'     => $cl->consultant,
                    'consultantOldName' => $cl->consultantName,
                    'consultantNew'     => $newCons->id,
                    'consultantNewName' => $newCons->personName,
                    'triggeredBy'       => $triggeredBy,
                ]);
                $moved++;
            });
            \App\Jobs\RecomputeTransferChainJob::dispatch('client', (int) $cl->id);
        }

        return ['moved' => $moved, 'target' => $targetId, 'fallbackUnknown' => $usedFallback ? $moved : 0];
    }

    /**
     * id ближайшего активного (activity ∉ {3,5}) вышестоящего наставника по
     * цепочке inviter. null — если такого нет (дошли до корня/NULL). Рекурсивный
     * CTE с лимитом глубины — защита от циклов в legacy-структуре.
     */
    private function nearestActiveUplineId(int $consultantId): ?int
    {
        $rows = DB::select(
            'WITH RECURSIVE up AS (
                SELECT id, inviter, activity, 0 AS depth FROM consultant WHERE id = ?
                UNION ALL
                SELECT c.id, c.inviter, c.activity, up.depth + 1
                FROM consultant c JOIN up ON c.id = up.inviter
                WHERE up.depth < 25
             )
             SELECT id, activity FROM up WHERE depth > 0 ORDER BY depth',
            [$consultantId]
        );

        foreach ($rows as $r) {
            if (! in_array((int) $r->activity, [
                PartnerActivity::Terminated->value,
                PartnerActivity::Excluded->value,
            ], true)) {
                return (int) $r->id;
            }
        }

        return null;
    }

    /**
     * Повторная регистрация терминированного партнёра.
     * Обнуляет баллы, ставит статус «Зарегистрирован».
     */
    public function reRegister(Consultant $consultant): bool
    {
        if ($consultant->activity !== PartnerActivity::Terminated) {
            return false;
        }

        if ($consultant->hasReachedMaxTerminations()) {
            return false;
        }

        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Registered;
            $consultant->personalVolume = 0;
            $consultant->groupVolume = 0;
            $consultant->groupVolumeCumulative = 0;
            $consultant->activationDeadline = Carbon::now()->addDays(PartnerActivity::activationDays());
            $consultant->yearPeriodEnd = null;
            $consultant->save();
        });

        $this->logStatusChange(
            $consultant,
            $previousActivity,
            PartnerActivity::Registered,
            "Повторная регистрация (терминаций: {$consultant->terminationCount})"
        );

        return true;
    }

    /**
     * Проверка просроченных дед��айнов — вызывается по крону.
     * Терминирует зарегистрированных, у которых истёк 90-дневный период.
     */
    public function checkExpiredRegistrations(): int
    {
        $expired = Consultant::registered()
            ->whereNotNull('activationDeadline')
            ->where('activationDeadline', '<', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expired as $consultant) {
            $personalVolume = (float) ($consultant->personalVolume ?? 0);
            if ($personalVolume < PartnerActivity::activationPoints()) {
                $this->terminate($consultant, 'Не набрал ЛП=500 за 90 дней');
                $count++;
            }
        }

        return $count;
    }

    /**
     * Проверка годового периода активных партнёров — вызывается по крону.
     * Терминирует активных, у которых за год ЛП < 500.
     */
    public function checkExpiredActivePeriods(): int
    {
        $expired = Consultant::activePartners()
            ->whereNotNull('yearPeriodEnd')
            ->where('yearPeriodEnd', '<', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expired as $consultant) {
            $personalVolume = (float) ($consultant->personalVolume ?? 0);
            if ($personalVolume < PartnerActivity::activationPoints()) {
                $this->terminate($consultant, 'ЛП < 500 за годовой период');
                $count++;
            } else {
                // Продлеваем на следующий год, обнуляем ЛП периода
                $consultant->yearPeriodEnd = Carbon::now()->addYear();
                $consultant->save();
            }
        }

        return $count;
    }

    /**
     * Получить информацию о статусе партнёра для отображения в кабинете.
     */
    public function getStatusInfo(Consultant $consultant): array
    {
        $activity = $consultant->activity ?? PartnerActivity::Registered;

        $info = [
            'activityId' => $activity->value,
            'activityName' => $activity->label(),
            'hasAccess' => $activity->hasAccess(),
            'canInvite' => $activity->canInvite(),
            'terminationCount' => $consultant->terminationCount ?? 0,
            'maxTerminations' => PartnerActivity::maxTerminations(),
        ];

        // Обратный отсчёт
        if ($activity === PartnerActivity::Registered && $consultant->activationDeadline) {
            $info['activationDeadline'] = $consultant->activationDeadline->toIso8601String();
            $info['daysRemaining'] = max(0, (int) Carbon::now()->diffInDays($consultant->activationDeadline, false));
            $info['requiredPoints'] = PartnerActivity::activationPoints();
            $info['currentPoints'] = (float) ($consultant->personalVolume ?? 0);
        }

        if ($activity === PartnerActivity::Active) {
            $endDate = $consultant->yearPeriodEnd;
            // Fallback: if yearPeriodEnd not set, calculate from dateActivity + 1 year
            if (!$endDate && $consultant->dateActivity) {
                $endDate = Carbon::parse($consultant->dateActivity)->addYear();
            }
            if ($endDate) {
                $info['yearPeriodEnd'] = $endDate instanceof Carbon ? $endDate->toIso8601String() : Carbon::parse($endDate)->toIso8601String();
                $info['daysRemaining'] = max(0, (int) Carbon::now()->diffInDays($endDate, false));
                $info['requiredPoints'] = PartnerActivity::activationPoints();
                $info['currentPoints'] = (float) ($consultant->personalVolume ?? 0);
            }
        }

        return $info;
    }

    /**
     * Логируем смену статуса в трёх местах:
     *   1. Spatie activity_log — единый системный аудит (искать по subject).
     *   2. Legacy `chageConsultanStatusLog` — для старых отчётов. После
     *      миграции 000080 у нас есть from/to/comment/source/changed_by.
     *   3. Laravel logs — для инцидент-разборок.
     */
    private function logStatusChange(
        Consultant $consultant,
        ?PartnerActivity $from,
        PartnerActivity $to,
        string $comment = '',
        string $source = 'system',
    ): void {
        $changedBy = auth()->id();

        // 1. Spatie activity_log — это «нормальный» аудит.
        if (function_exists('activity')) {
            try {
                activity('partner_status')
                    ->performedOn($consultant)
                    ->causedBy($changedBy ? \App\Models\User::find($changedBy) : null)
                    ->withProperties([
                        'from' => $from?->value,
                        'from_label' => $from?->label(),
                        'to' => $to->value,
                        'to_label' => $to->label(),
                        'comment' => $comment,
                        'source' => $source,
                    ])
                    ->log(sprintf('%s → %s', $from?->label() ?? '—', $to->label()));
            } catch (\Throwable $e) {
                Log::warning('activity() failed', ['error' => $e->getMessage()]);
            }
        }

        // 2. Legacy таблица — пишем новые поля если миграция 000080 применилась.
        $row = [
            'consultant' => $consultant->id,
            'dateCreated' => Carbon::now(),
            'webUser' => $consultant->webUser,
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('chageConsultanStatusLog', 'from_status')) {
            $row['from_status'] = $from?->label();
            $row['to_status'] = $to->label();
            $row['comment'] = $comment ?: null;
            $row['source'] = $source;
            $row['changed_by'] = $changedBy;
        }
        // Legacy-таблица без серийного id → генерируем явный id под advisory lock.
        DB::transaction(function () use ($row) {
            $row['id'] = LegacyId::next('chageConsultanStatusLog');
            DB::table('chageConsultanStatusLog')->insert($row);
        });

        // 3. Laravel log для разборок.
        Log::info('Partner status change', [
            'consultant_id' => $consultant->id,
            'from' => $from?->label(),
            'to' => $to->label(),
            'comment' => $comment,
            'source' => $source,
            'changed_by' => $changedBy,
        ]);

        if ($from !== null && $consultant->webUser) {
            $this->notifyStatusChange($consultant->webUser, $to, $comment);
        }
    }

    private function notifyStatusChange(int $userId, PartnerActivity $to, string $comment): void
    {
        [$title, $message] = match ($to) {
            PartnerActivity::Active => [
                'Статус: Активен',
                'Партнёрский аккаунт активирован. Теперь вам доступны реферальные ссылки.',
            ],
            PartnerActivity::Terminated => [
                'Статус: Терминация',
                $comment ?: 'Начислена терминация. Подробности — в личном кабинете.',
            ],
            PartnerActivity::Excluded => [
                'Статус: Исключён',
                $comment ?: 'Аккаунт переведён в статус «Исключён».',
            ],
            PartnerActivity::Registered => [
                'Статус: Зарегистрирован',
                $comment ?: 'Статус возвращён к «Зарегистрирован».',
            ],
        };

        NotificationController::create($userId, 'status', $title, $message, '/profile');
    }
}
