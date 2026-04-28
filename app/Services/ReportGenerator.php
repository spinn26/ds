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
 * CSV и пишет в storage/reports/{id}.csv. Архив + статусы — в
 * report_archive.
 *
 * Sync-путь оставлен для тестов и dev. В проде используется async
 * через {@see \App\Jobs\GenerateReportJob}: контроллер зовёт
 * reserveArchive() + dispatch(); воркер вызывает generateAsArchived().
 */
class ReportGenerator
{
    public function __construct(private readonly ReportTypeRegistry $registry) {}

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
     * Сгенерировать CSV для уже зарезервированной записи.
     * Вызывается из GenerateReportJob.
     */
    public function generateAsArchived(int $id): void
    {
        $row = DB::table('report_archive')->where('id', $id)->first();
        if (! $row) throw new \RuntimeException("Архив #{$id} не найден");

        $type = $this->registry->get($row->type);
        if (! $type) throw new \RuntimeException("Неизвестный тип отчёта: {$row->type}");

        $filters = json_decode($row->filters ?: '{}', true);
        $rows = $type->rows($row->date_from, $row->date_to, $filters);
        $headers = $type->headers();
        $path = "reports/{$id}.csv";
        $csv = $this->toCsv($headers, $rows);
        Storage::disk('local')->put($path, $csv);

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

    private function toCsv(array $headers, array $rows): string
    {
        $out = "\xEF\xBB\xBF"; // UTF-8 BOM для Excel
        $out .= $this->csvLine($headers);
        foreach ($rows as $r) $out .= $this->csvLine($r);
        return $out;
    }

    private function csvLine(array $vals): string
    {
        return implode(',', array_map(function ($v) {
            if ($v === null) return '""';
            $s = (string) $v;
            $s = str_replace('"', '""', $s);
            return '"' . $s . '"';
        }, $vals)) . "\n";
    }
}
