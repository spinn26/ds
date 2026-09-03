<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContractSheetSyncService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Забрать правки из Google-таблицы «Парус/Акцент» на платформу и, если данные
 * в листе оказались неверными, откатить прогон. Логика — в
 * ContractSheetSyncService.
 *
 * dry_run=1 отдаёт тот же отчёт, но без записи: им пользуется шаг «Сверить»
 * перед подтверждением.
 */
class ContractSheetSyncController extends Controller
{
    /** POST /admin/contracts/sheet-sync */
    public function sync(Request $request, ContractSheetSyncService $sync): JsonResponse
    {
        $dryRun = $request->boolean('dry_run');

        try {
            $result = $sync->run($dryRun);
        } catch (\Throwable $e) {
            // ТЗ §5: наружу — понятный текст, в лог — причина.
            Log::error('Contract sheet sync failed', ['error' => $e->getMessage()]);

            // 422, а не 5xx: на любой 5xx глобальный перехватчик фронта
            // показывает своё «Ошибка сервера» и перебивает текст ниже.
            return response()->json([
                'status' => 'connection_error',
                'message' => 'Ошибка подключения к таблице, попробуйте позже',
            ], 422);
        }

        if (! $dryRun && $result['updated'] > 0) {
            Audit::log('contract_sheet_sync', 'contract', null, [
                'runId' => $result['runId'] ?? null,
                'updated' => $result['updated'],
                'checked' => $result['checked'],
            ]);
        }

        // Расхождение ФИО — не сбой, а требование к сотруднику: 422 отличает
        // этот исход от успешного прогона на стороне фронта.
        return response()->json($result, $result['status'] === 'name_mismatch' ? 422 : 200);
    }

    /** GET /admin/contracts/sheet-sync/runs — последние прогоны для отката. */
    public function runs(): JsonResponse
    {
        $rows = DB::table('contract_sheet_sync_log as l')
            ->leftJoin('WebUser as u', 'u.id', '=', 'l.created_by')
            ->orderByDesc('l.id')
            ->limit(20)
            ->get([
                'l.id', 'l.status', 'l.checked_count', 'l.updated_count',
                'l.created_at', 'l.rolled_back_at', 'l.changes',
                'u.firstName', 'u.lastName',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'checked' => $r->checked_count,
                'updated' => $r->updated_count,
                'createdAt' => $r->created_at,
                'rolledBackAt' => $r->rolled_back_at,
                'author' => trim("{$r->lastName} {$r->firstName}") ?: '—',
                'changes' => json_decode((string) $r->changes, true) ?: [],
            ]);

        return response()->json(['data' => $rows]);
    }

    /** POST /admin/contracts/sheet-sync/runs/{id}/rollback */
    public function rollback(int $id, ContractSheetSyncService $sync): JsonResponse
    {
        $result = $sync->rollback($id);

        if ($result['status'] !== 'ok') {
            return response()->json($result, 422);
        }

        Audit::log('contract_sheet_sync_rollback', 'contract', null, [
            'runId' => $id,
            'restored' => $result['restored'],
            'skipped' => count($result['skipped']),
        ]);

        return response()->json($result);
    }
}
