<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Кто какие тикеты чата видит — в одном месте.
 *
 * Правила жили двумя дословными копиями (ChatController::index() и
 * ::unreadCount()), и каждое расхождение между ними оборачивалось багом:
 * сначала «тикет пришёл, виден в списке, а бейдж 0», потом обратное — «бейдж
 * 2, а в списке пусто», когда коллега уже забрал тикет. Копии сведены сюда;
 * единственное УМЫШЛЕННОЕ различие вынесено в параметр $excludeOwnerChannel.
 *
 * ⚠ Третья копия правил — ChatTicketPolicy: она работает не с запросом, а с
 * уже загруженным тикетом, поэтому осталась отдельно. Меняя что-то здесь,
 * проверяйте её тоже, иначе тикет будет виден в списке и отдавать 403 по
 * прямой ссылке. Сетка ChatVisibilityTest сверяет все три канала разом.
 */
class ChatTicketVisibility
{
    /**
     * Сузить запрос по chat_tickets до видимых пользователю.
     *
     * @param bool $excludeOwnerChannel убрать у партнёра тикеты «Написать
     *        собственнику»: в списке они нужны, а в счётчике непрочитанных —
     *        нет, это исходящий канал, а не обращение к партнёру.
     */
    public function apply(Builder $query, User $user, bool $excludeOwnerChannel = false): void
    {
        $userId = $user->id;
        $participantTicketIds = $this->participantTicketIds($userId);

        if (! $user->isStaff()) {
            // Партнёр видит только свои (автор / получатель) + где приглашён.
            // «Написать собственнику» (department=owner) — двусторонний канал:
            // партнёр видит тикет в «Мои обращения» под «Собственнику» и получает
            // ответ собственника как обычное сообщение (собственник отвечает из
            // /manage/chat). Спец-исключения owner для партнёра НЕТ.
            $query->where(function ($q) use ($userId, $participantTicketIds) {
                $q->where('created_by', $userId)
                  ->orWhere('recipient_id', $userId);
                if (! empty($participantTicketIds)) {
                    $q->orWhereIn('id', $participantTicketIds);
                }
            });

            if ($excludeOwnerChannel) {
                $query->where(fn ($q) => $q->where('department', '!=', 'owner')->orWhereNull('department'));
            }

            return;
        }

        // Staff: видимость по своим категориям + личное участие.
        // getRolesArray() — тот же массив, но с приведением регистра;
        // ручной explode его не делал (см. канон в User).
        $roles = $user->getRolesArray();
        $allowed = TicketService::visibleCategoriesForRoles($roles);
        $expanded = $allowed;
        foreach (TicketService::CATEGORY_ALIASES as $legacy => $modern) {
            if (in_array($modern, $allowed, true)) $expanded[] = $legacy;
        }
        $isAdmin = $user->isAdmin();
        // Руководитель отдела видит ВСЕ тикеты своих категорий, включая
        // взятые подчинёнными: ему нужна работа отдела целиком, а не
        // только неразобранное (запрос 2026-08-10 по бэк-офису).
        $isLead = (bool) ($user->chat_department_lead ?? false);

        $query->where(function ($q) use ($userId, $expanded, $participantTicketIds, $isAdmin, $isLead) {
            // Claim & hide: тикеты отдела видны staff ТОЛЬКО пока никто
            // не взял их в работу (assigned_to IS NULL). Как только staff
            // отправляет первое сообщение — sendMessage() выставляет
            // assigned_to=он, и тикет исчезает из списков остальных
            // сотрудников того же отдела. Свои назначенные / созданные /
            // recipient / приглашённые продолжают быть видны через OR-ветки.
            if (! empty($expanded)) {
                $q->where(function ($q2) use ($expanded, $isLead) {
                    $q2->whereIn('department', $expanded);
                    if (! $isLead) {
                        $q2->whereNull('assigned_to');
                    }
                });
            }
            // Admin-override: админы видят ВСЕ тикеты техподдержки
            // независимо от claim & hide — для контроля работы support-
            // команды. По запросу 2026-05-26: «админ видит все чаты тех
            // поддержки». Legacy-ключ technical добавлен для тикетов,
            // созданных до унификации категорий.
            if ($isAdmin) {
                $q->orWhereIn('department', ['support', 'technical']);
            }
            $q->orWhere('created_by', $userId)
              ->orWhere('recipient_id', $userId)
              ->orWhere('assigned_to', $userId);
            if (! empty($participantTicketIds)) {
                $q->orWhereIn('id', $participantTicketIds);
            }
        });
    }

    /**
     * Тикеты, где пользователь — дополнительный участник.
     *
     * @return list<int>
     */
    public function participantTicketIds(int $userId): array
    {
        return DB::table('chat_ticket_participants')
            ->where('user_id', $userId)
            ->pluck('ticket_id')
            ->all();
    }
}
