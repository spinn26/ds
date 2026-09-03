<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContractSheetSyncService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Забрать правки из Google-таблицы «Парус/Акцент» на платформу. Запуск только
 * вручную, кнопкой. Логика — в ContractSheetSyncService.
 *
 * dry_run=1 отдаёт тот же отчёт, но без записи: им пользуется шаг «Сверить»
 * перед подтверждением.
 */
class ContractSheetSyncController extends Controller
{
    public function __invoke(Request $request, ContractSheetSyncService $sync): JsonResponse
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
                'updated' => $result['updated'],
                'checked' => $result['checked'],
            ]);
        }

        // Расхождение ФИО — не сбой, а требование к сотруднику: 422 отличает
        // этот исход от успешного прогона на стороне фронта.
        return response()->json($result, $result['status'] === 'name_mismatch' ? 422 : 200);
    }
}
