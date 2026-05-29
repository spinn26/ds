<?php

namespace App\Services;

use App\Models\Consultant;
use App\Support\LegacyId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records partner acceptance of legal documents (Согласие на ПД, Политика,
 * Оферта, etc.) into the legacy logAcceptance + partnerAcceptance tables.
 *
 * Inherited schema:
 *   logAcceptance       — one row per acceptance session (snapshot of
 *                         request: headers, source, urlData).
 *   partnerAcceptance   — one row per (session × document) with
 *                         documentType, accepted=true, dateAccepted.
 *
 * Both tables have `id integer NOT NULL` without a sequence default, so
 * ids are generated via LegacyId::next() under an advisory_xact_lock.
 */
class PartnerAcceptanceService
{
    public const DOC_OFFER = 1;          // Публичная оферта
    public const DOC_PRIVACY_POLICY = 2; // Политика обработки ПД
    public const DOC_PD_CONSENT = 3;     // Согласие на обработку ПД
    public const DOC_STANDARDS = 4;      // Стандарты и правила (приложение к оферте)

    /**
     * Step 1 of registration: partner has ticked the consent checkbox
     * for personal data processing + privacy policy.
     */
    public function recordRegistrationConsents(Consultant $consultant, Request $request): void
    {
        $this->record($consultant, [
            self::DOC_PD_CONSENT,
            self::DOC_PRIVACY_POLICY,
        ], $request);
    }

    /**
     * Cabinet step (after IP requisites are verified): partner has
     * accepted the Оферта and its annexes (Стандарты).
     */
    public function recordOfferAcceptance(Consultant $consultant, Request $request): void
    {
        $this->record($consultant, [
            self::DOC_OFFER,
            self::DOC_STANDARDS,
        ], $request);
    }

    /**
     * @param  array<int>  $documentIds
     */
    public function record(Consultant $consultant, array $documentIds, Request $request): void
    {
        if (! $documentIds) {
            return;
        }

        DB::transaction(function () use ($consultant, $documentIds, $request) {
            $now = now();
            $headers = json_encode([
                'user-agent' => substr((string) $request->userAgent(), 0, 500),
                'ip' => $request->ip(),
            ], JSON_UNESCAPED_UNICODE);
            $urlData = json_encode([
                'sessionId' => (string) $request->header('X-Request-Id', ''),
            ], JSON_UNESCAPED_UNICODE);

            $logId = LegacyId::next('logAcceptance');
            DB::table('logAcceptance')->insert([
                'id' => $logId,
                'consultant' => $consultant->id,
                'dateAccepted' => $now,
                'WebUser' => $consultant->webUser,
                'urlData' => $urlData,
                'headers' => $headers,
                'source' => 'platform',
            ]);

            // LegacyId::next() возвращает MAX(id) + 1. Внутри ОДНОЙ
            // транзакции до commit'а MAX не меняется, поэтому повторный
            // вызов в том же цикле вернул бы тот же id → 23505 dup-key
            // (прод-баг 2026-05-29 на registr'е: оба row'a получили
            // id=3500 для (Согласие+Политика)). Берём базу один раз и
            // локально инкрементим — параллельные транзакции защищены
            // advisory_xact_lock внутри LegacyId::next.
            $baseId = LegacyId::next('partnerAcceptance');
            $rows = [];
            foreach (array_values($documentIds) as $i => $docId) {
                $rows[] = [
                    'id' => $baseId + $i,
                    'logAccepted' => $logId,
                    'WebUser' => $consultant->webUser,
                    'documentType' => $docId,
                    'urlData' => $urlData,
                    'sourse' => 'platform',
                    'headers' => $headers,
                    'accepted' => true,
                    'consultant' => $consultant->id,
                    'dateAccepted' => $now,
                ];
            }
            DB::table('partnerAcceptance')->insert($rows);
        });
    }
}
