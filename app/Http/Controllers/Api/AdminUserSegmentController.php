<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Сегменты партнёров (сохранённые фильтры). Доступны staff-страницам. */
class AdminUserSegmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['segments' => UserSegment::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'criteria' => ['required', 'array'],
        ]);
        $segment = UserSegment::create($data);

        return response()->json(['segment' => $segment], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        UserSegment::findOrFail($id)->delete();

        return response()->json(['message' => 'Сегмент удалён']);
    }
}
