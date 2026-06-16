<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Управление исходящими вебхуками (только admin). */
class AdminWebhookController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'webhooks' => Webhook::query()->orderByDesc('id')->get(),
            'events' => WebhookService::EVENTS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $webhook = Webhook::create($this->validateData($request));

        return response()->json(['webhook' => $webhook], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);
        $webhook->update($this->validateData($request));

        return response()->json(['webhook' => $webhook->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        Webhook::findOrFail($id)->delete();

        return response()->json(['message' => 'Вебхук удалён']);
    }

    /** POST /admin/webhooks/{id}/test — отправить тестовое событие. */
    public function test(int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);
        $delivery = WebhookService::send($webhook, 'webhook.test', ['message' => 'Тестовая доставка', 'webhookId' => $webhook->id]);

        return response()->json([
            'message' => $delivery->success ? 'Доставлено (HTTP ' . $delivery->status_code . ')' : 'Ошибка доставки',
            'delivery' => $delivery,
        ]);
    }

    /** GET /admin/webhooks/{id}/deliveries — последние доставки. */
    public function deliveries(int $id): JsonResponse
    {
        $rows = WebhookDelivery::query()->where('webhook_id', $id)
            ->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['deliveries' => $rows]);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:1000'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'max:100'],
            'secret' => ['nullable', 'string', 'max:191'],
            'active' => ['boolean'],
        ]);
        $data['active'] = (bool) ($data['active'] ?? true);
        if (empty($data['events'])) {
            $data['events'] = null;
        }

        return $data;
    }
}
