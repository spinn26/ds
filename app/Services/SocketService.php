<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocketService
{
    private string $apiUrl;
    private string $emitSecret;

    public function __construct()
    {
        $host = env('SOCKET_HOST', '127.0.0.1');
        $port = env('SOCKET_API_PORT', 3002);
        $this->apiUrl = "http://{$host}:{$port}";
        $this->emitSecret = (string) env('SOCKET_EMIT_SECRET', '');
    }

    /**
     * Emit event to a ticket room.
     */
    public function emitToTicket(int $ticketId, string $event, array $data): void
    {
        $this->emit($event, "ticket:{$ticketId}", $data);
    }

    /**
     * Notify specific user.
     */
    public function notifyUser(int $userId, string $event, array $data): void
    {
        $this->post('/notify', [
            'userId' => (string) $userId,
            'event' => $event,
            'data' => $data,
        ]);
    }

    /**
     * Emit event to room or broadcast.
     */
    public function emit(string $event, ?string $room, array $data): void
    {
        $this->post('/emit', [
            'event' => $event,
            'room' => $room,
            'data' => $data,
        ]);
    }

    private function post(string $path, array $payload): void
    {
        try {
            Http::timeout(2)
                ->withHeaders($this->authHeaders())
                ->post($this->apiUrl . $path, $payload);
        } catch (\Exception $e) {
            // Socket server may be offline — just log, don't break the app
            Log::debug("Socket {$path} failed: " . $e->getMessage());
        }
    }

    private function authHeaders(): array
    {
        return $this->emitSecret !== ''
            ? ['Authorization' => "Bearer {$this->emitSecret}"]
            : [];
    }
}
