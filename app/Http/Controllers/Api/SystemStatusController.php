<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemStatusController extends Controller
{
    /**
     * Публичная сводка для страницы /status:
     *  - компоненты с текущим статусом
     *  - активные инциденты (status != resolved/completed)
     *  - последние 30 завершённых для истории
     *  - overall — агрегированный статус (worst-case по компонентам).
     */
    public function index(): JsonResponse
    {
        $components = DB::table('system_components')
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'description', 'status']);

        $active = DB::table('system_incidents')
            ->whereNotIn('status', ['resolved', 'completed'])
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        $history = DB::table('system_incidents')
            ->whereIn('status', ['resolved', 'completed'])
            ->orderByDesc('resolved_at')->orderByDesc('id')
            ->limit(30)
            ->get();

        $overall = $this->aggregateOverall($components, $active);

        return response()->json([
            'overall' => $overall,
            'components' => $components,
            'active' => $active,
            'history' => $history,
        ]);
    }

    public function storeComponent(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'status' => 'nullable|in:operational,degraded,partial_outage,major_outage,maintenance',
            'sort_order' => 'nullable|integer',
            'active' => 'nullable|boolean',
        ]);
        $id = DB::table('system_components')->insertGetId([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'operational',
            'sort_order' => $data['sort_order'] ?? 0,
            'active' => $data['active'] ?? true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function updateComponent(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'description' => 'sometimes|nullable|string|max:500',
            'status' => 'sometimes|in:operational,degraded,partial_outage,major_outage,maintenance',
            'sort_order' => 'sometimes|integer',
            'active' => 'sometimes|boolean',
        ]);
        $data['updated_at'] = now();
        DB::table('system_components')->where('id', $id)->update($data);
        return response()->json(['message' => 'Обновлено']);
    }

    public function destroyComponent(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);
        DB::table('system_components')->where('id', $id)->delete();
        return response()->json(['message' => 'Удалено']);
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'status' => 'nullable|in:investigating,identified,monitoring,resolved,scheduled,in_progress,completed',
            'severity' => 'nullable|in:minor,major,critical,maintenance',
            'component_id' => 'nullable|integer|exists:system_components,id',
            'started_at' => 'nullable|date',
        ]);
        $status = $data['status'] ?? 'investigating';
        $id = DB::table('system_incidents')->insertGetId([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $status,
            'severity' => $data['severity'] ?? 'minor',
            'component_id' => $data['component_id'] ?? null,
            'started_at' => $data['started_at'] ?? now(),
            'resolved_at' => in_array($status, ['resolved', 'completed'], true) ? now() : null,
            'created_by' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function updateIncident(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:200',
            'description' => 'sometimes|nullable|string|max:5000',
            'status' => 'sometimes|in:investigating,identified,monitoring,resolved,scheduled,in_progress,completed',
            'severity' => 'sometimes|in:minor,major,critical,maintenance',
            'component_id' => 'sometimes|nullable|integer|exists:system_components,id',
            'started_at' => 'sometimes|date',
            'resolved_at' => 'sometimes|nullable|date',
        ]);
        // Авто-resolved_at при переходе в resolved/completed, если поле пустое.
        if (isset($data['status'])
            && in_array($data['status'], ['resolved', 'completed'], true)
            && empty($data['resolved_at'])
        ) {
            $current = DB::table('system_incidents')->where('id', $id)->value('resolved_at');
            if (! $current) $data['resolved_at'] = now();
        }
        $data['updated_at'] = now();
        DB::table('system_incidents')->where('id', $id)->update($data);
        return response()->json(['message' => 'Обновлено']);
    }

    public function destroyIncident(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);
        DB::table('system_incidents')->where('id', $id)->delete();
        return response()->json(['message' => 'Удалено']);
    }

    /**
     * Worst-case по компонентам, с учётом активных инцидентов как
     * overlay (major incident → overall major_outage даже если все
     * компоненты operational).
     */
    private function aggregateOverall($components, $active): array
    {
        $rank = [
            'operational' => 0, 'maintenance' => 1, 'degraded' => 2,
            'partial_outage' => 3, 'major_outage' => 4,
        ];
        $worst = 'operational';
        foreach ($components as $c) {
            if (($rank[$c->status] ?? 0) > ($rank[$worst] ?? 0)) $worst = $c->status;
        }
        foreach ($active as $i) {
            $sev = $i->severity ?? 'minor';
            if ($sev === 'critical' && ($rank[$worst] ?? 0) < $rank['major_outage']) $worst = 'major_outage';
            elseif ($sev === 'major' && ($rank[$worst] ?? 0) < $rank['partial_outage']) $worst = 'partial_outage';
            elseif ($sev === 'minor' && ($rank[$worst] ?? 0) < $rank['degraded']) $worst = 'degraded';
            elseif ($sev === 'maintenance' && ($rank[$worst] ?? 0) < $rank['maintenance']) $worst = 'maintenance';
        }
        return ['status' => $worst, 'label' => $this->statusLabel($worst)];
    }

    private function statusLabel(string $s): string
    {
        return match ($s) {
            'operational' => 'Все системы работают штатно',
            'maintenance' => 'Технические работы',
            'degraded' => 'Снижение производительности',
            'partial_outage' => 'Частичный сбой',
            'major_outage' => 'Серьёзный сбой',
            default => $s,
        };
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user() || ! $request->user()->hasAnyRole(['admin'])) {
            abort(403, 'Только администратор может управлять статусом.');
        }
    }
}
