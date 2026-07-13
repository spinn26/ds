<?php

namespace App\Services;

use App\Services\Reports\ReportTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Генератор отчётов (per spec ✅Отчеты.md).
 *
 * Это тонкий orchestrator: получает type, делегирует конкретному
 * App\Services\Reports\* классу через ReportTypeRegistry, рендерит
 * стилизованный XLSX (бренд-зелёная шапка, frozen panes, авто-фильтр)
 * через XlsxExportService и пишет в storage/reports/{id}.xlsx.
 * Архив + статусы — в report_archive.
 *
 * Sync-путь оставлен для тестов и dev. В проде используется async
 * через {@see \App\Jobs\GenerateReportJob}: контроллер зовёт
 * reserveArchive() + dispatch(); воркер вызывает generateAsArchived().
 */
class ReportGenerator
{
    /**
     * Расширить date-only верхнюю границу периода до конца суток, чтобы
     * сравнение с timestamp-колонками не отрезало последний день.
     */
    public static function endOfDay(string $date): string
    {
        return str_contains($date, ':') ? $date : $date.' 23:59:59';
    }

    public function __construct(
        private readonly ReportTypeRegistry $registry,
        private readonly XlsxExportService $xlsx,
    ) {}

    /** Резервируем запись в архиве, статус «generating». */
    public function reserveArchive(string $type, string $dateFrom, string $dateTo, array $filters = [], ?int $userId = null): int
    {
        return DB::table('report_archive')->insertGetId([
            'type' => $type,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'filters' => json_encode($filters),
            'status' => 'generating',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Сгенерировать XLSX для уже зарезервированной записи.
     * Вызывается из GenerateReportJob.
     */
    public function generateAsArchived(int $id): void
    {
        $row = DB::table('report_archive')->where('id', $id)->first();
        if (! $row) throw new \RuntimeException("Архив #{$id} не найден");

        $type = $this->registry->get($row->type);
        if (! $type) throw new \RuntimeException("Неизвестный тип отчёта: {$row->type}");

        $filters = json_decode($row->filters ?: '{}', true);
        // report_archive.date_to is a DATE ("2026-06-30"), but the columns the
        // reports filter on (transaction.date, qualificationLog.date, ...) are
        // TIMESTAMPs. Passing the bare date makes Postgres compare against
        // midnight, silently dropping the whole last day of the period.
        $rows = $type->rows($row->date_from, self::endOfDay($row->date_to), $filters);
        $headers = $type->headers();

        $path = "reports/{$id}.xlsx";
        $absPath = Storage::disk('local')->path($path);
        // Лист в Excel ограничен 31 символом — режем тип отчёта.
        $sheetTitle = mb_substr((string) $row->type, 0, 31);
        $this->xlsx->save($absPath, $sheetTitle, $headers, $rows);

        DB::table('report_archive')->where('id', $id)->update([
            'status' => 'ready',
            'file_path' => $path,
            'updated_at' => now(),
        ]);
    }

    /** Sync-обёртка (для тестов / небольших отчётов). */
    public function generate(string $type, string $dateFrom, string $dateTo, array $filters = [], ?int $userId = null): int
    {
        $id = $this->reserveArchive($type, $dateFrom, $dateTo, $filters, $userId);
        try {
            $this->generateAsArchived($id);
        } catch (\Throwable $e) {
            Log::warning('Report generation failed', ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
            DB::table('report_archive')->where('id', $id)->update([
                'status' => 'error',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
        }
        return $id;
    }

    /** Headers для типа — используется в тестах. */
    public function headersFor(string $type): array
    {
        return $this->registry->get($type)?->headers() ?? [];
    }
}
