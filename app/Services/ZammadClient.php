<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Тонкая обёртка над Zammad REST API.
 *
 * Поддерживает создание тикета, добавление сообщения, поиск и обновление.
 * Каждый исходящий вызов журналируется в integration_events через
 * IntegrationLogger — для health-метрик в /admin/integrations.
 *
 * Все методы поднимают исключение при HTTP-ошибке (Http::throw()),
 * чтобы вызывающий код видел ошибку и решил что с ней делать.
 */
class ZammadClient
{
    public function __construct(
        private readonly ApiSettingsService $settings,
        private readonly IntegrationLogger $logger,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) $this->settings->get('zammad.base_url')
            && (bool) $this->settings->get('zammad.token');
    }

    /**
     * Создать тикет.
     *
     * @param array{title:string,group?:string,customer_email:string,subject:string,body:string,priority?:string,state?:string} $data
     * @return array Ответ Zammad с полями `id`, `number`, `state_id`, ...
     */
    public function createTicket(array $data): array
    {
        $event = $this->logger->begin('zammad', 'outbound', 'create_ticket');
        try {
            $payload = [
                'title' => $data['title'],
                'group' => $data['group'] ?? 'Users',
                'customer' => $data['customer_email'],
                'article' => [
                    'subject' => $data['subject'] ?? $data['title'],
                    'body' => $data['body'],
                    'type' => 'web',
                    'internal' => false,
                ],
                'priority' => $data['priority'] ?? '2 normal',
                'state' => $data['state'] ?? 'new',
            ];
            $res = $this->client()->post('/api/v1/tickets', $payload)->throw()->json();
            $this->logger->finish($event, 'success',
                "Тикет #{$res['id']} ({$res['number']}) создан",
                $res, (string) $res['id']);
            return $res;
        } catch (Throwable $e) {
            $this->logger->finish($event, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Добавить ответ к существующему тикету.
     *
     * @return array
     */
    public function addArticle(int $ticketId, string $body, bool $internal = false, string $type = 'note'): array
    {
        $event = $this->logger->begin('zammad', 'outbound', 'add_article',
            null, null, (string) $ticketId);
        try {
            $payload = [
                'ticket_id' => $ticketId,
                'body' => $body,
                'type' => $type,
                'internal' => $internal,
            ];
            $res = $this->client()->post('/api/v1/ticket_articles', $payload)->throw()->json();
            $this->logger->finish($event, 'success',
                "Сообщение добавлено в тикет #{$ticketId}", $res, (string) $ticketId);
            return $res;
        } catch (Throwable $e) {
            $this->logger->finish($event, 'error', $e->getMessage(), null, (string) $ticketId);
            throw $e;
        }
    }

    public function getTicket(int $ticketId): array
    {
        return $this->client()->get("/api/v1/tickets/{$ticketId}")->throw()->json();
    }

    /**
     * Поиск тикетов по строке (full-text + Lucene-syntax поддерживается).
     */
    public function searchTickets(string $query, int $limit = 25): array
    {
        return $this->client()
            ->get('/api/v1/tickets/search', ['query' => $query, 'limit' => $limit])
            ->throw()->json();
    }

    private function client(): PendingRequest
    {
        $base = rtrim((string) $this->settings->get('zammad.base_url'), '/');
        $token = (string) $this->settings->get('zammad.token');
        return Http::baseUrl($base)
            ->withToken($token)
            ->timeout(10)
            ->acceptJson();
    }
}
