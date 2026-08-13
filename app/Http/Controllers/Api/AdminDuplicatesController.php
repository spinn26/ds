<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClientMergeService;
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
    public function __construct(
        private readonly PartnerMergeService $merge,
        private readonly ClientMergeService $clientMerge,
    ) {}

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
            ->leftJoin('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
            ->selectRaw("$groupExpr as grp, c.id, c.\"personName\", c.activity, c.\"webUser\",
                c.\"participantCode\", c.\"inviterName\", c.\"dateCreated\", c.\"dateActivity\",
                c.\"dateDeactivity\", c.acceptance, c.\"terminationCount\",
                c.\"personalVolume\", c.\"groupVolumeCumulative\",
                sl.title as status_title, sl.level as status_level,
                coalesce(w.email, c.email) as email, coalesce(w.phone, c.phone) as phone,
                w.last_seen_at")
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
                'dateActivity' => $r->dateActivity,
                'dateDeactivity' => $r->dateDeactivity,
                'lastSeenAt' => $r->last_seen_at,
                'acceptance' => (bool) $r->acceptance,
                'terminationCount' => (int) ($r->terminationCount ?? 0),
                'personalVolume' => round((float) ($r->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($r->groupVolumeCumulative ?? 0), 2),
                'statusName' => $r->status_title
                    ? $r->status_level.' ['.$r->status_title.']'
                    : null,
            ] + ($metrics[$r->id] ?? self::EMPTY_METRICS))->values(),
        ])->values();

        return response()->json(['data' => $groups, 'total' => $groups->count()]);
    }

    private const EMPTY_METRICS = [
        'contracts' => 0, 'clients' => 0, 'downline' => 0,
        'commissions' => 0, 'commissionsSum' => 0.0, 'remaining' => 0.0,
        'accrued' => 0.0, 'payed' => 0.0, 'qualLogs' => 0, 'isClient' => false,
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
        // Деньги показываем тремя числами: начислено за всю историю, выплачено
        // и остаток. Оператору важно видеть не только «есть остаток», но и был
        // ли по записи оборот вообще — клон без начислений сливается спокойно.
        $balance = DB::table('consultantBalance')->whereIn('consultant', $ids)
            ->select('consultant',
                DB::raw('sum(coalesce("accruedTotal",0)) as accrued'),
                DB::raw('sum(coalesce(payed,0)) as payed'),
                DB::raw('sum(coalesce(remaining,0)) as remaining'))
            ->groupBy('consultant')->get()->keyBy('consultant');

        $commissionsSum = DB::table('commission')->whereIn('consultant', $ids)->whereNull('deletedAt')
            ->select('consultant', DB::raw('sum(coalesce(amount,0)) as s'))
            ->groupBy('consultant')->pluck('s', 'consultant');

        // Глубина истории: сколько периодов записи насчитано квалификаций.
        $qualLogs = $count('qualificationLog', 'consultant', 'dateDeleted');

        // Является ли партнёр ещё и клиентом (явная связь, а не общий person).
        $asClient = DB::table('client')->whereIn('partner_consultant_id', $ids)
            ->whereNull('dateDeleted')->pluck('partner_consultant_id')->unique()->flip();

        $out = [];
        foreach ($ids as $id) {
            $b = $balance[$id] ?? null;
            $out[$id] = [
                'contracts' => (int) ($contracts[$id] ?? 0),
                'clients' => (int) ($clients[$id] ?? 0),
                'downline' => (int) ($downline[$id] ?? 0),
                'commissions' => (int) ($commissions[$id] ?? 0),
                'commissionsSum' => round((float) ($commissionsSum[$id] ?? 0), 2),
                'qualLogs' => (int) ($qualLogs[$id] ?? 0),
                'accrued' => round((float) ($b->accrued ?? 0), 2),
                'payed' => round((float) ($b->payed ?? 0), 2),
                'remaining' => round((float) ($b->remaining ?? 0), 2),
                'isClient' => isset($asClient[$id]),
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

    /**
     * Группы дублей КЛИЕНТОВ: по точному ФИО, телефону (последние 10 цифр) или
     * почте. По каждой карточке — чем она отличается от соседней: партнёр,
     * контакты, контракты и деньги по ним, дата заведения.
     *
     * ⚠ Группировка по телефону и почте — сигнал «посмотрите», а не список к
     * слиянию: один номер и одна почта сплошь общие у семьи (родитель
     * оформляет полисы на детей). Из 239 таких групп настоящими дублями в
     * августе оказались семь.
     */
    public function clients(Request $request): JsonResponse
    {
        $by = in_array($request->input('by'), ['phone', 'email'], true) ? $request->input('by') : 'fio';

        // Разные алиасы во внешнем запросе и в подзапросе: одинаковые сделали бы
        // подзапрос коррелирующим, и `having count(*) > 1` стало бы истиной для
        // всех (грабля с партнёрами, 12.08.2026).
        $expr = fn (string $a) => match ($by) {
            'phone' => "right(regexp_replace(coalesce({$a}.phone,''), '[^0-9]', '', 'g'), 10)",
            'email' => "btrim(lower({$a}.email))",
            default => "btrim(lower(regexp_replace({$a}.\"personName\", '\s+', ' ', 'g')))",
        };
        $groupExpr = $expr('cl');
        $subExpr = $expr('cl2');
        $minLen = $by === 'phone' ? 10 : 3;

        $rows = DB::table('client as cl')
            ->leftJoin('consultant as c', 'c.id', '=', 'cl.consultant')
            ->whereNull('cl.dateDeleted')
            ->whereRaw("length(coalesce({$groupExpr}, '')) >= ?", [$minLen])
            ->whereRaw("{$groupExpr} in (
                select {$subExpr} from client as cl2
                where cl2.\"dateDeleted\" is null and length(coalesce({$subExpr}, '')) >= ?
                group by 1 having count(*) > 1
            )", [$minLen])
            ->selectRaw("{$groupExpr} as grp, cl.id, cl.\"personName\", cl.email, cl.phone,
                cl.\"birthDate\", cl.city, cl.\"dateCreated\", cl.comment,
                cl.partner_consultant_id, cl.consultant as consultant_id,
                c.\"personName\" as consultant_name")
            ->orderByRaw("{$groupExpr}, cl.id")
            ->limit(1000)
            ->get();

        $metrics = $this->clientMetrics($rows->pluck('id')->all());

        $groups = $rows->groupBy('grp')->map(fn ($items, $key) => [
            'key' => (string) $key,
            // Один ли это человек: при группировке по контакту ФИО могут
            // разойтись — тогда это семья, а не дубль.
            'sameName' => $items->pluck('personName')
                ->map(fn ($n) => mb_strtolower(trim((string) $n)))->unique()->count() === 1,
            'records' => $items->map(fn ($r) => [
                'id' => $r->id,
                'personName' => $r->personName,
                'email' => $r->email,
                'phone' => $r->phone,
                'birthDate' => $r->birthDate,
                'city' => $r->city,
                'comment' => $r->comment,
                'dateCreated' => $r->dateCreated,
                'consultantId' => $r->consultant_id,
                'consultantName' => $r->consultant_name,
                'isPartner' => $r->partner_consultant_id !== null,
            ] + ($metrics[$r->id] ?? self::EMPTY_CLIENT_METRICS))->values(),
        ])->values();

        return response()->json(['data' => $groups, 'total' => $groups->count()]);
    }

    private const EMPTY_CLIENT_METRICS = [
        'contracts' => 0, 'contractsSum' => 0.0, 'transactions' => 0,
        'dsIncome' => 0.0, 'lastContractAt' => null,
    ];

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    private function clientMetrics(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $contracts = DB::table('contract')->whereIn('client', $ids)->whereNull('deletedAt')
            ->select('client', DB::raw('count(*) as cnt'), DB::raw('max("createDate") as last_at'))
            ->groupBy('client')->get()->keyBy('client');

        // Деньги по контрактам карточки: сумма транзакций и доход ДС. По ним
        // видно, какая из карточек «настоящая», а какая пустышка.
        $money = DB::table('transaction as t')
            ->join('contract as c', 'c.id', '=', 't.contract')
            ->whereIn('c.client', $ids)->whereNull('c.deletedAt')
            ->select('c.client',
                DB::raw('count(*) as cnt'),
                DB::raw('sum(coalesce(t."amountRUB",0)) as amount'),
                DB::raw('sum(coalesce(t."commissionsAmountRUB",0)) as ds'))
            ->groupBy('c.client')->get()->keyBy('client');

        $out = [];
        foreach ($ids as $id) {
            $c = $contracts[$id] ?? null;
            $m = $money[$id] ?? null;
            $out[$id] = [
                'contracts' => (int) ($c->cnt ?? 0),
                'lastContractAt' => $c->last_at ?? null,
                'transactions' => (int) ($m->cnt ?? 0),
                'contractsSum' => round((float) ($m->amount ?? 0), 2),
                'dsIncome' => round((float) ($m->ds ?? 0), 2),
            ];
        }

        return $out;
    }

    /** Слияние карточек клиента: from → to. Без apply — предпросмотр. */
    public function mergeClients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'integer', 'exists:client,id'],
            'to' => ['required', 'integer', 'exists:client,id', 'different:from'],
            'apply' => ['nullable', 'boolean'],
        ]);

        $res = $this->clientMerge->merge((int) $data['from'], (int) $data['to'], (bool) ($data['apply'] ?? false));

        return response()->json($res, $res['ok'] ? 200 : 422);
    }
}
