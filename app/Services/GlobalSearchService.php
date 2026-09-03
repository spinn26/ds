<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сквозной поиск для верхней панели: партнёр, клиент, контракт — из любого
 * места платформы, без захода в нужный раздел.
 *
 * Бэкофис весь день кого-то ищет, а до этого поиска приходилось сперва
 * открыть «Партнёров» или «Менеджер контрактов» и только потом набирать. Это
 * НЕ поиск по пунктам меню (тот живёт в MainLayout) — здесь ищутся данные.
 *
 * ⚠ Разделы фильтруются по правам: сотрудник, которому не выдали клиентов,
 * не должен находить их через поиск в обход меню.
 */
class GlobalSearchService
{
    /** Сколько строк отдаём на раздел: панель — не полноценный список. */
    private const PER_SECTION = 5;

    /** Короче двух символов не ищем: выдача была бы случайной. */
    public const MIN_QUERY = 2;

    public function __construct(
        private readonly PermissionResolverService $permissions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(string $query, User $user): array
    {
        $q = trim($query);

        if (mb_strlen($q) < self::MIN_QUERY) {
            return ['query' => $q, 'groups' => [], 'total' => 0];
        }

        $roles = $user->getRolesArray();
        $like = '%' . $q . '%';
        // Чисто числовой запрос — скорее всего ID или номер, ищем и по ним.
        $asId = ctype_digit($q) ? (int) $q : null;

        $groups = [];

        if ($this->permissions->canView($roles, 'partners')) {
            $groups[] = $this->group('Партнёры', 'mdi-account-search', $this->partners($like, $asId));
        }
        if ($this->permissions->canView($roles, 'clients')) {
            $groups[] = $this->group('Клиенты', 'mdi-account-group', $this->clients($like, $asId));
        }
        if ($this->permissions->canView($roles, 'contracts')) {
            $groups[] = $this->group('Контракты', 'mdi-file-document-outline', $this->contracts($like, $asId));
        }

        $out = [];
        $total = 0;
        foreach ($groups as $group) {
            if ($group['items'] === []) {
                continue;
            }
            $out[] = $group;
            $total += count($group['items']);
        }

        return ['query' => $q, 'groups' => $out, 'total' => $total];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{title: string, icon: string, items: array<int, array<string, mixed>>}
     */
    private function group(string $title, string $icon, array $items): array
    {
        return ['title' => $title, 'icon' => $icon, 'items' => $items];
    }

    /** @return array<int, array<string, mixed>> */
    private function partners(string $like, ?int $asId): array
    {
        // Подпись статуса берём из энума, а не join'ом: справочной таблицы
        // activityStatus в базе нет, названия живут в PartnerActivity.
        return DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where(function ($w) use ($like, $asId) {
                $w->where('personName', 'ilike', $like)
                    ->orWhere('participantCode', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like);
                if ($asId !== null) $w->orWhere('id', $asId);
            })
            ->orderBy('personName')
            ->limit(self::PER_SECTION)
            ->get(['id', 'personName', 'activity'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'title' => $r->personName ?: ('Партнёр #' . $r->id),
                'subtitle' => '#' . $r->id . ' · '
                    . (\App\Enums\PartnerActivity::tryFrom((int) $r->activity)?->label() ?? '—'),
                'path' => '/manage/partners?search=' . rawurlencode((string) $r->personName),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function clients(string $like, ?int $asId): array
    {
        return DB::table('client')
            ->where(function ($w) use ($like, $asId) {
                $w->where('personName', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like);
                if ($asId !== null) $w->orWhere('id', $asId);
            })
            ->orderBy('personName')
            ->limit(self::PER_SECTION)
            ->get(['id', 'personName', 'consultantName'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'title' => $r->personName ?: ('Клиент #' . $r->id),
                'subtitle' => trim('#' . $r->id . ($r->consultantName ? ' · ' . $r->consultantName : '')),
                'path' => '/manage/clients?search=' . rawurlencode((string) $r->personName),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function contracts(string $like, ?int $asId): array
    {
        return DB::table('contract')
            ->whereNull('deletedAt')
            ->where(function ($w) use ($like, $asId) {
                $w->where('number', 'ilike', $like)
                    ->orWhere('clientName', 'ilike', $like);
                if ($asId !== null) $w->orWhere('id', $asId);
            })
            ->orderByDesc('createDate')
            ->limit(self::PER_SECTION)
            ->get(['id', 'number', 'clientName', 'productName'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'title' => $r->number ?: ('Контракт #' . $r->id),
                'subtitle' => trim(($r->clientName ?: '—') . ($r->productName ? ' · ' . $r->productName : '')),
                'path' => '/manage/contracts?number=' . rawurlencode((string) $r->number),
            ])
            ->all();
    }
}
