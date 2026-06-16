<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Управление контент-страницами (только admin). */
class AdminContentPageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['pages' => ContentPage::query()->orderBy('title')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $page = ContentPage::create($this->validateData($request));

        return response()->json(['page' => $page], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $page = ContentPage::findOrFail($id);
        $page->update($this->validateData($request, $id));

        return response()->json(['page' => $page->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        ContentPage::findOrFail($id)->delete();

        return response()->json(['message' => 'Страница удалена']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('content_pages', 'slug')->ignore($id)],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'active' => ['boolean'],
        ]);
        $data['active'] = (bool) ($data['active'] ?? true);

        return $data;
    }
}
