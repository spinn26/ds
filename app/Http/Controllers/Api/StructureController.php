<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\ConsultantService;
use App\Services\XlsxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StructureController extends Controller
{
    /**
     * Потолок flat-выборки. Раньше был 500 и стоял в SQL ДО фильтрации в PHP:
     * из базы брались первые 500 по алфавиту, и «Статус = Зарегистрирован»
     * применялся уже к ним — из 101 зарегистрированного на экран попадало 18
     * (срез обрывался на «Дурниян»). Теперь точные фильтры уходят в SQL
     * (applySqlPrefilters), а потолок стоит выше размера базы и служит только
     * защитой от выгрузки всего; при его достижении отдаём truncated=true,
     * чтобы обрезка не выглядела как правда.
     */
    private const MAX_FLAT_ROWS = 5000;

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
        // Канон «кто сотрудник» — User::isStaff() (вкл. invest/finance/education),
        // а не локальный хардкод-список. Инвестор (напр. Жарков, role=invest)
        // должен видеть структуру как сотрудник, а не «свою команду».
        $isStaff = $user->isStaff();
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
                $this->applySqlPrefilters($query, $request);

                return $this->flatResponse($query, $request);
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
            $topLevel = $this->applySort($topLevel, $request);
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
            $query = Consultant::whereIn('id', $descendantIds)
                ->whereNull('dateDeleted');
            $this->applySqlPrefilters($query, $request);

            return $this->flatResponse($query, $request);
        }

        // Без фильтров → 1-я линия команды как корень дерева.
        $rows = Consultant::where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->consultantService->formatMembers($rows);
        $members = $this->applySort($members, $request);
        return response()->json(['data' => $members->values()]);
    }

    /**
     * SQL-предфильтр по диапазону фактической даты терминации.
     * Используем поле dateDeterministic — то же, что и на странице
     * «Статусы партнёров» (AdminDataController::partnerStatuses).
     * dateActivity для терминированных — это дата последней смены
     * активности (часто = регистрация), а не реальная терминация.
     *
     * activity IN (Terminated, Excluded) обязателен: для Active/Registered
     * в dateDeterministic лежат другие значения (срок активации, конец
     * годового цикла), а не дата терминации.
     */
    /**
     * Общий ответ flat-режима: добираем оставшиеся (не выразимые в SQL)
     * фильтры в PHP, сортируем и отдаём вместе с total и признаком обрезки.
     * total — размер результата ПОСЛЕ всех фильтров, чтобы на экране было
     * видно, сколько партнёров реально подошло.
     */
    private function flatResponse($query, Request $request): JsonResponse
    {
        $rows = $query->orderBy('personName')->limit(self::MAX_FLAT_ROWS)->get();
        $truncated = $rows->count() >= self::MAX_FLAT_ROWS;

        $members = $this->consultantService->formatMembers($rows);
        $members = $this->consultantService->applyFilters($members, $request->all());
        $members = $this->applySort($members, $request);

        return response()->json([
            'data' => $members->values(),
            'total' => $members->count(),
            'truncated' => $truncated,
        ]);
    }

    /**
     * SQL-фильтры, применяемые ДО лимита выборки.
     *
     * Сюда попадает всё, что выражается через колонки consultant без потерь
     * (статус) либо расширяюще (ФИО, город) — расширяющий фильтр может отдать
     * лишнее, его добьёт applyFilters в PHP, но НЕ может потерять совпадение.
     *
     * Что осознанно осталось в PHP: ЛП/ГП/НГП и квалификация. В
     * ConsultantService::formatMembers эти значения берутся из последнего
     * qualificationLog с фолбэком на колонки consultant, поэтому фильтр по
     * голой колонке отсекал бы не тех.
     */
    private function applySqlPrefilters($query, Request $request): void
    {
        // Статус активности — точное соответствие колонке. Именно из-за того,
        // что этот фильтр работал только в PHP поверх среза, «Зарегистрирован»
        // показывал 18 из 101.
        $rawActivity = $request->input('activity', $request->input('status'));
        if (filled($rawActivity)) {
            $alias = [
                'active' => PartnerActivity::Active->value,
                'registered' => PartnerActivity::Registered->value,
                'terminated' => PartnerActivity::Terminated->value,
                'excluded' => PartnerActivity::Excluded->value,
            ];
            $raw = is_array($rawActivity) ? $rawActivity : explode(',', (string) $rawActivity);
            $ids = array_values(array_filter(array_map(
                fn ($v) => is_numeric($v) ? (int) $v : ($alias[$v] ?? null),
                $raw
            ), fn ($v) => $v !== null));
            if ($ids) {
                // legacy activity=2 трактуется как «Активен» — иначе фильтр
                // «Активен» терял импортные строки Directual.
                if (in_array(PartnerActivity::Active->value, $ids, true)) {
                    $ids[] = PartnerActivity::Inactive->value;
                }
                $query->whereIn('activity', $ids);
            }
        }

        // ФИО. Части имени живут в WebUser, а fallback идёт по personName —
        // покрываем оба источника через OR, чтобы ничего не потерять.
        foreach (['last_name' => 'lastName', 'first_name' => 'firstName', 'patronymic' => 'patronymic'] as $param => $column) {
            if (! $request->filled($param)) {
                continue;
            }
            $needle = '%' . $request->input($param) . '%';
            $query->where(function ($q) use ($needle, $column) {
                $q->where('personName', 'ilike', $needle)
                    ->orWhereIn('webUser', function ($sub) use ($needle, $column) {
                        $sub->from('WebUser')->select('id')->where($column, 'ilike', $needle);
                    });
            });
        }

        // Город — собственная колонка партнёра (перенесена из person 13.08.2026).
        // Хранит и название, и legacy-код справочника, поэтому ищем по обоим.
        if ($request->filled('city')) {
            $needle = '%' . $request->input('city') . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('city', 'ilike', $needle)
                    ->orWhereIn('city', function ($sub) use ($needle) {
                        $sub->from('city')->select(DB::raw('"id"::text'))
                            ->where('cityNameRu', 'ilike', $needle);
                    });
            });
        }

        $this->applyDateRangePrefilter($query, $request);
    }

    private function applyDateRangePrefilter($query, Request $request): void
    {
        if (! $request->filled('termination_from') && ! $request->filled('termination_to')) {
            return;
        }
        $query->whereIn('activity', [
            PartnerActivity::Terminated->value,
            PartnerActivity::Excluded->value,
        ]);
        if ($request->filled('termination_from')) {
            $query->whereDate('dateDeterministic', '>=', $request->input('termination_from'));
        }
        if ($request->filled('termination_to')) {
            $query->whereDate('dateDeterministic', '<=', $request->input('termination_to'));
        }
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
        return app(\App\Services\ConsultantTreeService::class)->descendantIds($rootId);
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
        $members = $this->applySort($members, $request);

        return response()->json(['data' => $members->values()]);
    }

    /**
     * XLSX-экспорт всей структуры (или отфильтрованного подмножества).
     * Без привязки к конкретной ветке — выгружает всё дерево с учётом фильтров.
     *
     * Партнёр получает своих потомков; сотрудник — всю базу консультантов.
     */
    public function exportFiltered(Request $request): StreamedResponse
    {
        $user = $request->user();
        $userRoles = array_map('trim', explode(',', $user->role ?? ''));
        // Канон «кто сотрудник» — User::isStaff() (см. index()).
        $isStaff = $user->isStaff();
        $consultant = Consultant::where('webUser', $user->id)->first();
        $hasFilters = $this->hasActiveFilters($request);

        if ($isStaff && ! in_array('consultant', $userRoles)) {
            $query = Consultant::whereNull('dateDeleted')
                ->whereNotIn('id', $this->systemConsultantIds());
            if ($request->filled('search')) {
                $query->where('personName', 'ilike', '%' . $request->search . '%');
            }
            $this->applyDateRangePrefilter($query, $request);
            $rows = $query->orderBy('personName')->limit(5000)->get();
            $filenameBase = 'structure-all';
        } else {
            if (! $consultant) {
                abort(403);
            }
            $descendantIds = $this->descendantIds($consultant->id);
            $query = Consultant::whereIn('id', $descendantIds)->whereNull('dateDeleted');
            $this->applyDateRangePrefilter($query, $request);
            $rows = $query->orderBy('personName')->limit(5000)->get();
            $baseName = preg_replace('/[^\p{L}\d\s\-]/u', '', $consultant->personName ?? 'export');
            $filenameBase = 'structure-' . trim($baseName);
        }

        $members = $this->consultantService->formatMembers($rows);
        if ($hasFilters) {
            $members = $this->consultantService->applyFilters($members, $request->all());
        }
        $members = $this->applySort($members, $request);

        $headers = [
            'ФИО',
            'Квалификация',
            'Статус активности',
            'ЛП',
            'ГП',
            'НГП',
            'Клиенты',
            'Контракты',
            'Партнёры',
            'Город',
            'Дата рождения',
            'Дата активации',
            'Дата смены статуса',
            'Пригласитель',
        ];

        $exportRows = $members->map(fn ($m) => [
            $m['personName'] ?? null,
            $m['qualification']
                ? ($m['qualification']['level'] . ' [' . $m['qualification']['title'] . ']')
                : null,
            $m['activityName'] ?? null,
            $m['personalVolume'] ?? 0,
            $m['groupVolume'] ?? 0,
            $m['groupVolumeCumulative'] ?? 0,
            $m['clientCount'] ?? 0,
            $m['contractCount'] ?? 0,
            $m['partnersCount'] ?? 0,
            $m['city'] ?? null,
            $m['birthDate'] ?? null,
            $m['dateActivity'] ?? null,
            $m['dateDeterministic'] ?? null,
            $m['inviterName'] ?? null,
        ]);

        $filename = $filenameBase . '-' . now()->format('Y-m-d');

        return $this->xlsx->stream(
            $filename,
            'Структура',
            $headers,
            $exportRows,
            ['numericColumns' => [4, 5, 6, 7, 8, 9]],
        );
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
     * Сортировка коллекции members по одному из числовых полей.
     * Применяется ПОСЛЕ formatMembers() и applyFilters(), потому что
     * поля (personalVolume, groupVolume, …) вычисляются там.
     *
     * sort_by: lp | gp | ngp | clients | contracts | partners
     * sort_dir: desc (по умолчанию) | asc
     */
    private function applySort(Collection $members, Request $request): Collection
    {
        $sortBy = $request->input('sort_by');
        $sortDir = $request->input('sort_dir', 'desc');

        $fieldMap = [
            'lp'        => 'personalVolume',
            'gp'        => 'groupVolume',
            'ngp'       => 'groupVolumeCumulative',
            'clients'   => 'clientCount',
            'contracts' => 'contractCount',
            'partners'  => 'partnersCount',
        ];

        if (! $sortBy || ! isset($fieldMap[$sortBy])) {
            return $members;
        }

        $field = $fieldMap[$sortBy];

        return $sortDir === 'asc'
            ? $members->sortBy(fn ($m) => (float) ($m[$field] ?? 0))
            : $members->sortByDesc(fn ($m) => (float) ($m[$field] ?? 0));
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
