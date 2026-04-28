<?php

namespace App\Services\Reports;

/**
 * Контракт для одного типа отчёта (per spec ✅Отчеты §3).
 * Каждый из 7 типов реализует свои headers() + rows().
 */
interface ReportTypeContract
{
    /** Машинное имя (ключ в reportTypes на UI). */
    public function key(): string;

    /** Заголовки CSV в порядке колонок. */
    public function headers(): array;

    /**
     * Строки CSV. $filters — произвольные параметры (activity, ...).
     * @return array<int,array<int,scalar|null>>
     */
    public function rows(string $dateFrom, string $dateTo, array $filters): array;
}
