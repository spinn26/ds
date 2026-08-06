<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\NotificationController;
use App\Models\Consultant;
use App\Support\LegacyId;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

            // Активный партнёр не должен нести маркеры терминации. При активации
            // из «Терминирован»/«Исключён» dateDeactivity уже выставлен, а
            // dateDeterministic отчёты/фильтры читают как «дату терминации»
            // (PartnerStatusReport, StructureController, фильтры term_from/to).
            // Не переписав их, реактивированный партнёр остался бы в выборках
            // терминаций со старой датой. Чистим дату деактивации и переводим
            // dateDeterministic в конец годового периода (как ручной override).
            $consultant->dateDeactivity = null;
            $consultant->dateDeterministic = Carbon::now()->addYear();

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
     * Терминация партнёра. Увеличивает счётчик; если восстанавливаться больше
     * нечем — исключает.
     *
     * Триггер исключения (2026-08-06): терминация при ИСЧЕРПАННЫХ
     * самовосстановлениях, а не «терминаций стало N». Иначе последняя попытка
     * восстановления недостижима — партнёр уходил бы в «Исключён» раньше, чем
     * успел ею воспользоваться. maxTerminations остаётся жёстким потолком.
     */
    public function terminate(Consultant $consultant, string $reason = ''): PartnerActivity
    {
        if (! $consultant->canBeTerminated()) {
            return $consultant->activity;
        }

        $previousActivity = $consultant->activity;
        $newCount = ($consultant->terminationCount ?? 0) + 1;
        $reinstatementsLeft = $consultant->reinstatementsLeft();

        $result = DB::transaction(function () use ($consultant, $previousActivity, $newCount, $reinstatementsLeft, $reason) {
            $consultant->terminationCount = $newCount;
            $consultant->active = false;
            $consultant->dateDeactivity = Carbon::now();

            // Фича выключена → legacy-правило «N терминаций → исключение».
            // Иначе исключаем того, кому возвращаться уже нечем.
            $noWayBack = PartnerActivity::selfReinstateEnabled()
                && ($reinstatementsLeft < 1 || $consultant->reinstate_blocked);

            if ($noWayBack || $newCount >= PartnerActivity::maxTerminations()) {
                $consultant->activity = PartnerActivity::Excluded;
                $consultant->save();

                $why = $noWayBack
                    ? 'восстановления исчерпаны'
                    : 'достигнут потолок терминаций';
                $this->logStatusChange(
                    $consultant,
                    $previousActivity,
                    PartnerActivity::Excluded,
                    "Исключение: {$why} (терминация #{$newCount}). {$reason}"
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
     * ОТМЕНА ошибочной терминации/исключения: статус возвращается к тому, что
     * был ДО неё, а контракты и клиенты — прежнему владельцу.
     *
     * Терминация — операция с побочными эффектами: contracts/clients уезжают
     * на ближайшего активного вышестоящего (reassignContractsToUpline/
     * reassignClientsToUpline). Простая «активация обратно» статус чинит, а
     * портфель оставляет у наставника — поэтому и нужен отдельный откат.
     *
     * Что откатываем:
     *  - статус → from_status последней записи терминации/исключения в
     *    chageConsultanStatusLog (Активен/Зарегистрирован), а не жёстко
     *    «Активен»: партнёр мог быть терминирован из «Зарегистрирован»;
     *  - terminationCount −1 (ошибочная терминация не должна приближать
     *    партнёра к исключению на 3-й);
     *  - контракты и клиенты, уехавшие СИСТЕМНЫМ переносом (triggeredBy ≠
     *    'manual') начиная с момента терминации.
     *
     * Чего НЕ трогаем осознанно:
     *  - структуру нижестоящих: терминация не переписывает inviter, ветка
     *    остаётся на партнёре и возвращается вместе со статусом;
     *  - контракт/клиента, которого ПОСЛЕ терминации перевели куда-то ещё
     *    (сейчас он не у того, кому его отдала терминация) — это уже чужое
     *    решение, откат вернул бы его вслепую. Такие позиции возвращаются
     *    в `skipped` для ручного разбора.
     *
     * @return array{status:string, contracts:int, clients:int, skipped:list<string>}
     */
    public function restoreFromTermination(Consultant $consultant, string $comment = ''): array
    {
        if (! in_array($consultant->activity, [PartnerActivity::Terminated, PartnerActivity::Excluded], true)) {
            return ['status' => 'Партнёр не терминирован', 'contracts' => 0, 'clients' => 0, 'skipped' => []];
        }

        $event = $this->lastTerminationEvent((int) $consultant->id);
        // Момент терминации. Небольшой люфт назад: статус и перенос пишутся
        // разными транзакциями, и перенос может опередить лог на доли секунды.
        $since = ($event?->dateCreated ? Carbon::parse($event->dateCreated) : ($consultant->dateDeactivity
            ? Carbon::parse($consultant->dateDeactivity)
            : Carbon::now()->subYears(5)))->subMinutes(5);

        $target = $this->activityFromLabel($event->from_status ?? null) ?? PartnerActivity::Active;

        $skipped = [];
        $contracts = $this->restoreTransfers('contract', $consultant, $since, $skipped);
        $clients = $this->restoreTransfers('client', $consultant, $since, $skipped);

        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant, $target) {
            $consultant->activity = $target;
            $consultant->active = $target === PartnerActivity::Active;
            $consultant->dateDeactivity = null;
            // Счётчик терминаций откатываем: ошибочная не считается.
            $consultant->terminationCount = max(0, (int) ($consultant->terminationCount ?? 0) - 1);

            if ($target === PartnerActivity::Active) {
                $consultant->dateActivity = Carbon::now();
                $consultant->yearPeriodEnd = Carbon::now()->addYear();
                // dateDeterministic отчёты/фильтры читают как дату терминации —
                // без сброса партнёр остался бы в выборках терминаций.
                $consultant->dateDeterministic = Carbon::now()->addYear();
                if (empty($consultant->participantCode)) {
                    $consultant->participantCode = $this->generateUniqueCode();
                }
            }

            $consultant->save();
        });

        $note = trim('Отмена ошибочной терминации: статус и портфель возвращены. '.$comment);
        $this->logStatusChange($consultant, $previousActivity, $target, $note, 'manual');

        return [
            'status' => $target->label(),
            'contracts' => $contracts,
            'clients' => $clients,
            'skipped' => $skipped,
        ];
    }

    /**
     * Вернуть контракты/клиентов, уехавших при терминации, прежнему владельцу.
     * Обратный перенос пишется в тот же лог (обычной строкой со своим
     * triggeredBy) и диспатчит RecomputeTransferChainJob — комиссии по
     * открытым периодам пересчитаются на восстановленную цепочку.
     *
     * @param 'contract'|'client' $kind
     * @param list<string> $skipped
     */
    private function restoreTransfers(string $kind, Consultant $consultant, Carbon $since, array &$skipped): int
    {
        $map = [
            'contract' => [
                'log' => 'changeConsultantContractLog',
                'table' => 'contract',
                'fk' => 'contract',
                'nameCol' => 'contractNumber',
                'entityName' => 'number',
                'label' => 'Контракт',
            ],
            'client' => [
                'log' => 'changeConsultantClientLog',
                'table' => 'client',
                'fk' => 'client',
                'nameCol' => 'clientName',
                'entityName' => 'personName',
                'label' => 'Клиент',
            ],
        ][$kind];

        $rows = DB::table($map['log'])
            ->where('consultantOld', $consultant->id)
            ->where('dateCreated', '>=', $since)
            // Ручные перезакрепления — не наша операция, их не откатываем.
            ->where(function ($q) {
                $q->whereNull('triggeredBy')->orWhere('triggeredBy', '!=', 'manual');
            })
            ->orderBy('id')
            ->get();

        $restored = 0;
        foreach ($rows as $r) {
            $entityId = (int) $r->{$map['fk']};
            if (! $entityId) {
                continue;
            }

            $current = DB::table($map['table'])->where('id', $entityId)->first();
            if (! $current) {
                continue;
            }
            if ((int) $current->consultant === (int) $consultant->id) {
                continue; // уже вернули (напр. повторный запуск отката)
            }
            if ((int) $current->consultant !== (int) $r->consultantNew) {
                // После терминации сущность увели дальше — вслепую не возвращаем.
                $skipped[] = sprintf('%s %s — сейчас у #%d, а терминация отдала #%d',
                    $map['label'], $current->{$map['entityName']} ?? $entityId,
                    (int) $current->consultant, (int) $r->consultantNew);
                continue;
            }

            DB::transaction(function () use ($map, $entityId, $current, $consultant, &$restored) {
                DB::table($map['table'])->where('id', $entityId)->update([
                    'consultant' => $consultant->id,
                    'consultantName' => $consultant->personName,
                ]);
                DB::table($map['log'])->insert([
                    'id'                => LegacyId::next($map['log']),
                    'dateCreated'       => now(),
                    'webUser'           => auth()->id(),
                    $map['fk']          => $entityId,
                    $map['nameCol']     => $current->{$map['entityName']} ?? null,
                    'consultantOld'     => $current->consultant,
                    'consultantOldName' => $current->consultantName,
                    'consultantNew'     => $consultant->id,
                    'consultantNewName' => $consultant->personName,
                    'triggeredBy'       => 'Отмена ошибочной терминации (возврат к прежнему владельцу)',
                ]);
                $restored++;
            });

            \App\Jobs\RecomputeTransferChainJob::dispatch($kind, $entityId);
        }

        return $restored;
    }

    /** Последняя запись перевода в «Терминирован»/«Исключён» из лога статусов. */
    private function lastTerminationEvent(int $consultantId): ?object
    {
        $rows = DB::table('chageConsultanStatusLog')
            ->where('consultant', $consultantId)
            ->whereIn('to_status', [PartnerActivity::Terminated->label(), PartnerActivity::Excluded->label()])
            ->orderByDesc('dateCreated')
            ->limit(1)
            ->get(['dateCreated', 'from_status', 'to_status']);

        return $rows->first();
    }

    /** Метка статуса из лога → enum. */
    private function activityFromLabel(?string $label): ?PartnerActivity
    {
        if (! $label) {
            return null;
        }
        foreach ([PartnerActivity::Active, PartnerActivity::Registered] as $case) {
            if ($case->label() === $label) {
                return $case;
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
     * САМОвосстановление партнёра после терминации (2026-08-06).
     *
     * Партнёр сам, из блокирующего окна при входе, возвращается в работу — до
     * activation.self_reinstate_limit раз. Механика та же, что у reRegister():
     * статус «Зарегистрирован», ЛП/ГП обнуляются, даётся новое окно активации.
     *
     * Портфель НЕ возвращается: контракты и клиенты уехали к наставнику при
     * терминации, комиссии по ним уже пересчитаны на новую цепочку. Возврат
     * означал бы, что партнёр может двигать деньги, уходя в терминацию и
     * возвращаясь. Возврат портфеля остаётся админской операцией
     * (restoreFromTermination — отмена ОШИБОЧНОЙ терминации).
     *
     * Счётчик отдельный от terminationCount: тот уменьшается при отмене
     * ошибочной терминации, и лимит попыток не должен от этого зависеть.
     *
     * @return array{ok:bool, message:string, attemptsLeft:int}
     */
    public function selfReinstate(Consultant $consultant, ?Request $request = null): array
    {
        // Блокировка строки: два параллельных запроса (двойной клик, ретрай
        // сети) иначе прошли бы гард оба и сожгли две попытки.
        return DB::transaction(function () use ($consultant, $request) {
            /** @var Consultant|null $fresh */
            $fresh = Consultant::whereKey($consultant->id)->lockForUpdate()->first();
            if (! $fresh) {
                return ['ok' => false, 'message' => 'Партнёр не найден', 'attemptsLeft' => 0];
            }

            $reason = $fresh->selfReinstateBlockReason();
            if ($reason !== null) {
                return ['ok' => false, 'message' => $reason, 'attemptsLeft' => $fresh->reinstatementsLeft()];
            }

            $attemptNo = (int) ($fresh->reinstatement_count ?? 0) + 1;
            $limit = PartnerActivity::selfReinstateLimit();
            $previousActivity = $fresh->activity;

            $fresh->activity = PartnerActivity::Registered;
            $fresh->personalVolume = 0;
            $fresh->groupVolume = 0;
            $fresh->groupVolumeCumulative = 0;
            $fresh->activationDeadline = Carbon::now()->addDays(PartnerActivity::activationDays());
            $fresh->yearPeriodEnd = null;
            $fresh->dateDeactivity = null;
            $fresh->reinstatement_count = $attemptNo;
            $fresh->last_reinstate_at = Carbon::now();
            $fresh->save();

            $trace = $request
                ? sprintf(' IP %s, UA %s.', $request->ip(), substr((string) $request->userAgent(), 0, 200))
                : '';
            $this->logStatusChange(
                $fresh,
                $previousActivity,
                PartnerActivity::Registered,
                "Самовосстановление {$attemptNo}/{$limit} (терминаций: {$fresh->terminationCount}). "
                    . 'Портфель остался у наставника, баллы обнулены.' . $trace,
                'self'
            );

            $this->notifyInviterAboutReinstate($fresh);

            return [
                'ok' => true,
                'message' => 'Участие восстановлено. Статус — «Зарегистрирован», на активацию снова '
                    . PartnerActivity::activationDays() . ' дней.',
                'attemptsLeft' => $fresh->reinstatementsLeft(),
            ];
        });
    }

    /**
     * Уведомить наставника, что нижестоящий вернулся: портфель партнёра остался
     * у наставника, поэтому возврат — значимое для него событие.
     */
    private function notifyInviterAboutReinstate(Consultant $consultant): void
    {
        if (! $consultant->inviter) {
            return;
        }
        $inviterWebUser = DB::table('consultant')->where('id', $consultant->inviter)->value('webUser');
        if (! $inviterWebUser) {
            return;
        }

        NotificationController::create(
            (int) $inviterWebUser,
            'status',
            'Партнёр восстановил участие',
            "{$consultant->personName} вернулся в работу после терминации. Контракты и клиенты, перешедшие "
                . 'к вам при терминации, остаются за вами.',
            '/structure'
        );
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
            // Пороги активации — нужны и вне статусов Registered/Active
            // (окно восстановления объясняет условия терминированному).
            'activationPoints' => PartnerActivity::activationPoints(),
            'windowDays' => PartnerActivity::activationDays(),
            // Самовосстановление: этим блоком фронт решает, показывать ли
            // блокирующее окно при входе и активна ли в нём кнопка.
            'reinstate' => [
                'available' => $consultant->canSelfReinstate(),
                'attemptsLeft' => $consultant->reinstatementsLeft(),
                'limit' => PartnerActivity::selfReinstateLimit(),
                'used' => (int) ($consultant->reinstatement_count ?? 0),
                'blockedReason' => $consultant->selfReinstateBlockReason(),
            ],
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
            PartnerActivity::Inactive => [
                'Статус обновлён',
                $comment ?: 'Статус партнёрского аккаунта изменён.',
            ],
        };

        NotificationController::create($userId, 'status', $title, $message, '/profile');
    }
}
