<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Support\TerminationDeadline;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Раздел «Статусы партнёров» (/admin/partner-statuses): сводка, фильтры,
 * сборка строк.
 *
 * Вынесено из AdminDataController (метод занимал 138 строк). Как и в списке
 * партнёров, query() отдаёт билдер: total считается по отфильтрованному
 * запросу ДО пагинации, а строки собираются уже по странице.
 */
class PartnerStatusesListingService
{
    /**
     * Ключи фильтров. Восемь из десяти — границы диапазонов по ЧЕТЫРЁМ разным
     * колонкам дат, поэтому список держим явным: перепутать их при переносе
     * проще всего.
     *
     * @var list<string>
     */
    public const FILTERS = [
        'search', 'activity',
        'created_from', 'created_to',
        'activity_from', 'activity_to',
        'plan_from', 'plan_to',
        'term_from', 'term_to',
    ];

    /**
     * Колонка → пара границ диапазона.
     *
     * ⚠ Плановой терминации здесь нет: это не колонка, а вычисляемый дедлайн
     * (App\Support\TerminationDeadline) — см. фильтр plan_from/plan_to ниже.
     */
    private const DATE_RANGES = [
        'dateCreated' => ['created_from', 'created_to'],
        'dateActivity' => ['activity_from', 'activity_to'],
        'dateDeterministic' => ['term_from', 'term_to'],
    ];

    /**
     * Сводка «сколько партнёров в каждом статусе». Считается по ВСЕЙ базе, а не
     * по отфильтрованной выдаче — это счётчики разделов, а не итог страницы.
     *
     * Дженерики не сужаем: строки приходят из DB::table() как stdClass с
     * mixed-полями, точный shape анализатору не доказать.
     */
    public function summary(): Collection
    {
        $counts = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->select('activity', DB::raw('count(*) as cnt'))
            ->groupBy('activity')
            ->pluck('cnt', 'activity')
            ->toArray();

        return DB::table('directory_of_activities')->orderBy('id')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'count' => $counts[$s->id] ?? 0,
            ]);
    }

    /**
     * Запрос с фильтрами, без сортировки и пагинации.
     *
     * @param array<string, mixed> $filters только заполненные значения
     */
    public function query(array $filters): Builder
    {
        $query = DB::table('consultant')->whereNull('dateDeleted');

        if (isset($filters['search'])) {
            $query->where('personName', 'ilike', '%' . $filters['search'] . '%');
        }
        if (isset($filters['activity'])) {
            $query->where('activity', $filters['activity']);
        }

        foreach (self::DATE_RANGES as $column => [$fromKey, $toKey]) {
            if (isset($filters[$fromKey])) {
                $query->where($column, '>=', $filters[$fromKey]);
            }
            if (isset($filters[$toKey])) {
                // ⚠ Верхняя граница включает ВЕСЬ день. Колонки хранят время,
                // и сравнение с голой датой отсекало записи, созданные в
                // течение последнего дня диапазона.
                $query->where($column, '<=', $filters[$toKey] . ' 23:59:59');
            }
        }

        // Плановая терминация — по тому же выражению, которым колонка «Будет
        // терминирован» рисуется в выдаче. Раньше фильтр брал колонку
        // dateDeterministicPlan (окно активации из самоактивации), и выбор
        // «ноябрь 2026» возвращал строки с июнем 2027 в колонке.
        if (isset($filters['plan_from'])) {
            $query->whereRaw(TerminationDeadline::sql() . ' >= ?', [$filters['plan_from']]);
        }
        if (isset($filters['plan_to'])) {
            $query->whereRaw(TerminationDeadline::sql() . ' <= ?', [$filters['plan_to'] . ' 23:59:59']);
        }

        return $query;
    }

    /**
     * Строки страницы → массив для ответа. Связанное грузится пачками.
     */
    public function present(Collection $rows): Collection
    {
        $activityIds = $rows->pluck('activity')->filter()->unique();
        $activityNames = $activityIds->isNotEmpty()
            ? DB::table('directory_of_activities')->whereIn('id', $activityIds)->pluck('name', 'id')
            : collect();

        // Email партнёра: основной источник — WebUser (consultant.webUser →
        // WebUser.email). У legacy/терминированных логина нет — берём
        // собственную колонку партнёра (перенесена из person 13.08.2026),
        // она и держит те же ~97% покрытия, что раньше давал фолбэк.
        $webUserIds = $rows->pluck('webUser')->filter()->unique();
        $emailByWebUser = $webUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webUserIds)->pluck('email', 'id')
            : collect();

        $lpFromActivation = $this->lpFromActivation($rows->pluck('id')->filter()->unique()->values());
        $reinstateLimit = PartnerActivity::selfReinstateLimit();
        // Окно активации — настройка; резолвим один раз на страницу, а не на
        // каждой строке (нужно фоллбэку дедлайна у «Зарегистрирован»).
        $activationDays = PartnerActivity::activationDays();

        return $rows->map(function ($c) use ($activityNames, $lpFromActivation, $emailByWebUser, $reinstateLimit, $activationDays) {
            $activityName = $c->activity ? ($activityNames[$c->activity] ?? '—') : '—';

            // Прогноз терминации — общее определение с фильтром и сортировкой
            // (App\Support\TerminationDeadline). Раньше считался прямо здесь
            // как dateActivity + 1 год: у партнёра со второго года это давало
            // дату в прошлом, потому что годовой период двигает yearPeriodEnd,
            // а у «Зарегистрирован» прогноза не было вовсе, хотя окно набора
            // ЛП — такой же срок, ведущий к терминации.
            $willTerminate = TerminationDeadline::resolve(
                activity: $c->activity,
                yearPeriodEnd: $c->yearPeriodEnd ?? null,
                dateActivity: $c->dateActivity,
                activationDeadline: $c->activationDeadline ?? null,
                dateCreated: $c->dateCreated,
                activationDays: $activationDays,
            );

            return [
                'id' => $c->id,
                'personName' => $c->personName,
                'email' => ($c->webUser ? ($emailByWebUser[$c->webUser] ?? null) : null)
                    ?: ($c->email ?: null),
                'activityId' => $c->activity,
                'activityName' => $activityName,
                'dateCreated' => $c->dateCreated,
                'dateActivity' => $c->dateActivity,
                'dateDeactivity' => $c->dateDeactivity,
                'dateDeterministic' => $c->dateDeterministic,
                'dateDeterministicPlan' => $c->dateDeterministicPlan,
                'willTerminate' => $willTerminate,
                'terminationCount' => $c->terminationCount ?? 0,
                'reinstatementCount' => (int) ($c->reinstatement_count ?? 0),
                'reinstateLimit' => $reinstateLimit,
                'reinstateBlocked' => (bool) ($c->reinstate_blocked ?? false),
                'lastReinstateAt' => $c->last_reinstate_at ?? null,
                // ЛП «глобальное» из consultant.personalVolume (для совместимости).
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                // ЛП с даты активации, обнуляющееся раз в год — то самое поле из спеки.
                'lpFromActivation' => round((float) ($lpFromActivation[$c->id] ?? 0), 2),
            ];
        });
    }

    /**
     * Per spec ✅Статусы партнеров §2 col.7: «Сумма ЛП от даты активации
     * (каждый год обнуляется)».
     *
     * ⚠ Считает PartnerStatusService::periodPersonalVolumeFor() — та же
     * формула, по которой партнёру рисуется счётчик «Набрано N / 500» в
     * кабинете и по которой его терминируют. Свой расчёт тут был и расходился
     * с ней трижды: окно отсчитывалось от dateActivity (а годовой период
     * двигает yearPeriodEnd), суммировались commission вместо транзакций
     * (комиссии появляются только после расчёта), и не учитывались ручные
     * баллы «Прочих начислений», которые по спеке тоже держат статус.
     *
     * @param  Collection<int, mixed>  $consultantIds
     * @return Collection<int, float>
     */
    private function lpFromActivation(Collection $consultantIds): Collection
    {
        if ($consultantIds->isEmpty()) {
            return collect();
        }

        return collect(
            app(PartnerStatusService::class)
                ->periodPersonalVolumeFor($consultantIds->map(fn ($v) => (int) $v)->all())
        );
    }
}
