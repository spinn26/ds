<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('person', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Client::where('consultant', $consultant->id);

        // Filters
        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('active')) {
            $query->where('active', $request->active === 'true');
        }

        $total = $query->count();

        $clients = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'personName' => $c->personName,
                'active' => $c->active,
                'source' => $c->source,
                'comment' => $c->comment,
                'dateCreated' => $c->dateCreated?->format('d.m.Y'),
                'consultantName' => $consultant->personName,
            ]);

        return response()->json(['data' => $clients, 'total' => $total]);
    }
}
