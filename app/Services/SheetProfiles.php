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
     *   fields => [canonicalKey => header | [header, ...алиасы]]
     * ]
     *
     * Заголовок можно задать списком: поставщики переименовывают колонки в
     * листе, и профиль должен переживать это без падения всего импорта
     * (IB MF: «ID сделки» → «Контракт»).
     *
     * canonicalKey из набора: contract_number, client_name, amount,
     * commission, date, currency, productName, programName, service_type.
     */
    public const PROFILES = [
        'робоэдвайзер' => [
            // Поставщик листа «робоэдвайзер» — RG.HT (канал), а не Тинькофф.
            // (RG.HT = counterparty id 8; ранее ошибочно матчился на Тинькофф.)
            'counterpartyName' => 'RG.HT',
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
            // commissionCalcProperty=9 («МФ») — лист «робоэдвайзер»
            // импортируется как Тинькофф портфель / Робоэдвайзер с
            // дефолтным свойством МФ. Раньше property терялась → 1003
            // майских транзакции пришли с NULL (фикс 33fab7d4 закрыл
            // IB MF/IB UP, но не этот лист). Бэкфилл существующих —
            // artisan finance:backfill-tinkoff-property.
            'commissionCalcProperty' => 9,
            // МФ грузим «Своей комиссией» (ТЗ 2026-08-17): брокер платит МФ
            // пропорционально времени нахождения активов на счёте за квартал,
            // поэтому фактическая «Сумма, руб.» (30 ₽ = 0,3%) не совпадает со
            // ставкой из продуктовой сетки (0,5% = 50 ₽). Доход ДС берём из
            // отчёта как есть, ставку выводим: сумма / база × 100.
            // Апфронт не трогаем — он идёт по тарифу 2% из «Продуктов».
            'custom_commission_properties' => [9],
            // ⚠ «Сумма, руб.» в отчёте брокера — доход ДС С НДС, в отличие от
            // ГГА (там сумма уже без НДС, см. custom_commission). Без этого
            // флага 1 000 ₽ из файла попадали в «Доход ДС БЕЗ НДС», а сверху
            // накручивался НДС и в «Доходе ДС» получалось 1 050,11 ₽.
            'custom_commission_gross' => true,
        ],
        'IB MF' => [
            'counterpartyName' => 'Interactive Brokers',
            'productHint' => 'IB MF',
            'fields' => [
                'date'            => 'Дата',
                'client_name'     => 'Клиент',
                // Колонку переименовали в листе: было «ID сделки», стало
                // «Контракт» — импорт падал «пустой номер контракта» на ВСЕХ
                // строках (172 из 172, 2026-08-06). Держим оба варианта.
                'contract_number' => ['Контракт', 'ID сделки'],
                'commission'      => 'Всего комиссии',
            ],
            // Лист IB MF временами приходит БЕЗ строки заголовков (первая
            // строка пустая). Позиционные заголовки — порядок колонок данных:
            // дата, клиент, № контракта, комиссия. Используются, только когда
            // фактическая шапка пуста.
            'headerless' => ['Дата', 'Клиент', 'Контракт', 'Всего комиссии'],
            'currency' => 'USD',
            // commissionCalcProperty=9 («МФ») — лист содержит только MF-комиссии,
            // профиль сам задаёт свойство, чтобы не возиться с per-row маппингом.
            'commissionCalcProperty' => 9,
        ],
        'IB UP' => [
            'counterpartyName' => 'Interactive Brokers',
            'productHint' => 'IB UP',
            'fields' => [
                'date'            => 'Дата',
                'client_name'     => 'Клиент',
                'contract_number' => ['Контракт', 'ID сделки'],
                'commission'      => 'Сумма вознаграждения',
            ],
            // Лист IB UP сейчас приходит с ПУСТОЙ строкой заголовков — как и
            // IB MF. Без позиционных заголовков alignRow не мапил ничего и
            // импорт падал целиком.
            'headerless' => ['Дата', 'Клиент', 'Контракт', 'Сумма вознаграждения'],
            'currency' => 'USD',
            // commissionCalcProperty=10 («Апфронт») — апфронт-комиссия по IB.
            'commissionCalcProperty' => 10,
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
            // «Размер комиссии» хранится долей (0.055 = 5.5%), не процентами;
            // ×100 приводит к формату dsCommissionPercentage платформы.
            // Валюта — per-row из колонки «Валюта» (USD/EUR), поэтому top-level
            // 'currency' НЕ задаём (иначе перебьёт per-row → всё в одну валюту).
            'commission_pct_scale' => 100,
        ],
        // ГГА — по ТЗ «Импорт транзакций ГГА на платформу» (2026-08-06).
        // Сумма контракта берётся из «исходника» (столбец E), а не из «базы»:
        // в листе E заполнен у 26 из 61 строки, и там, где он есть и отличается
        // от базы, готовая колонка «Сумма комиссии / Сумма контракта (decimal)»
        // посчитана именно от него (BDONL-010278: 1060.67/6151.5 = 0.1724, а не
        // /24606 = 0.0431). Где исходника нет — падаем на базу, тогда сходится
        // с той же колонкой. Ставку не берём из отчёта: %ДС считается от суммы
        // комиссии — см. 'custom_commission'.
        'ГГА' => [
            'counterpartyName' => 'ГГА',
            'productHint' => 'ГГА',
            'fields' => [
                'client_name'     => 'Клиент',
                'policy_number'   => 'Номер полиса',
                'contract_number' => 'Номер контракта',
                'commission'      => 'Сумма комиссии',
                'amount'          => 'Сумма контракта (исходник)',
                'amount_fallback' => 'Сумма контракта (база)',
                'programName'     => 'Программа',
                'productName'     => 'Продукт',
                'date'            => 'Дата',
            ],
            'currency' => 'RUB',
            // «Своя комиссия»: доход ДС = сумма комиссии из отчёта как есть,
            // ставка выводится из неё (сумма комиссии / сумма контракта × 100).
            'custom_commission' => true,
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
                // ⚠ «g» — опечатка в шапке листа (A1), затёршая «Номер
                // контракта»: колонка содержит нормальные номера (L-00014…),
                // но импорт падал «пустой номер контракта» на всех строках.
                // Править надо в таблице; сервисному аккаунту платформы
                // (ds-platform@ds-platform-429508.iam.gserviceaccount.com)
                // выдан только просмотр, поэтому держим алиас. Как заголовок
                // поправят — алиас можно убрать.
                'contract_number' => ['Номер контракта', 'g'],
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
        if ($code === '') return $default;

        $row = DB::table('currency')
            ->where(function ($q) use ($code) {
                // Основной матч: currencyName начинается с ISO-кода
                // («USD Доллар США», «EUR Евро», «GBP Фунт…»). nameEn/symbol
                // ненадёжны: «US Dollar» не содержит «USD», а symbol «$» общий
                // для 7+ валют — из-за этого resolveCurrencyId('USD') давал NULL
                // и Trust грузился в RUB.
                $q->where('currencyName', 'ilike', $code . '%')
                  ->orWhere('cbrCode', 'ilike', $code)
                  ->orWhere('nameEn', 'ilike', '%' . $code . '%')
                  ->orWhere('symbol', $code);
            })
            ->orderByDesc('selectable')   // предпочитаем selectable=true
            ->value('id');
        return $row ?? $default;
    }

    /** @var array<string,int>|null кэш commissionCalcProperty.title → id */
    private static ?array $propertyTitleMap = null;

    /**
     * Резолвинг commissionCalcProperty.id по значению из листа: id числом,
     * название («МФ», «Апфронт», «5 год») или английский алиас (mf/up/upfront).
     * Канон для импорта — используется и джобой, и валидатором.
     */
    public static function resolvePropertyId($value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (int) $value;

        if (self::$propertyTitleMap === null) {
            self::$propertyTitleMap = [];
            foreach (DB::table('commissionCalcProperty')->get(['id', 'title']) as $p) {
                self::$propertyTitleMap[mb_strtolower(trim((string) $p->title))] = (int) $p->id;
            }
        }

        // NBSP/двойные пробелы из Sheets схлопываем — иначе «мф » мимо карты.
        $key = preg_replace('/[\pZ\s]+/u', ' ', mb_strtolower(trim((string) $value)));
        if (isset(self::$propertyTitleMap[$key])) return self::$propertyTitleMap[$key];

        // Лёгкие алиасы: «MF», «UP» — английские варианты МФ/Апфронт.
        $aliases = ['mf' => 'мф', 'up' => 'апфронт', 'upfront' => 'апфронт'];
        if (isset($aliases[$key], self::$propertyTitleMap[$aliases[$key]])) {
            return self::$propertyTitleMap[$aliases[$key]];
        }
        return null;
    }

    /**
     * Выровнять строку по профилю: вернуть ассоциативный массив
     * [canonicalKey => value] c учётом заголовков.
     */
    public static function alignRow(array $row, array $headers, array $profile): array
    {
        $out = [];
        foreach ($profile['fields'] as $canonical => $headerName) {
            $idx = self::headerIndex($headers, $headerName);
            $out[$canonical] = $idx !== null ? ($row[$idx] ?? null) : null;
        }
        return $out;
    }

    /**
     * Индекс колонки по имени заголовка (или по списку алиасов): сначала
     * точное совпадение, затем без учёта регистра и пробелов по краям.
     *
     * @param  string|array<int,string>  $headerName
     */
    public static function headerIndex(array $headers, $headerName): ?int
    {
        foreach ((array) $headerName as $candidate) {
            $idx = array_search($candidate, $headers, true);
            if ($idx !== false) {
                return (int) $idx;
            }
            foreach ($headers as $i => $h) {
                if (mb_strtolower(trim((string) $h)) === mb_strtolower(trim((string) $candidate))) {
                    return (int) $i;
                }
            }
        }

        return null;
    }
}
