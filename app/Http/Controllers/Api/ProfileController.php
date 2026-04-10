<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('person', $user->id)->first();

        $country = $user->taxResidency
            ? DB::table('country')->where('id', $user->taxResidency)->value('countryNameRu')
            : null;

        $city = $user->city
            ? DB::table('city')->where('id', $user->city)->value('cityNameRu')
            : null;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'firstName' => $user->firstName,
                'lastName' => $user->lastName,
                'patronymic' => $user->patronymic,
                'phone' => $user->phone,
                'nicTG' => $user->nicTG,
                'gender' => $user->gender,
                'birthDate' => $user->birthDate,
                'role' => $user->role,
            ],
            'location' => [
                'taxResidency' => $country,
                'city' => $city,
            ],
            'consultant' => $consultant ? [
                'id' => $consultant->id,
                'personName' => $consultant->personName,
                'participantCode' => $consultant->participantCode,
                'active' => $consultant->active,
                'dateCreated' => $consultant->dateCreated,
                'inviterName' => $consultant->inviterName,
            ] : null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'phone' => 'nullable|string|max:50',
            'nicTG' => 'nullable|string|max:100',
            'gender' => 'nullable|string',
            'birthDate' => 'nullable|date',
        ]);

        $user->phone = $request->input('phone', $user->phone);
        $user->nicTG = $request->input('nicTG', $user->nicTG);
        $user->gender = $request->input('gender', $user->gender);
        $user->birthDate = $request->input('birthDate', $user->birthDate);
        $user->saveQuietly();

        return response()->json(['message' => 'Профиль обновлён']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password) && $user->password !== md5($request->current_password)) {
            return response()->json(['message' => 'Текущий пароль неверен'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->saveQuietly();

        return response()->json(['message' => 'Пароль изменён']);
    }
}
