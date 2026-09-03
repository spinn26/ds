<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use App\Support\TerminationDeadline;
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
        'registered_from', 'registered_to', 'code', 'is_client', 'is_blocked',
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
            // Список активностей приходит через запятую: в окне фильтров их
            // выбирают несколько («терминированные и исключённые» — обычный
            // запрос бэкофиса). Одно значение продолжает работать как раньше.
            // Значения остаются СТРОКАМИ: так их сравнивал прежний where(),
            // и приведение к int поменяло бы тип сравнения в Postgres.
            $ids = array_values(array_filter(
                array_map('trim', explode(',', (string) $filters['activity'])),
                static fn ($v) => $v !== ''
            ));
            if (count($ids) > 1) {
                $query->whereIn('activity', $ids);
            } else {
                $query->where('activity', $filters['activity']);
            }
        }
        if (isset($filters['code'])) {
            $query->where('participantCode', 'ilike', '%' . $filters['code'] . '%');
        }
        // Признак «клиент» — тот же, что в present(): есть живая запись в
        // client с этим партнёром. Считаем через exists, а не подгрузкой:
        // фильтр должен работать на всей таблице, а не на странице.
        if (isset($filters['is_client'])) {
            $query->whereExists(static function ($sub) {
                $sub->selectRaw('1')->from('client')
                    ->whereColumn('client.partner_consultant_id', 'consultant.id')
                    ->whereNull('client.dateDeleted');
            });
        }
        // «Заблокирован» — именно закрытый вход, а НЕ отсутствие логина:
        // у 897 импортированных партнёров WebUser нет вовсе, и закрывать
        // им нечего (см. поле isBlocked в present()).
        if (isset($filters['is_blocked'])) {
            $query->whereIn('webUser', static function ($sub) {
                $sub->select('id')->from('WebUser')->where('isBlocked', true);
            });
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
        // Диапазон регистрации. `dateCreated` — timestamp, поэтому сравниваем
        // по дате: иначе фильтр «по 30.08» терял всех, кто зарегистрировался
        // в этот день позже полуночи.
        if (isset($filters['registered_from'])) {
            $query->whereDate('dateCreated', '>=', $filters['registered_from']);
        }
        if (isset($filters['registered_to'])) {
            $query->whereDate('dateCreated', '<=', $filters['registered_to']);
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
        // Считаем клиентов, а не просто наличие: список показывает их числом,
        // а признак «партнёр является клиентом» — это тот же ответ > 0.
        $ids = $rows->pluck('id');
        $clientCounts = $ids->isNotEmpty()
            ? DB::table('client')->whereIn('partner_consultant_id', $ids)
                ->whereNull('dateDeleted')
                ->groupBy('partner_consultant_id')
                ->selectRaw('partner_consultant_id, count(*) as n')
                ->pluck('n', 'partner_consultant_id')
            : collect();

        // Показатели строки: квалификация с ГП и пул. DISTINCT ON берёт
        // последнюю запись журнала по каждому партнёру — один запрос на
        // страницу вместо запроса на строку.
        $quals = $ids->isNotEmpty()
            ? DB::table('qualificationLog')
                ->selectRaw('distinct on (consultant) consultant, "levelNew", "nominalLevel",'
                    . ' "calculationLevel", "groupVolume"')
                ->whereIn('consultant', $ids)
                ->whereNull('dateDeleted')
                ->orderBy('consultant')
                ->orderByDesc('date')
                ->get()->keyBy('consultant')
            : collect();

        $pools = $ids->isNotEmpty()
            ? DB::table('poolLog')
                ->selectRaw('distinct on (consultant) consultant, "poolBonus"')
                ->whereIn('consultant', $ids)
                ->orderBy('consultant')
                ->orderByDesc('date')
                ->get()->keyBy('consultant')
            : collect();

        $contractCounts = $ids->isNotEmpty()
            ? DB::table('contract')->whereIn('consultant', $ids)
                ->whereNull('deletedAt')
                ->groupBy('consultant')
                ->selectRaw('consultant, count(*) as n')
                ->pluck('n', 'consultant')
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
            $webUsers, $clientCounts, $statusTitles, $reinstateLimit, $activationDays,
            $quals, $pools, $contractCounts
        ) {
            $webUser = $webUsers->get($c->webUser);
            $qual = $quals->get($c->id);
            $pool = $pools->get($c->id);

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
                // Квалификация: levelNew заполнен не везде, поэтому спускаемся
                // к номинальному и расчётному уровню.
                'qualLevel' => $qual->levelNew ?? $qual->nominalLevel ?? $qual->calculationLevel ?? null,
                'groupVolume' => isset($qual->groupVolume) ? round((float) $qual->groupVolume, 2) : null,
                'poolBonus' => isset($pool->poolBonus) ? round((float) $pool->poolBonus, 2) : null,
                'contractsCount' => (int) ($contractCounts[$c->id] ?? 0),
                'clientsCount' => (int) ($clientCounts[$c->id] ?? 0),
                // dateLastActivity в базе пуст у всех — живой признак только этот.
                'lastSeenAt' => $webUser->last_seen_at ?? null,
                'isClient' => (int) ($clientCounts[$c->id] ?? 0) > 0,
                'platformAccess' => $webUser && ! ($webUser->isBlocked ?? false),
                // ⚠ Не то же самое, что !platformAccess. Тот false и у 897
                // партнёров БЕЗ логина — «доступа нет, потому что и входить
                // некому». Список рисует замок только на реально закрытых,
                // иначе иконка блокировки висела бы на каждом импортированном.
                'isBlocked' => (bool) ($webUser && ($webUser->isBlocked ?? false)),
            ];
        });
    }

    /**
     * «Дата смены статуса» (per spec ✅Партнеры §1.2):
     *   - Активен → конец годового периода;
     *   - Зарегистрирован → activationDeadline (его могли продлить), а расчёт
     *     от даты регистрации — только фолбэк.
     *
     * Правило общее с разделом «Статусы партнёров» и отчётом по статусам —
     * App\Support\TerminationDeadline. Раньше активному считалось «dateActivity
     * + 1 год», но per spec ✅Статусная схема партнёров §«Логика продления»
     * срок действия статуса каждый год переносится вперёд — в коде это
     * yearPeriodEnd, который двигает раннер, тогда как dateActivity остаётся
     * датой первой активации. Со второго года «активация + год» лежала в
     * прошлом, и список расходился и с деревом структуры (Structure.vue), и с
     * разделом «Статусы партнёров», и с кабинетом партнёра.
     */
    private function statusChangeDate(Consultant $c, int $activationDays): ?string
    {
        return TerminationDeadline::resolve(
            activity: $c->activity?->value,
            yearPeriodEnd: $c->yearPeriodEnd,
            dateActivity: $c->dateActivity,
            activationDeadline: $c->activationDeadline,
            dateCreated: $c->dateCreated,
            activationDays: $activationDays,
        );
    }
}
