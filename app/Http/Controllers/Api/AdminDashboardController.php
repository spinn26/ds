<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $prevMonthStart = now()->subMonth()->startOfMonth();
        $prevMonthEnd = now()->subMonth()->endOfMonth();

        // === KPI Cards ===
        $totalPartners = DB::table('consultant')->whereNull('dateDeleted')->count();
        $activePartners = DB::table('consultant')->whereNull('dateDeleted')->where('activity', 1)->count();
        $newPartnersMonth = DB::table('consultant')->whereNull('dateDeleted')
            ->where('dateCreated', '>=', $monthStart)->count();
        $totalClients = DB::table('client')->count();
        $totalContracts = DB::table('contract')->whereNull('deletedAt')->count();
        $openTickets = DB::table('tickets')->whereIn('status', ['open', 'in_progress'])->count();

        // Revenue this month (from commissions)
        $revenueMonth = DB::table('commission')
            ->where('dateMonth', now()->format('Y-m'))
            ->whereNull('deletedAt')
            ->sum('amountRUB');

        $revenuePrevMonth = DB::table('commission')
            ->where('dateMonth', now()->subMonth()->format('Y-m'))
            ->whereNull('deletedAt')
            ->sum('amountRUB');

        // === Charts Data ===

        // Partners by status
        $partnersByStatus = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->join('directory_of_activities', 'consultant.activity', '=', 'directory_of_activities.id')
            ->select('directory_of_activities.name', DB::raw('count(*) as count'))
            ->groupBy('directory_of_activities.name')
            ->get();

        // Monthly revenue trend (last 12 months)
        $revenueTrend = DB::table('commission')
            ->whereNull('deletedAt')
            ->where('date', '>=', now()->subMonths(12))
            ->select('dateMonth', DB::raw('sum("amountRUB") as total'))
            ->groupBy('dateMonth')
            ->orderBy('dateMonth')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->dateMonth,
                'total' => round((float) ($r->total ?? 0), 2),
            ]);

        // New partners trend (last 12 months)
        $partnersTrend = collect();
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $count = DB::table('consultant')
                ->whereNull('dateDeleted')
                ->whereRaw("to_char(\"dateCreated\", 'YYYY-MM') = ?", [$m->format('Y-m')])
                ->count();
            $partnersTrend->push(['month' => $m->format('Y-m'), 'count' => $count]);
        }

        // Top 10 consultants by volume
        $topConsultants = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where('activity', 1)
            ->orderByDesc('groupVolumeCumulative')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'name' => $c->personName,
                'ngp' => round((float) ($c->groupVolumeCumulative ?? 0), 0),
                'lp' => round((float) ($c->personalVolume ?? 0), 0),
            ]);

        // Qualification distribution
        $qualDistribution = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->whereNotNull('status_and_lvl')
            ->join('status_levels', 'consultant.status_and_lvl', '=', 'status_levels.id')
            ->select('status_levels.title', 'status_levels.level', DB::raw('count(*) as count'))
            ->groupBy('status_levels.title', 'status_levels.level')
            ->orderBy('status_levels.level')
            ->get();

        // Funnel: registered -> activated -> first contract -> TOP FC+
        $registered = DB::table('consultant')->whereNull('dateDeleted')->count();
        $activated = DB::table('consultant')->whereNull('dateDeleted')->whereNotNull('dateActivity')->count();
        $withContract = DB::table('consultant as c')
            ->whereNull('c.dateDeleted')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('contract')
                  ->whereColumn('contract.consultant', 'c.id')
                  ->whereNull('contract.deletedAt');
            })
            ->count();
        $topLevel = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->join('status_levels', 'consultant.status_and_lvl', '=', 'status_levels.id')
            ->where('status_levels.level', '>=', 6) // TOP FC и выше
            ->count();

        // Revenue by product (top 7 this month)
        $revenueByProduct = DB::table('commission as c')
            ->join('transaction as t', 't.id', '=', 'c.transaction')
            ->leftJoin('contract as ct', 'ct.id', '=', 't.contract')
            ->where('c.dateMonth', now()->format('Y-m'))
            ->whereNull('c.deletedAt')
            ->whereNotNull('ct.productName')
            ->select('ct.productName as name', DB::raw('sum("c"."amountRUB") as total'))
            ->groupBy('ct.productName')
            ->orderByDesc('total')
            ->limit(7)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'total' => round((float) ($r->total ?? 0), 0),
            ]);

        // KPI deltas: previous vs current (for period-over-period %)
        $totalPartnersPrev = DB::table('consultant')->whereNull('dateDeleted')
            ->where('dateCreated', '<=', $prevMonthEnd)->count();
        $activePartnersPrev = DB::table('consultant')->whereNull('dateDeleted')
            ->where('activity', 1)
            ->where('dateCreated', '<=', $prevMonthEnd)
            ->count();
        $newPartnersPrevMonth = DB::table('consultant')->whereNull('dateDeleted')
            ->whereBetween('dateCreated', [$prevMonthStart, $prevMonthEnd])->count();
        $totalContractsPrev = DB::table('contract')->whereNull('deletedAt')
            ->where('createDate', '<=', $prevMonthEnd)->count();

        // Recent activity (last 10 events)
        $recentActivity = collect();

        // Recent tickets
        $recentTickets = DB::table('tickets')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'type' => 'ticket',
                'icon' => 'mdi-ticket',
                'color' => 'info',
                'text' => "Новый тикет: {$t->subject}",
                'date' => $t->created_at,
            ]);

        // Recent imports
        $recentImports = DB::table('transaction_import_log')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get()
            ->map(fn ($i) => [
                'type' => 'import',
                'icon' => 'mdi-upload',
                'color' => 'success',
                'text' => "Импорт: {$i->success_count} транзакций",
                'date' => $i->created_at,
            ]);

        $recentActivity = $recentTickets->merge($recentImports)
            ->sortByDesc('date')
            ->values()
            ->take(10);

        return response()->json([
            'kpi' => [
                'totalPartners' => $totalPartners,
                'totalPartnersPrev' => $totalPartnersPrev,
                'activePartners' => $activePartners,
                'activePartnersPrev' => $activePartnersPrev,
                'newPartnersMonth' => $newPartnersMonth,
                'newPartnersPrevMonth' => $newPartnersPrevMonth,
                'totalClients' => $totalClients,
                'totalContracts' => $totalContracts,
                'totalContractsPrev' => $totalContractsPrev,
                'openTickets' => $openTickets,
                'revenueMonth' => round((float) $revenueMonth, 0),
                'revenuePrevMonth' => round((float) $revenuePrevMonth, 0),
            ],
            'charts' => [
                'partnersByStatus' => $partnersByStatus,
                'revenueTrend' => $revenueTrend,
                'partnersTrend' => $partnersTrend,
                'topConsultants' => $topConsultants,
                'qualDistribution' => $qualDistribution,
                'revenueByProduct' => $revenueByProduct,
                'funnel' => [
                    ['stage' => 'Регистрация', 'count' => $registered],
                    ['stage' => 'Активация', 'count' => $activated],
                    ['stage' => 'Первый контракт', 'count' => $withContract],
                    ['stage' => 'TOP FC и выше', 'count' => $topLevel],
                ],
            ],
            'recentActivity' => $recentActivity,
        ]);
    }
}
