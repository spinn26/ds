<?php

namespace App\Services;

use App\Models\Contract;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;

/**
 * Забор данных из Google-таблицы «База для загрузки контрактов Парус/Акцент».
 *
 * Как устроен процесс:
 *   1. Сделка при поступлении заводится на платформе со статусом «Комплайнс» —
 *      чтобы сразу попасть в прогноз выручки.
 *   2. После сверки с контрагентом бэкофис вносит итоговые данные в таблицу.
 *   3. По кнопке платформа читает лист и приводит контракты к нему.
 *
 * Ключ сопоставления — НОМЕР контракта (столбец D), а не ID: номер бэкофис
 * знает и вводит, ID платформы — нет. Найденному контракту его ID
 * проставляется обратно в столбец C, ненайденный контракт создаётся, и в
 * столбец C уходит ID новой записи. После первого прогона лист оказывается
 * связан с платформой по ID, и дальше сверять становится проще.
 *
 * Таблица — источник истины: при расхождении перезаписывается ПЛАТФОРМА.
 * Каждая правка попадает в «Историю изменений» контракта с автором (кто
 * нажал кнопку), датой, старым и новым значением и основанием.
 *
 * Столбцы адресуются позиционно (ТЗ §3), а не по шапке: заголовки в листе
 * живые («партнёр » с хвостовым пробелом), завязываться на них нельзя.
 *
 * ⚠ ФИО клиента и партнёра НЕ перезаписываются автоматически. По ТЗ §4.2
 * (исключение) и §5 расхождение по ФИО останавливает синхронизацию и
 * показывается списком: смена клиента или партнёра у контракта двигает
 * деньги по дереву наставников, такое делается руками и осознанно.
 */
class ContractSheetSyncService
{
    public const DEFAULT_SPREADSHEET_ID = '1IYWUzLvnD0woPLHI9VU0q8wLy4CE2Yv3jyBWhlMIG4Y';
    public const DEFAULT_SHEET = 'Лист1';

    /** Основание правки, попадает в ленту истории контракта. */
    public const REASON = 'Обновлено из таблицы Парус/Акцент';

    // Раскладка столбцов per ТЗ §3.
    private const COL_STATUS = 0;    // A: Статус
    private const COL_OPENED = 1;    // B: открыт
    private const COL_ID = 2;        // C: ID контракта на платформе
    private const COL_NUMBER = 3;    // D: Номер
    private const COL_CLIENT = 4;    // E: клиент
    private const COL_PARTNER = 5;   // F: партнёр
    private const COL_PRODUCT = 6;   // G: Продукт
    private const COL_PROGRAM = 7;   // H: Программа
    private const COL_AMOUNT = 8;    // I: Сумма по акту
    private const COL_CURRENCY = 9;  // J: валюта по акту

    /** ТЗ §4.3: «Активирован» дополнительно тянет дату открытия из столбца B. */
    private const STATUS_ACTIVATED = 'активирован';

    public function __construct(
        private readonly GoogleSheetsReader $reader,
        private readonly GoogleSheetsWriter $writer,
        private readonly ApiSettingsService $settings,
        private readonly AccrualForecastService $forecast,
    ) {}

    /**
     * @param  bool  $dryRun  посчитать изменения, но ничего не писать —
     *                        ни на платформу, ни в таблицу.
     * Ключи ответа: status, message, updated, checked, changes, nameMismatches,
     * errors и — только у реального прогона — runId для отката.
     *
     * @return array<string, mixed>
     */
    public function run(bool $dryRun = false): array
    {
        $spreadsheetId = $this->settings->get('google.sheets.parus_id') ?: self::DEFAULT_SPREADSHEET_ID;
        $sheet = $this->settings->get('google.sheets.parus_sheet') ?: self::DEFAULT_SHEET;

        $rows = $this->reader->readRawRows($spreadsheetId, $sheet, $this->settings->get('google.sheets.api_key'));
        array_shift($rows); // шапка

        $plan = [];            // строки, готовые к применению
        $nameMismatches = [];
        $errors = [];
        $checked = 0;

        // --- Проход 1: разбор и сверка. Ничего не пишем. ---
        foreach ($rows as $i => $row) {
            $line = $i + 2; // человеческий номер строки в листе

            if (trim(implode('', $row)) === '') {
                continue;
            }
            $checked++;

            $number = trim((string) ($row[self::COL_NUMBER] ?? ''));
            if ($number === '') {
                $errors[] = $this->rowRef($line, $row, 'не заполнен номер контракта — сопоставить не с чем');
                continue;
            }

            $parsed = $this->parseRow($row, $line, $errors);
            if ($parsed === null) {
                continue; // причина уже в $errors
            }

            // Сопоставление: ID (ТЗ §4.1) в приоритете, номер — запасной путь.
            // В свежем листе столбец C пуст, поэтому первый прогон идёт по
            // номеру и сам проставляет ID; дальше работает уже связка по ID,
            // и номер становится обычным сверяемым полем (§4.2).
            $sheetId = trim((string) ($row[self::COL_ID] ?? ''));

            if ($sheetId !== '' && ctype_digit($sheetId)) {
                $contract = Contract::whereNull('deletedAt')->find((int) $sheetId);
                if (! $contract) {
                    $errors[] = $this->rowRef($line, $row, "контракт с ID {$sheetId} не найден на платформе");
                    continue;
                }
            } else {
                $contract = Contract::whereNull('deletedAt')->where('number', $number)->first();
            }

            // Контракты синхронизация НЕ создаёт (в ТЗ этого нет): заводятся
            // они на платформе при поступлении сделки, а лист только приносит
            // итоги сверки. Ненайденная строка — повод разобраться руками.
            if (! $contract) {
                $errors[] = $this->rowRef($line, $row, "контракт «{$number}» не найден на платформе");
                continue;
            }

            // ФИО: только сверяем, никогда не перезаписываем (см. докблок).
            foreach ([
                ['клиента', $parsed['clientName'], $contract->clientName],
                ['партнёра', $parsed['partnerName'], $this->consultantName($contract)],
            ] as [$what, $fromSheet, $onPlatform]) {
                if ($fromSheet === '' || $this->sameName($fromSheet, (string) $onPlatform)) {
                    continue;
                }
                $nameMismatches[] = [
                    'line' => $line,
                    'contractId' => $contract->id,
                    'number' => $contract->number,
                    'field' => $what,
                    'sheet' => $fromSheet,
                    'platform' => (string) $onPlatform ?: '—',
                ];
            }

            $plan[] = [
                'line' => $line,
                'contractId' => $contract->id,
                'number' => $contract->number,
                'fields' => $this->diffFor($contract, $parsed),
                // Пустой или чужой ID в столбце C — проставим свой.
                'needsId' => $sheetId !== (string) $contract->id,
            ];
        }

        // ТЗ §5: расхождение по ФИО останавливает синхронизацию целиком.
        if ($nameMismatches !== []) {
            return $this->report('name_mismatch',
                'Синхронизация остановлена: расходятся ФИО. Исправьте вручную на платформе и запустите снова.',
                0, $checked, [], $nameMismatches, $errors);
        }

        $changes = array_values(array_filter($plan, static fn ($p) => $p['fields'] !== []));

        if ($dryRun) {
            return $this->report('ok',
                sprintf('Проверено строк: %d. К обновлению: %d.', $checked, count($changes)),
                count($changes), $checked, $changes, [], $errors);
        }

        // --- Проход 2: запись на платформу. ---
        $updated = 0;
        $idCells = []; // что проставить обратно в столбец C

        DB::transaction(function () use ($plan, &$updated, &$idCells, $sheet) {
            foreach ($plan as $step) {
                if ($step['fields'] !== []) {
                    $contract = Contract::find($step['contractId']);
                    if ($contract) {
                        // Через модель, а не DB::update: у Contract подключён
                        // activitylog, и правка должна попасть в историю
                        // карточки — с автором, датой и старым значением.
                        $contract->activityReason = self::REASON;
                        $contract->fill(array_map(static fn ($f) => $f['value'], $step['fields']));
                        $contract->save();
                        $this->forecast->recomputeForContract($contract->id);
                        $updated++;
                    }
                }

                if ($step['needsId']) {
                    $idCells[] = ['range' => sprintf('%s!C%d', $sheet, $step['line']), 'values' => [[$step['contractId']]]];
                }
            }
        });

        // --- Проход 3: проставить ID обратно в лист. ---
        // Ошибка записи в таблицу не должна выглядеть как провал всей
        // синхронизации: на платформе всё уже применено и откатывать нечего.
        $idWriteError = null;
        if ($idCells) {
            try {
                $this->writer->batchUpdateValues($spreadsheetId, array_map(
                    static fn ($c) => ['range' => $c['range'], 'majorDimension' => 'ROWS', 'values' => $c['values']],
                    $idCells,
                ));
            } catch (\Throwable $e) {
                $idWriteError = $e->getMessage();
            }
        }

        // Журнал прогона — по нему катится откат, если в лист попали неверные
        // данные. Пишем даже нулевой прогон: в истории должно быть видно, что
        // кнопку жали и ничего не изменилось.
        $runId = DB::table('contract_sheet_sync_log')->insertGetId([
            'status' => 'success',
            'checked_count' => $checked,
            'updated_count' => $updated,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Формулировка из ТЗ §5.
        $message = sprintf('Синхронизация завершена. Обновлено %d контрактов.', $updated);
        if ($idWriteError) {
            $message .= ' Данные применены, но проставить ID обратно в таблицу не удалось.';
        }

        return $this->report('ok', $message, $updated, $checked, $changes, [], $errors) + ['runId' => $runId];
    }

    /**
     * Откатить прогон: вернуть контрактам значения, которые были до него.
     *
     * ⚠ Поле, которое после синхронизации успели поправить руками, НЕ
     * трогаем: у него текущее значение уже не то, что мы записали, и вернуть
     * «как было до синхронизации» значило бы затереть более свежую правку
     * человека. Такие поля перечисляются в ответе — с ними разбираются глазами.
     *
     * Ключи ответа: status, message, restored, skipped.
     *
     * @return array<string, mixed>
     */
    public function rollback(int $runId): array
    {
        $run = DB::table('contract_sheet_sync_log')->where('id', $runId)->first();

        if (! $run) {
            return ['status' => 'error', 'message' => 'Прогон не найден', 'restored' => 0, 'skipped' => []];
        }
        if ($run->status === 'rolled_back') {
            return ['status' => 'error', 'message' => 'Этот прогон уже откачен', 'restored' => 0, 'skipped' => []];
        }

        /** @var list<array<string, mixed>> $changes */
        $changes = json_decode((string) $run->changes, true) ?: [];
        $restored = 0;
        $skipped = [];

        DB::transaction(function () use ($changes, $runId, &$restored, &$skipped) {
            foreach ($changes as $change) {
                $contract = Contract::find((int) ($change['contractId'] ?? 0));
                if (! $contract) {
                    $skipped[] = ['contractId' => $change['contractId'] ?? null, 'reason' => 'контракт удалён'];
                    continue;
                }

                $restore = [];
                /** @var array<string, array<string, mixed>> $fields */
                $fields = $change['fields'] ?? [];
                foreach ($fields as $field => $f) {
                    if (! $this->stillHasSyncedValue($contract, $field, $f['value'] ?? null)) {
                        $skipped[] = [
                            'contractId' => $contract->id,
                            'number' => $contract->number,
                            'field' => $f['label'] ?? $field,
                            'reason' => 'значение меняли после синхронизации',
                        ];
                        continue;
                    }
                    $restore[$field] = $f['old'] ?? null;
                }

                if ($restore === []) {
                    continue;
                }

                $contract->activityReason = sprintf('Откат синхронизации с таблицей Парус/Акцент (прогон #%d)', $runId);
                $contract->fill($restore);
                $contract->save();
                $this->forecast->recomputeForContract($contract->id);
                $restored++;
            }

            DB::table('contract_sheet_sync_log')->where('id', $runId)->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'rolled_back_by' => auth()->id(),
                'updated_at' => now(),
            ]);
        });

        return [
            'status' => 'ok',
            'message' => $skipped
                ? sprintf('Откат выполнен. Возвращено контрактов: %d, пропущено полей: %d.', $restored, count($skipped))
                : sprintf('Откат выполнен. Возвращено контрактов: %d.', $restored),
            'restored' => $restored,
            'skipped' => $skipped,
        ];
    }

    /**
     * Осталось ли у контракта то значение, которое записала синхронизация.
     * Если нет — поле трогали после неё, и откатывать его нельзя.
     */
    private function stillHasSyncedValue(Contract $contract, string $field, mixed $written): bool
    {
        $current = $contract->getAttribute($field);

        if ($field === 'openDate') {
            return ($current?->format('Y-m-d')) === $written;
        }
        if ($field === 'ammount') {
            return abs((float) $current - (float) $written) < 0.005;
        }
        if (in_array($field, ['product', 'program', 'currency', 'status'], true)) {
            return (int) $current === (int) $written;
        }

        return (string) $current === (string) $written;
    }

    /**
     * Разбор строки в значения платформы. null — строку применять нельзя,
     * причина уже записана в $errors: применить половину полей хуже, чем
     * не применить ничего.
     *
     * @param  list<string>  $row
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>|null
     */
    private function parseRow(array $row, int $line, array &$errors): ?array
    {
        $out = [
            'clientName' => trim((string) ($row[self::COL_CLIENT] ?? '')),
            'partnerName' => trim((string) ($row[self::COL_PARTNER] ?? '')),
            'number' => trim((string) ($row[self::COL_NUMBER] ?? '')),
        ];

        // G: Продукт. Только точное совпадение: подстрочный поиск делает из
        // «Парус» — «Парус-МАКС» (баг уже переименовывал 75 контрактов).
        $productName = trim((string) ($row[self::COL_PRODUCT] ?? ''));
        if ($productName !== '') {
            $id = DB::table('product')->whereRaw('LOWER(name) = LOWER(?)', [$productName])->value('id');
            if (! $id) {
                $errors[] = $this->rowRef($line, $row, "продукт «{$productName}» не найден в справочнике");
                return null;
            }
            $out['product'] = (int) $id;
            $out['productName'] = $productName;
        }

        // H: Программа — в пределах продукта.
        $programName = trim((string) ($row[self::COL_PROGRAM] ?? ''));
        if ($programName !== '') {
            if (! isset($out['product'])) {
                $errors[] = $this->rowRef($line, $row, 'программа указана без продукта');
                return null;
            }
            $id = DB::table('program')->where('product', $out['product'])
                ->whereRaw('LOWER(name) = LOWER(?)', [$programName])->value('id');
            if (! $id) {
                $errors[] = $this->rowRef($line, $row, "программа «{$programName}» не найдена у продукта «{$productName}»");
                return null;
            }
            $out['program'] = (int) $id;
            $out['programName'] = $programName;
        }

        // I: Сумма по акту. «2 836,00» — неразрывные пробелы в разрядах.
        $rawAmount = (string) ($row[self::COL_AMOUNT] ?? '');
        if (trim($rawAmount) !== '') {
            $out['ammount'] = Numbers::decimal($rawAmount);
        }

        // J: валюта — приходит символом «₽».
        $currencyRaw = trim((string) ($row[self::COL_CURRENCY] ?? ''));
        if ($currencyRaw !== '') {
            $id = DB::table('currency')
                ->where(function ($q) use ($currencyRaw) {
                    $q->where('symbol', $currencyRaw)
                        ->orWhereRaw('LOWER("nameRu") = LOWER(?)', [$currencyRaw])
                        ->orWhereRaw('LOWER("nameEn") = LOWER(?)', [$currencyRaw])
                        ->orWhereRaw('LOWER("currencyName") = LOWER(?)', [$currencyRaw]);
                })->value('id');
            if (! $id) {
                $errors[] = $this->rowRef($line, $row, "валюта «{$currencyRaw}» не распознана");
                return null;
            }
            $out['currency'] = (int) $id;
        }

        // A: Статус + B: дата открытия (ТЗ §4.3) — это и есть итог сверки.
        $statusRaw = trim((string) ($row[self::COL_STATUS] ?? ''));
        if ($statusRaw !== '') {
            $id = DB::table('contractStatus')->whereRaw('LOWER(name) = LOWER(?)', [$statusRaw])->value('id');
            if (! $id) {
                $errors[] = $this->rowRef($line, $row, "статус «{$statusRaw}» не найден в справочнике");
                return null;
            }
            $out['status'] = (int) $id;

            // Дату проставляем только для «Активирован» и только если столбец B
            // заполнен: затирать проставленную дату пустотой нельзя.
            if (mb_strtolower($statusRaw) === self::STATUS_ACTIVATED) {
                $date = $this->parseDate((string) ($row[self::COL_OPENED] ?? ''));
                if ($date) {
                    $out['openDate'] = $date;
                }
            }
        }

        return $out;
    }

    /**
     * Поля контракта, расходящиеся с таблицей.
     *
     * `value` уходит в БД, `from`/`to` — для отчёта: у ссылочных полей это
     * разные вещи (в базу id, в отчёт название).
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, array{value: mixed, old: mixed, from: mixed, to: mixed, label: string}>
     */
    private function diffFor(Contract $contract, array $parsed): array
    {
        $diff = [];

        // Номер — сверяемое поле по ТЗ §4.2. Расходиться он может только при
        // сопоставлении по ID: при поиске по номеру они равны по определению.
        if ($parsed['number'] !== '' && $parsed['number'] !== (string) $contract->number) {
            $diff['number'] = ['value' => $parsed['number'], 'old' => $contract->number, 'from' => $contract->number, 'to' => $parsed['number'], 'label' => 'номер'];
        }

        if (isset($parsed['product']) && $parsed['product'] !== (int) $contract->product) {
            $diff['product'] = ['value' => $parsed['product'], 'old' => (int) $contract->product, 'from' => $contract->productName, 'to' => $parsed['productName'], 'label' => 'продукт'];
            // Денорм-имя обновляем той же правкой: без него карточка покажет
            // старое название.
            $diff['productName'] = ['value' => $parsed['productName'], 'old' => $contract->productName, 'from' => $contract->productName, 'to' => $parsed['productName'], 'label' => 'продукт (название)'];
        }

        if (isset($parsed['program']) && $parsed['program'] !== (int) $contract->program) {
            $diff['program'] = ['value' => $parsed['program'], 'old' => (int) $contract->program, 'from' => $contract->programName, 'to' => $parsed['programName'], 'label' => 'программа'];
            $diff['programName'] = ['value' => $parsed['programName'], 'old' => $contract->programName, 'from' => $contract->programName, 'to' => $parsed['programName'], 'label' => 'программа (название)'];
        }

        if (isset($parsed['ammount']) && abs($parsed['ammount'] - (float) $contract->ammount) > 0.005) {
            $diff['ammount'] = ['value' => $parsed['ammount'], 'old' => (float) $contract->ammount, 'from' => (float) $contract->ammount, 'to' => $parsed['ammount'], 'label' => 'сумма'];
        }

        if (isset($parsed['currency']) && $parsed['currency'] !== (int) $contract->currency) {
            $diff['currency'] = ['value' => $parsed['currency'], 'old' => (int) $contract->currency, 'from' => $contract->currency, 'to' => $parsed['currency'], 'label' => 'валюта'];
        }

        if (isset($parsed['status']) && $parsed['status'] !== (int) $contract->status) {
            $diff['status'] = ['value' => $parsed['status'], 'old' => (int) $contract->status, 'from' => $this->statusName((int) $contract->status), 'to' => $this->statusName($parsed['status']), 'label' => 'статус'];
        }

        if (isset($parsed['openDate']) && $parsed['openDate'] !== $contract->openDate?->format('Y-m-d')) {
            $diff['openDate'] = ['value' => $parsed['openDate'], 'old' => $contract->openDate?->format('Y-m-d'), 'from' => $contract->openDate?->format('d.m.Y'), 'to' => $parsed['openDate'], 'label' => 'дата открытия'];
        }

        return $diff;
    }

    private function statusName(?int $id): ?string
    {
        return $id ? DB::table('contractStatus')->where('id', $id)->value('name') : null;
    }

    /** ФИО партнёра у контракта: денорм-поле, иначе живое имя из карточки. */
    private function consultantName(Contract $contract): string
    {
        if (! empty($contract->consultantName)) {
            return (string) $contract->consultantName;
        }

        return (string) DB::table('consultant')->where('id', $contract->consultant)->value('personName');
    }

    /** Сравнение ФИО: регистр, кратные пробелы и «ё» различием не считаем. */
    private function sameName(string $a, string $b): bool
    {
        $norm = static fn (string $s): string => str_replace(
            'ё', 'е',
            mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? ''),
        );

        return $norm($a) === $norm($b);
    }

    /** Дата из листа: 01.09.2026, 2026-09-01 или 01/09/2026. */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'd.m.y'] as $format) {
            $d = \DateTime::createFromFormat($format, $raw);
            if ($d && $d->format($format) === $raw) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $row
     * @return array<string, mixed>
     */
    private function rowRef(int $line, array $row, string $reason): array
    {
        return [
            'line' => $line,
            'id' => trim((string) ($row[self::COL_ID] ?? '')),
            'number' => trim((string) ($row[self::COL_NUMBER] ?? '')),
            'client' => trim((string) ($row[self::COL_CLIENT] ?? '')),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  list<array<string, mixed>>  $nameMismatches
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>
     */
    private function report(string $status, string $message, int $updated,
        int $checked, array $changes, array $nameMismatches, array $errors): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'updated' => $updated,
            'checked' => $checked,
            'changes' => $changes,
            'nameMismatches' => $nameMismatches,
            'errors' => $errors,
        ];
    }
}
