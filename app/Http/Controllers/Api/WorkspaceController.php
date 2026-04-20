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

    /**
     * Задачи для сотрудников — «рабочий стол» бэк-офиса: что требует внимания сегодня.
     *
     * Каждая секция → { count, items: [...preview] } либо голый count для простых
     * числовых плиток. Фронт группирует это в ленту задач на /manage/workspace.
     */
    private function getStaffTasks(): array
    {
        return [
            'unverifiedRequisites' => $this->countUnverifiedRequisites(),
            'unreadMessages'       => $this->countUnreadMessages(),
            'pendingPayments'      => $this->countPendingPayments(),

            // Расширенные блоки — ленты задач
            'pendingAcceptance'    => $this->listPendingAcceptance(),
            'newContractsNoTx'     => $this->listContractsWithoutTransactions(),
            'failedImports'        => $this->listFailedImports(),
            'pendingAccruals'      => $this->listPendingAccruals(),
            'activationExpiring'   => $this->listActivationExpiring(),
            'unclosedPeriods'      => $this->listUnclosedPeriods(),
        ];
    }

    private function countUnverifiedRequisites(): int
    {
        return DB::table('requisites')
            ->whereNull('deletedAt')
            ->where(function ($q) { $q->where('verified', false)->orWhereNull('verified'); })
            ->count();
    }

    private function countUnreadMessages(): int
    {
        return DB::table('platformCommunication')
            ->where('direction', 'p2ds')
            ->where('read', false)
            ->count();
    }

    private function countPendingPayments(): int
    {
        return DB::table('consultantPayment')->where('status', 1)->count();
    }

    /** Записи partnerAcceptance, ещё не акцептованные. */
    private function listPendingAcceptance(): array
    {
        $rows = DB::table('partnerAcceptance as pa')
            ->leftJoin('consultant as c', 'c.id', '=', 'pa.consultant')
            ->leftJoin('WebUser as u', 'u.id', '=', 'c.webUser')
            ->where(function ($q) { $q->where('pa.accepted', false)->orWhereNull('pa.accepted'); })
            ->orderByDesc('pa.id')
            ->select('pa.id', 'pa.documentType', 'pa.dateAccepted', 'c.personName', 'u.email')
            ->limit(5)
            ->get();

        $count = DB::table('partnerAcceptance')
            ->where(function ($q) { $q->where('accepted', false)->orWhereNull('accepted'); })
            ->count();

        return ['count' => $count, 'items' => $rows];
    }

    /** Контракты «Активирован», у которых нет ни одной транзакции за 30 дней. */
    private function listContractsWithoutTransactions(): array
    {
        $cutoff = now()->subDays(30);

        $rows = DB::select(
            'SELECT c.id, c.number, c."openDate", c.consultant,
                    cons."personName" AS "consultantName",
                    s.name AS "statusName"
               FROM contract c
               LEFT JOIN consultant cons ON cons.id = c.consultant
               LEFT JOIN "contractStatus" s ON s.id = c.status
              WHERE c."openDate" <= ?
                AND c."deletedAt" IS NULL
                AND s.name ILIKE \'%Активирован%\'
                AND NOT EXISTS (
                    SELECT 1 FROM transaction t
                     WHERE t.contract = c.id AND t."deletedAt" IS NULL
                )
              ORDER BY c."openDate" DESC
              LIMIT 5',
            [$cutoff]
        );

        $count = (int) DB::scalar(
            'SELECT COUNT(*) FROM contract c
              LEFT JOIN "contractStatus" s ON s.id = c.status
              WHERE c."openDate" <= ?
                AND c."deletedAt" IS NULL
                AND s.name ILIKE \'%Активирован%\'
                AND NOT EXISTS (
                    SELECT 1 FROM transaction t
                     WHERE t.contract = c.id AND t."deletedAt" IS NULL
                )',
            [$cutoff]
        );

        return ['count' => $count, 'items' => $rows];
    }

    /** Последние неудачные импорты транзакций. */
    private function listFailedImports(): array
    {
        if (! Schema::hasTable('transaction_import_log')) {
            return ['count' => 0, 'items' => []];
        }

        $rows = DB::table('transaction_import_log')
            ->whereIn('status', ['error', 'partial'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'status', 'success_count', 'error_count', 'created_at']);

        $count = DB::table('transaction_import_log')
            ->whereIn('status', ['error', 'partial'])
            ->count();

        return ['count' => $count, 'items' => $rows];
    }

    /**
     * «Прочие начисления» за текущий месяц — свежие начисления, которые staff,
     * возможно, ещё должен проверить. В нашей схеме нет явного approved_at,
     * поэтому показываем последние записи этого месяца как «свежие».
     */
    private function listPendingAccruals(): array
    {
        if (! Schema::hasTable('other_accruals')) {
            return ['count' => 0, 'items' => []];
        }

        $from = now()->startOfMonth();

        $rows = DB::table('other_accruals as oa')
            ->leftJoin('consultant as c', 'c.id', '=', 'oa.consultant')
            ->where('oa.created_at', '>=', $from)
            ->orderByDesc('oa.created_at')
            ->limit(5)
            ->get([
                'oa.id', 'oa.amount', 'oa.points', 'oa.type', 'oa.comment as reason',
                'oa.created_at', 'c.personName as consultantName',
            ]);

        $count = DB::table('other_accruals')->where('created_at', '>=', $from)->count();

        return ['count' => $count, 'items' => $rows];
    }

    /**
     * Партнёры в статусе «Зарегистрирован» (consultant.activity=4), чей
     * 90-дневный срок активации истекает в ближайшие 7 дней.
     * Предпочитаем поле `activationDeadline`, если оно заполнено.
     */
    private function listActivationExpiring(): array
    {
        $now = now();
        $in7 = now()->addDays(7);

        $rows = DB::table('consultant as c')
            ->leftJoin('WebUser as u', 'u.id', '=', 'c.webUser')
            ->whereNull('c.dateDeleted')
            ->where('c.activity', 4)
            ->where(function ($q) use ($now, $in7) {
                $q->whereBetween('c.activationDeadline', [$now, $in7])
                  ->orWhere(function ($qq) use ($now, $in7) {
                      $qq->whereNull('c.activationDeadline')
                         ->whereRaw('c."dateCreated" + interval \'90 days\' BETWEEN ? AND ?', [$now, $in7]);
                  });
            })
            ->select('c.id', 'c.personName', 'u.email', 'c.dateCreated', 'c.activationDeadline', 'c.personalVolume')
            ->orderBy('c.dateCreated')
            ->limit(5)
            ->get();

        $count = DB::table('consultant as c')
            ->whereNull('c.dateDeleted')
            ->where('c.activity', 4)
            ->where(function ($q) use ($now, $in7) {
                $q->whereBetween('c.activationDeadline', [$now, $in7])
                  ->orWhere(function ($qq) use ($now, $in7) {
                      $qq->whereNull('c.activationDeadline')
                         ->whereRaw('c."dateCreated" + interval \'90 days\' BETWEEN ? AND ?', [$now, $in7]);
                  });
            })
            ->count();

        return ['count' => $count, 'items' => $rows];
    }

    /** Последние месяцы, ещё не закрытые. */
    private function listUnclosedPeriods(): array
    {
        if (! Schema::hasTable('period_closures')) {
            return ['count' => 0, 'items' => []];
        }

        // Прошлый и позапрошлый месяц, проверяем closure
        $prev = now()->subMonth();
        $prev2 = now()->subMonths(2);
        $unclosed = [];
        foreach ([$prev2, $prev] as $d) {
            // «Закрыт и заморожен» = есть закрытие без переоткрытия.
            $frozen = DB::table('period_closures')
                ->where('year', $d->year)
                ->where('month', $d->month)
                ->whereNotNull('closed_at')
                ->whereNull('reopened_at')
                ->exists();
            if (! $frozen) {
                $unclosed[] = [
                    'year' => $d->year,
                    'month' => $d->month,
                    'label' => sprintf('%02d.%d', $d->month, $d->year),
                ];
            }
        }

        return ['count' => count($unclosed), 'items' => $unclosed];
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
