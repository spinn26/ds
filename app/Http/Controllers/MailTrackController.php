<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tracking pixel + click-wrapper для исходящих писем (Уровень 3).
 *
 * Пути:
 *  - GET /mt/o/{tid}.gif — пиксель открытия (1×1 transparent gif), всегда 200
 *  - GET /mt/c/{tid}     — обёртка клика, 302-redirect на оригинальный URL
 *
 * Эндпоинты публичные, без auth и без Sanctum — иначе письмо в чужом
 * inbox не сможет «отстучаться». Ноль обратной связи получателю
 * (никаких ошибок 403/404 в браузере получателя, даже если tid
 * некорректный — мы либо 200/пиксель, либо 302/main page).
 */
class MailTrackController extends Controller
{
    private const PIXEL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    /**
     * Open-tracker: инкрементит opens_count + проставляет opened_at
     * (только первый раз). Всегда возвращает 1×1 transparent gif.
     */
    public function open(Request $request, string $tid): Response
    {
        try {
            // tid в URL — uuid v4 (36 chars). Невалидный tid просто игнорим.
            if ($this->isValidTid($tid)) {
                DB::table('mail_log')
                    ->where('tracking_id', $tid)
                    ->update([
                        'opens_count' => DB::raw('opens_count + 1'),
                        'opened_at' => DB::raw('COALESCE(opened_at, NOW())'),
                        'delivery_status' => DB::raw("CASE WHEN delivery_status IN ('sent','delivered') THEN 'delivered' ELSE delivery_status END"),
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('mail-track open failed: ' . $e->getMessage());
        }

        return response(self::PIXEL_GIF, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Click-tracker: инкрементит clicks_count + проставляет clicked_at
     * (только первый раз), затем редиректит на оригинальный URL из ?u=.
     * URL передаётся в base64url, чтобы не ломать query-string.
     */
    public function click(Request $request, string $tid)
    {
        $u = (string) $request->query('u', '');
        $target = $this->decodeUrl($u);

        try {
            if ($this->isValidTid($tid)) {
                DB::table('mail_log')
                    ->where('tracking_id', $tid)
                    ->update([
                        'clicks_count' => DB::raw('clicks_count + 1'),
                        'clicked_at' => DB::raw('COALESCE(clicked_at, NOW())'),
                        'last_click_url' => $target ? mb_substr($target, 0, 2000) : null,
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('mail-track click failed: ' . $e->getMessage());
        }

        // Безопасный fallback — если URL пустой/невалидный, редиректим на
        // главную приложения, чтобы пользователь хотя бы куда-то попал.
        return redirect()->away($target ?: (string) config('app.url'));
    }

    private function isValidTid(string $tid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tid);
    }

    /**
     * base64url decode + http(s)-валидация. Возвращает null если что-то
     * не так — caller сам решит, куда редиректить fallback.
     */
    private function decodeUrl(string $encoded): ?string
    {
        if ($encoded === '') return null;
        $padded = strtr($encoded, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding) $padded .= str_repeat('=', 4 - $padding);

        $decoded = base64_decode($padded, true);
        if ($decoded === false) return null;
        if (! preg_match('~^https?://~i', $decoded)) return null;

        return $decoded;
    }
}
