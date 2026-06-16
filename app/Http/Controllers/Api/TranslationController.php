<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TranslationOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/** Публичная выдача переопределений i18n — SPA мёржит на старте. */
class TranslationController extends Controller
{
    public function overrides(): JsonResponse
    {
        if (! Schema::hasTable('translation_overrides')) {
            return response()->json(['overrides' => []]);
        }

        // { ru: { key: value }, en: {...} }
        $out = [];
        foreach (TranslationOverride::query()->get(['locale', 'key', 'value']) as $r) {
            $out[$r->locale][$r->key] = $r->value;
        }

        return response()->json(['overrides' => $out]);
    }
}
