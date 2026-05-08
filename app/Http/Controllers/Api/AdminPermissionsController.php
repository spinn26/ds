<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD для permission_groups — управление правами через админку
 * (см. resources/js/pages/Admin/Permissions.vue).
 *
 * Доступ: только admin (проверяется в каждом методе вручную, потому
 * что Policy/Gate система пока не настроена для этого ресурса).
 *
 * Семантика см. database/migrations/2026_05_08_000010_create_permission_groups.php
 * + resources/js/config/cabinetPermissions.js (fallback для фронта).
 */
class AdminPermissionsController extends Controller
{
    /** Допустимые уровни — должны совпадать с фронтом (cabinetPermissions.js). */
    private const LEVELS = ['view', 'edit', 'full'];

    /**
     * GET /admin/permissions/groups — список всех групп с правами.
     * Также возвращает список всех известных секций (из меню) — фронт
     * рендерит таблицу-матрицу, нужны заголовки колонок.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $groups = DB::table('permission_groups')
            ->orderBy('is_system', 'desc')   // системные — первыми
            ->orderBy('id')
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'key' => $g->key,
                'name' => $g->name,
                'description' => $g->description,
                'permissions' => is_string($g->permissions)
                    ? json_decode($g->permissions, true) ?? []
                    : ($g->permissions ?? []),
                'isSystem' => (bool) $g->is_system,
                'createdAt' => $g->created_at,
                'updatedAt' => $g->updated_at,
            ]);

        return response()->json([
            'groups' => $groups,
            'sections' => $this->knownSections(),
            'levels' => self::LEVELS,
        ]);
    }

    /**
     * POST /admin/permissions/groups — создать новую группу.
     * Ключ должен быть уникален и в lower-snake_case (используется
     * в WebUser.role и в коде).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'key' => ['required', 'string', 'min:2', 'max:50',
                     'regex:/^[a-z][a-z0-9_-]*$/',
                     'unique:permission_groups,key'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
        ]);

        $perms = $this->normalizePermissions($data['permissions'] ?? []);

        $id = DB::table('permission_groups')->insertGetId([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
            'is_system' => false,    // user-созданные группы — не системные
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Группа создана']);
    }

    /**
     * PATCH /admin/permissions/groups/{id} — обновить имя/описание/права.
     * Ключ системной группы (admin/backoffice/...) менять нельзя — иначе
     * сломается mapping на WebUser.role и isStaff().
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $row = DB::table('permission_groups')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Группа не найдена'], 404);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
        ];
        // Только для не-системных групп можно менять key.
        if (! $row->is_system) {
            $rules['key'] = ['sometimes', 'string', 'min:2', 'max:50',
                'regex:/^[a-z][a-z0-9_-]*$/',
                'unique:permission_groups,key,' . $id];
        }
        $data = $request->validate($rules);

        $update = [];
        if (isset($data['key'])) $update['key'] = $data['key'];
        if (isset($data['name'])) $update['name'] = $data['name'];
        if (array_key_exists('description', $data)) $update['description'] = $data['description'];
        if (isset($data['permissions'])) {
            $perms = $this->normalizePermissions($data['permissions']);
            $update['permissions'] = json_encode($perms, JSON_UNESCAPED_UNICODE);
        }
        $update['updated_at'] = now();

        DB::table('permission_groups')->where('id', $id)->update($update);

        return response()->json(['message' => 'Группа обновлена']);
    }

    /**
     * DELETE /admin/permissions/groups/{id}. Системные группы
     * (is_system=true) удалять нельзя — на них завязан isStaff/UI.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $row = DB::table('permission_groups')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Группа не найдена'], 404);
        }
        if ($row->is_system) {
            return response()->json([
                'message' => 'Системную группу нельзя удалить',
            ], 403);
        }

        DB::table('permission_groups')->where('id', $id)->delete();
        return response()->json(['message' => 'Группа удалена']);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user() || ! $request->user()->isAdmin()) {
            abort(403, 'Доступ запрещён');
        }
    }

    /**
     * Список всех известных «секций» — фронт использует их как названия
     * колонок в матрице. Захардкожен на бэке, чтобы UI не нужно было
     * парсить JS-файл с menuItems. Если меню расширяется — править
     * этот метод (TODO: вынести в общий config + читать из БД).
     */
    private function knownSections(): array
    {
        return [
            ['key' => 'calculator',              'label' => 'Калькулятор объёмов'],
            ['key' => 'structure',               'label' => 'Структура'],
            ['key' => 'partners',                'label' => 'Партнёры'],
            ['key' => 'statuses',                'label' => 'Статусы партнёров'],
            ['key' => 'clients',                 'label' => 'Клиенты'],
            ['key' => 'contracts',               'label' => 'Менеджер контрактов'],
            ['key' => 'upload',                  'label' => 'Загрузка контрактов'],
            ['key' => 'acceptance',              'label' => 'Акцепт документов'],
            ['key' => 'requisites',              'label' => 'Реквизиты'],
            ['key' => 'transfers',               'label' => 'Перестановки'],
            ['key' => 'import',                  'label' => 'Импорт транзакций'],
            ['key' => 'transactions',            'label' => 'Транзакции (Manual)'],
            ['key' => 'commissions',             'label' => 'Комиссии'],
            ['key' => 'pool',                    'label' => 'Пул'],
            ['key' => 'qualifications',          'label' => 'Квалификации'],
            ['key' => 'charges',                 'label' => 'Прочие начисления'],
            ['key' => 'payments',                'label' => 'Реестр выплат'],
            ['key' => 'currencies',              'label' => 'Валюты и НДС'],
            ['key' => 'products',                'label' => 'Продукты / Инструкции'],
            ['key' => 'education',               'label' => 'Конструктор курсов'],
            ['key' => 'education-analytics',     'label' => 'Статистика обучения'],
            ['key' => 'partner-questionnaires',  'label' => 'Анкеты партнёров'],
            ['key' => 'communication',           'label' => 'Чат / Тикеты'],
            ['key' => 'support-desk',            'label' => 'Тех. поддержка (desk)'],
            ['key' => 'chat-analytics',          'label' => 'Аналитика чата'],
            ['key' => 'reports',                 'label' => 'Отчёты'],
            ['key' => 'owner-dashboard',         'label' => 'Дашборд руководителя'],
            ['key' => 'reconciliation',          'label' => 'Реконсиляция'],
            ['key' => 'anomalies',               'label' => 'Аномалии'],
            ['key' => 'funnel',                  'label' => 'Воронка партнёров'],
            ['key' => 'cohorts',                 'label' => 'Когорты'],
        ];
    }

    /**
     * Чистим permissions: оставляем только валидные ключи + уровни,
     * пустые/невалидные значения отбрасываем (трактуем как «нет доступа»).
     */
    private function normalizePermissions(array $perms): array
    {
        $allowedSections = collect($this->knownSections())->pluck('key')->all();
        $out = [];
        foreach ($perms as $section => $level) {
            if (! in_array($section, $allowedSections, true)) continue;
            if (! in_array($level, self::LEVELS, true)) continue;
            $out[$section] = $level;
        }
        return $out;
    }
}
