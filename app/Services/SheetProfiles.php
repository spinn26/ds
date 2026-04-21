<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Профили листов Google Sheets «Таблица отчетов в нужном формате».
 *
 * Каждый лист — отдельный поставщик с СВОИМ набором колонок и форматом
 * (dates как d.m.Y, суммы с пробелами и запятой, CRM-ID контрактов в
 * нестандартных форматах). Этот класс хранит словарь: для каждого
 * известного листа — маппинг header → поле + id counterparty по имени.
 *
 * Если лист не знаком — profile() возвращает null, и импорт использует
 * прежний generic-парсер (contract_number/amount/date).
 */
class SheetProfiles
{
    /**
     * sheet name → [
     *   counterpartyName => имя из counterparty.counterpartyName для автоматики
     *   fields => [canonicalKey => header]
     * ]
     *
     * canonicalKey из набора: contract_number, client_name, amount,
     * commission, date, currency, productName, programName, service_type.
     */
    public const PROFILES = [
        'робоэдвайзер' => [
            'counterpartyName' => 'Тинькофф',
            'productHint' => 'Тинькофф портфель',
            'programHint' => 'Робоэдвайзер',
            'fields' => [
                'service_type'    => 'Вид услуги',
                'amount'          => 'База для начисления комиссии (руб.)',
                'client_name'     => 'Ф.И.О. клиента',
                'date'            => 'Дата оплаты счета',
                'commission'      => 'Сумма, руб.',
                'contract_number' => 'CRMIDклиента',
            ],
            'currency' => 'RUB',
        ],
        'IB MF' => [
            'counterpartyName' => 'Interactive Brokers',
            'productHint' => 'IB MF',
            'fields' => [
                'date'            => 'Дата',
                'client_name'     => 'Клиент',
                'contract_number' => 'ID сделки',
                'commission'      => 'Всего комиссии',
            ],
            'currency' => 'USD',
        ],
        'IB UP' => [
            'counterpartyName' => 'Interactive Brokers',
            'productHint' => 'IB UP',
            'fields' => [
                'date'            => 'Дата',
                'client_name'     => 'Клиент',
                'contract_number' => 'ID сделки',
                'commission'      => 'Сумма вознаграждения',
            ],
            'currency' => 'USD',
        ],
        'InvestorsTrust' => [
            'counterpartyName' => 'Investor Trust',
            'productHint' => 'InvestorsTrust',
            'fields' => [
                'contract_number' => 'Номер контракта',
                'year'            => 'Год',
                'currency'        => 'Валюта',
                'amount'          => 'Сумма взноса',
                'commission_pct'  => 'Размер комиссии',
                'commission'      => 'Сумма комиссии',
                'date'            => 'Дата оплаты',
                'ds_level'        => 'Уровень ДС',
            ],
        ],
        'ГГА' => [
            'counterpartyName' => 'Альянс',
            'productHint' => 'ГГА',
            'fields' => [
                'client_name'     => 'Клиент',
                'policy_number'   => 'Номер полиса',
                'contract_number' => 'Номер контракта',
                'commission'      => 'Сумма комиссии',
                'amount'          => 'Сумма контракта (база)',
                'programName'     => 'Программа',
                'productName'     => 'Продукт',
                'date'            => 'Дата',
            ],
            'currency' => 'RUB',
        ],
        'Woodville' => [
            'counterpartyName' => 'Woodville Consultants',
            'productHint' => 'Woodville ноты',
            'fields' => [
                'programName'     => 'Инструмент',
                'client_name'     => 'ФИО клиента',
                'amount'          => 'Сумма контракта',
                'currency'        => 'Валюта контракта',
                'commission_pct'  => 'Процент комиссии',
                'contract_number' => 'Номер контракта',
                'amount_rub'      => 'Сумма контракта в рублях',
            ],
        ],
        'БКС ПИФ' => [
            'counterpartyName' => 'БКС',
            'productHint' => 'БКС ПИФ',
            'fields' => [
                'client_name'     => 'ФИО',
                'contract_number' => 'Номер контракта',
                'amount'          => 'Выручка MF',
                'commission'      => 'Сумма взноса',
                'date'            => 'Дата',
            ],
            'currency' => 'RUB',
        ],
        'Medlife' => [
            'counterpartyName' => 'Medlife',
            'productHint' => 'Medlife',
            'fields' => [
                'holder_name'     => 'Страхователь',
                'client_name'     => 'ФИО',
                'ds_level'        => 'Уровень ДС',
                'year'            => 'Год контракта',
                'contract_number' => 'Номер контракта',
                'amount'          => 'Сумма взноса',
                'currency'        => 'Валюта',
            ],
        ],
        'Anderida MF' => [
            'counterpartyName' => 'Anderida',
            'productHint' => 'Anderida MF',
            'fields' => [
                'contract_number' => 'номер контракта',
                'amount'          => 'сумма контракта',
                'currency'        => 'Валюта',
                'date'            => 'Дата',
            ],
        ],
        'Брокер+' => [
            'counterpartyName' => 'Брокер+',
            'productHint' => 'Брокер+',
            'fields' => [
                'client_name'     => 'ФИО',
                'contract_number' => 'Номер контракта',
                'amount'          => 'Сумма контракта',
                'commission'      => 'Сумма вознаграждения',
                'date'            => 'Дата',
            ],
            'currency' => 'RUB',
        ],
        'Юнилайф' => [
            'counterpartyName' => 'Unilife',
            'productHint' => 'Юнилайф',
            'fields' => [
                'contract_number' => 'Номер контракта',
                'client_name'     => 'ФИО',
                'client_name_en'  => 'ФИО анг.',
                'year'            => 'Год',
                'amount'          => 'Сумма полиса',
                'currency'        => 'Валюта',
                'commission'      => 'Сумма комиссионных',
                'date'            => 'Дата ',   // trailing space intentional
            ],
        ],
        'Private Equity' => [
            'counterpartyName' => 'Private Equity',
            'productHint' => 'Private Equity',
            'fields' => [
                'contract_number' => 'Номер контракта',
                'client_name'     => 'ФИО',
                'client_commission' => 'Комиссия клиента (руб.)',
                'commission'      => 'Доход от партнерской программы (руб.)',
                'date'            => 'Дата ',
            ],
            'currency' => 'RUB',
        ],
        'Axevil' => [
            'counterpartyName' => 'Axevil',
            'productHint' => 'Axevil',
            'fields' => [
                'contract_number' => 'Номер контракта',
                'client_name_db'  => 'ФИО с базы',
                'client_name'     => 'ФИО с отчета',
                'amount_usd'      => 'Сумма инвестиций (дол.)',
                'amount_rub'      => 'Сумма инвестиций (руб.)',
                'date'            => 'Дата',
            ],
        ],
    ];

    /** Вернуть профиль или null если лист неизвестен. */
    public static function profile(string $sheet): ?array
    {
        return self::PROFILES[$sheet] ?? null;
    }

    /** Резолвинг counterparty.id по имени из профиля. */
    public static function resolveCounterpartyId(string $name): ?int
    {
        return DB::table('counterparty')
            ->where('counterpartyName', 'ilike', $name)
            ->value('id');
    }

    /** Резолвинг currency.id по коду ISO/названию (RUB/USD/EUR/GBP). */
    public static function resolveCurrencyId(string $code, ?int $default = null): ?int
    {
        $code = mb_strtoupper(trim($code));
        $row = DB::table('currency')
            ->where(function ($q) use ($code) {
                $q->where('nameEn', 'ilike', '%' . $code . '%')
                  ->orWhere('cbrCode', 'ilike', $code)
                  ->orWhere('symbol', $code);
            })
            ->orderByDesc('selectable')   // предпочитаем selectable=true
            ->value('id');
        return $row ?? $default;
    }

    /**
     * Выровнять строку по профилю: вернуть ассоциативный массив
     * [canonicalKey => value] c учётом заголовков.
     */
    public static function alignRow(array $row, array $headers, array $profile): array
    {
        $out = [];
        foreach ($profile['fields'] as $canonical => $headerName) {
            $idx = array_search($headerName, $headers, true);
            if ($idx === false) {
                // Попробуем нечёткий поиск — trim + ilike
                foreach ($headers as $i => $h) {
                    if (mb_strtolower(trim((string) $h)) === mb_strtolower(trim($headerName))) {
                        $idx = $i;
                        break;
                    }
                }
            }
            $out[$canonical] = $idx !== false ? ($row[$idx] ?? null) : null;
        }
        return $out;
    }
}
