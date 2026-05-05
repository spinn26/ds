<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Универсальный экспорт в стилизованный XLSX (бренд-зелёная шапка,
     * frozen panes, autofilter, авто-ширины, форматы чисел и дат).
     * Поддерживает: partners, clients, contracts, transactions, commissions, qualifications, payments.
     */
    public function export(Request $request, string $type, XlsxExportService $xlsx): StreamedResponse
    {
        $config = $this->getExportConfig($type);
        if (! $config) {
            abort(404, 'Неизвестный тип экспорта');
        }

        $query = DB::table($config['table']);

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
        $rows = $query->get();

        $fields = array_keys($config['columns']);
        $data = $rows->map(function ($row) use ($fields) {
            $line = [];
            foreach ($fields as $field) {
                $value = $row->$field ?? '';
                if (is_bool($value)) $value = $value ? 'Да' : 'Нет';
                $line[] = $value;
            }
            return $line;
        })->all();

        // Готовим карту индексов 1-based для форматов (числа / проценты / даты).
        $opts = [];
        foreach (['numeric' => 'numericColumns', 'percent' => 'percentColumns', 'date' => 'dateColumns'] as $cfgKey => $optKey) {
            if (! empty($config[$cfgKey])) {
                $opts[$optKey] = array_values(array_filter(array_map(
                    fn ($f) => ($pos = array_search($f, $fields, true)) === false ? null : $pos + 1,
                    $config[$cfgKey],
                )));
            }
        }

        return $xlsx->stream(
            "ds_export_{$type}_" . now()->format('Y-m-d_His'),
            $config['title'] ?? ucfirst($type),
            array_values($config['columns']),
            $data,
            $opts,
        );
    }

    private function getExportConfig(string $type): ?array
    {
        $configs = [
            'partners' => [
                'table' => 'consultant',
                'title' => 'Партнёры',
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
                'numeric' => ['personalVolume', 'groupVolume', 'groupVolumeCumulative'],
                'date' => ['dateCreated', 'dateActivity'],
            ],
            'clients' => [
                'table' => 'client',
                'title' => 'Клиенты',
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
                'date' => ['dateCreated', 'workSince'],
            ],
            'contracts' => [
                'table' => 'contract',
                'title' => 'Контракты',
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
                'numeric' => ['ammount'],
                'date' => ['openDate'],
            ],
            'transactions' => [
                'table' => 'transaction',
                'title' => 'Транзакции',
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
                'numeric' => ['amount', 'amountRUB', 'amountUSD'],
                'date' => ['date'],
            ],
            'commissions' => [
                'table' => 'commission',
                'title' => 'Комиссии',
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
                'numeric' => ['amountRUB', 'personalVolume', 'groupVolume', 'groupBonusRub'],
                'percent' => ['percent'],
                'date' => ['date'],
            ],
            'qualifications' => [
                'table' => 'qualificationLog',
                'title' => 'Квалификации',
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
                'numeric' => ['personalVolume', 'groupVolume', 'groupVolumeCumulative'],
                'date' => ['date'],
            ],
        ];

        return $configs[$type] ?? null;
    }
}
