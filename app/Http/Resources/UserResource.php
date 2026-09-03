<?php

namespace App\Http\Resources;

use App\Models\Consultant;
use App\Models\Requisite;
use App\Services\ProfileCompletenessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $consultant = Consultant::where('webUser', $this->id)->first();
        $activityRaw = $consultant?->activity;
        $activityValue = is_object($activityRaw) ? $activityRaw->value : $activityRaw;

        // Статус ручной верификации реквизитов — нужен глобально для
        // блокирующих баннеров фронта (решение от 2026-05-27: до
        // подтверждения УСН финменеджером часть кабинета недоступна).
        $requisitesStatus = null;
        $requisitesRejectionReason = null;
        if ($consultant) {
            $verifiedByConsultant = (int) $consultant->statusRequisites === 3;
            $requisite = Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->first();
            if ($verifiedByConsultant || ($requisite && $requisite->verified)) {
                $requisitesStatus = 'verified';
            } elseif ($requisite && filled($requisite->rejection_reason)) {
                // Отказано в верификации — причина (текст сотрудника / ФИО не на
                // своё имя / режим не УСН). Фронт показывает плашку на всех страницах.
                $requisitesStatus = 'rejected';
                $requisitesRejectionReason = $requisite->rejection_reason;
            } elseif ($requisite) {
                $requisitesStatus = 'pending';
            }
        }

        // Полнота профиля активного ФК (только личные данные; реквизиты
        // ИП/банк в гейт не входят). Для staff/registered/terminated
        // сервис вернёт complete=true.
        $completeness = app(ProfileCompletenessService::class)
            ->evaluate($this->resource, $consultant);

        return [
            'id' => $this->id,
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'patronymic' => $this->patronymic,
            'phone' => $this->phone,
            'role' => $this->role,
            'activityStatus' => $activityValue,
            'avatarUrl' => $this->avatar ? '/storage/' . $this->avatar : null,
            'questionnaireCompleted' => (bool) $this->questionnaireCompletedAt,
            // Кто «вошёл как» под этой учёткой, или null. Признак берём из
            // abilities токена, а не из хранилища браузера: тогда полоса видна
            // в любой вкладке, а не только в той, где нажали «Войти как».
            'impersonatedBy' => $this->impersonatedBy($request),
            // verified | rejected | pending | null (не заполнял)
            'requisitesVerificationStatus' => $requisitesStatus,
            // Причина отказа в верификации (если статус rejected) — для плашки.
            'requisitesRejectionReason' => $requisitesRejectionReason,
            // Партнёр уже подписал Оферту? Используется фронтом для показа
            // блокирующей модалки акцепта после верификации реквизитов.
            'offerAccepted' => (bool) ($consultant?->acceptance ?? false),
            // Есть ли у логина карточка партнёра (consultant.webUser = этот
            // WebUser). Без неё все consultant-scoped эндпоинты отвечают
            // «Консультант не найден», в том числе акцепт документов — а окно
            // акцепта показывалось по offerAccepted=false, которое у таких
            // пользователей false просто из-за отсутствия карточки. Выходил
            // тупик: окно блокирует кабинет, «Принять» падает 404 (инцидент
            // 17.08.2026). Фронт теперь показывает окно только при карточке.
            'hasConsultant' => (bool) $consultant,
            // Терминация и самовосстановление: фронт по этому блоку показывает
            // блокирующее окно возврата при входе. null для пользователей без
            // консультанта.
            'termination' => self::terminationBlock($consultant, $activityValue),
            // Применим ли к пользователю гейт «заполни профиль» (только
            // активный ФК). Нужен фронту, чтобы показывать «всё ок» только
            // тем, к кому требование относится.
            'profileRequired' => $completeness['applicable'],
            // Профиль активного ФК заполнен полностью? false → фронт при
            // входе ведёт на /profile и держит баннер до заполнения.
            'profileComplete' => $completeness['complete'],
            'profileMissing' => $completeness['missing'],
            // Выплаты приостановлены (смена реквизитов) — глобальный баннер.
            'paymentsSuspended' => (bool) ($consultant?->payments_suspended ?? false),
            // Есть ли активный запрос на смену банковских реквизитов (для UI профиля).
            'bankChangePending' => $consultant
                ? \App\Http\Controllers\Api\BankRequisiteChangeController::pendingForConsultant((int) $consultant->id)
                : false,
        ];
    }

    /**
     * Блок терминации/самовосстановления для фронта: по нему MainLayout решает,
     * показывать ли блокирующее окно возврата и активна ли в нём кнопка.
     *
     * @return array<string,mixed>|null
     */
    private static function terminationBlock(?Consultant $consultant, mixed $activityValue): ?array
    {
        if ($consultant === null) {
            return null;
        }

        $value = (int) $activityValue;

        return [
            'terminated' => in_array($value, [
                \App\Enums\PartnerActivity::Terminated->value,
                \App\Enums\PartnerActivity::Excluded->value,
            ], true),
            'excluded' => $value === \App\Enums\PartnerActivity::Excluded->value,
            'canReinstate' => $consultant->canSelfReinstate(),
            'attemptsLeft' => $consultant->reinstatementsLeft(),
            'limit' => \App\Enums\PartnerActivity::selfReinstateLimit(),
            'blockedReason' => $consultant->selfReinstateBlockReason(),
            // Незакрытый шаг «наставник» после восстановления. Пока true, окно
            // показывается при каждом входе, а акцепт документов ждёт: выбор
            // обязателен, закрытая вкладка его не пропускает.
            'mentorPending' => (bool) ($consultant->reinstate_mentor_pending ?? false),
            // getAttribute, а не ->inviterName: larastan не видит legacy-колонок
            // Directual, которых нет в casts() (см. reference_static_analysis).
            'inviterName' => $consultant->getAttribute('inviterName'),
        ];
    }

    /**
     * Админ, вошедший под этой учёткой через «Войти как», или null.
     *
     * ImpersonateController зашивает его id в способность токена
     * (impersonate:from:{id}) — оттуда и читаем. Тело запроса и хранилище
     * браузера для этого не годятся: первое подделывается, второе живёт
     * только в той вкладке, где нажали кнопку.
     *
     * @return array{id: int, name: string}|null
     */
    private function impersonatedBy(Request $request): ?array
    {
        $token = $request->user()?->currentAccessToken();

        foreach ((array) ($token?->abilities ?? []) as $ability) {
            if (! str_starts_with((string) $ability, 'impersonate:from:')) {
                continue;
            }

            $id = (int) substr((string) $ability, strlen('impersonate:from:'));
            $admin = AppModelsUser::find($id);

            return $admin
                ? ['id' => $id, 'name' => trim("{$admin->lastName} {$admin->firstName}") ?: $admin->email]
                : null;
        }

        return null;
    }
}
