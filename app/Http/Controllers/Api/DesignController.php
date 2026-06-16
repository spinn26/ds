<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DesignTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Публичная (для всех авторизованных) выдача активного дизайна — SPA
 * применяет его в рантайме на старте: палитры тем, логотип, кастомный CSS.
 */
class DesignController extends Controller
{
    public function active(): JsonResponse
    {
        if (! Schema::hasTable('design_themes')) {
            return response()->json(['config' => null]);
        }
        $theme = DesignTheme::active();

        return response()->json(['config' => $theme?->config]);
    }
}
