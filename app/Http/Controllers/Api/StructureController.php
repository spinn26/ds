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
        $hasFilters = $this->hasActiveFilters($request);

        // Staff without consultant role → top-level (no inviter) или flat-поиск
        if ($isStaff && ! in_array('consultant', $userRoles)) {
            // Если активен ЛЮБОЙ фильтр — flat search across all consultants.
            // Раньше срабатывало только на `search`, а фильтры
            // qualification/status/ЛП/ГП/НГП/город применялись поверх
            // top-level (~ корни структуры) — большинство матчей оказывалось
            // глубоко и просто не попадало в выдачу.
            if ($hasFilters) {
                $query = Consultant::whereNull('dateDeleted')
                    ->whereNotIn('id', $this->systemConsultantIds());
                if ($request->filled('search')) {
                    $query->where('personName', 'ilike', '%' . $request->search . '%');
                }
                $rows = $query->orderBy('personName')->limit(500)->get();
                $members = $this->consultantService->formatMembers($rows);
                $members = $this->consultantService->applyFilters($members, $request->all());

                return response()->json(['data' => $members->values()]);
            }

            // Нет фильтров → top-level structure (consultants без inviter).
            // Терминированных-сирот в корне НЕ показываем: после терминации
            // их структура улетает наставнику. Также скрываем системные
            // аккаунты (supreme, «Неизвестный консультант»).
            $topLevelRows = Consultant::whereNull('dateDeleted')
                ->where(function ($q) {
                    $q->whereNull('inviter')->orWhere('inviter', 0);
                })
                ->where('activity', '!=', PartnerActivity::Terminated->value)
                ->whereNotIn('id', $this->systemConsultantIds())
                ->orderBy('personName')
                ->get();
            $topLevel = $this->consultantService->formatMembers($topLevelRows);
            return response()->json(['data' => $topLevel->values()]);
        }

        // Consultant → собственное поддерево
        if (! $consultant) {
            return response()->json(['data' => []]);
        }

        // С фильтрами → flat-поиск по ВСЕМ потомкам через recursive CTE.
        // Раньше брались только прямые children (inviter=$consultant->id),
        // и фильтр применялся поверх. Если матч глубже — оператор видел
        // пустой результат и не мог развернуть. Теперь рекурсивно.
        if ($hasFilters) {
            $descendantIds = $this->descendantIds($consultant->id);
            $rows = Consultant::whereIn('id', $descendantIds)
                ->whereNull('dateDeleted')
                ->orderBy('personName')
                ->limit(500)
                ->get();
            $members = $this->consultantService->formatMembers($rows);
            $members = $this->consultantService->applyFilters($members, $request->all());
            return response()->json(['data' => $members->values()]);
        }

        // Без фильтров → 1-я линия команды как корень дерева.
        $rows = Consultant::where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->consultantService->formatMembers($rows);
        return response()->json(['data' => $members->values()]);
    }

    /**
     * Активирован ли хоть один фильтр-параметр (исключая page/limit).
     * Используется для переключения tree↔flat-режима в index/children.
     */
    private function hasActiveFilters(Request $request): bool
    {
        foreach (['search', 'last_name', 'first_name', 'patronymic',
                  'qualification', 'levels', 'status', 'activity',
                  'birth_date_from', 'birth_date_to', 'city',
                  'lp_min', 'lp_max', 'gp_min', 'gp_max', 'ngp_min', 'ngp_max',
                  'termination_from', 'termination_to'] as $key) {
            if ($request->filled($key)) return true;
        }
        return false;
    }

    /**
     * Все consultant.id ниже корня (не включая сам корень) — рекурсивно
     * через PostgreSQL CTE. Удаленные исключаются сразу, чтобы не тащить
     * orphan-ветки через soft-deleted родителя.
     *
     * @return list<int>
     */
    private function descendantIds(int $rootId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE descendants AS (
                SELECT id FROM consultant
                 WHERE inviter = ? AND "dateDeleted" IS NULL
                UNION ALL
                SELECT c.id FROM consultant c
                JOIN descendants d ON c.inviter = d.id
                WHERE c."dateDeleted" IS NULL
            )
            SELECT id FROM descendants',
            [$rootId]
        );
        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    public function children(Request $request, int $consultantId): JsonResponse
    {
        $target = Consultant::whereNull('dateDeleted')->findOrFail($consultantId);
        $this->authorize('viewTree', $target);

        $rows = Consultant::where('inviter', $consultantId)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->consultantService->formatMembers($rows);

        // Применяем те же фильтры, что и в /structure (search, qualification,
        // status, ЛП/ГП/НГП, города, даты). Без этого при разворачивании
        // ветки в дереве показывались ВСЕ потомки независимо от фильтра —
        // оператор фильтровал «Активен», но в развёртке всё равно были
        // терминированные. Frontend начал передавать filterParams() сюда.
        $members = $this->consultantService->applyFilters($members, $request->all());

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
