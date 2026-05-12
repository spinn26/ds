<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/search?q=... — глобальный поиск для Ctrl+K модалки.
 * Возвращает топ-5 совпадений по каждой сущности.
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%' . $q . '%';
        $isStaff = $request->user()->isStaff();

        $results = [];

        // Партнёры
        if ($isStaff) {
            $partners = DB::table('consultant')
                ->whereNull('dateDeleted')
                ->where(function ($w) use ($like) {
                    $w->where('personName', 'ilike', $like)
                      ->orWhere('participantCode', 'ilike', $like);
                })
                ->limit(5)
                ->get(['id', 'personName as title', 'participantCode']);
            foreach ($partners as $p) {
                $results[] = [
                    'type' => 'partner', 'icon' => 'mdi-account-search',
                    'title' => $p->title, 'subtitle' => $p->participantCode ? "Код: {$p->participantCode}" : null,
                    'url' => "/manage/partners?id={$p->id}",
                ];
            }
        }

        // Клиенты
        if ($isStaff) {
            $clients = DB::table('client')
                ->whereNull('dateDeleted')
                ->where('personName', 'ilike', $like)
                ->limit(5)
                ->get(['id', 'personName as title']);
            foreach ($clients as $c) {
                $results[] = [
                    'type' => 'client', 'icon' => 'mdi-account-group',
                    'title' => $c->title, 'subtitle' => "ID {$c->id}",
                    'url' => "/manage/clients?id={$c->id}",
                ];
            }
        }

        // Контракты — по номеру
        $contractsQ = DB::table('contract')->whereNull('deletedAt')
            ->where(function ($w) use ($like) {
                $w->where('number', 'ilike', $like)
                  ->orWhere('clientName', 'ilike', $like);
            });
        if (! $isStaff) {
            // Партнёр видит только свои контракты.
            $consId = DB::table('consultant')
                ->where('webUser', $request->user()->id)
                ->value('id');
            if ($consId) $contractsQ->where('consultant', $consId);
            else $contractsQ->whereRaw('1=0');
        }
        $contracts = $contractsQ->limit(5)->get(['id', 'number', 'clientName', 'ammount', 'currencySymbol']);
        foreach ($contracts as $c) {
            $results[] = [
                'type' => 'contract', 'icon' => 'mdi-file-document-edit',
                'title' => "#{$c->number}",
                'subtitle' => trim(($c->clientName ?? '—') . ' · ' . ($c->ammount ?? 0) . ' ' . ($c->currencySymbol ?? '')),
                'url' => "/manage/contracts?number={$c->number}",
            ];
        }

        // Тикеты — по subject
        if ($isStaff) {
            $tickets = DB::table('chat_tickets')
                ->where('subject', 'ilike', $like)
                ->orWhere('incident_no', 'ilike', $like)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'subject', 'incident_no', 'status']);
            foreach ($tickets as $t) {
                $results[] = [
                    'type' => 'ticket', 'icon' => 'mdi-chat-processing',
                    'title' => $t->subject,
                    'subtitle' => ($t->incident_no ? $t->incident_no . ' · ' : '') . $t->status,
                    'url' => "/manage/chat?open={$t->id}",
                ];
            }
        }

        // Продукты
        $products = DB::table('product')
            ->where('active', true)
            ->where('name', 'ilike', $like)
            ->limit(5)
            ->get(['id', 'name']);
        foreach ($products as $p) {
            $results[] = [
                'type' => 'product', 'icon' => 'mdi-package-variant',
                'title' => $p->name, 'subtitle' => null,
                'url' => $isStaff ? "/manage/products?id={$p->id}" : '/products',
            ];
        }

        return response()->json(['results' => $results]);
    }
}
