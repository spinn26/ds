<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocketService
{
    private string $apiUrl;

    public function __construct()
    {
        $host = env('SOCKET_HOST', '127.0.0.1');
        $port = env('SOCKET_API_PORT', 3002);
        $this->apiUrl = "http://{$host}:{$port}";
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
        try {
            Http::timeout(2)->post("{$this->apiUrl}/notify", [
                'userId' => (string) $userId,
                'event' => $event,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::debug("Socket notify failed: " . $e->getMessage());
        }
    }

    /**
     * Emit event to room or broadcast.
     */
    public function emit(string $event, ?string $room, array $data): void
    {
        try {
            Http::timeout(2)->post("{$this->apiUrl}/emit", [
                'event' => $event,
                'room' => $room,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            // Socket server may be offline — just log, don't break the app
            Log::debug("Socket emit failed: " . $e->getMessage());
        }
    }
}
