<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\SocketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per spec ✅Написать собственику.
 *
 * Партнёр пишет сообщение собственнику. Уходит НЕ в Telegram, а в обычный чат
 * на платформе: тикет chat_tickets с категорией «owner» («Собственнику»),
 * адресованный собственнику (WebUser). Собственник видит обращение в
 * /manage/chat и отвечает прямо в платформе; ответ возвращается партнёру.
 *
 * Получатель — system_setting 'chat.owner_recipient_id' (дефолт — WebUser
 * Ламакина Александра, роль head). Раньше уходило в Telegram-группу «Тех.отдел
 * DS Consulting» — не по адресу (реш. 2026-07-28).
 *
 * ⚠ Анонимность best-effort: имя/email отправителя скрываются в карточке, но
 * это полноценный 2-сторонний чат (ответ возвращается автору), поэтому
 * created_by = партнёр — техническая привязка остаётся.
 */
class FounderMessageController extends Controller
{
    /** WebUser собственника по умолчанию (Ламакин Александр, роль head). */
    private const DEFAULT_OWNER_WEBUSER_ID = 1057;

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'anonymous' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $isAnon = (bool) ($data['anonymous'] ?? false);
        $now = now();
        $message = strip_tags((string) $data['message']);

        $recipientId = (int) SystemSetting::value('chat.owner_recipient_id', self::DEFAULT_OWNER_WEBUSER_ID);
        $recipient = DB::table('WebUser')->where('id', $recipientId)->first();
        $recipientName = $recipient
            ? trim(($recipient->lastName ?? '') . ' ' . ($recipient->firstName ?? '')) : null;

        // Имя отправителя (или «Аноним», если партнёр выбрал анонимность).
        $senderName = $isAnon
            ? 'Аноним'
            : (trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')) ?: ('user#' . $user->id));

        // Тикет бессмысленен без первого сообщения — обе вставки атомарно.
        $ticketId = DB::transaction(function () use ($user, $recipientId, $recipientName, $senderName, $isAnon, $message, $now) {
            $id = DB::table('chat_tickets')->insertGetId([
                'subject' => 'Сообщение собственнику',
                'description' => null,
                'status' => 'new',
                'priority' => 'medium',
                'department' => 'owner',
                'created_by' => $user->id,
                'customer_name' => $senderName,
                'customer_email' => $isAnon ? null : $user->email,
                'recipient_id' => $recipientId,
                'recipient_name' => $recipientName,
                'context_type' => null,
                'context_id' => null,
                'tags' => null,
                'messages_count' => 1,
                'is_incident' => false,
                'incident_no' => null,
                'incident_severity' => null,
                'incident_logged_at' => null,
                'incident_logged_by' => null,
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('chat_messages')->insert([
                'ticket_id' => $id,
                'sender_id' => $user->id,
                'sender_name' => $senderName,
                'content' => $message,
                'is_agent' => false,
                'is_system' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $id;
        });

        // Уведомление стаффа по сокету (как в ChatController::store).
        try {
            app(SocketService::class)->emit('chat:new-ticket', null, [
                'ticketId' => $ticketId,
                'subject' => 'Сообщение собственнику',
                'department' => 'owner',
                'customerName' => $senderName,
            ]);
        } catch (\Exception $e) {
            Log::warning('founder-message socket emit failed', ['ticket_id' => $ticketId, 'exception' => $e->getMessage()]);
        }

        // Персональное уведомление собственнику: колокольчик (notifications) +
        // сокет-пуш + личное Telegram-зеркало (если привязал аккаунт). Так он
        // не пропустит обращение, даже когда не сидит в разделе чатов.
        try {
            $preview = mb_substr($message, 0, 120) . (mb_strlen($message) > 120 ? '…' : '');
            NotificationController::create(
                $recipientId,
                'chat',
                'Новое сообщение собственнику',
                $senderName . ': ' . $preview,
                "/manage/chat?open={$ticketId}",
            );
        } catch (\Throwable $e) {
            Log::warning('founder-message notify failed', ['ticket_id' => $ticketId, 'exception' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Сообщение отправлено собственнику']);
    }
}
