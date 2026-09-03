<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Состояние расчётов для верхней панели.
 *
 * Автоматических пересчётов на платформе нет с 05.06.2026 — статусы,
 * квалификации, отрыв и пул считаются только по кнопке. Из-за этого регулярно
 * возникает один и тот же класс обращений: данные внесли, а цифры не сошлись,
 * потому что кнопку никто не нажал. Панель показывает это постоянно, не
 * заставляя открывать «Периоды».
 *
 * Видно тем, кто с этим работает: администраторам, руководителям и
 * руководителю расчётов (роль calculations).
 */
class CalcStateController extends Controller
{
    /** Роли, которым показываем состояние расчётов. */
    private const ROLES = ['admin', 'head', 'calculations'];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(self::ROLES)) {
            return response()->json(['visible' => false]);
        }

        // Первый месяц, который ещё не закрыт (или был переоткрыт), начиная с
        // самого раннего закрытия: это и есть «текущий рабочий период».
        $closed = DB::table('period_closures')
            ->whereNotNull('closed_at')
            ->whereNull('reopened_at')
            ->orderByDesc('year')->orderByDesc('month')
            ->first(['year', 'month', 'closed_at']);

        $openYear = $closed ? (int) $closed->year : (int) now()->year;
        $openMonth = $closed ? (int) $closed->month + 1 : (int) now()->month;
        if ($openMonth > 12) { $openMonth = 1; $openYear++; }

        return response()->json([
            'visible' => true,
            'openPeriod' => sprintf('%04d-%02d', $openYear, $openMonth),
            'lastClosed' => $closed ? sprintf('%04d-%02d', $closed->year, $closed->month) : null,
            'lastClosedAt' => $closed?->closed_at,
            // Разморозка — исключительная ситуация: закрытый месяц открыт для
            // правок, и об этом должно быть видно сразу.
            'reopened' => DB::table('period_closures')->whereNotNull('reopened_at')->count(),
            // Когда в последний раз вообще считались комиссии. Отдельного
            // журнала пересчётов нет, поэтому берём свежесть самих строк.
            'lastCalcAt' => DB::table('commission')->whereNull('deletedAt')->max('createdAt'),
        ]);
    }
}
