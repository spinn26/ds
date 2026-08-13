<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PartnerMergeService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Разбор дублей и кривых связок вручную — страница /admin/duplicates.
 *
 * Появилась после 12.08.2026: дубли партнёров и карточки клиентов, привязанные
 * к чужому контакту, до этого чинились скриптами. Оператору нужен инструмент,
 * где видно, что на записи висит, и можно решить по каждой паре самому.
 */
class AdminDuplicatesController extends Controller
{
    public function __construct(private readonly PartnerMergeService $merge) {}

    /**
     * Группы дублей партнёров: по точному ФИО и по телефону (последние 10 цифр,
     * канон App\Support\Phone). Для каждой записи — что на ней висит, чтобы
     * оператор видел, какую оставлять.
     */
    public function partners(Request $request): JsonResponse
    {
        $by = $request->input('by', 'fio') === 'phone' ? 'phone' : 'fio';

        // Одно и то же выражение нужно и во внешнем запросе, и в подзапросе,
        // но с РАЗНЫМИ алиасами: иначе подзапрос коррелирует с внешней строкой
        // и `having count(*) > 1` становится истиной для всех (ловил 1955
        // «групп» вместо 11).
        $expr = fn (string $c, string $w) => $by === 'phone'
            ? "right(regexp_replace(coalesce({$w}.phone,''), '[^0-9]', '', 'g'), 10)"
            : "btrim(lower({$c}.\"personName\"))";
        $groupExpr = $expr('c', 'w');
        $subExpr = $expr('c2', 'w2');

        $rows = DB::table('consultant as c')
            ->leftJoin('WebUser as w', 'w.id', '=', 'c.webUser')
            ->whereNull('c.dateDeleted')
            ->whereNotNull('c.personName')
            ->when($by === 'phone', fn ($q) => $q->whereRaw("length(regexp_replace(coalesce(w.phone,''), '[^0-9]', '', 'g')) >= 10"))
            ->whereRaw("$groupExpr in (
                select $subExpr from consultant as c2
                left join \"WebUser\" as w2 on w2.id = c2.\"webUser\"
                where c2.\"dateDeleted\" is null and c2.\"personName\" is not null
                group by 1 having count(*) > 1
            )")
            ->selectRaw("$groupExpr as grp, c.id, c.\"personName\", c.activity, c.\"webUser\",
                c.\"participantCode\", c.\"inviterName\", c.\"dateCreated\", w.email, w.phone")
            ->orderByRaw("$groupExpr, c.id")
            ->get();

        $ids = $rows->pluck('id')->all();
        $metrics = $this->partnerMetrics($ids);

        $groups = $rows->groupBy('grp')->map(fn ($items, $key) => [
            'key' => (string) $key,
            'records' => $items->map(fn ($r) => [
                'id' => $r->id,
                'personName' => $r->personName,
                'activityId' => $r->activity,
                'hasLogin' => $r->webUser !== null,
                'email' => $r->email,
                'phone' => $r->phone,
                'participantCode' => $r->participantCode,
                'inviterName' => $r->inviterName,
                'dateCreated' => $r->dateCreated,
            ] + ($metrics[$r->id] ?? self::EMPTY_METRICS))->values(),
        ])->values();

        return response()->json(['data' => $groups, 'total' => $groups->count()]);
    }

    private const EMPTY_METRICS = [
        'contracts' => 0, 'clients' => 0, 'downline' => 0,
        'commissions' => 0, 'remaining' => 0.0,
    ];

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    private function partnerMetrics(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $count = function (string $table, string $col, ?string $deletedCol) use ($ids) {
            return DB::table($table)->whereIn($col, $ids)
                ->when($deletedCol, fn ($q) => $q->whereNull($deletedCol))
                ->select($col, DB::raw('count(*) as cnt'))->groupBy($col)->pluck('cnt', $col);
        };

        $contracts = $count('contract', 'consultant', 'deletedAt');
        $clients = $count('client', 'consultant', 'dateDeleted');
        $downline = $count('consultant', 'inviter', 'dateDeleted');
        $commissions = $count('commission', 'consultant', 'deletedAt');
        $remaining = DB::table('consultantBalance')->whereIn('consultant', $ids)
            ->select('consultant', DB::raw('sum(remaining) as s'))->groupBy('consultant')->pluck('s', 'consultant');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'contracts' => (int) ($contracts[$id] ?? 0),
                'clients' => (int) ($clients[$id] ?? 0),
                'downline' => (int) ($downline[$id] ?? 0),
                'commissions' => (int) ($commissions[$id] ?? 0),
                'remaining' => round((float) ($remaining[$id] ?? 0), 2),
            ];
        }

        return $out;
    }

    /** Слияние: from → to. Без apply — предпросмотр. */
    public function mergePartners(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'integer', 'exists:consultant,id'],
            'to' => ['required', 'integer', 'exists:consultant,id', 'different:from'],
            'apply' => ['nullable', 'boolean'],
        ]);

        $res = $this->merge->merge((int) $data['from'], (int) $data['to'], (bool) ($data['apply'] ?? false));

        return response()->json($res, $res['ok'] ? 200 : 422);
    }
}
