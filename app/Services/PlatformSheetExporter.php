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
 *
 * ⚠ Строки из таблицы НЕ удаляются (по ним контрагенты держат ссылки на номера
 * строк), вместо этого у каждой вкладки есть колонки «Дата удаления» + «Удалён»
 * (Да/Нет) — фильтровать по ним. Soft-delete на платформе НЕ трогает
 * dateChanged, поэтому одного инкремента по watermark мало: каждый прогон
 * дополнительно сверяет колонку «Удалён» по всем строкам листа
 * (reconcileDeleted) — ловит и soft-delete, и строки, физически исчезнувшие
 * из БД.
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
                    // ⚠ «Комментарий» дописан В КОНЕЦ намеренно: контрагенты
                    // ссылаются на колонки листа по буквам, и вставка в
                    // середину сдвинула бы все даты вправо, поломав их формулы.
                    'Дата закрытия', 'Дата изменения', 'Дата удаления', 'Удалён', 'Комментарий'],
                'changedColumn' => 'c."changedAt"',
                'idTable' => 'contract',
                'deletedColumn' => '"deletedAt"',
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
                        c."createDate", c."openDate", c."closeDate", c."changedAt", c."deletedAt",
                        CASE WHEN c."deletedAt" IS NULL THEN 'Нет' ELSE 'Да' END AS is_deleted,
                        c.comment
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
                    WHERE (:since::timestamp IS NULL OR c."changedAt" > :since2::timestamp
                           OR c."deletedAt" > :since3::timestamp)
                    ORDER BY c.id
                    SQL,
            ],
            'clients' => [
                'title' => 'Клиенты',
                'headers' => ['Айди', 'Дата создания', 'Дата удаления', 'Дата изменения',
                    'ФИО клиента', 'ФИО консультанта', 'Почта', 'Телефон', 'Источник создания', 'Удалён'],
                'changedColumn' => 'cl."dateChanged"',
                'idTable' => 'client',
                'deletedColumn' => '"dateDeleted"',
                'sql' => <<<'SQL'
                    SELECT cl.id, cl."dateCreated", cl."dateDeleted", cl."dateChanged",
                        cl."personName" AS client_name, cl."consultantName",
                        cl.email, cl.phone,
                        cl.source,
                        CASE WHEN cl."dateDeleted" IS NULL THEN 'Нет' ELSE 'Да' END AS is_deleted
                    FROM client cl
                    WHERE (:since::timestamp IS NULL OR cl."dateChanged" > :since2::timestamp
                           OR cl."dateDeleted" > :since3::timestamp)
                    ORDER BY cl.id
                    SQL,
            ],
            'consultants' => [
                'title' => 'Консультанты',
                'headers' => ['Айди', 'Статус', 'ФИО консультанта', 'ФИО наставника',
                    'Партнёрский код', 'Почта', 'Телефон', 'Ник ТГ', 'Дата рождения', 'Страна', 'Город',
                    'Дата удаления', 'Удалён'],
                'changedColumn' => 'c."dateChanged"',
                'idTable' => 'consultant',
                'deletedColumn' => '"dateDeleted"',
                'sql' => <<<'SQL'
                    SELECT c.id,
                        act.name AS status,
                        c."personName", c."inviterName", c."participantCode",
                        COALESCE(wu.email, c.email) AS email,
                        COALESCE(wu.phone, c.phone) AS phone,
                        COALESCE(wu.telegram_username, wu."nicTG", c."nicTG") AS tg,
                        COALESCE(wu."birthDate"::text, c."birthDate") AS birth_date,
                        ctry."countryNameRu" AS country,
                        c.city AS city,
                        c."dateDeleted",
                        CASE WHEN c."dateDeleted" IS NULL THEN 'Нет' ELSE 'Да' END AS is_deleted
                    FROM consultant c
                    LEFT JOIN "WebUser" wu ON wu.id = c."webUser"
                    LEFT JOIN directory_of_activities act ON act.id = c.activity
                    LEFT JOIN country ctry ON ctry.id = c.country
                    WHERE (:since::timestamp IS NULL OR c."dateChanged" > :since2::timestamp
                           OR c."dateDeleted" > :since3::timestamp)
                    ORDER BY c.id
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

        $rows = DB::select($tab['sql'], ['since' => $since, 'since2' => $since, 'since3' => $since]);

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

        // Сверка признака удаления по ВСЕМ строкам листа (независимо от watermark).
        $marked = $this->reconcileDeleted($spreadsheetId, $tab, $existing);

        SystemSetting::put($settingKey, $startedAt->toDateTimeString());

        return [
            'updated' => count($updates),
            'appended' => count($appends),
            'deleted' => $marked,
            'since' => $since,
        ];
    }

    /**
     * Проставить «Удалён»/«Дата удаления» у строк, которые УЖЕ лежат в листе.
     *
     * Нужен потому, что soft-delete на платформе не двигает dateChanged, а
     * физически удалённые строки инкремент вообще никогда не увидит. Пишем две
     * колонки целиком (2 диапазона на вкладку), значения берём из БД; для строк,
     * которых в БД больше нет, ставим «Да», а дату оставляем как была.
     *
     * @param array $existing снимок листа ДО записи этого прогона (строки 1..N)
     * @return int сколько строк помечено удалёнными
     */
    private function reconcileDeleted(string $spreadsheetId, array $tab, array $existing): int
    {
        $flagIdx = array_search('Удалён', $tab['headers'], true);
        $dateIdx = array_search('Дата удаления', $tab['headers'], true);
        if ($flagIdx === false || empty($tab['idTable']) || count($existing) < 2) {
            return 0;
        }

        $state = [];
        foreach (DB::select("SELECT id, {$tab['deletedColumn']} AS del FROM {$tab['idTable']}") as $r) {
            $state[(string) $r->id] = $r->del;
        }

        $flagCol = [];
        $dateCol = [];
        $marked = 0;

        for ($i = 1; $i < count($existing); $i++) {
            $id = (string) ($existing[$i][0] ?? '');
            $curFlag = (string) ($existing[$i][$flagIdx] ?? '');
            $curDate = $dateIdx === false ? '' : (string) ($existing[$i][$dateIdx] ?? '');

            if ($id === '') {                     // пустая/служебная строка — не трогаем
                $flagCol[] = [$curFlag];
                $dateCol[] = [$curDate];
                continue;
            }

            if (! array_key_exists($id, $state)) { // строки в БД больше нет
                $flagCol[] = ['Да'];
                $dateCol[] = [$curDate];
                $marked++;
                continue;
            }

            $del = $state[$id];
            $flagCol[] = [$del === null ? 'Нет' : 'Да'];
            $dateCol[] = [$del === null ? '' : $this->fmt($del)];
            if ($del !== null) $marked++;
        }

        $lastRow = count($existing);
        $data = [[
            'range' => $tab['title'] . '!' . $this->colLetter($flagIdx + 1) . '2:' . $this->colLetter($flagIdx + 1) . $lastRow,
            'majorDimension' => 'ROWS',
            'values' => $flagCol,
        ]];
        if ($dateIdx !== false) {
            $data[] = [
                'range' => $tab['title'] . '!' . $this->colLetter($dateIdx + 1) . '2:' . $this->colLetter($dateIdx + 1) . $lastRow,
                'majorDimension' => 'ROWS',
                'values' => $dateCol,
            ];
        }
        $this->writer->batchUpdateValues($spreadsheetId, $data);

        return $marked;
    }

    /** Значение даты из БД → строка листа (PDO отдаёт строкой, но подстрахуемся). */
    private function fmt($v): string
    {
        if ($v === null) return '';
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        return (string) $v;
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
