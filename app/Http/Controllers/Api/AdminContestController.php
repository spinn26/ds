<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreContestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminContestController extends Controller
{
    use PaginatesRequests;

    /**
     * Paginated list of contests for admin screen.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('Contest')->orderByDesc('start');

        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ilike', $s)
                  ->orWhere('description', 'ilike', $s);
            });
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', (int) $request->input('type'));
        }

        $total = $query->count();

        $rows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        $typeNames = DB::table('type_contest')->pluck('type', 'id');
        $statusNames = DB::table('status_contest')->pluck('name', 'id');

        $items = $rows->map(fn ($c) => $this->shape($c, $typeNames, $statusNames));

        return response()->json([
            'contests' => $items,
            'total' => $total,
        ]);
    }

    /**
     * Reference data for contest create/edit form.
     */
    public function references(): JsonResponse
    {
        return response()->json([
            'types' => DB::table('type_contest')->orderBy('id')->get(['id', 'type'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->type]),
            'statuses' => DB::table('status_contest')->orderBy('id')->get(['id', 'name']),
            'criteria' => DB::table('criterion')->whereNull('delete')->orderBy('name')
                ->get(['id', 'name']),
            'products' => DB::table('product')->where('active', true)->orderBy('name')
                ->get(['id', 'name']),
            'programs' => DB::table('program')->orderBy('name')->get(['id', 'name', 'product']),
        ]);
    }

    public function store(StoreContestRequest $request): JsonResponse
    {
        $payload = $this->payload($request);
        $payload['createdAt'] = now();
        $payload['updatedAt'] = now();
        $payload['webUser'] = $request->user()->id;

        $id = DB::table('Contest')->insertGetId($payload);

        return response()->json(['id' => $id], 201);
    }

    public function update(StoreContestRequest $request, int $id): JsonResponse
    {
        $exists = DB::table('Contest')->where('id', $id)->exists();
        if (! $exists) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $payload = $this->payload($request);
        $payload['updatedAt'] = now();

        DB::table('Contest')->where('id', $id)->update($payload);

        return response()->json(['id' => $id]);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = DB::table('Contest')->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Extract whitelisted Contest columns from the request.
     */
    private function payload(StoreContestRequest $request): array
    {
        $cols = [
            'name', 'description', 'type', 'status', 'typeEvent',
            'start', 'end', 'resultsPublicationDate', 'archiveDate',
            'numberOfWinners', 'criterion', 'product', 'program',
            'numericValue', 'nameNumericValue', 'presentation',
            'visibility', 'visibilityConsultants', 'visibilityResidents',
            'conditionalTurnOn', 'urlData', 'headers', 'techComment',
        ];

        $out = [];
        foreach ($cols as $c) {
            if ($request->has($c)) {
                $out[$c] = $request->input($c);
            }
        }

        return $out;
    }

    private function shape(object $c, $typeNames, $statusNames): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'type' => $c->type,
            'typeName' => $c->type ? ($typeNames[$c->type] ?? null) : null,
            'status' => (int) $c->status,
            'statusName' => $c->status ? ($statusNames[$c->status] ?? null) : null,
            'typeEvent' => $c->typeEvent,
            'start' => $c->start,
            'end' => $c->end,
            'resultsPublicationDate' => $c->resultsPublicationDate,
            'archiveDate' => $c->archiveDate,
            'numberOfWinners' => $c->numberOfWinners,
            'criterion' => $c->criterion,
            'product' => $c->product,
            'program' => $c->program,
            'numericValue' => $c->numericValue,
            'nameNumericValue' => $c->nameNumericValue,
            'presentation' => $c->presentation,
            'visibility' => $c->visibility,
            'visibilityConsultants' => (bool) $c->visibilityConsultants,
            'visibilityResidents' => (bool) $c->visibilityResidents,
            'conditionalTurnOn' => (bool) $c->conditionalTurnOn,
            'urlData' => $c->urlData,
            'headers' => $c->headers,
            'techComment' => $c->techComment,
        ];
    }
}
