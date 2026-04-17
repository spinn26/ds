<?php

namespace App\Http\Resources;

use App\Models\Consultant;
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
        ];
    }
}
