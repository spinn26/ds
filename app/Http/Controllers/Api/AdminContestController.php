<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AppliesSorting;
use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreContestRequest;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminContestController extends Controller
{
    use AppliesSorting;
    use PaginatesRequests;

    private function db(): ConnectionInterface
    {
        return DB::connection('pgsql_v2');
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->db()->table('contests as c')
            ->leftJoin('contest_types as ct', 'ct.id', '=', 'c.type_id')
            ->leftJoin('contest_statuses as cs', 'cs.id', '=', 'c.status_id');

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($q) => $q->where('c.name', 'ilike', $search)->orWhere('c.description', 'ilike', $search));
        }
        if ($request->filled('status')) {
            $query->where('c.status_id', $request->integer('status'));
        }
        if ($request->filled('type')) {
            $query->where('c.type_id', $request->integer('type'));
        }

        $total = (clone $query)->count('c.id');
        $this->applySorting($query, $request, [
            'name' => 'c.name',
            'typeName' => 'ct.name',
            'statusName' => 'cs.name',
            'start' => 'c.starts_on',
            'end' => 'c.ends_on',
            'numberOfWinners' => 'c.winner_count',
        ], 'c.starts_on', 'desc');

        $items = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get(['c.*', 'ct.name as type_name', 'cs.name as status_name'])
            ->map(fn ($contest) => $this->shape($contest));

        return response()->json(['contests' => $items, 'total' => $total]);
    }

    public function references(): JsonResponse
    {
        return response()->json([
            'types' => $this->db()->table('contest_types')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => $this->db()->table('contest_statuses')->where('active', true)->orderBy('id')->get(['id', 'name']),
            'criteria' => $this->db()->table('contest_criteria')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'products' => $this->db()->table('products')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'programs' => $this->db()->table('programs')->where('active', true)->orderBy('name')->get(['id', 'name', 'product_id as product']),
        ]);
    }

    public function store(StoreContestRequest $request): JsonResponse
    {
        $payload = $this->payload($request);
        $payload['created_by_user_id'] = $request->user()?->id;
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        $id = $this->db()->table('contests')->insertGetId($payload);

        return response()->json(['id' => $id], 201);
    }

    public function update(StoreContestRequest $request, int $id): JsonResponse
    {
        if (! $this->db()->table('contests')->where('id', $id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $payload = $this->payload($request);
        $payload['updated_at'] = now();
        $this->db()->table('contests')->where('id', $id)->update($payload);

        return response()->json(['id' => $id]);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->db()->table('contests')->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['ok' => true]);
    }

    private function payload(StoreContestRequest $request): array
    {
        $map = [
            'name' => 'name', 'description' => 'description', 'type' => 'type_id',
            'status' => 'status_id', 'criterion' => 'criterion_id', 'product' => 'product_id',
            'program' => 'program_id', 'typeEvent' => 'event_type', 'start' => 'starts_on',
            'end' => 'ends_on', 'resultsPublicationDate' => 'results_published_on',
            'archiveDate' => 'archived_on', 'numberOfWinners' => 'winner_count',
            'numericValue' => 'target_value', 'nameNumericValue' => 'target_label',
            'presentation' => 'presentation_url', 'visibility' => 'visibility',
            'visibilityConsultants' => 'visible_to_partners',
            'visibilityResidents' => 'visible_to_residents',
            'conditionalTurnOn' => 'conditional_activation', 'urlData' => 'data_url',
            'headers' => 'headers', 'techComment' => 'technical_comment',
        ];
        $payload = [];
        foreach ($map as $requestKey => $column) {
            if ($request->has($requestKey)) {
                $payload[$column] = $request->input($requestKey);
            }
        }
        return $payload;
    }

    private function shape(object $contest): array
    {
        return [
            'id' => $contest->id,
            'name' => $contest->name,
            'description' => $contest->description,
            'type' => $contest->type_id,
            'typeName' => $contest->type_name,
            'status' => $contest->status_id,
            'statusName' => $contest->status_name,
            'typeEvent' => $contest->event_type,
            'start' => $contest->starts_on,
            'end' => $contest->ends_on,
            'resultsPublicationDate' => $contest->results_published_on,
            'archiveDate' => $contest->archived_on,
            'numberOfWinners' => $contest->winner_count,
            'criterion' => $contest->criterion_id,
            'product' => $contest->product_id,
            'program' => $contest->program_id,
            'numericValue' => $contest->target_value,
            'nameNumericValue' => $contest->target_label,
            'presentation' => $contest->presentation_url,
            'visibility' => $contest->visibility,
            'visibilityConsultants' => (bool) $contest->visible_to_partners,
            'visibilityResidents' => (bool) $contest->visible_to_residents,
            'conditionalTurnOn' => (bool) $contest->conditional_activation,
            'urlData' => $contest->data_url,
            'headers' => $contest->headers,
            'techComment' => $contest->technical_comment,
        ];
    }
}
