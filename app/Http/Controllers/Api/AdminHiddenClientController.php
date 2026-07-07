<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic (read-only): soft-deleted clients that still hold LIVE contracts
 * ("orphan contracts"). Surfaces the data-quality tail left by the July
 * consolidation so an admin can decide per-record: restore, re-point, or leave.
 *
 * Admin-only (route middleware role:admin). Nothing is mutated here.
 */
class AdminHiddenClientController extends Controller
{
    public function index(): JsonResponse
    {
        // Hidden client (dateDeleted set) + at least one live contract pointing to it.
        // Per-client: contract count, contract date span, live twin, misattach flag.
        $rows = DB::select(<<<'SQL'
            SELECT c.id,
                   c."personName"      AS name,
                   c."consultantName"  AS owner,
                   c."dateDeleted"::date AS deleted,
                   cnt.n               AS contracts,
                   cnt.dmin::date      AS cfirst,
                   cnt.dmax::date      AS clast,
                   (cnt.dmax > c."dateDeleted") AS misattached,
                   EXISTS (
                       SELECT 1 FROM client l
                       WHERE l."dateDeleted" IS NULL AND l.id <> c.id
                         AND ((c.person IS NOT NULL AND l.person = c.person)
                              OR l."personName" = c."personName")
                   ) AS has_live_twin
            FROM client c
            JOIN LATERAL (
                SELECT count(*) n, min("createDate") dmin, max("createDate") dmax
                FROM contract k WHERE k.client = c.id
            ) cnt ON TRUE
            WHERE c."dateDeleted" IS NOT NULL AND cnt.n > 0
            ORDER BY cnt.n DESC, c."dateDeleted" DESC
        SQL);

        $items = array_map(function ($r) {
            $name = trim((string) ($r->name ?? ''));
            $category = $this->classify($name, (bool) $r->has_live_twin);

            return [
                'id'           => (int) $r->id,
                'name'         => $name !== '' ? $name : '(пустое ФИО)',
                'owner'        => $r->owner ?: '—',
                'deleted'      => $r->deleted,
                'contracts'    => (int) $r->contracts,
                'firstContract'=> $r->cfirst,
                'lastContract' => $r->clast,
                'misattached'  => (bool) $r->misattached,
                'hasLiveTwin'  => (bool) $r->has_live_twin,
                'category'     => $category,
            ];
        }, $rows);

        $summary = [
            'clients'      => count($items),
            'contracts'    => array_sum(array_column($items, 'contracts')),
            'internal'     => $this->countBy($items, 'internal'),
            'test'         => $this->countBy($items, 'test'),
            'review'       => $this->countBy($items, 'review'),
            'repoint'      => $this->countBy($items, 'repoint'),
            'misattached'  => count(array_filter($items, fn ($i) => $i['misattached'])),
        ];

        return response()->json(['summary' => $summary, 'items' => $items]);
    }

    /**
     * Heuristic bucket. Factual signals (misattached, hasLiveTwin) travel
     * separately; this is only a hint for the reviewer, never an action.
     */
    private function classify(string $name, bool $hasLiveTwin): string
    {
        // Internal service records — deliberately hidden (Sidorov excluded from
        // the payout registry). Owner/name always contains these surnames.
        if (preg_match('/сидоров|тарасенко/iu', $name)) {
            return 'internal';
        }
        if ($this->looksLikeTest($name)) {
            return 'test';
        }
        // A live namesake exists — the contract can just be re-pointed to it.
        if ($hasLiveTwin) {
            return 'repoint';
        }

        return 'review';
    }

    private function looksLikeTest(string $name): bool
    {
        $n = trim($name);
        if ($n === '') {
            return true;
        }
        // Explicit test markers.
        if (preg_match('/тест|тестов|геткурс|get\s*курс|getkurs/iu', $n)) {
            return true;
        }
        $tokens = preg_split('/\s+/u', $n);
        // "f f f", "оо оо 1", "kk jj kk" — every token is 1–2 chars.
        if (count($tokens) >= 2 && ! array_filter($tokens, fn ($t) => mb_strlen($t) > 2)) {
            return true;
        }
        // Cyrillic gibberish token: 4+ letters, no vowels ("увцуа", "увцуа").
        foreach ($tokens as $t) {
            if (preg_match('/^[а-яё]{4,}$/iu', $t) && ! preg_match('/[аеёиоуыэюя]/iu', $t)) {
                return true;
            }
        }
        // Known keyboard-mash prefixes (asdas, qwe, chc, ntcn, ггг…, ооо…).
        if (preg_match('/^(asdas|qwe|zxc|rukghkj|chc|ntcn|fgg|g{3,}|о{3,}|а{3,})/iu', $n)) {
            return true;
        }

        return false;
    }

    private function countBy(array $items, string $cat): int
    {
        return count(array_filter($items, fn ($i) => $i['category'] === $cat));
    }
}
