<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\ConsultantService;
use App\Services\XlsxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StructureController extends Controller
{
    public function __construct(
        private readonly ConsultantService $consultantService,
        private readonly XlsxExportService $xlsx,
    ) {}

    /**
     * Структура команды — 1 линия (прямые дети).
     * Расширенные фильтры: ФИО, квалификация, уровень, статус активности,
     * ЛП/ГП/НГП диапазон, город, дата рождения.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRoles = array_map('trim', explode(',', $user->role ?? ''));
        $isStaff = array_intersect($userRoles, ['admin', 'backoffice', 'support', 'head', 'calculations', 'corrections']);
        $consultant = Consultant::where('webUser', $user->id)->first();

        // Staff without consultant role → show top-level consultants (no inviter) as tree roots
        if ($isStaff && ! in_array('consultant', $userRoles)) {
            // If searching — flat search across all consultants
            if ($request->filled('search')) {
                $query = Consultant::whereNull('dateDeleted')
                    ->where('personName', 'ilike', '%' . $request->search . '%');

                $rows = $query->orderBy('personName')->limit(100)->get();
                $members = $this->consultantService->formatMembers($rows);
                // Let the service map status/activity aliases and other filters
                $members = $this->consultantService->applyFilters($members, $request->all());

                return response()->json(['data' => $members->values()]);
            }

            // No search → show top-level structure (consultants without inviter).
            // Терминированных-сирот в корне НЕ показываем: после терминации
            // их структура улетает наставнику, поэтому видеть их «висящими»
            // в верхушке нет смысла — они только засоряют дерево.
            //
            // Также скрываем системные аккаунты (role=supreme — глобальный
            // супер-юзер; «Неизвестный консультант» — служебная сущность
            // per spec ✅Бизнес-логика «Неизвестного консультанта»).
            $topLevelRows = Consultant::whereNull('dateDeleted')
                ->where(function ($q) {
                    $q->whereNull('inviter')->orWhere('inviter', 0);
                })
                ->where('activity', '!=', PartnerActivity::Terminated->value)
                ->whereNotIn('id', $this->systemConsultantIds())
                ->orderBy('personName')
                ->get();
            $topLevel = $this->consultantService->formatMembers($topLevelRows);

            $members = $this->consultantService->applyFilters($topLevel, $request->all());
            return response()->json(['data' => $members->values()]);
        }

        // Consultant → show own team
        if (! $consultant) {
            return response()->json(['data' => []]);
        }

        // Children = consultants whose inviter is this consultant
        $rows = Consultant::where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->consultantService->formatMembers($rows);

        // Apply filters
        $members = $this->consultantService->applyFilters($members, $request->all());

        return response()->json(['data' => $members->values()]);
    }

    public function children(Request $request, int $consultantId): JsonResponse
    {
        $target = Consultant::whereNull('dateDeleted')->findOrFail($consultantId);
        $this->authorize('viewTree', $target);

        $rows = Consultant::where('inviter', $consultantId)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->consultantService->formatMembers($rows);

        return response()->json(['data' => $members->values()]);
    }

    /**
     * XLSX-экспорт всей ветки от $consultantId вниз (рекурсивно).
     *
     * Формирует плоский лист со всеми descendants — чтобы наставник
     * мог выгрузить свою структуру и работать с ней в Excel
     * (фильтры, сводные, e-mail).
     */
    public function exportSubtree(Request $request, int $consultantId): StreamedResponse
    {
        $root = Consultant::whereNull('dateDeleted')->findOrFail($consultantId);
        $this->authorize('viewTree', $root);

        // Recursive CTE — соберём все id ветки с глубиной от корня.
        $treeRows = DB::select(
            'WITH RECURSIVE tree AS (
                SELECT id, 0 AS depth FROM consultant WHERE id = ?
                UNION ALL
                SELECT c.id, t.depth + 1
                FROM consultant c
                JOIN tree t ON c.inviter = t.id
                WHERE c."dateDeleted" IS NULL
            )
            SELECT id, depth FROM tree ORDER BY depth, id',
            [$consultantId],
        );
        $depthById = [];
        foreach ($treeRows as $r) {
            $depthById[$r->id] = (int) $r->depth;
        }

        $consultants = Consultant::whereIn('id', array_keys($depthById))
            ->whereNull('dateDeleted')
            ->orderBy('personName')
            ->get();
        $members = $this->consultantService->formatMembers($consultants);

        $headers = [
            'Уровень дерева',
            'ФИО',
            'Email',
            'Телефон',
            'Город',
            'Дата рождения',
            'Квалификация',
            'Статус активности',
            'ЛП накопл.',
            'ГП накопл.',
            'НГП накопл.',
            'Контрактов',
            'Клиентов',
            'Дата активации',
        ];

        $rows = $members->map(function ($m) use ($depthById) {
            return [
                $depthById[$m['id']] ?? null,
                $m['personName'] ?? null,
                $m['email'] ?? null,
                $m['phone'] ?? null,
                $m['city'] ?? null,
                $m['birthDate'] ?? null,
                $m['qualificationTitle'] ?? null,
                $m['activityName'] ?? null,
                $m['cumulativeLp'] ?? 0,
                $m['cumulativeGp'] ?? 0,
                $m['cumulativeNgp'] ?? 0,
                $m['contractCount'] ?? 0,
                $m['clientCount'] ?? 0,
                $m['dateActivity'] ?? null,
            ];
        });

        $rootName = preg_replace('/[^\p{L}\d\s\-]/u', '', $root->personName ?? "consultant-{$consultantId}");
        $filename = 'structure-' . trim($rootName) . '-' . now()->format('Y-m-d');

        return $this->xlsx->stream(
            $filename,
            'Структура ветки',
            $headers,
            $rows,
            [
                'numericColumns' => [9, 10, 11, 12, 13],
                'dateColumns' => [6, 14],
            ],
        );
    }

    /**
     * ID системных consultant'ов, которых не показываем в верхушке
     * структуры команды.
     *
     * Включает:
     *   - всех с ролью supreme (глобальный супер-юзер платформы);
     *   - «Неизвестного консультанта» — служебная сущность для сделок
     *     «с улицы» per spec ✅Бизнес-логика «Неизвестного консультанта».
     *
     * Кэшировать не стоит — список крайне маленький (1-2 строки).
     *
     * @return array<int>
     */
    private function systemConsultantIds(): array
    {
        $supremeIds = DB::table('consultant as c')
            ->join('WebUser as w', 'w.id', '=', 'c.webUser')
            ->where('w.role', 'ilike', '%supreme%')
            ->pluck('c.id')
            ->all();

        $unknownIds = DB::table('consultant')
            ->where('personName', 'ilike', 'Неизвестный консультант%')
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_map('intval', array_merge($supremeIds, $unknownIds))));
    }

    /**
     * Справочник уровней квалификации (для фильтра).
     */
    public function qualificationLevels(): JsonResponse
    {
        $levels = DB::table('status_levels')
            ->orderBy('level')
            ->get()
            ->map(fn ($l) => ['id' => $l->id, 'level' => $l->level, 'title' => $l->title]);

        return response()->json($levels);
    }

    /**
     * Справочник статусов активности (для фильтра).
     */
    public function activityStatuses(): JsonResponse
    {
        $statuses = DB::table('directory_of_activities')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        return response()->json($statuses);
    }

    /**
     * Автокомплит по городам из справочника city.
     */
    public function cities(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        // Очистка мусора: legacy-импорт CSV положил в city.cityNameRu
        // тире, пустые строки и e-mail адреса (видимо колонка съехала
        // при импорте). Фильтруем явно: исключаем '@' (email), цифры в
        // начале, тире/прочерки, слишком короткие (≤2 символа).
        $query = DB::table('city')
            ->select('id', 'cityNameRu')
            ->whereNotNull('cityNameRu')
            ->where('cityNameRu', '!~', '@')             // нет email
            ->where('cityNameRu', '!~', '^[-—–\s]+$')   // не одни тире/пробелы
            ->whereRaw('LENGTH(TRIM("cityNameRu")) >= 3') // минимум 3 символа
            ->orderBy('cityNameRu');
        if ($q !== '') {
            $query->where('cityNameRu', 'ilike', '%' . $q . '%');
        }

        return response()->json(
            $query->limit(30)->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->cityNameRu])
        );
    }
}
