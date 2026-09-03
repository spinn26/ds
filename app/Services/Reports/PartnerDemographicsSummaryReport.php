<?php

namespace App\Services\Reports;

use App\Services\PartnerDemographicsService;
use App\Support\Age;
use App\Support\Gender;

/**
 * Демография сети — СВОДКА: проценты по полу и распределение по возрасту.
 *
 * Это и есть ответ на вопрос «какой процент мужчин и женщин, как выглядит
 * возраст». Один лист, четыре смысловых раздела в первой колонке:
 *
 *   Пол           — сколько мужчин, женщин, у скольких пол не определён;
 *   Возраст       — сколько партнёров в каждой группе, плюс разбивка м/ж
 *                   в двух последних колонках;
 *   Итого         — всего партнёров, средний и медианный возраст;
 *   Качество данных — сколько полов вычислено по отчеству и у скольких нет
 *                   даты рождения. Без этих двух строк проценты выглядят
 *                   точнее, чем они есть.
 *
 * Диаграмма по возрасту строится из блока «Возраст» за один шаг: выделить
 * колонки «Показатель» и «Партнёров» → Вставка → Гистограмма. Рисовать
 * график внутри файла не стали: XlsxExportService общий на восемь отчётов,
 * и добавлять в него поддержку чартов ради одного — лишний риск на пути,
 * которым генерируются денежные отчёты.
 *
 * ⚠ Период не участвует: снимок сети на сегодня. Границы приходят (NOT NULL
 * в report_archive) и игнорируются.
 */
class PartnerDemographicsSummaryReport extends AbstractReportType
{
    public function __construct(private readonly PartnerDemographicsService $data) {}

    public function key(): string { return 'partner_demographics_summary'; }

    public function headers(): array
    {
        return ['Раздел', 'Показатель', 'Партнёров', 'Доля, %', 'Мужчины', 'Женщины'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        // Сборщик отдаёт массив (см. его докблок про инвариантность
        // Collection); для агрегатов оборачиваем обратно.
        $records = collect($this->data->records($filters));
        $total = $records->count();
        if ($total === 0) {
            return [['Итого', 'Всего партнёров', 0, 0, 0, 0]];
        }

        $males = $records->where('gender', Gender::MALE)->count();
        $females = $records->where('gender', Gender::FEMALE)->count();
        $unknownGender = $total - $males - $females;

        $rows = [
            ['Пол', 'Мужской', $males, $this->pct($males, $total), '', ''],
            ['Пол', 'Женский', $females, $this->pct($females, $total), '', ''],
            ['Пол', 'Не определён', $unknownGender, $this->pct($unknownGender, $total), '', ''],
        ];

        // Возрастные группы идут в порядке возрастания — так их и рисует
        // гистограмма, без ручной пересортировки в Excel.
        foreach (Age::bucketLabels() as $label) {
            $inBucket = $records->where('bucket', $label);
            $rows[] = [
                'Возраст',
                $label,
                $inBucket->count(),
                $this->pct($inBucket->count(), $total),
                $inBucket->where('gender', Gender::MALE)->count(),
                $inBucket->where('gender', Gender::FEMALE)->count(),
            ];
        }

        $ages = $records->pluck('years')->filter(fn ($y) => $y !== null)->values();
        $malesAges = $records->where('gender', Gender::MALE)->pluck('years')->filter(fn ($y) => $y !== null);
        $femalesAges = $records->where('gender', Gender::FEMALE)->pluck('years')->filter(fn ($y) => $y !== null);

        $rows[] = ['Итого', 'Всего партнёров', $total, 100.0, $males, $females];
        $rows[] = ['Итого', 'Средний возраст', $this->n($ages->avg() ?? 0, 1), '',
            $this->n($malesAges->avg() ?? 0, 1), $this->n($femalesAges->avg() ?? 0, 1)];
        $rows[] = ['Итого', 'Медианный возраст', $this->n($ages->median() ?? 0, 1), '', '', ''];

        $guessed = $records->where('genderSource', 'По отчеству')->count();
        $noBirthDate = $records->whereNull('years')->count();
        $rows[] = ['Качество данных', 'Пол определён по отчеству, а не указан в профиле',
            $guessed, $this->pct($guessed, $total), '', ''];
        $rows[] = ['Качество данных', 'Дата рождения не заполнена',
            $noBirthDate, $this->pct($noBirthDate, $total), '', ''];

        return $rows;
    }

    /** Доля в процентах, 0..100 с одним знаком. */
    private function pct(int $part, int $total): float
    {
        return $total > 0 ? $this->n($part / $total * 100, 1) : 0.0;
    }
}
