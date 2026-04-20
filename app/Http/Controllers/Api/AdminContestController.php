<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreContestRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if (! DB::table('Contest')->where('id', $id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            DB::transaction(function () use ($id) {
                // Contest sits inside a cycle: Contest.criterion → criterion.id
                // AND criterion.contest → Contest.id. A plain cascade-delete
                // trips on shared criterions — the one we'd wipe belongs to
                // another Contest too. So before the cascade, detach the
                // criterion from THIS Contest by nulling the back-ref; the
                // criterion itself keeps its other owners. Same treatment for
                // any referring table whose FK column is nullable and which
                // legitimately survives the delete (e.g. criterion).
                $this->detachBeforeCascade('Contest', 'id', [$id]);
                $this->cascadeDelete('Contest', 'id', [$id]);
                DB::table('Contest')->where('id', $id)->delete();
            });
        } catch (QueryException $e) {
            \Log::warning('Contest delete blocked by FK', [
                'contest_id' => $id,
                'sqlstate' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
            if ($e->getCode() === '23503') {
                // Pull "constraint X on table Y" out of the PG error for a
                // human-readable hint. Example from PG:
                //   update or delete on table "Contest" violates foreign key
                //   constraint "abc_contest_fkey" on table "abc"
                $hint = null;
                // PG message format:
                //   update or delete on table "Contest" violates foreign key
                //   constraint "X_fkey" on table "child"
                // Pin the match to the constraint half so we always get the
                // referencing table, not the subject of the delete.
                if (preg_match('/violates foreign key constraint "[^"]+" on table "([^"]+)"/', $e->getMessage(), $m)) {
                    $hint = "Блокирует таблица: {$m[1]}";
                }
                return response()->json([
                    'message' => 'Невозможно удалить конкурс: на него ссылаются связанные данные.',
                    'hint' => $hint,
                    'detail' => $e->getMessage(),
                ], 409);
            }
            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Recursively purge rows that reference (table, column) via FK,
     * leaf-first. Handles self-referential FKs (e.g. Contest.parentContest
     * → Contest.id) by NOT memoising on table::column — that previously
     * blocked the second hop down a self-ref chain. Instead we cap the
     * recursion depth; cycles in the graph are exceedingly rare here and
     * depth-10 is more than enough for any real Contest tree.
     *
     * @param array<int|string> $values
     */
    /**
     * Null the back-ref FK on shared child rows before we try to cascade.
     * For Contest → criterion cycle specifically: criterion can belong to
     * several Contests (Contest.criterion), so we don't want to drop the
     * row — we just say "this Contest no longer owns it".
     *
     * Only nulls FKs on tables listed below; the generic cascade handles
     * the pure-log tables (contestrating, coefficientCriterion, calculation*).
     */
    private function detachBeforeCascade(string $parentTable, string $parentColumn, array $values): void
    {
        $sharedTables = ['criterion'];
        foreach ($sharedTables as $t) {
            // Only update rows that are actually referenced from ELSEWHERE —
            // otherwise we want the cascade to delete them outright below.
            $backrefCol = $this->findFkColumn($t, $parentTable, $parentColumn);
            if (! $backrefCol) continue;

            $forwardFks = $this->tablesReferencing($t);
            if (empty($forwardFks)) {
                // Nobody else points at this child table; cascadeDelete handles it.
                continue;
            }

            // Shared criterions for this Contest = those referenced by another row.
            $ids = DB::table($t)->whereIn($backrefCol, $values)->pluck('id')->all();
            if (! $ids) continue;

            $stillReferenced = [];
            foreach ($forwardFks as [$otherTable, $otherCol]) {
                $extra = DB::table($otherTable)
                    ->whereIn($otherCol, $ids)
                    // exclude the rows we're about to delete
                    ->when(
                        $otherTable === $parentTable && $otherCol === 'criterion',
                        fn ($q) => $q->whereNotIn('id', $values)
                    )
                    ->pluck($otherCol)->all();
                foreach ($extra as $e) $stillReferenced[$e] = true;
            }
            if ($stillReferenced) {
                DB::table($t)
                    ->whereIn('id', array_keys($stillReferenced))
                    ->update([$backrefCol => null]);
            }
        }
    }

    /** Tables whose FK points at (table.id). Returns [[childTable, column], …]. */
    private function tablesReferencing(string $table): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT child.relname AS t, att.attname AS c
            FROM pg_constraint con
            JOIN pg_class parent ON parent.oid = con.confrelid
            JOIN pg_class child  ON child.oid  = con.conrelid
            JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)
            WHERE con.contype='f' AND parent.relname = ?
        SQL, [$table]);
        return array_map(fn ($r) => [$r->t, $r->c], $rows);
    }

    /** First FK column in $table pointing at $parentTable.$parentColumn. */
    private function findFkColumn(string $table, string $parentTable, string $parentColumn): ?string
    {
        $rows = DB::select(<<<'SQL'
            SELECT att.attname AS c
            FROM pg_constraint con
            JOIN pg_class parent ON parent.oid = con.confrelid
            JOIN pg_class child  ON child.oid  = con.conrelid
            JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)
            JOIN pg_attribute patt ON patt.attrelid = con.confrelid AND patt.attnum = ANY(con.confkey)
            WHERE con.contype='f'
              AND child.relname = ? AND parent.relname = ? AND patt.attname = ?
            LIMIT 1
        SQL, [$table, $parentTable, $parentColumn]);
        return $rows[0]->c ?? null;
    }

    private function cascadeDelete(string $table, string $column, array $values, int $depth = 0): void
    {
        if (empty($values) || $depth > 10) {
            return;
        }

        $refs = DB::select(<<<'SQL'
            SELECT
                child.relname AS table_name,
                att.attname   AS column_name
            FROM pg_constraint con
            JOIN pg_class      parent ON parent.oid = con.confrelid
            JOIN pg_class      child  ON child.oid  = con.conrelid
            JOIN pg_attribute  att    ON att.attrelid = con.conrelid
                                      AND att.attnum = ANY(con.conkey)
            JOIN pg_attribute  patt   ON patt.attrelid = con.confrelid
                                      AND patt.attnum = ANY(con.confkey)
            WHERE con.contype = 'f'
              AND parent.relname = ?
              AND patt.attname = ?
SQL, [$table, $column]);

        foreach ($refs as $r) {
            $rowIds = [];
            if (Schema::hasColumn($r->table_name, 'id')) {
                $rowIds = DB::table($r->table_name)
                    ->whereIn($r->column_name, $values)
                    ->pluck('id')
                    ->all();
            }
            if (! empty($rowIds)) {
                $this->cascadeDelete($r->table_name, 'id', $rowIds, $depth + 1);
            }
            DB::table($r->table_name)->whereIn($r->column_name, $values)->delete();
        }
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
