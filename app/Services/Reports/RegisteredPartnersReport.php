<?php

namespace App\Services\Reports;

use App\Enums\PartnerActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Зарегистрированные партнёры — когорта по дате регистрации.
 *
 * Отвечает на вопрос «что стало с теми, кто пришёл за период»: сколько
 * контрактов завели, сколько баллов набрали и кто дошёл до активации.
 *
 * ⚠ Баллы берём из `consultant."personalVolume"`. Это не произвольный выбор:
 * именно по этому полю CheckPartnerStatuses сравнивает партнёра с
 * PartnerActivity::activationPoints() и решает, активировался он или уходит
 * в терминацию. Снимки qualificationLog сюда не годятся — они помесячные,
 * а окно активации сквозное.
 *
 * Период фильтрует ДАТУ РЕГИСТРАЦИИ, а не активности: когорта задаётся тем,
 * когда человек пришёл, иначе «сколько из них активировалось» теряет смысл.
 *
 * Терминированные из выборки не исключаются: партнёр мог активироваться и
 * позже выбыть — для вопроса «дошёл ли до активации» это «да».
 */
class RegisteredPartnersReport extends AbstractReportType
{
    public function key(): string
    {
        return 'registered_partners';
    }

    public function headers(): array
    {
        return [
            'Партнёр',
            'Код участника',
            'Дата регистрации',
            'Пригласитель',
            'Статус сейчас',
            'Активирован',
            'Дата активации',
            'Дедлайн активации',
            'Контрактов',
            'Баллы (ЛП)',
            'Не хватает до активации',
        ];
    }

    public function rows(string $dateFrom, string $dateTo, array $filters): array
    {
        $threshold = (float) PartnerActivity::activationPoints();

        $partners = DB::table('consultant')
            ->whereNull('dateDeleted')
            // Время не дописываем: ReportGenerator уже прогоняет верхнюю
            // границу через endOfDay(), и второе «23:59:59» ломало запрос.
            ->whereBetween('dateCreated', [$dateFrom, $dateTo])
            ->orderBy('dateCreated')
            ->get([
                'id', 'personName', 'participantCode', 'dateCreated', 'inviterName',
                'activity', 'dateActivity', 'activationDeadline', 'personalVolume',
            ]);

        if ($partners->isEmpty()) {
            return [];
        }

        // Контракты одним запросом: по запросу на строку это был бы N+1
        // на когорте в сотни человек.
        $contracts = DB::table('contract')
            ->whereIn('consultant', $partners->pluck('id')->all())
            ->whereNull('deletedAt')
            ->selectRaw('consultant, COUNT(*) AS cnt')
            ->groupBy('consultant')
            ->pluck('cnt', 'consultant');

        $date = fn ($v) => $v ? Carbon::parse($v)->format('d.m.Y') : null;

        return $partners->map(function ($p) use ($contracts, $threshold, $date) {
            $lp = (float) ($p->personalVolume ?? 0);
            // Признак активации — проставленная дата, а не текущий статус:
            // человек мог активироваться и позже выбыть.
            $activated = $p->dateActivity !== null;
            $status = $p->activity !== null
                ? PartnerActivity::tryFrom((int) $p->activity)?->label()
                : null;

            return [
                $p->personName,
                $p->participantCode,
                $date($p->dateCreated),
                $p->inviterName,
                $status,
                $activated ? 'Да' : 'Нет',
                $date($p->dateActivity),
                // Дедлайн интересен только пока человек не активировался.
                $activated ? null : $date($p->activationDeadline),
                (int) ($contracts[$p->id] ?? 0),
                $this->n($lp),
                $activated ? null : $this->n(max(0, $threshold - $lp)),
            ];
        })->all();
    }
}
