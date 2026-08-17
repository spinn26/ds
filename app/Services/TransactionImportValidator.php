<?php

namespace App\Services;

use App\Services\SheetProfiles;
use Illuminate\Support\Facades\DB;

/**
 * Валидация строк импорта транзакций: разбор, поиск контракта, курс, свойство.
 *
 * Вынесено из ImportTransactionsJob — там это была середина метода handle()
 * на 334 строки. Код перенесён дословно. Job остался «загрузить → ПРОВЕРИТЬ →
 * вставить пачкой → записать итог», а всё, что про отдельную строку, живёт
 * здесь.
 *
 * ⚠ Правила, за каждым из которых стоит инцидент (сетка —
 * TransactionImportJobTest):
 *   - суммы чистятся от неразрывных пробелов: «7 754,00» это 7754, а не 7;
 *   - частичное совпадение номера принимается ТОЛЬКО при единственном
 *     кандидате, иначе деньги уходили в чужой контракт;
 *   - нет курса на месяц строки — ошибка, а не пересчёт один к одному,
 *     занижавший рублёвую сумму примерно в восемьдесят раз;
 *   - строка в закрытом периоде пропускается с предупреждением, а не роняет
 *     импорт; всё прочее — ошибка, и она отменяет ВСЮ пачку (атомарность
 *     проверяет уже job).
 */
class TransactionImportValidator
{
    /**
     * @param list<array<string, mixed>> $rows строки источника
     * @param ?int $resolvedCurrency валюта импорта (перебивается колонкой строки)
     * @param ?string $profileWarning расхождение шапки листа с профилем
     * @param ?callable $onProgress (обработано, ошибок) — куда писать прогресс,
     *        знает вызывающий; сервис только считает
     * @return array{prepared: list<array<string, mixed>>, errors: list<string>, warnings: list<string>}
     */
    public function validate(array $rows, ?int $resolvedCurrency, ?string $profileWarning, ?callable $onProgress = null): array
    {
        // === STEP 2: validation (parse + match contract) ===
        // Курс — ПО ДАТЕ СТРОКИ (валюта тоже per-row: у Trust/Woodville она
        // задаётся колонкой «Валюта» и различается по строкам). Раньше курс
        // брался один на всю пачку и «последний в справочнике» — импорт
        // майских выплат в июле конвертировал их по июльскому курсу.
        // CurrencyRates сам кэширует по (валюта, месяц).
        $rateFor = fn (?int $cur, $date = null): float => \App\Support\CurrencyRates::forDate($cur, $date);

        $contractMap = $this->contractsByNumber($rows);

        // Локальный кэш period-freeze: для 1267 строк одного-двух
        // периодов вместо 1267 SELECT'ов делаем 1-2.
        $periodFreeze = app(\App\Services\PeriodFreezeService::class);
        $frozenCache = [];
        $isFrozen = function (int $y, int $m) use ($periodFreeze, &$frozenCache): bool {
            $key = "{$y}-{$m}";
            if (! array_key_exists($key, $frozenCache)) {
                $frozenCache[$key] = $periodFreeze->isFrozen($y, $m);
            }
            return $frozenCache[$key];
        };

        $errors = [];
        // Расхождение шапки листа с профилем — предупреждение, а не ошибка:
        // недостающей может оказаться необязательная колонка, и валить
        // рабочий импорт из-за неё нельзя. В список ошибок оно попадёт
        // первым только если импорт и так упал (см. ниже).
        $warnings = $profileWarning ? [$profileWarning] : [];
        $prepared = [];
        foreach ($rows as $i => $row) {
            $lineNo = $i + 2;
            $contractNumber = trim((string) ($row['contract_number'] ?? $row['number'] ?? $row['номер_контракта'] ?? $row['contract'] ?? ''));
            // \App\Support\Numbers::decimal снимает НЕразрывные пробелы
            // (U+00A0 / U+202F) — разделители разрядов из Google Sheets/Excel.
            // Обычный str_replace их не убирал → «7 754,00» → 7 (инцидент Робо).
            $amount = \App\Support\Numbers::decimal($row['amount'] ?? $row['сумма'] ?? $row['sum'] ?? null);
            $date = $row['date'] ?? $row['дата'] ?? $row['payment_date'] ?? null;

            if ($contractNumber === '') {
                $errors[] = "Строка {$lineNo}: пустой номер контракта";
                continue;
            }

            // 1) exact из batch-map (O(1))
            $contract = $contractMap->get($contractNumber);
            $matchedByIlike = false;
            if (! $contract) {
                // 2) Fallback ilike: ТОЛЬКО при уникальном совпадении.
                // Раньше брался первый попавшийся, и «1001» матчил
                // «10010», «100123» — оператор получал warning и
                // деньги уходили в чужой контракт. Теперь >1 совпадения
                // = ошибка с перечислением кандидатов.
                $candidates = DB::table('contract')
                    ->where('number', 'ilike', '%' . $contractNumber . '%')
                    ->whereNull('deletedAt')
                    ->limit(5)
                    ->get(['id', 'number', 'clientName']);
                if ($candidates->count() === 1) {
                    $contract = $candidates->first();
                    $matchedByIlike = true;
                } elseif ($candidates->count() > 1) {
                    $list = $candidates->pluck('number')->join(', ');
                    $errors[] = "Строка {$lineNo}: контракт «{$contractNumber}» — несколько совпадений ({$list}). Уточните номер для точного совпадения.";
                    continue;
                }
            }
            if (! $contract) {
                $errors[] = "Строка {$lineNo}: контракт «{$contractNumber}» не найден в БД (ни по точному, ни по частичному совпадению)";
                continue;
            }

            // Period-freeze: строки в закрытом месяце ПРОПУСКАЕМ
            // (не валим импорт целиком).
            if ($date) {
                $ts = strtotime($date);
                if ($ts === false) {
                    $errors[] = "Строка {$lineNo}: не удалось распарсить дату «{$date}» (ожидается YYYY-MM-DD или DD.MM.YYYY)";
                    continue;
                }
                $year = (int) date('Y', $ts);
                $month = (int) date('m', $ts);
                // Проверка `$month` тут была лишней: date('m') от валидной
                // метки всегда даёт 01–12, то есть всегда истинна.
                if ($year && $isFrozen($year, $month)) {
                    $warnings[] = sprintf(
                        'Строка %d: дата %s в закрытом периоде %02d.%d — строка пропущена.',
                        $lineNo, date('Y-m-d', $ts), $month, $year,
                    );
                    continue;
                }
            }

            // Отрицательная сумма — сторно (возврат клиента, отмена взноса):
            // движок считает такую сделку в минус по всей цепочке, т.е.
            // вычитает ранее начисленное. Запрещён только ноль.
            // Ноль разрешён: нулевые транзакции заводятся намеренно
            // (напр. фиксация факта сделки без денежного движения).

            if ($matchedByIlike && $contract->number !== $contractNumber) {
                $warnings[] = sprintf(
                    'Строка %d: точного совпадения нет, найден по частичному → контракт «%s» (id %d, клиент %s). Проверьте.',
                    $lineNo,
                    $contract->number ?? '?',
                    $contract->id,
                    $contract->clientName ?? '—',
                );
            }

            // Валюта строки: per-row (профили с колонкой «Валюта») либо
            // валюта импорта. Раньше всегда бралась одна валюта импорта →
            // Trust (USD/EUR) грузился в RUB, суммы в рублях были неверны.
            $rowCurrency = ($row['currency'] ?? null) ?: $resolvedCurrency;
            // Нет курса на месяц строки — это ошибка СТРОКИ, а не повод
            // грузить её по курсу 1:1 (прежний тихий фолбэк CurrencyRates
            // занижал amountRUB примерно в 80 раз для USD/EUR) и не повод
            // ронять весь импорт.
            try {
                $rowRate = $rateFor($rowCurrency, $date);
            } catch (\RuntimeException $e) {
                $errors[] = "Строка {$lineNo}: {$e->getMessage()}";
                continue;
            }
            $amountRub = $amount * $rowRate;
            $rowUsdRate = \App\Support\CurrencyRates::usdForDate($date);
            $amountUsd = $rowUsdRate > 0 ? $amountRub / $rowUsdRate : 0;

            // ds_percent: «0,028» (русская локаль Excel) — приходит из
            // Google Sheets и валит PG bulk INSERT с invalid numeric
            // syntax. Нормализуем запятую → точка и проверяем тип.
            $dsPercentRaw = $row['ds_percent'] ?? $row['процент_дс'] ?? null;
            $dsPercent = null;
            if ($dsPercentRaw !== null && $dsPercentRaw !== '') {
                $s = \App\Support\Numbers::normalizeString($dsPercentRaw);
                if ($s !== '') $dsPercent = (float) $s;
            }

            // property → commissionCalcProperty.id. Принимаем:
            //   - числовой id (профиль уже резолвил, напр. IB MF → 9)
            //   - строку-название («МФ»/«Апфронт»/«5 год») — резолвим
            //     через commissionCalcProperty.title (case-insensitive).
            $propertyRaw = $row['property'] ?? $row['свойство'] ?? $row['вид услуги'] ?? null;
            $propertyId = null;
            if ($propertyRaw !== null && $propertyRaw !== '') {
                if (is_numeric($propertyRaw)) {
                    $propertyId = (int) $propertyRaw;
                } else {
                    $propertyId = SheetProfiles::resolvePropertyId($propertyRaw);
                    // Значение свойства есть, но не распознано — не роняем в
                    // NULL молча (иначе МФ/Апфронт незаметно теряется), а
                    // предупреждаем оператора с указанием строки и значения.
                    if ($propertyId === null) {
                        $warnings[] = sprintf(
                            'Строка %d: свойство «%s» не распознано (ожидается МФ/Апфронт или id) — импортировано без свойства.',
                            $lineNo, trim((string) $propertyRaw),
                        );
                    }
                }
            }

            // «Своя комиссия» включена для строки, но суммы комиссии в отчёте
            // нет — строка уйдёт по тарифу из «Продуктов». Для МФ (Робо) тариф
            // заведомо расходится с фактом оплаты, поэтому предупреждаем.
            if (! empty($row['custom_commission_missing'])) {
                $warnings[] = sprintf(
                    'Строка %d: пустая сумма комиссии — транзакция посчитана по тарифу из «Продуктов», а не по факту оплаты.',
                    $lineNo,
                );
            }

            $prepared[] = [
                'line' => $lineNo,
                'contract_id' => $contract->id,
                'amount' => $amount,
                'amountRub' => $amountRub,
                'amountUsd' => $amountUsd,
                'date' => $date,
                'ds_percent' => $dsPercent,
                'property' => $propertyId,
                'currency' => $rowCurrency,
                'currencyRate' => $rowRate,
                'year' => $row['year'] ?? null,
                // «Своя комиссия»: доход ДС задан суммой из отчёта
                // (профили с custom_commission, напр. ГГА).
                'custom_commission' => ! empty($row['custom_commission']),
                'commission_abs' => $row['commission_abs'] ?? null,
            ];

            // Прогресс — каждые 200 строк (вместо 50: меньше cache-overhead).
            // Куда его писать, знает вызывающий: сервис только считает.
            if ($onProgress && (($i + 1) % 200 === 0 || $i === count($rows) - 1)) {
                $onProgress($i + 1, count($errors));
            }
        }


        return ['prepared' => $prepared, 'errors' => $errors, 'warnings' => $warnings];
    }


    /**
     * Контракты всех строк источника — одним запросом.
     *
     * ⚠ Раньше на каждую строку шёл отдельный SELECT: на выгрузке в 1267
     * строк это было горлышко всей валидации.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function contractsByNumber(array $rows)
    {
        // Batch-загрузка контрактов: 1 SELECT вместо 1267 (раньше каждая
        // строка делала отдельный exact SELECT — горлышко валидации).
        $allNumbers = [];
        foreach ($rows as $row) {
            $n = trim((string) ($row['contract_number'] ?? $row['number'] ?? $row['номер_контракта'] ?? $row['contract'] ?? ''));
            if ($n !== '') $allNumbers[$n] = true;
        }
        $allNumbers = array_keys($allNumbers);
        $contractMap = $allNumbers
            ? DB::table('contract')
                ->whereIn('number', $allNumbers)
                ->whereNull('deletedAt')
                ->get(['id', 'number', 'clientName'])
                ->keyBy('number')
            : collect();


        return $contractMap;
    }
}
