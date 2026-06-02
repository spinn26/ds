<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-sign all acceptance-flow documents for every CURRENT consultant.
 *
 * Business decision (2026-06-02): partners no longer go through the manual
 * document-acceptance step. Current consultants get partnerAcceptance rows for
 * every in_acceptance_flow document (Оферта / Политика / Согласие / ПЭП) plus
 * consultant.acceptance=true, so their profile shows the documents as signed.
 * New registrations are auto-signed in AuthController::register via
 * PartnerAcceptanceService::acceptAllFlowDocuments().
 *
 * logAcceptance / partnerAcceptance ids have no sequence default, so we
 * generate them from MAX(id) and increment locally (safe within one run).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agreementPartnersDocuments') || ! Schema::hasTable('partnerAcceptance')) {
            return;
        }

        $flowDocs = DB::table('agreementPartnersDocuments')
            ->where('in_acceptance_flow', true)
            ->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (! $flowDocs) {
            return;
        }

        $now = now();
        $meta = json_encode(['source' => 'backfill-2026-06-02'], JSON_UNESCAPED_UNICODE);

        // consultant → [уже подписанные documentType] — чтобы не дублировать.
        $existing = DB::table('partnerAcceptance')
            ->where('accepted', true)
            ->get(['consultant', 'documentType'])
            ->groupBy('consultant')
            ->map(fn ($g) => $g->pluck('documentType')->map(fn ($x) => (int) $x)->all());

        $logId = (int) DB::table('logAcceptance')->max('id');
        $accId = (int) DB::table('partnerAcceptance')->max('id');

        $logRows = [];
        $accRows = [];

        DB::table('consultant')->whereNotNull('webUser')->orderBy('id')
            ->chunk(500, function ($consultants) use (&$logRows, &$accRows, &$logId, &$accId, $flowDocs, $existing, $now, $meta) {
                foreach ($consultants as $c) {
                    $have = $existing[$c->id] ?? [];
                    $missing = array_values(array_diff($flowDocs, $have));
                    if (! $missing) {
                        continue;
                    }
                    $logId++;
                    $logRows[] = [
                        'id' => $logId, 'consultant' => $c->id, 'dateAccepted' => $now,
                        'WebUser' => $c->webUser, 'urlData' => $meta, 'headers' => $meta,
                        'source' => 'backfill',
                    ];
                    foreach ($missing as $docId) {
                        $accId++;
                        $accRows[] = [
                            'id' => $accId, 'logAccepted' => $logId, 'WebUser' => $c->webUser,
                            'documentType' => $docId, 'urlData' => $meta, 'sourse' => 'backfill',
                            'headers' => $meta, 'accepted' => true, 'consultant' => $c->id,
                            'dateAccepted' => $now,
                        ];
                    }
                }
            });

        foreach (array_chunk($logRows, 500) as $chunk) {
            DB::table('logAcceptance')->insert($chunk);
        }
        foreach (array_chunk($accRows, 500) as $chunk) {
            DB::table('partnerAcceptance')->insert($chunk);
        }

        // Документы приняты для всех текущих консультантов.
        DB::table('consultant')->update(['acceptance' => true]);

        if (app()->runningInConsole()) {
            echo '  backfill acceptance: logAcceptance +' . count($logRows)
               . ', partnerAcceptance +' . count($accRows) . "\n";
        }
    }

    public function down(): void
    {
        // Удаляем только backfill-строки (помечены source/sourse='backfill').
        DB::table('partnerAcceptance')->where('sourse', 'backfill')->delete();
        DB::table('logAcceptance')->where('source', 'backfill')->delete();
    }
};
