<?php

namespace App\Services;

use App\Support\Audit;
use App\Support\LegacyId;
use Illuminate\Support\Facades\DB;

/**
 * Ручное присвоение квалификации партнёру.
 *
 * ⚠ Это запись в денежный контур. Ставка комиссии берётся из
 * `status_levels.percent` уровня, который CommissionCalculator достаёт
 * из `qualificationLog` (максимум nominalLevel/calculationLevel) по
 * открывающей строке месяца — см. getQualificationLevel(). Поэтому
 * присвоение обязано писать именно в qualificationLog: правка одного
 * `consultant.status_and_lvl` поменяла бы карточку, но не деньги.
 *
 * Защиты, снимать нельзя:
 *  • период раньше CommissionCalculator::HISTORICAL_CUTOFF не трогаем —
 *    это выгруженная из Directual история, она неизменна;
 *  • PeriodFreezeService::guard перед записью — закрытый месяц правится
 *    только после явной разморозки админом.
 *
 * Что метод НЕ делает: не пересчитывает уже начисленные комиссии за
 * месяц. Новая ставка применится при следующем пересчёте — вызывающий
 * код обязан сказать об этом оператору (см. поле `recalcRequired`).
 */
class ManualQualificationService
{
    public function __construct(
        private readonly PeriodFreezeService $freeze,
    ) {}

    /**
     * @return array{
     *   consultantId:int, month:string, levelId:int, level:int, title:?string,
     *   percent:float, previousLevelId:?int, created:bool, recalcRequired:bool
     * }
     */
    public function assign(int $consultantId, int $levelId, string $month, ?string $comment, ?int $userId): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            abort(422, 'Месяц должен быть в формате YYYY-MM.');
        }

        if (CommissionCalculator::isHistorical($month)) {
            abort(422, 'Период ' . $month . ' относится к выгруженной истории (до '
                . CommissionCalculator::HISTORICAL_CUTOFF . ') и не редактируется.');
        }

        [$year, $mon] = array_map('intval', explode('-', $month));
        $this->freeze->guard($year, $mon);

        $consultant = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where('id', $consultantId)
            ->first(['id', 'personName', 'status_and_lvl']);
        if (! $consultant) {
            abort(404, 'Партнёр не найден.');
        }

        $level = DB::table('status_levels')->where('id', $levelId)->first();
        if (! $level) {
            abort(422, 'Квалификация не найдена.');
        }

        // Открывающая строка месяца — та, что датирована 1-м числом: именно её
        // читает getQualificationLevel для сделок этого месяца.
        $openingDate = $month . '-01 00:00:00';

        $result = DB::transaction(function () use ($consultantId, $consultant, $level, $month, $openingDate, $comment) {
            $existing = DB::table('qualificationLog')
                ->where('consultant', $consultantId)
                ->whereNull('dateDeleted')
                ->whereRaw('date::date = ?', [$month . '-01'])
                ->orderByDesc('id')
                ->first();

            $note = 'Ручное присвоение уровня' . ($comment ? ': ' . $comment : '');

            if ($existing) {
                // Строка месяца уже есть — правим её, а не добавляем вторую:
                // две открывающие строки на один день сделали бы выбор уровня
                // зависимым от порядка сортировки.
                DB::table('qualificationLog')
                    ->where('id', $existing->id)
                    ->update([
                        'nominalLevel' => $level->id,
                        'calculationLevel' => $level->id,
                        'comment' => $note,
                        'savingDate' => now(),
                    ]);

                $created = false;
                $previousLevelId = $existing->nominalLevel ?? $existing->calculationLevel ?? null;
            } else {
                // Объёмы берём из последнего снимка до этого месяца, чтобы
                // карточка не показала нули там, где партнёр уже что-то набрал.
                $prior = DB::table('qualificationLog')
                    ->where('consultant', $consultantId)
                    ->whereNull('dateDeleted')
                    ->whereRaw('date::timestamp < ?::timestamp', [$openingDate])
                    ->orderByRaw('date::timestamp DESC')
                    ->first();

                LegacyId::syncSequence('qualificationLog');

                DB::table('qualificationLog')->insert([
                    'consultant' => $consultantId,
                    'consultantPersonName' => $consultant->personName,
                    'nominalLevel' => $level->id,
                    'calculationLevel' => $level->id,
                    'levelPrevious' => $prior->nominalLevel ?? $prior->calculationLevel ?? null,
                    'personalVolume' => $prior->personalVolume ?? 0,
                    'groupVolume' => $prior->groupVolume ?? 0,
                    'groupVolumeCumulative' => $prior->groupVolumeCumulative ?? 0,
                    'date' => $openingDate,
                    'createdAt' => now(),
                    'savingDate' => now(),
                    'comment' => $note,
                ]);

                $created = true;
                $previousLevelId = $prior->nominalLevel ?? $prior->calculationLevel ?? null;
            }

            // Текущий уровень в карточке двигаем только вперёд по времени:
            // присвоение за прошлый месяц не должно менять то, что партнёр
            // видит как свою сегодняшнюю квалификацию.
            if ($month >= now()->format('Y-m')) {
                DB::table('consultant')->where('id', $consultantId)
                    ->update(['status_and_lvl' => $level->id]);
            }

            return [$created, $previousLevelId];
        });

        [$created, $previousLevelId] = $result;

        Audit::log('manual-qualification', 'consultant', $consultantId, [
            'month' => $month,
            'levelId' => $level->id,
            'level' => $level->level,
            'percent' => $level->percent,
            'previousLevelId' => $previousLevelId,
            'created' => $created,
            'comment' => $comment,
            'by' => $userId,
        ]);

        return [
            'consultantId' => $consultantId,
            'month' => $month,
            'levelId' => (int) $level->id,
            'level' => (int) $level->level,
            'title' => $level->title,
            'percent' => (float) $level->percent,
            'previousLevelId' => $previousLevelId !== null ? (int) $previousLevelId : null,
            'created' => $created,
            // Уже начисленные комиссии месяца остаются со старой ставкой:
            // пересчёт запускается отдельно и осознанно.
            'recalcRequired' => true,
        ];
    }
}
