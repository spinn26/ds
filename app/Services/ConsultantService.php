<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsultantService
{
    /**
     * Batch-format a collection of consultants (avoids N+1 queries).
     */
    public function formatMembers(Collection $consultants): Collection
    {
        if ($consultants->isEmpty()) {
            return collect();
        }

        ['ids' => $ids, 'statusLevels' => $statusLevels, 'qLogs' => $qLogs, 'cumulativeByConsultant' => $cumulativeByConsultant, 'clientCounts' => $clientCounts, 'contractCounts' => $contractCounts, 'subCounts' => $subCounts, 'activityIds' => $activityIds, 'activityNames' => $activityNames, 'cumulativeLpByConsultant' => $cumulativeLpByConsultant, 'webUsers' => $webUsers, 'cities' => $cities, 'statusLevelsByLevel' => $statusLevelsByLevel] = $this->memberRelations($consultants);

        return $consultants->map(function ($c) use ($statusLevels, $statusLevelsByLevel, $qLogs, $cumulativeByConsultant, $clientCounts, $contractCounts, $subCounts, $activityNames, $cities, $webUsers, $cumulativeLpByConsultant) {
            $statusLevel = $c->status_and_lvl ? ($statusLevels[$c->status_and_lvl] ?? null) : null;
            $qLog = $qLogs[$c->id] ?? null;

            // Fallback: if consultant.status_and_lvl is empty but the latest
            // qualificationLog carries a level, resolve it to status_levels by
            // level number (status_levels has 10 tiers keyed by .level).
            if (! $statusLevel && $qLog) {
                $levelNum = $qLog->levelNew ?? $qLog->calculationLevel ?? $qLog->nominalLevel ?? null;
                if ($levelNum) {
                    $statusLevel = $statusLevelsByLevel[$levelNum] ?? null;
                }
            }
            $clientCount = $clientCounts[$c->id] ?? 0;
            $contractCount = $contractCounts[$c->id] ?? 0;
            $subCount = $subCounts[$c->id] ?? 0;

            $activityId = is_object($c->activity) ? $c->activity->value : $c->activity;
            $activityName = null;
            if ($c->activity) {
                $activityName = is_object($c->activity) ? $c->activity->label() : ($activityNames[$c->activity] ?? null);
            }

            $birthDate = $c->birthDate ?? null;
            $cityName = $c->city
                ? (is_numeric($c->city) ? ($cities[$c->city] ?? null) : $c->city)
                : null;

            // Name parts via WebUser (source of truth per project rules)
            $webUser = $c->webUser ? ($webUsers[$c->webUser] ?? null) : null;
            $firstName = $webUser?->firstName ?? null;
            $lastName = $webUser?->lastName ?? null;
            $patronymic = $webUser?->patronymic ?? null;

            return [
                'id' => $c->id,
                'personName' => $c->personName,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'patronymic' => $patronymic,
                'active' => $c->active,
                'activityId' => $activityId,
                'activityName' => $activityName ?? ($c->active ? 'Активный' : 'Неактивен'),
                'qualification' => $statusLevel ? [
                    'level' => $statusLevel->level,
                    'title' => $statusLevel->title,
                ] : null,
                'level' => $c->structureLevel,
                'personalVolume' => round((float) ($qLog->personalVolume ?? $c->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($qLog->groupVolume ?? $c->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($cumulativeByConsultant[$c->id] ?? $c->groupVolumeCumulative ?? 0), 2),
                'personalVolumeSinceActivation' => round((float) ($cumulativeLpByConsultant[$c->id] ?? 0), 2),
                'clientCount' => $clientCount,
                'contractCount' => $contractCount,
                'hasChildren' => $subCount > 0,
                'residentCount' => $subCount,
                'fcCount' => 0,
                'partnersCount' => $subCount,
                'email' => $webUser?->email ?? null,
                'phone' => $webUser?->phone ?? null,
                'nicTG' => $webUser?->nicTG ?? null,
                'inviterName' => $c->inviterName,
                'birthDate' => $birthDate,
                'city' => $cityName,
                'dateActivity' => $c->dateActivity?->format('d.m.Y'),
                'dateDeterministic' => $c->dateDeterministic
                    ? \Carbon\Carbon::parse($c->dateDeterministic)->format('d.m.Y')
                    : null,
                'yearPeriodEnd' => $c->yearPeriodEnd?->format('d.m.Y'),
                'activationDeadline' => $c->activationDeadline?->format('d.m.Y'),
            ];
        });
    }

    /**
     * «Дата смены статуса» одного партнёра — ровно та, что рисует колонка в
     * «Структуре моей команды» (Structure.vue::statusChangeDate), per spec
     * ✅Структура §4:
     *   - Активен → конец годового цикла: yearPeriodEnd, а если он пуст
     *     (legacy-партнёры) — dateActivity + 12 месяцев;
     *   - Зарегистрирован → activationDeadline, дедлайн активации;
     *   - Терминирован/Исключён → dateDeterministic;
     *   - остальные → dateActivity.
     *
     * Это ЕДИНСТВЕННОЕ место, где живёт правило «какая дата у какого статуса».
     * SQL-предфильтр в StructureController его не повторяет: он лишь грубо
     * сужает выборку по всем колонкам-кандидатам сразу, чтобы подходящая
     * строка не осталась за лимитом, а точный отбор делается здесь.
     *
     * @param  array<string, mixed>  $m  строка из formatMembers (даты в 'd.m.Y')
     */
    public static function statusChangeDate(array $m): ?\Carbon\Carbon
    {
        $parse = static function ($raw): ?\Carbon\Carbon {
            if (empty($raw)) return null;
            try {
                return \Carbon\Carbon::createFromFormat('d.m.Y', (string) $raw)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $activity = (int) ($m['activityId'] ?? 0);

        return match ($activity) {
            \App\Enums\PartnerActivity::Terminated->value,
            \App\Enums\PartnerActivity::Excluded->value => $parse($m['dateDeterministic'] ?? null),

            \App\Enums\PartnerActivity::Registered->value => $parse($m['activationDeadline'] ?? null),

            \App\Enums\PartnerActivity::Active->value,
            \App\Enums\PartnerActivity::Inactive->value => $parse($m['yearPeriodEnd'] ?? null)
                ?? $parse($m['dateActivity'] ?? null)?->addYear(),

            default => $parse($m['dateActivity'] ?? null),
        };
    }

    /**
     * Apply collection-level filters to formatted members.
     */
    public function applyFilters(Collection $members, array $filters): Collection
    {
        // ФИО (общий поиск — по собранной personName)
        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $members = $members->filter(fn ($m) => str_contains(mb_strtolower($m['personName']), $search));
        }

        // ФИО (раздельные поля) — ищем в WebUser-частях или в personName как fallback
        $matchPart = static function ($member, string $part, string $q): bool {
            $q = mb_strtolower($q);
            $val = $member[$part] ?? null;
            if ($val) return str_contains(mb_strtolower($val), $q);
            // Fallback: в personName как подстрока
            return str_contains(mb_strtolower($member['personName'] ?? ''), $q);
        };
        if (! empty($filters['last_name'])) {
            $q = $filters['last_name'];
            $members = $members->filter(fn ($m) => $matchPart($m, 'lastName', $q));
        }
        if (! empty($filters['first_name'])) {
            $q = $filters['first_name'];
            $members = $members->filter(fn ($m) => $matchPart($m, 'firstName', $q));
        }
        if (! empty($filters['patronymic'])) {
            $q = $filters['patronymic'];
            $members = $members->filter(fn ($m) => $matchPart($m, 'patronymic', $q));
        }

        // Статус активности (множественный) — принимаем ID или строковые алиасы
        // из UI: 'active', 'registered', 'terminated', 'excluded'
        $statusAlias = [
            'active' => \App\Enums\PartnerActivity::Active->value,
            'registered' => \App\Enums\PartnerActivity::Registered->value,
            'terminated' => \App\Enums\PartnerActivity::Terminated->value,
            'excluded' => \App\Enums\PartnerActivity::Excluded->value,
        ];
        $rawActivity = $filters['activity'] ?? $filters['status'] ?? null;
        if (! empty($rawActivity)) {
            $raw = is_array($rawActivity) ? $rawActivity : explode(',', $rawActivity);
            $activityIds = array_map(
                fn ($v) => is_numeric($v) ? (int) $v : ($statusAlias[$v] ?? null),
                $raw
            );
            $activityIds = array_filter($activityIds, fn ($v) => $v !== null);
            if ($activityIds) {
                $members = $members->filter(fn ($m) => in_array($m['activityId'], $activityIds));
            }
        }

        // Квалификация (множественный)
        if (! empty($filters['qualification'])) {
            $levels = is_array($filters['qualification']) ? $filters['qualification'] : explode(',', $filters['qualification']);
            $members = $members->filter(fn ($m) => $m['qualification'] && in_array($m['qualification']['level'], $levels));
        }

        // ЛП диапазон
        if (isset($filters['lp_min']) && $filters['lp_min'] !== '' && $filters['lp_min'] !== null) {
            $members = $members->filter(fn ($m) => $m['personalVolume'] >= (float) $filters['lp_min']);
        }
        if (isset($filters['lp_max']) && $filters['lp_max'] !== '' && $filters['lp_max'] !== null) {
            $members = $members->filter(fn ($m) => $m['personalVolume'] <= (float) $filters['lp_max']);
        }

        // ГП диапазон
        if (isset($filters['gp_min']) && $filters['gp_min'] !== '' && $filters['gp_min'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolume'] >= (float) $filters['gp_min']);
        }
        if (isset($filters['gp_max']) && $filters['gp_max'] !== '' && $filters['gp_max'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolume'] <= (float) $filters['gp_max']);
        }

        // НГП диапазон
        if (isset($filters['ngp_min']) && $filters['ngp_min'] !== '' && $filters['ngp_min'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolumeCumulative'] >= (float) $filters['ngp_min']);
        }
        if (isset($filters['ngp_max']) && $filters['ngp_max'] !== '' && $filters['ngp_max'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolumeCumulative'] <= (float) $filters['ngp_max']);
        }

        // Город
        if (! empty($filters['city'])) {
            $city = mb_strtolower($filters['city']);
            $members = $members->filter(fn ($m) => $m['city'] && str_contains(mb_strtolower($m['city']), $city));
        }

        // Дата смены статуса (range). Фильтруем по ТОЙ ЖЕ дате, что показана в
        // одноимённой колонке, — см. statusChangeDate() ниже. Раньше здесь
        // жёстко брались только терминированные и исключённые по
        // dateDeterministic, из-за чего фильтр не находил ни зарегистрированных
        // (у них дата — activationDeadline), ни активных: у первых
        // dateDeterministic вообще NULL, и выдача выходила пустой.
        $termFrom = $filters['termination_from'] ?? null;
        $termTo = $filters['termination_to'] ?? null;
        if ($termFrom || $termTo) {
            $parseDate = static function ($s) {
                if (! $s) return null;
                try { return \Carbon\Carbon::parse($s); } catch (\Throwable) { return null; }
            };
            $from = $parseDate($termFrom);
            $to = $parseDate($termTo);
            $members = $members->filter(function ($m) use ($from, $to) {
                $d = self::statusChangeDate($m);
                if (! $d) return false;
                if ($from && $d->lt($from->copy()->startOfDay())) return false;
                if ($to && $d->gt($to->copy()->endOfDay())) return false;
                return true;
            });
        }

        // Дата рождения (range) — использует 'birthDate' который может быть Carbon|null
        $bdFrom = $filters['birth_date_from'] ?? null;
        $bdTo = $filters['birth_date_to'] ?? null;
        if ($bdFrom || $bdTo) {
            $parseDate = static function ($s) {
                if (! $s) return null;
                try { return \Carbon\Carbon::parse($s); } catch (\Throwable) { return null; }
            };
            $from = $parseDate($bdFrom);
            $to = $parseDate($bdTo);
            $members = $members->filter(function ($m) use ($from, $to) {
                if (empty($m['birthDate'])) return false;
                try {
                    $d = $m['birthDate'] instanceof \Carbon\Carbon
                        ? $m['birthDate']
                        : \Carbon\Carbon::parse($m['birthDate']);
                } catch (\Throwable) {
                    return false;
                }
                if ($from && $d->lt($from)) return false;
                if ($to && $d->gt($to->copy()->endOfDay())) return false;
                return true;
            });
        }

        return $members;
    }

    /**
     * Get partner counts by activity status for a team.
     */
    public function getPartnerCountsByStatus(int $consultantId, array $teamIds): array
    {
        $counts = \App\Models\Consultant::whereIn('id', $teamIds)
            ->where('id', '!=', $consultantId)
            ->whereNull('dateDeleted')
            ->select('activity', DB::raw('count(*) as cnt'))
            ->groupBy('activity')
            ->pluck('cnt', 'activity')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'registered' => $counts[\App\Enums\PartnerActivity::Registered->value] ?? 0,
            'active' => $counts[\App\Enums\PartnerActivity::Active->value] ?? 0,
            'inactive' => 0, // Статус удалён
            'terminated' => $counts[\App\Enums\PartnerActivity::Terminated->value] ?? 0,
            'excluded' => $counts[\App\Enums\PartnerActivity::Excluded->value] ?? 0,
        ];
    }

    /**
     * Get partner counts as a point-in-time snapshot at the end of the
     * previous period. "active" = already had a real activation event
     * (dateActivity) on/before $prevEnd; everyone else created by then is
     * "registered". Grouping the LIVE `activity` column (as before) made a
     * partner who was Registered last month but Active now count as Active in
     * BOTH snapshots, so the Registered→Activated delta silently lost them.
     */
    public function getPrevPartnerCounts(int $consultantId, array $teamIds, \Carbon\Carbon $prevEnd): array
    {
        $base = \App\Models\Consultant::whereIn('id', $teamIds)
            ->where('id', '!=', $consultantId)
            ->whereNull('dateDeleted')
            ->where('dateCreated', '<=', $prevEnd);

        $active = (clone $base)
            ->whereNotNull('dateActivity')
            ->where('dateActivity', '<=', $prevEnd)
            ->count();
        $total = (clone $base)->count();

        return [
            'total' => $total,
            'registered' => max(0, $total - $active),
            'active' => $active,
            'inactive' => 0, // Статус удалён
            'terminated' => 0,
            'excluded' => 0,
        ];
    }

    /**
     * Count team partners who actually transitioned Registered → Activated
     * within [periodStart, periodEnd], keyed on the real activation timestamp
     * consultant."dateActivity" — NOT a diff of the live `activity` column.
     */
    public function getActivatedInPeriod(int $consultantId, array $teamIds, \Carbon\Carbon $periodStart, \Carbon\Carbon $periodEnd): int
    {
        return \App\Models\Consultant::whereIn('id', $teamIds)
            ->where('id', '!=', $consultantId)
            ->whereNull('dateDeleted')
            ->whereNotNull('dateActivity')
            ->whereBetween('dateActivity', [$periodStart, $periodEnd])
            ->count();
    }

    /**
     * Recursively get all descendant consultant IDs (all levels deep).
     */
    public function getAllDescendants(int $parentId): array
    {
        $allIds = [];
        $currentLevel = [$parentId];

        for ($i = 0; $i < 20; $i++) {
            $children = DB::table('consultant')
                ->whereIn('inviter', $currentLevel)
                ->whereNull('dateDeleted')
                ->pluck('id')
                ->toArray();

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return array_unique($allIds);
    }

    /**
     * Get all team IDs (descendants + the consultant itself).
     */
    public function getTeamIds(int $consultantId): array
    {
        $ids = $this->getAllDescendants($consultantId);
        $ids[] = $consultantId;
        return $ids;
    }

    /**
     * Всё связанное со строками структуры — одной пачкой.
     *
     * ⚠ Журнал квалификаций берётся дважды и по-разному: ЛП и ГП — из самой
     * свежей записи, а НГП — из последней с НЕПУСТЫМ накопительным ГП.
     * Строка финализа Отрыв/ОП приходит позже и с пустым значением, и
     * взять её для НГП значит обнулить показатель.
     *
     * @return array<string, mixed>
     */
    private function memberRelations(Collection $consultants): array
    {
        $ids = $consultants->pluck('id')->filter()->unique();

        // Batch load status_levels
        $statusLevelIds = $consultants->pluck('status_and_lvl')->filter()->unique();
        $statusLevels = $statusLevelIds->isNotEmpty()
            ? DB::table('status_levels')->whereIn('id', $statusLevelIds)->get()->keyBy('id')
            : collect();

        // Batch load latest qualificationLog per consultant
        $qLogLatestIds = DB::table('qualificationLog')
            ->whereIn('consultant', $ids)
            ->whereNull('dateDeleted')
            ->selectRaw('MAX(id) as id')
            ->groupBy('consultant')
            ->pluck('id');
        $qLogs = $qLogLatestIds->isNotEmpty()
            ? DB::table('qualificationLog')->whereIn('id', $qLogLatestIds)->get()->keyBy('consultant')
            : collect();

        // НГП (накопительный) — последний НЕ-NULL groupVolumeCumulative.
        // Самая свежая строка может быть penalty-строкой финализа Отрыв/ОП с
        // NULL cumulative; она не должна ронять НГП на stale consultant-поле.
        $cumulativeLatestIds = DB::table('qualificationLog')
            ->whereIn('consultant', $ids)
            ->whereNull('dateDeleted')
            ->whereNotNull('groupVolumeCumulative')
            ->selectRaw('MAX(id) as id')
            ->groupBy('consultant')
            ->pluck('id');
        $cumulativeByConsultant = $cumulativeLatestIds->isNotEmpty()
            ? DB::table('qualificationLog')->whereIn('id', $cumulativeLatestIds)->pluck('groupVolumeCumulative', 'consultant')
            : collect();

        // Batch count clients per consultant.
        // Критерий — НЕ удалён, а не active=true: у 4256 из 8149 клиентов флаг
        // active пуст (наследие Directual), и по нему колонка «Клиенты» в
        // структуре занижалась — партнёр с единственным клиентом видел 0.
        $clientCounts = DB::table('client')
            ->whereIn('consultant', $ids)
            ->whereNull('dateDeleted')
            ->select('consultant', DB::raw('count(*) as cnt'))
            ->groupBy('consultant')
            ->pluck('cnt', 'consultant');

        // Batch count contracts per consultant
        $contractCounts = DB::table('contract')
            ->whereIn('consultant', $ids)
            ->whereNull('deletedAt')
            ->select('consultant', DB::raw('count(*) as cnt'))
            ->groupBy('consultant')
            ->pluck('cnt', 'consultant');

        // Batch count children (by inviter)
        $subCounts = DB::table('consultant')
            ->whereIn('inviter', $ids)
            ->whereNull('dateDeleted')
            ->select('inviter', DB::raw('count(*) as cnt'))
            ->groupBy('inviter')
            ->pluck('cnt', 'inviter');

        // Batch load activity names
        $activityIds = $consultants->map(fn ($c) => is_object($c->activity) ? $c->activity->value : $c->activity)->filter()->unique();
        $activityNames = $activityIds->isNotEmpty()
            ? DB::table('directory_of_activities')->whereIn('id', $activityIds)->pluck('name', 'id')
            : collect();


        // Batch: накопленный ЛП с даты активации (chainOrder=1 = личная комиссия)
        // Возвращаем одним запросом все комиссии ветки и суммируем в PHP
        // только после consultant.dateActivity. Так избегаем коррелированного
        // подзапроса и остаёмся совместимы с любой СУБД.
        $activationMap = $consultants->pluck('dateActivity', 'id');
        $cumulativeLpByConsultant = [];
        $activeIds = $activationMap->filter()->keys()->all();
        if (! empty($activeIds)) {
            $rows = DB::table('commission')
                ->whereIn('consultant', $activeIds)
                ->where('chainOrder', 1)
                ->whereNull('deletedAt')
                ->get(['consultant', 'date', 'personalVolume']);

            foreach ($rows as $r) {
                $activation = $activationMap[$r->consultant] ?? null;
                if (! $activation) continue;
                $commissionDate = $r->date ? \Carbon\Carbon::parse($r->date) : null;
                if (! $commissionDate) continue;
                $actDate = $activation instanceof \Carbon\Carbon
                    ? $activation
                    : \Carbon\Carbon::parse($activation);
                if ($commissionDate->lt($actDate)) continue;

                $cumulativeLpByConsultant[$r->consultant] =
                    ($cumulativeLpByConsultant[$r->consultant] ?? 0) + (float) ($r->personalVolume ?? 0);
            }
        }

        // Batch load WebUser name parts (firstName/lastName/patronymic)
        // Used as source of truth for name parts per project rules
        $webUserIds = $consultants->pluck('webUser')->filter()->unique();
        $webUsers = $webUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webUserIds)
                ->get(['id', 'firstName', 'lastName', 'patronymic', 'email', 'phone', 'nicTG'])
                ->keyBy('id')
            : collect();

        // Город — собственная колонка партнёра (перенесена из person
        // 13.08.2026); хранит и legacy-код справочника, и название.
        $cityIds = $consultants->pluck('city')->filter()->unique();
        $cities = $cityIds->isNotEmpty()
            ? DB::table('city')->whereIn('id', $cityIds)->pluck('cityNameRu', 'id')
            : collect();

        // Index status_levels by level number for qualificationLog fallback
        $statusLevelsByLevel = $statusLevels->keyBy('level');


        return ['ids' => $ids, 'statusLevels' => $statusLevels, 'qLogs' => $qLogs, 'cumulativeByConsultant' => $cumulativeByConsultant, 'clientCounts' => $clientCounts, 'contractCounts' => $contractCounts, 'subCounts' => $subCounts, 'activityIds' => $activityIds, 'activityNames' => $activityNames, 'cumulativeLpByConsultant' => $cumulativeLpByConsultant, 'webUsers' => $webUsers, 'cities' => $cities, 'statusLevelsByLevel' => $statusLevelsByLevel];
    }
}
