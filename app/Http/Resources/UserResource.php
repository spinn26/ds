<?php

namespace App\Http\Resources;

use App\Models\Consultant;
use App\Models\Requisite;
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
        if ($consultant) {
            $verifiedByConsultant = (int) $consultant->statusRequisites === 3;
            $requisite = Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->first();
            if ($verifiedByConsultant || ($requisite && $requisite->verified)) {
                $requisitesStatus = 'verified';
            } elseif ($requisite) {
                $requisitesStatus = 'pending';
            }
        }

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
            // verified | pending | null (не заполнял)
            'requisitesVerificationStatus' => $requisitesStatus,
            // Партнёр уже подписал Оферту? Используется фронтом для показа
            // блокирующей модалки акцепта после верификации реквизитов.
            'offerAccepted' => (bool) ($consultant?->acceptance ?? false),
        ];
    }
}
