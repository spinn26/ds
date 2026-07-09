<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

/**
 * Выгрузка данных платформы в Google-таблицу (вкладки Контракты / Клиенты /
 * Консультанты). Работает на постоянной основе: upsert по ID — изменённые
 * строки перезаписываются, новые дописываются (инкремент по changedAt).
 *
 * Целевая таблица и путь к service-account JSON — в api_settings
 * (google.sheets.export_id, google.sa.credentials_path).
 *
 * ⚠ «Свойство» и «Срок выплаты КВ» для контракта берутся best-effort из
 * последней транзакции (эти поля транзакционные, у контракта могут различаться
 * между транзакциями) — уточнить при необходимости.
 */
class PlatformSheetExporter
{
    public function __construct(private readonly GoogleSheetsWriter $writer) {}

    /** Конфиг вкладок: заголовки + запрос строк (id первой колонкой). */
    private function tabs(): array
    {
        return [
            'contracts' => [
                'title' => 'Контракты',
                'headers' => ['ID', 'Номер контракта', 'ID контрагента', 'Сумма', 'Валюта',
                    'Название программы', 'Название продукта', 'Название поставщика', 'Свойство',
                    'Срок контракта', 'Срок выплаты КВ', 'Название статуса', 'Название риск-профиля',
                    'Страна', 'ФИО клиента', 'ФИО консультанта', 'Дата создания', 'Дата открытия',
                    'Дата закрытия', 'Дата изменения', 'Дата удаления'],
                'changedColumn' => 'c."changedAt"',
                'sql' => <<<'SQL'
                    SELECT c.id,
                        c.number,
                        c."counterpartyContractId" AS counterparty_id,
                        c.ammount,
                        cur."currencyName" AS currency,
                        COALESCE(prc.name, c."programName") AS program,
                        COALESCE(pc.name, c."productName") AS product,
                        COALESCE(pc.provider_name, pr."providerName") AS provider,
                        prop.title AS property,
                        c.term,
                        tx.score AS kv_year,
                        cs.name AS status_name,
                        rp.name AS risk_profile,
                        ctry."countryNameRu" AS country,
                        c."clientName",
                        c."consultantName",
                        c."createDate", c."openDate", c."closeDate", c."changedAt", c."deletedAt"
                    FROM contract c
                    LEFT JOIN currency cur ON cur.id = c.currency
                    LEFT JOIN program pr ON pr.id = c.program
                    LEFT JOIN products_catalog pc ON pc.legacy_product_id = c.product
                    LEFT JOIN programs_catalog prc ON prc.legacy_program_id = c.program
                    LEFT JOIN "contractStatus" cs ON cs.id = c.status
                    LEFT JOIN "riskProfile" rp ON rp.id = c."riskProfile"
                    LEFT JOIN country ctry ON ctry.id = c.country
                    LEFT JOIN LATERAL (
                        SELECT t."commissionCalcProperty", t.score
                        FROM transaction t
                        WHERE t.contract = c.id AND t."deletedAt" IS NULL
                        ORDER BY t.date DESC NULLS LAST LIMIT 1
                    ) tx ON true
                    LEFT JOIN "commissionCalcProperty" prop ON prop.id = tx."commissionCalcProperty"
                    WHERE (:since::timestamp IS NULL OR c."changedAt" > :since2::timestamp)
                    ORDER BY c.id
                    SQL,
            ],
            'clients' => [
                'title' => 'Клиенты',
                'headers' => ['Айди', 'Дата создания', 'Дата удаления', 'Дата изменения',
                    'ФИО клиента', 'ФИО консультанта', 'Почта', 'Телефон', 'Источник создания'],
                'changedColumn' => 'cl."dateChanged"',
                'sql' => <<<'SQL'
                    SELECT cl.id, cl."dateCreated", cl."dateDeleted", cl."dateChanged",
                        cl."personName" AS client_name, cl."consultantName",
                        p.email, p.phone, cl.source
                    FROM client cl
                    LEFT JOIN person p ON p.id = cl.person
                    WHERE (:since::timestamp IS NULL OR cl."dateChanged" > :since2::timestamp)
                    ORDER BY cl.id
                    SQL,
            ],
            'consultants' => [
                'title' => 'Консультанты',
                'headers' => ['Айди', 'Статус', 'ФИО консультанта', 'ФИО наставника',
                    'Партнёрский код', 'Почта', 'Телефон', 'Ник ТГ', 'Дата рождения', 'Страна', 'Город'],
                'changedColumn' => 'c."dateChanged"',
                'sql' => <<<'SQL'
                    SELECT c.id,
                        act.name AS status,
                        c."personName", c."inviterName", c."participantCode",
                        COALESCE(wu.email, p.email) AS email,
                        COALESCE(wu.phone, p.phone) AS phone,
                        COALESCE(wu.telegram_username, wu."nicTG", p."nicTG") AS tg,
                        COALESCE(wu."birthDate"::text, p."birthDate"::text) AS birth_date,
                        ctry."countryNameRu" AS country,
                        p.city AS city
                    FROM consultant c
                    LEFT JOIN person p ON p.id = c.person
                    LEFT JOIN "WebUser" wu ON wu.id = c."webUser"
                    LEFT JOIN directory_of_activities act ON act.id = c.activity
                    LEFT JOIN country ctry ON ctry.id = c.country
                    WHERE (:since::timestamp IS NULL OR c."dateChanged" > :since2::timestamp)
                    ORDER BY c.id
                    SQL,
            ],
            // Клиенты, которых НЕ удалось автоматически выровнять по ФИО:
            // 0 совпадений person по ФИО или 2+ (неоднозначно). Для ручной сверки.
            'clients_review' => [
                'title' => 'Клиенты — на проверку',
                'headers' => ['Айди', 'ФИО клиента (карточка)', 'Текущая person (привязана)',
                    'Текущая почта', 'Текущий телефон', 'Совпадений по ФИО'],
                'changedColumn' => 'cl."dateChanged"',
                'sql' => <<<'SQL'
                    WITH pc AS (
                        SELECT btrim(lower("lastName"||' '||"firstName"||' '||coalesce(patronymic,''))) AS nm,
                               count(*) AS cnt
                        FROM person
                        GROUP BY 1
                    )
                    SELECT cl.id,
                        cl."personName" AS card_name,
                        p."lastName" || ' ' || p."firstName" AS current_person,
                        p.email, p.phone,
                        COALESCE(pcnt.cnt, 0) AS name_matches
                    FROM client cl
                    LEFT JOIN person p ON p.id = cl.person
                    LEFT JOIN pc pcnt ON pcnt.nm = btrim(lower(cl."personName"))
                    WHERE cl."dateDeleted" IS NULL AND cl."personName" IS NOT NULL
                      AND COALESCE(pcnt.cnt, 0) <> 1
                      AND (:since::timestamp IS NULL OR cl."dateChanged" > :since2::timestamp)
                    ORDER BY cl.id
                    SQL,
            ],
        ];
    }

    /** Выгрузить все вкладки. $full=true — игнорировать watermark (полная перезаливка). */
    public function exportAll(bool $full = false): array
    {
        $spreadsheetId = app(ApiSettingsService::class)->get('google.sheets.export_id')
            ?: config('services.google_sheets.export_id');
        if (! $spreadsheetId) {
            throw new \RuntimeException('Не задан google.sheets.export_id (id целевой таблицы)');
        }

        $out = [];
        foreach ($this->tabs() as $key => $tab) {
            $out[$key] = $this->exportTab($spreadsheetId, $key, $tab, $full);
        }
        return $out;
    }

    private function exportTab(string $spreadsheetId, string $key, array $tab, bool $full): array
    {
        $this->writer->ensureSheet($spreadsheetId, $tab['title']);

        // Текущее содержимое: карта id → номер строки (1-based) + синхронизация шапки.
        $existing = $this->writer->readValues($spreadsheetId, $tab['title']);
        // Шапку сравниваем ЦЕЛИКОМ (не только первую ячейку) — иначе добавленные
        // колонки не попадают в шапку, хотя данные-строки уже расширены.
        if (! isset($existing[0]) || array_values($existing[0]) !== $tab['headers']) {
            $this->writer->updateValues($spreadsheetId, $tab['title'] . '!A1', [$tab['headers']]);
            if (! isset($existing[0])) {
                $existing = [$tab['headers']];
            }
        }
        $idToRow = [];
        for ($i = 1; $i < count($existing); $i++) {
            $id = (string) ($existing[$i][0] ?? '');
            if ($id !== '') $idToRow[$id] = $i + 1; // 1-based строка листа
        }

        $settingKey = 'export.' . $key . '.last_run';
        $since = $full ? null : SystemSetting::value($settingKey, null);
        $startedAt = now();

        $rows = DB::select($tab['sql'], ['since' => $since, 'since2' => $since]);

        $updates = [];  // batchUpdate diapазоны
        $appends = [];  // новые строки
        $lastCol = $this->colLetter(count($tab['headers']));

        foreach ($rows as $r) {
            $vals = $this->rowValues((array) $r);
            $id = (string) $vals[0];
            if (isset($idToRow[$id])) {
                $rowNum = $idToRow[$id];
                $updates[] = [
                    'range' => "{$tab['title']}!A{$rowNum}:{$lastCol}{$rowNum}",
                    'majorDimension' => 'ROWS',
                    'values' => [$vals],
                ];
            } else {
                $appends[] = $vals;
            }
        }

        // Пишем пачками (лимит Sheets API — держим чанки).
        foreach (array_chunk($updates, 500) as $chunk) {
            $this->writer->batchUpdateValues($spreadsheetId, $chunk);
        }
        foreach (array_chunk($appends, 2000) as $chunk) {
            $this->writer->appendValues($spreadsheetId, $tab['title'], $chunk);
        }

        SystemSetting::put($settingKey, $startedAt->toDateTimeString());

        return ['updated' => count($updates), 'appended' => count($appends), 'since' => $since];
    }

    /** Значения строки в порядке колонок запроса (id первым). Даты — ISO, null → ''. */
    private function rowValues(array $row): array
    {
        $vals = array_values($row);
        return array_map(function ($v) {
            if ($v === null) return '';
            if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
            if (is_bool($v)) return $v ? 'TRUE' : 'FALSE';
            // Строки-даты Postgres оставляем как есть.
            return (string) $v;
        }, $vals);
    }

    /** Номер колонки → буква (1→A, 27→AA). */
    private function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
        return $s;
    }
}
