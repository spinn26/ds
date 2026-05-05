<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Один на все красивые XLSX-экспорты.
 *
 * Стиль фиксированный, в едином корпоративном виде:
 *   - Шапка таблицы — жирный белый текст на бренд-зелёном фоне #2E7D32
 *   - Frozen panes по первой строке (заголовки всегда видны при скролле)
 *   - AutoFilter по всему диапазону
 *   - Авто-ширина колонок
 *   - Опциональные форматы для числовых / процентных / дата-колонок
 *
 * Используется через `XlsxExportService::stream(...)` в контроллере —
 * возвращает StreamedResponse, который контроллер просто возвращает наружу.
 */
class XlsxExportService
{
    private const BRAND_HEX = '2E7D32';
    private const HEADER_HEIGHT = 26;

    /**
     * @param string $filename     имя файла без расширения (например, "education-analytics-2026-05-05")
     * @param string $title        заголовок листа (≤31 символ)
     * @param array<int,string>  $headers подписи столбцов
     * @param iterable<int,array> $rows каждая строка — массив значений в порядке $headers
     * @param array{
     *     numericColumns?: array<int,int>,   1-based индексы колонок-чисел
     *     percentColumns?: array<int,int>,   1-based индексы колонок-процентов (значения 0..100)
     *     dateColumns?: array<int,int>,      1-based индексы колонок с датой/временем
     *     totalsRow?: array<int,string>      опциональная итоговая строка (того же размера что и $headers)
     * } $opts
     */
    public function stream(string $filename, string $title, array $headers, iterable $rows, array $opts = []): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));

        $this->writeHeaders($sheet, $headers);
        $rowCount = $this->writeRows($sheet, $rows, count($headers));

        if (! empty($opts['totalsRow'])) {
            $rowCount += $this->writeTotals($sheet, $opts['totalsRow'], $rowCount + 1);
        }

        $this->applyColumnFormats($sheet, $opts, $rowCount);
        $this->finalizeSheet($sheet, count($headers), $rowCount);

        return $this->streamResponse($spreadsheet, $filename);
    }

    private function writeHeaders(Worksheet $sheet, array $headers): void
    {
        $sheet->fromArray([$headers], null, 'A1');
        $lastCol = $this->columnLetter(count($headers));
        $range = "A1:{$lastCol}1";

        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . self::BRAND_HEX],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF1B5E20']],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(self::HEADER_HEIGHT);
    }

    private function writeRows(Worksheet $sheet, iterable $rows, int $colCount): int
    {
        $rowIndex = 2;
        foreach ($rows as $row) {
            $values = array_values($row);
            // Дополняем недостающие до длины headers, чтобы fromArray не сдвинул.
            $values = array_pad($values, $colCount, null);
            $sheet->fromArray([$values], null, "A{$rowIndex}");
            $rowIndex++;
        }
        return $rowIndex - 1; // последняя занятая строка (включая заголовок)
    }

    private function writeTotals(Worksheet $sheet, array $totals, int $atRow): int
    {
        $sheet->fromArray([$totals], null, "A{$atRow}");
        $lastCol = $this->columnLetter(count($totals));
        $sheet->getStyle("A{$atRow}:{$lastCol}{$atRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE8F5E9'],
            ],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF' . self::BRAND_HEX]],
            ],
        ]);
        return 1;
    }

    private function applyColumnFormats(Worksheet $sheet, array $opts, int $lastRow): void
    {
        if ($lastRow < 2) return;

        foreach ($opts['numericColumns'] ?? [] as $col) {
            $letter = $this->columnLetter($col);
            $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('# ##0;-# ##0');
        }
        foreach ($opts['percentColumns'] ?? [] as $col) {
            $letter = $this->columnLetter($col);
            $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('0.0"%"');
        }
        foreach ($opts['dateColumns'] ?? [] as $col) {
            $letter = $this->columnLetter($col);
            $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_DATE_DATETIME);
        }
    }

    private function finalizeSheet(Worksheet $sheet, int $colCount, int $lastRow): void
    {
        $lastCol = $this->columnLetter($colCount);

        // Auto-width по содержимому. Дорого для огромных таблиц,
        // но для отчётов до ~5K строк нормально.
        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimension($this->columnLetter($i))->setAutoSize(true);
        }

        $sheet->freezePane('A2');

        if ($lastRow >= 2) {
            $sheet->setAutoFilter("A1:{$lastCol}{$lastRow}");
        }
    }

    private function columnLetter(int $index): string
    {
        // 1 → A, 26 → Z, 27 → AA, ...
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    private function streamResponse(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $writer = new XlsxWriter($spreadsheet);
        $name = $filename . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
