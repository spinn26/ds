<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Режим обслуживания. Статус — публичный (страница техработ читает отсчёт и
 * сама впускает обратно, когда админ выключит). Управление — только role:admin.
 */
class MaintenanceController extends Controller
{
    /** Публичный статус для страницы /maintenance (без auth). */
    public function status()
    {
        return response()->json($this->payload());
    }

    /** Включить/выключить режим + задать длительность (мин) и сообщение. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'ends_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        SystemSetting::put('maintenance.enabled', (bool) $data['enabled']);

        if (isset($data['message']) && $data['message'] !== '') {
            SystemSetting::put('maintenance.message', $data['message']);
        }

        if ($data['enabled']) {
            if (! empty($data['ends_at'])) {
                $endsAt = Carbon::parse($data['ends_at']);
            } elseif (! empty($data['minutes'])) {
                $endsAt = now()->addMinutes((int) $data['minutes']);
            } else {
                $endsAt = null; // без отсчёта
            }
            SystemSetting::put('maintenance.ends_at', $endsAt ? $endsAt->toIso8601String() : '');
        } else {
            SystemSetting::put('maintenance.ends_at', '');
        }

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        $endsAt = SystemSetting::value('maintenance.ends_at');

        return [
            'enabled' => (bool) SystemSetting::value('maintenance.enabled', false),
            'message' => SystemSetting::value('maintenance.message', 'Идут технические работы. Скоро вернёмся.'),
            'ends_at' => $endsAt !== '' ? $endsAt : null,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
