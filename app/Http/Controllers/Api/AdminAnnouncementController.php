<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Управление системными объявлениями (только admin).
 */
class AdminAnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'announcements' => Announcement::query()->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $a = Announcement::create($this->validateData($request));

        return response()->json(['announcement' => $a], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $a = Announcement::findOrFail($id);
        $a->update($this->validateData($request));

        return response()->json(['announcement' => $a->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        Announcement::findOrFail($id)->delete();

        return response()->json(['message' => 'Объявление удалено']);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['info', 'warning', 'success', 'error'])],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:64'],
            'active' => ['boolean'],
            'dismissible' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);
        $data['active'] = (bool) ($data['active'] ?? true);
        $data['dismissible'] = (bool) ($data['dismissible'] ?? true);
        if (empty($data['roles'])) {
            $data['roles'] = null;
        }

        return $data;
    }
}
