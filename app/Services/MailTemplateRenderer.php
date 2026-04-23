<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Substitutes {{token}} placeholders in subject/body using WebUser fields
 * and linked consultant data. Extra tokens supported:
 *   {{firstName}} {{lastName}} {{patronymic}} {{fullName}}
 *   {{email}} {{phone}}
 *   {{qualification}} {{activity}} {{referralCode}}
 * Unknown tokens are left intact so the UI can flag them visually.
 */
class MailTemplateRenderer
{
    public static function availableTokens(): array
    {
        return [
            'firstName' => 'Имя',
            'lastName' => 'Фамилия',
            'patronymic' => 'Отчество',
            'fullName' => 'Полное ФИО',
            'email' => 'Email',
            'phone' => 'Телефон',
            'qualification' => 'Квалификация',
            'activity' => 'Статус активности',
            'referralCode' => 'Реферальный код',
        ];
    }

    /**
     * Render a template against one user's data.
     * $userRow must contain: id, firstName, lastName, patronymic, email, phone.
     * Consultant and qualification are resolved lazily via joined cache.
     */
    public function render(string $text, object $userRow, ?object $consultantRow, ?string $qualificationTitle): string
    {
        $fullName = trim(implode(' ', array_filter([
            $userRow->lastName ?? null,
            $userRow->firstName ?? null,
            $userRow->patronymic ?? null,
        ])));

        $vars = [
            'firstName' => $userRow->firstName ?? '',
            'lastName' => $userRow->lastName ?? '',
            'patronymic' => $userRow->patronymic ?? '',
            'fullName' => $fullName,
            'email' => $userRow->email ?? '',
            'phone' => $userRow->phone ?? '',
            'qualification' => $qualificationTitle ?? '',
            'activity' => $consultantRow?->activity ? $this->activityLabel((int) $consultantRow->activity) : '',
            'referralCode' => $consultantRow?->participantCode ?? '',
        ];

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/u', function ($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? (string) $vars[$key] : $m[0];
        }, $text);
    }

    /**
     * Preload consultant + status_levels data for a list of WebUser ids.
     * Returns: [userId => ['consultant' => object|null, 'qualification' => string|null]]
     */
    public function batchContext(array $userIds): array
    {
        if (empty($userIds)) return [];

        $consultants = DB::table('consultant')
            ->whereIn('webUser', $userIds)
            ->whereNull('dateDeleted')
            ->get(['id', 'webUser', 'activity', 'status_and_lvl', 'participantCode'])
            ->keyBy('webUser');

        $levelIds = $consultants->pluck('status_and_lvl')->filter()->unique();
        $levels = $levelIds->isNotEmpty()
            ? DB::table('status_levels')->whereIn('id', $levelIds)->pluck('title', 'id')
            : collect();

        $out = [];
        foreach ($userIds as $uid) {
            $c = $consultants[$uid] ?? null;
            $out[$uid] = [
                'consultant' => $c,
                'qualification' => $c && $c->status_and_lvl ? ($levels[$c->status_and_lvl] ?? null) : null,
            ];
        }
        return $out;
    }

    private function activityLabel(int $id): string
    {
        return match ($id) {
            1 => 'Активен',
            3 => 'Терминирован',
            4 => 'Зарегистрирован-Партнёр',
            5 => 'Исключён',
            default => '—',
        };
    }
}
