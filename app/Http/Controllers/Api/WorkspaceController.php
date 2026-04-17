<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkspaceController extends Controller
{
    /**
     * Рабочий стол — агрегированные данные для всех ролей.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRoles = array_map('trim', explode(',', $user->role ?? ''));
        $isConsultant = in_array('consultant', $userRoles);
        $isStaff = array_intersect($userRoles, ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections']);

        $consultant = Consultant::where('webUser', $user->id)->first();

        $data = [
            'news' => $this->getNews(),
            'recentMessages' => $this->getRecentMessages($consultant?->id),
            'unreadCount' => $this->getUnreadCount($consultant?->id),
            'upcomingEvents' => $this->getUpcomingEvents(),
        ];

        // Партнёрские данные
        if ($isConsultant && $consultant) {
            $data['partnerStats'] = $this->getPartnerStats($consultant);
            $data['teamActivity'] = $this->getTeamActivity($consultant);
            // Консультант сам является Лидером сети, если у него нет пригласителя
            $data['isNetworkLeader'] = empty($consultant->inviter);
            $data['mentor'] = $data['isNetworkLeader'] ? null : $this->getMentor($consultant);
            $data['networkLeader'] = $data['isNetworkLeader'] ? null : $this->getNetworkLeader($consultant);
        }

        // Сотрудники
        if ($isStaff) {
            $data['staffTasks'] = $this->getStaffTasks();
        }

        return response()->json($data);
    }

    /** Новости (последние 10) */
    private function getNews(): array
    {
        $this->ensureNewsTable();

        return DB::table('news')
            ->where('active', true)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'content' => $n->content,
                'type' => $n->type, // info, warning, success
                'createdAt' => $n->created_at,
            ])
            ->toArray();
    }

    /** Последние 5 сообщений */
    private function getRecentMessages(?int $consultantId): array
    {
        if (! $consultantId) return [];

        return DB::table('platformCommunication')
            ->where('consultant', $consultantId)
            ->orderByDesc('date')
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'message' => mb_substr($m->message ?? '', 0, 100),
                'direction' => $m->direction,
                'read' => (bool) $m->read,
                'date' => $m->date,
                'isIncoming' => $m->direction === 'ds2p',
            ])
            ->toArray();
    }

    /** Непрочитанные */
    private function getUnreadCount(?int $consultantId): int
    {
        if (! $consultantId) return 0;

        return DB::table('platformCommunication')
            ->where('consultant', $consultantId)
            ->where('direction', 'ds2p')
            ->where('read', false)
            ->count();
    }

    /** Ближайшие конкурсы/события */
    private function getUpcomingEvents(): array
    {
        return DB::table('Contest')
            ->where('status', 1)
            ->where('end', '>=', now())
            ->orderBy('start')
            ->limit(3)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'start' => $c->start,
                'end' => $c->end,
            ])
            ->toArray();
    }

    /** Показатели партнёра */
    private function getPartnerStats(Consultant $consultant): array
    {
        $qLog = DB::table('qualificationLog')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->first();

        // Nominal level = закрытая квалификация (by НГП)
        $nominalLevel = null;
        if ($qLog && $qLog->nominalLevel) {
            $nominalLevel = DB::table('status_levels')->where('id', $qLog->nominalLevel)->first();
        }

        // Calculation level = уровень расчёта комиссии
        $calcLevel = null;
        if ($qLog && $qLog->calculationLevel) {
            $calcLevel = DB::table('status_levels')->where('id', $qLog->calculationLevel)->first();
        }

        // Fallback to status_and_lvl
        if (!$nominalLevel && $consultant->status_and_lvl) {
            $nominalLevel = DB::table('status_levels')->where('id', $consultant->status_and_lvl)->first();
        }
        if (!$calcLevel) {
            $calcLevel = $nominalLevel;
        }
        if (!$nominalLevel) {
            $nominalLevel = $calcLevel;
        }

        $clientCount = DB::table('client')
            ->where('consultant', $consultant->id)
            ->count();

        $childrenCount = DB::table('consultant')
            ->where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->count();

        $levelsDontMatch = $nominalLevel && $calcLevel && $nominalLevel->id !== $calcLevel->id;

        return [
            'personalVolume' => round((float) ($qLog->personalVolume ?? $consultant->personalVolume ?? 0), 2),
            'groupVolume' => round((float) ($qLog->groupVolume ?? $consultant->groupVolume ?? 0), 2),
            'groupVolumeCumulative' => round((float) ($qLog->groupVolumeCumulative ?? $consultant->groupVolumeCumulative ?? 0), 2),
            'qualification' => $nominalLevel ? "{$nominalLevel->level} [{$nominalLevel->title}]" : '—',
            'percent' => $calcLevel ? $calcLevel->percent : 0,
            'calcQualification' => $levelsDontMatch ? "{$calcLevel->level} [{$calcLevel->title}]" : null,
            'calcPercent' => $calcLevel ? $calcLevel->percent : 0,
            'levelsDontMatch' => $levelsDontMatch,
            'clientCount' => $clientCount,
            'teamCount' => $childrenCount,
        ];
    }

    /** Активность команды (последние продажи) */
    private function getTeamActivity(Consultant $consultant): array
    {
        $teamIds = DB::table('consultant')
            ->where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->pluck('id')
            ->toArray();

        if (empty($teamIds)) return [];

        $commissions = DB::table('commission')
            ->whereIn('consultant', $teamIds)
            ->where('chainOrder', 1)
            ->whereNull('deletedAt')
            ->orderByDesc('date')
            ->limit(5)
            ->get();

        // Batch load consultant names
        $consultantIds = $commissions->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();

        return $commissions->map(function ($c) use ($consultantNames) {
                return [
                    'partnerName' => $consultantNames[$c->consultant] ?? '—',
                    'amount' => round((float) ($c->amountRUB ?? 0), 2),
                    'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                    'date' => $c->date,
                ];
            })
            ->toArray();
    }

    /** Наставник — тот кто пригласил */
    private function getMentor(Consultant $consultant): ?array
    {
        if (! $consultant->inviter) return null;

        $inviter = DB::table('consultant')->where('id', $consultant->inviter)->first();
        if (! $inviter) return null;

        $level = $inviter->status_and_lvl
            ? DB::table('status_levels')->where('id', $inviter->status_and_lvl)->first()
            : null;

        // Контакты из WebUser
        $webUser = $inviter->webUser
            ? DB::table('WebUser')->where('id', $inviter->webUser)->first()
            : null;

        return [
            'id' => $inviter->id,
            'personName' => $inviter->personName,
            'qualification' => $level ? "{$level->level} [{$level->title}]" : '—',
            'phone' => $webUser->phone ?? null,
            'email' => $webUser->email ?? null,
            'telegram' => $webUser->nicTG ?? null,
        ];
    }

    /** Лидер сети — корень ветки (идём вверх по inviter до самого верха) */
    private function getNetworkLeader(Consultant $consultant): ?array
    {
        $currentId = $consultant->inviter;
        $visited = [$consultant->id];
        $leader = null;

        // Идём вверх по цепочке inviter до корня
        for ($i = 0; $i < 50; $i++) {
            if (! $currentId || in_array($currentId, $visited)) break;
            $visited[] = $currentId;

            $current = DB::table('consultant')->where('id', $currentId)->first();
            if (! $current) break;

            $leader = $current; // Запоминаем каждого — последний будет корнем
            $currentId = $current->inviter;
        }

        if (! $leader || $leader->id === $consultant->inviter) {
            // Лидер = наставник (нет глубины), не показываем дубль
            return null;
        }

        $level = $leader->status_and_lvl
            ? DB::table('status_levels')->where('id', $leader->status_and_lvl)->first()
            : null;

        $webUser = $leader->webUser
            ? DB::table('WebUser')->where('id', $leader->webUser)->first()
            : null;

        return [
            'id' => $leader->id,
            'personName' => $leader->personName,
            'qualification' => $level ? "{$level->level} [{$level->title}]" : '—',
            'phone' => $webUser->phone ?? null,
            'email' => $webUser->email ?? null,
            'telegram' => $webUser->nicTG ?? null,
        ];
    }

    /** Задачи для сотрудников */
    private function getStaffTasks(): array
    {
        $unverifiedRequisites = DB::table('requisites')
            ->whereNull('deletedAt')
            ->where(function ($q) {
                $q->where('verified', false)->orWhereNull('verified');
            })
            ->count();

        $unreadMessages = DB::table('platformCommunication')
            ->where('direction', 'p2ds')
            ->where('read', false)
            ->count();

        $pendingPayments = DB::table('consultantPayment')
            ->where('status', 1)
            ->count();

        return [
            'unverifiedRequisites' => $unverifiedRequisites,
            'unreadMessages' => $unreadMessages,
            'pendingPayments' => $pendingPayments,
        ];
    }

    // === CRUD Новости ===

    public function newsList(): JsonResponse
    {
        $this->ensureNewsTable();
        $news = DB::table('news')->orderByDesc('created_at')->get();
        return response()->json($news);
    }

    public function createNews(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $this->ensureNewsTable();

        $id = DB::table('news')->insertGetId([
            'title' => $request->title,
            'content' => $request->content,
            'type' => $request->input('type', 'info'),
            'active' => $request->boolean('active', true),
            'created_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Новость создана', 'id' => $id], 201);
    }

    public function updateNews(Request $request, int $id): JsonResponse
    {
        DB::table('news')->where('id', $id)->update([
            'title' => $request->title,
            'content' => $request->content,
            'type' => $request->input('type', 'info'),
            'active' => $request->boolean('active'),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Новость обновлена']);
    }

    public function deleteNews(int $id): JsonResponse
    {
        DB::table('news')->where('id', $id)->delete();
        return response()->json(['message' => 'Новость удалена']);
    }

    private function ensureNewsTable(): void
    {
        if (! Schema::hasTable('news')) {
            DB::statement('CREATE TABLE news (
                id BIGSERIAL PRIMARY KEY,
                title TEXT NOT NULL,
                content TEXT,
                type VARCHAR DEFAULT \'info\',
                active BOOLEAN DEFAULT true,
                created_by INTEGER,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }
    }
}
