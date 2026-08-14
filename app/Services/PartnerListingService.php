<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Список партнёров для /admin/partners: фильтры и сборка строк.
 *
 * Вынесено из AdminDataController (метод занимал 146 строк). Контроллеру
 * оставлены только его дела: разбор запроса, сортировка, пагинация, ответ.
 *
 * Разделение на два шага не косметическое: `total` считается по
 * ОТФИЛЬТРОВАННОМУ запросу до пагинации, а сборка строк работает уже со
 * страницей. Поэтому query() отдаёт билдер, а не готовые данные.
 */
class PartnerListingService
{
    /**
     * Ключи фильтров, которые понимает список. Контроллер отбирает из запроса
     * только заполненные (filled) — семантика «пустое значение = фильтр не
     * применён» остаётся на HTTP-слое, где ей и место.
     *
     * @var list<string>
     */
    public const FILTERS = [
        'search', 'activity', 'active', 'partner_id', 'inviter_name', 'email', 'phone',
    ];

    /**
     * Нормализация телефона в SQL: обе стороны сравнения обязаны быть цифрами.
     * Отформатированный номер в колонке («+7 (911) 111-11-11») иначе не
     * совпадает с очищенным вводом — на этом поиск по телефону не находил
     * партнёров с логином.
     */
    private const PHONE_DIGITS = "regexp_replace(coalesce(%s, ''), '[^0-9]', '', 'g')";

    /**
     * Запрос со всеми применёнными фильтрами, без сортировки и пагинации.
     *
     * @param array<string, mixed> $filters только заполненные значения
     */
    public function query(array $filters): Builder
    {
        $query = Consultant::query()->whereNull('dateDeleted');

        if (isset($filters['search'])) {
            $query->where('personName', 'ilike', '%' . $filters['search'] . '%');
        }
        if (isset($filters['activity'])) {
            $query->where('activity', $filters['activity']);
        }
        if (isset($filters['active'])) {
            // ⚠ Сравнение со СТРОКОЙ 'true', а не булево приведение: фронт шлёт
            // именно строку, и любое «умное» приведение поменяло бы выдачу.
            $query->where('active', $filters['active'] === 'true');
        }
        // Доп. фильтры per spec ✅Партнёры §1.1
        if (isset($filters['partner_id'])) {
            $query->where('id', (int) $filters['partner_id']);
        }
        if (isset($filters['inviter_name'])) {
            $query->where('inviterName', 'ilike', '%' . $filters['inviter_name'] . '%');
        }
        if (isset($filters['email'])) {
            // Контакты живут на WebUser (у кого есть логин) и в собственных
            // колонках партнёра — person из поиска убран (2026-08-12): часть
            // указателей вела на другого человека, и фильтр находил чужих.
            $emailLike = '%' . $filters['email'] . '%';
            $query->where(function ($q) use ($emailLike) {
                $q->whereIn('webUser', function ($sub) use ($emailLike) {
                    $sub->select('id')->from('WebUser')->where('email', 'ilike', $emailLike);
                })->orWhere('email', 'ilike', $emailLike);
            });
        }
        if (isset($filters['phone'])) {
            $phoneLike = '%' . preg_replace('/\D/', '', (string) $filters['phone']) . '%';
            $query->where(function ($q) use ($phoneLike) {
                $q->whereIn('webUser', function ($sub) use ($phoneLike) {
                    $sub->select('id')->from('WebUser')
                        ->whereRaw(sprintf(self::PHONE_DIGITS, '"phone"') . ' ilike ?', [$phoneLike]);
                })->orWhereRaw(sprintf(self::PHONE_DIGITS, 'phone') . ' ilike ?', [$phoneLike]);
            });
        }

        return $query;
    }

    /**
     * Строки страницы → массив для ответа. Связанные данные подгружаются
     * ПАЧКАМИ: запрос на страницу, а не на строку.
     *
     * Дженерики в аннотации намеренно не указаны: сюда приходит
     * Eloquent\Collection моделей, а наружу уходит коллекция массивов —
     * сужение типов дало бы ложные ошибки анализатора без пользы.
     */
    public function present(Collection $rows): Collection
    {
        $webUserIds = $rows->pluck('webUser')->filter()->unique();
        $webUsers = $webUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webUserIds)->get()->keyBy('id')
            : collect();

        // Признак «партнёр является и клиентом» — по явной связи
        // client.partner_consultant_id (заполняет clients:link-partners).
        // Прежде считался через общий person: связь оказалась неверной у 30 пар
        // и не определялась вовсе у партнёров без person.
        $partnerClients = $rows->isNotEmpty()
            ? DB::table('client')->whereIn('partner_consultant_id', $rows->pluck('id'))
                ->whereNull('dateDeleted')
                ->pluck('partner_consultant_id')->unique()->flip()
            : collect();

        $statusIds = $rows->pluck('status')->filter()->unique();
        $statusTitles = $statusIds->isNotEmpty()
            ? DB::table('status')->whereIn('id', $statusIds)->pluck('title', 'id')
            : collect();

        // Лимит самовосстановлений одинаков для всех строк — читаем один раз,
        // а не на каждую (SystemSetting кэширует, но лишний вызов ни к чему).
        $reinstateLimit = PartnerActivity::selfReinstateLimit();
        $activationDays = PartnerActivity::activationDays();

        // Замыкание, а не стрелочная функция: строка ссылается на WebUser
        // трижды, и резолвить его на каждое поле — лишняя работа.
        return $rows->map(function ($c) use (
            $webUsers, $partnerClients, $statusTitles, $reinstateLimit, $activationDays
        ) {
            $webUser = $webUsers->get($c->webUser);

            return [
                'id' => $c->id,
                'personName' => $c->personName,
                'active' => $c->active,
                'activityName' => $c->activityLabel(),
                'activityId' => $c->activity?->value,
                'statusName' => $c->status ? ($statusTitles[$c->status] ?? null) : null,
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($c->groupVolumeCumulative ?? 0), 2),
                'participantCode' => $c->participantCode,
                'dateCreated' => $c->dateCreated?->format('d.m.Y'),
                'createdAt' => $c->dateCreated?->format('d.m.Y'),
                'statusChangeDate' => $this->statusChangeDate($c, $activationDays),
                'terminationCount' => $c->terminationCount ?? 0,
                // Самовосстановления партнёра: сколько потрачено из лимита
                // и не запрещено ли ему возвращаться самому.
                'reinstatementCount' => (int) ($c->reinstatement_count ?? 0),
                'reinstateLimit' => $reinstateLimit,
                'reinstateBlocked' => (bool) ($c->reinstate_blocked ?? false),
                // WebUser важнее: он канон для владельцев логина и меняется,
                // когда партнёр правит профиль. Собственные колонки — для
                // 897 импортированных ФК без логина (перенесены из person
                // командой partners:backfill-contacts).
                'email' => $webUser->email ?? $c->email ?? null,
                'phone' => $webUser->phone ?? $c->phone ?? null,
                'birthDate' => $webUser->birthDate ?? $c->birthDate ?? null,
                'inviterName' => $c->inviterName,
                'inviterId' => $c->inviter,
                'isClient' => isset($partnerClients[$c->id]),
                'platformAccess' => $webUser && ! ($webUser->isBlocked ?? false),
            ];
        });
    }

    /**
     * «Дата смены статуса» (per spec ✅Партнеры §1.2):
     *   - Активен → +12 мес от dateActivity
     *   - Зарегистрирован → activationDeadline (его могли продлить), а расчёт
     *     от даты регистрации — только фолбэк.
     */
    private function statusChangeDate(Consultant $c, int $activationDays): ?string
    {
        $activityValue = $c->activity?->value;

        if ($activityValue == 1 && $c->dateActivity) { // Active
            return \Carbon\Carbon::parse($c->dateActivity)->addYear()->format('Y-m-d');
        }

        if ($activityValue == 4) { // Registered
            if ($c->activationDeadline) {
                return \Carbon\Carbon::parse($c->activationDeadline)->format('Y-m-d');
            }

            return $c->dateCreated
                ? \Carbon\Carbon::parse($c->dateCreated)->addDays($activationDays)->format('Y-m-d')
                : null;
        }

        return null;
    }
}
