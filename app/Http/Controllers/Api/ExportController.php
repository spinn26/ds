<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Универсальный экспорт в CSV (Excel совместимый).
     * Поддерживает: partners, clients, contracts, transactions, commissions, qualifications, payments.
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        $config = $this->getExportConfig($type);
        if (! $config) {
            abort(404, 'Неизвестный тип экспорта');
        }

        $query = DB::table($config['table']);

        // Apply basic filters
        if ($config['whereNull'] ?? null) {
            $query->whereNull($config['whereNull']);
        }
        if ($request->filled('search') && ($config['searchField'] ?? null)) {
            $query->where($config['searchField'], 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('month') && ($config['monthField'] ?? null)) {
            $query->where($config['monthField'], $request->month);
        }

        $query->orderByDesc($config['orderBy'] ?? 'id')->limit(5000);

        $filename = "ds_export_{$type}_" . now()->format('Y-m-d_His') . '.csv';

        return new StreamedResponse(function () use ($query, $config) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8
            fwrite($handle, "\xEF\xBB\xBF");

            // Headers
            fputcsv($handle, array_values($config['columns']), ';');

            // Data
            $query->chunk(500, function ($rows) use ($handle, $config) {
                foreach ($rows as $row) {
                    $line = [];
                    foreach (array_keys($config['columns']) as $field) {
                        $value = $row->$field ?? '';
                        if (is_bool($value)) $value = $value ? 'Да' : 'Нет';
                        $line[] = $value;
                    }
                    fputcsv($handle, $line, ';');
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache',
        ]);
    }

    private function getExportConfig(string $type): ?array
    {
        $configs = [
            'partners' => [
                'table' => 'consultant',
                'whereNull' => 'dateDeleted',
                'searchField' => 'personName',
                'orderBy' => 'id',
                'columns' => [
                    'id' => 'ID',
                    'personName' => 'ФИО',
                    'statusesName' => 'Статус',
                    'personalVolume' => 'ЛП',
                    'groupVolume' => 'ГП',
                    'groupVolumeCumulative' => 'НГП',
                    'participantCode' => 'Код участника',
                    'inviterName' => 'Пригласитель',
                    'dateCreated' => 'Дата регистрации',
                    'dateActivity' => 'Дата активации',
                ],
            ],
            'clients' => [
                'table' => 'client',
                'searchField' => 'personName',
                'orderBy' => 'id',
                'columns' => [
                    'id' => 'ID',
                    'personName' => 'ФИО клиента',
                    'consultant' => 'ID консультанта',
                    'active' => 'Активен',
                    'dateCreated' => 'Дата создания',
                    'workSince' => 'Работаем с',
                    'comment' => 'Комментарий',
                ],
            ],
            'contracts' => [
                'table' => 'contract',
                'whereNull' => 'deletedAt',
                'searchField' => 'clientName',
                'orderBy' => 'id',
                'columns' => [
                    'id' => 'ID',
                    'number' => 'Номер',
                    'clientName' => 'Клиент',
                    'consultantName' => 'Консультант',
                    'productName' => 'Продукт',
                    'programName' => 'Программа',
                    'ammount' => 'Сумма',
                    'openDate' => 'Дата открытия',
                ],
            ],
            'transactions' => [
                'table' => 'transaction',
                'whereNull' => 'deletedAt',
                'monthField' => 'dateMonth',
                'orderBy' => 'id',
                'columns' => [
                    'id' => 'ID',
                    'contract' => 'Контракт',
                    'amount' => 'Сумма',
                    'amountRUB' => 'Сумма (RUB)',
                    'amountUSD' => 'Сумма (USD)',
                    'date' => 'Дата',
                    'dateMonth' => 'Месяц',
                ],
            ],
            'commissions' => [
                'table' => 'commission',
                'whereNull' => 'deletedAt',
                'monthField' => 'dateMonth',
                'orderBy' => 'id',
                'columns' => [
                    'id' => 'ID',
                    'consultant' => 'Консультант',
                    'type' => 'Тип',
                    'amountRUB' => 'Сумма (RUB)',
                    'personalVolume' => 'ЛП',
                    'groupVolume' => 'ГП',
                    'groupBonusRub' => 'Бонус (RUB)',
                    'percent' => '%',
                    'date' => 'Дата',
                ],
            ],
            'qualifications' => [
                'table' => 'qualificationLog',
                'whereNull' => 'dateDeleted',
                'searchField' => 'consultantPersonName',
                'orderBy' => 'id',
                'columns' => [
                    'id' => 'ID',
                    'consultantPersonName' => 'Партнёр',
                    'personalVolume' => 'ЛП',
                    'groupVolume' => 'ГП',
                    'groupVolumeCumulative' => 'НГП',
                    'nominalLevel' => 'Номинальный уровень',
                    'calculationLevel' => 'Расчётный уровень',
                    'result' => 'Результат',
                    'date' => 'Дата',
                ],
            ],
        ];

        return $configs[$type] ?? null;
    }
}
