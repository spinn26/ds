<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TicketService
{
    /**
     * Категории тикетов + видимость по ролям стаффа.
     *
     * Правило: партнёр выбирает категорию → тикет виден только тем
     * сотрудникам, чья роль есть в `roles`. Админы видят всё (особый
     * случай — короткая дорожка ниже в visibleCategoriesForRoles()).
     *
     * `accounting` (старый «Бухгалтер») и `billing` (с фронта) свернуты
     * в `accruals` — это backward-compat для старых тикетов; на фронте
     * пользователю показывается только новый список.
     */
    public const CATEGORIES = [
        'support'    => ['label' => 'Техподдержка',         'roles' => ['admin', 'support']],
        'backoffice' => ['label' => 'Бэк-офис / Документы', 'roles' => ['admin', 'backoffice']],
        'accruals'   => ['label' => 'Начисления и выплаты', 'roles' => ['admin', 'finance', 'calculations', 'corrections']],
        'legal'      => ['label' => 'Юридический вопрос',   'roles' => ['admin', 'head']],
        'general'    => ['label' => 'Общий вопрос',         'roles' => ['admin', 'support', 'head']],
        'owner'      => ['label' => 'Собственнику',         'roles' => ['admin', 'head']],
    ];

    /**
     * Backward-compat: старые ключи категорий → новые.
     *
     * Источники legacy-ключей:
     *   - chat_tickets.department: technical / billing / sales / general
     *     (старая enum'ка ChatController, до унификации с TicketService).
     *   - tickets.category: accounting (старый «Бухгалтер»).
     *
     * sales → backoffice — был раздел «Продажи» обслуживался бэк-офисом.
     * technical → support, billing/accounting → accruals.
     */
    public const CATEGORY_ALIASES = [
        'accounting' => 'accruals',
        'billing'    => 'accruals',
        'technical'  => 'support',
        'sales'      => 'backoffice',
    ];

    /** Нормализуем legacy-ключ к актуальному. */
    public static function normalizeCategory(?string $category): ?string
    {
        if ($category === null) return null;
        return self::CATEGORY_ALIASES[$category] ?? $category;
    }

    /** Текстовая подпись для UI (label). */
    public static function categoryLabel(?string $category): string
    {
        $key = self::normalizeCategory($category);
        return self::CATEGORIES[$key]['label'] ?? ($category ?? '');
    }

    /**
     * Список категорий, доступных стафф-сотруднику с заданными ролями.
     * Админ всегда видит всё. Возвращает список ключей.
     */
    public static function visibleCategoriesForRoles(array $roles): array
    {
        if (in_array('admin', $roles, true)) {
            return array_keys(self::CATEGORIES);
        }
        $visible = [];
        foreach (self::CATEGORIES as $key => $cfg) {
            if (array_intersect($roles, $cfg['roles'])) $visible[] = $key;
        }
        return $visible;
    }

    /** Может ли стафф с такими ролями видеть конкретный тикет (по category). */
    public static function staffCanSeeCategory(array $roles, ?string $category): bool
    {
        $key = self::normalizeCategory($category);
        if (! $key || ! isset(self::CATEGORIES[$key])) {
            // Неизвестная категория — viewing разрешаем только админу.
            return in_array('admin', $roles, true);
        }
        return (bool) array_intersect($roles, self::CATEGORIES[$key]['roles']);
    }

    /**
     * Format ticket list with batch-loaded related data.
     */
    public function formatTicketList(Collection $rows, int $currentUserId): Collection
    {
        $ticketIds = $rows->pluck('id')->filter()->unique();

        // Batch load all WebUsers (creators + assignees)
        $userIds = $rows->pluck('created_by')->merge($rows->pluck('assigned_to'))->filter()->unique();
        $webUsers = $userIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $userIds)->get()->keyBy('id')
            : collect();

        // Batch load last messages per ticket
        $lastMessages = collect();
        if ($ticketIds->isNotEmpty()) {
            $latestMsgIds = DB::table('ticket_messages')
                ->whereIn('ticket_id', $ticketIds)
                ->selectRaw('MAX(id) as id')
                ->groupBy('ticket_id')
                ->pluck('id');
            if ($latestMsgIds->isNotEmpty()) {
                $lastMessages = DB::table('ticket_messages')
                    ->whereIn('id', $latestMsgIds)
                    ->get()
                    ->keyBy('ticket_id');
            }
        }

        // Batch load unread counts per ticket
        $unreadCounts = collect();
        if ($ticketIds->isNotEmpty()) {
            $unreadCounts = DB::table('ticket_messages')
                ->whereIn('ticket_id', $ticketIds)
                ->where('user_id', '!=', $currentUserId)
                ->whereRaw('"created_at" > (SELECT COALESCE("updated_at", "created_at") FROM tickets WHERE tickets.id = ticket_messages.ticket_id)')
                ->select('ticket_id', DB::raw('count(*) as cnt'))
                ->groupBy('ticket_id')
                ->pluck('cnt', 'ticket_id');
        }

        return $rows->map(function ($t) use ($webUsers, $lastMessages, $unreadCounts) {
            $creator = $webUsers[$t->created_by] ?? null;
            $assignee = $t->assigned_to ? ($webUsers[$t->assigned_to] ?? null) : null;
            $lastMsg = $lastMessages[$t->id] ?? null;

            return [
                'id' => $t->id,
                'subject' => $t->subject,
                'category' => $t->category,
                'categoryLabel' => self::categoryLabel($t->category),
                'status' => $t->status,
                'priority' => $t->priority,
                'createdBy' => $creator ? trim(($creator->lastName ?? '') . ' ' . ($creator->firstName ?? '')) : '—',
                'assignedTo' => $assignee ? trim(($assignee->lastName ?? '') . ' ' . ($assignee->firstName ?? '')) : null,
                'contextType' => $t->context_type,
                'lastMessage' => $lastMsg ? mb_substr($lastMsg->message ?? '', 0, 80) : null,
                'lastMessageAt' => $lastMsg?->created_at ?? null,
                'unreadCount' => $unreadCounts[$t->id] ?? 0,
                'createdAt' => $t->created_at,
                'updatedAt' => $t->updated_at,
            ];
        });
    }

    /**
     * Format a single ticket with its messages and participants.
     */
    public function formatTicketShow(object $ticket): array
    {
        $messageRows = DB::table('ticket_messages')
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at')
            ->get();

        $participantRows = DB::table('ticket_participants')
            ->where('ticket_id', $ticket->id)
            ->get();

        // Batch load all WebUsers needed (message authors + participants + creator)
        $allUserIds = $messageRows->pluck('user_id')
            ->merge($participantRows->pluck('user_id'))
            ->push($ticket->created_by)
            ->filter()->unique();
        $webUsers = $allUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $allUserIds)->get()->keyBy('id')
            : collect();

        $messages = $messageRows->map(function ($m) use ($webUsers) {
            $user = $webUsers[$m->user_id] ?? null;
            return [
                'id' => $m->id,
                'userId' => $m->user_id,
                'userName' => $user ? trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')) : '—',
                'message' => $m->message,
                'attachmentPath' => $m->attachment_path,
                'attachmentName' => $m->attachment_name,
                'isSystem' => (bool) $m->is_system,
                'createdAt' => $m->created_at,
            ];
        });

        $participants = $participantRows->map(function ($p) use ($webUsers) {
            $user = $webUsers[$p->user_id] ?? null;
            return [
                'userId' => $p->user_id,
                'userName' => $user ? trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')) : '—',
                'role' => $p->role,
            ];
        });

        $creator = $webUsers[$ticket->created_by] ?? null;

        return [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'category' => $ticket->category,
                'categoryLabel' => self::categoryLabel($ticket->category),
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'createdBy' => $creator ? trim(($creator->lastName ?? '') . ' ' . ($creator->firstName ?? '')) : '—',
                'createdById' => $ticket->created_by,
                'contextType' => $ticket->context_type,
                'contextInfo' => $ticket->context_info ? json_decode($ticket->context_info) : null,
                'createdAt' => $ticket->created_at,
            ],
            'messages' => $messages,
            'participants' => $participants,
        ];
    }
}
