<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Буферная валидация и фиксация контрактов из Google Sheets.
 *
 * Per spec ✅Загрузка контрактов §2-§3:
 *   1. Подтянутые из листа строки попадают в буферную таблицу
 *      contract_import_preview (НЕ в боевую `contract`).
 *   2. Сервис прогоняет каждую через валидаторы и проставляет ошибки.
 *   3. UI рендерит красные треугольники у проблемных строк.
 *   4. Сотрудник правит/удаляет строки → каждое сохранение перезапускает
 *      validate(); ошибка исчезает — иконка пропадает.
 *   5. Когда все строки valid → finalize() переносит их в `contract`.
 *
 * Валидаторы в одном месте, чтобы preview и финализация использовали
 * одинаковые правила и не было drift'а.
 */
class ContractImportPreviewService
{
    /**
     * Загрузить новый сет строк в буфер. Возвращает session_id +
     * количество valid/invalid.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{sessionId:string, total:int, valid:int, invalid:int}
     */
    public function bufferRows(array $rows, ?int $userId = null): array
    {
        $sessionId = (string) Str::uuid();
        $valid = 0;
        $invalid = 0;

        foreach ($rows as $row) {
            $row = $this->normaliseRow($row);
            $errors = $this->validate($row);
            $status = empty($errors) ? 'valid' : 'invalid';
            DB::table('contract_import_preview')->insert([
                'session_id' => $sessionId,
                'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE),
                'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'status' => $status,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $status === 'valid' ? $valid++ : $invalid++;
        }

        return ['sessionId' => $sessionId, 'total' => count($rows), 'valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * Привести row из шаблона к каноничному формату:
     * — резолв строковых значений (client/product/program/consultant/riskProfile/currency)
     *   в FK-id'ы, чтобы validate() и finalize() работали с числами;
     * — поддержка clientPlatform/personPlatform как явных id;
     * — парс дат dd.mm.yyyy → Y-m-d.
     */
    private function normaliseRow(array $row): array
    {
        // 1. Client: clientPlatform (id) → client (строка-имя)
        if (! empty($row['clientPlatform']) && is_numeric($row['clientPlatform'])) {
            $row['client'] = (int) $row['clientPlatform'];
        } elseif (! empty($row['client']) && ! is_numeric($row['client'])) {
            $found = DB::table('client')
                ->where('personName', 'ilike', '%' . trim($row['client']) . '%')
                ->whereNull('dateDeleted')
                ->value('id');
            if ($found) $row['client'] = (int) $found;
        }
        // personPlatform — справочный, проверяется в validate.

        // 2. Product: по name ilike
        if (! empty($row['product']) && ! is_numeric($row['product'])) {
            $found = DB::table('product')
                ->where('name', 'ilike', '%' . trim($row['product']) . '%')
                ->where('active', true)
                ->value('id');
            if ($found) $row['product'] = (int) $found;
        }

        // 3. Program: по name внутри выбранного product
        if (! empty($row['program']) && ! is_numeric($row['program'])) {
            $progQ = DB::table('program')
                ->where('name', 'ilike', '%' . trim($row['program']) . '%')
                ->whereNull('dateDeleted');
            if (! empty($row['product']) && is_numeric($row['product'])) {
                $progQ->where('product', (int) $row['product']);
            }
            $found = $progQ->value('id');
            if ($found) $row['program'] = (int) $found;
        }

        // 4. Consultant: по personName — переопределяет client.consultant
        if (! empty($row['consultant']) && ! is_numeric($row['consultant'])) {
            $found = DB::table('consultant')
                ->where('personName', 'ilike', '%' . trim($row['consultant']) . '%')
                ->whereNull('dateDeleted')
                ->value('id');
            if ($found) $row['consultant'] = (int) $found;
        }

        // 5. RiskProfile: по name
        if (! empty($row['riskProfile']) && ! is_numeric($row['riskProfile'])) {
            $found = DB::table('riskProfile')
                ->where('name', 'ilike', '%' . trim($row['riskProfile']) . '%')
                ->value('id');
            if ($found) $row['riskProfile'] = (int) $found;
        }

        // 6. Currency: тикер (RUB/USD/EUR) → id
        if (! empty($row['currency']) && ! is_numeric($row['currency'])) {
            $code = trim($row['currency']);
            $found = DB::table('currency')
                ->where(function ($q) use ($code) {
                    $q->where('nameEn', 'ilike', $code)
                      ->orWhere('symbol', $code);
                })->value('id');
            if ($found) $row['currency'] = (int) $found;
        }

        // 7. Даты: поддержка dd.mm.yyyy кроме ISO
        foreach (['createDate', 'openDate', 'closeDate'] as $f) {
            if (! empty($row[$f]) && is_string($row[$f])) {
                $row[$f] = $this->parseDdMmYyyy($row[$f]) ?? $row[$f];
            }
        }

        return $row;
    }

    private function parseDdMmYyyy(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
            try {
                return \Carbon\Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * Применить правки к строке буфера и перезапустить валидацию.
     * @return array{status:string, errors:list<array{field:string,message:string}>}
     */
    public function updateRow(int $previewId, array $patch): array
    {
        $row = DB::table('contract_import_preview')->where('id', $previewId)->first();
        if (! $row) {
            throw new \RuntimeException('Строка буфера не найдена');
        }

        $existing = json_decode($row->row_data, true) ?: [];
        $merged = $this->normaliseRow(array_merge($existing, $patch));
        $errors = $this->validate($merged);
        $status = empty($errors) ? 'valid' : 'invalid';

        DB::table('contract_import_preview')->where('id', $previewId)->update([
            'row_data' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'updated_at' => now(),
        ]);

        return ['status' => $status, 'errors' => $errors, 'rowData' => $merged];
    }

    /**
     * Зафиксировать все valid-строки сессии в `contract` и удалить их
     * из буфера. Invalid-строки остаются в буфере.
     */
    public function finalize(string $sessionId, ?int $userId = null): array
    {
        $rows = DB::table('contract_import_preview')
            ->where('session_id', $sessionId)
            ->where('status', 'valid')
            ->get();

        if ($rows->isEmpty()) {
            return ['written' => 0, 'message' => 'Нет валидных строк для сохранения'];
        }

        $written = DB::transaction(function () use ($rows) {
            $count = 0;
            foreach ($rows as $r) {
                $data = json_decode($r->row_data, true) ?: [];
                $resolved = $this->resolveDenormalizedFields($data);
                if (! $resolved) continue;
                DB::table('contract')->insert(array_merge($resolved, [
                    'createdAt' => now(),
                    'changedAt' => now(),
                ]));
                $count++;
            }
            return $count;
        });

        DB::table('contract_import_preview')
            ->where('session_id', $sessionId)
            ->where('status', 'valid')
            ->delete();

        return ['written' => $written, 'message' => "Сохранено: {$written} контрактов"];
    }

    /** Обогатить row_data именами по FK + убрать UI-only поля. */
    private function resolveDenormalizedFields(array $row): ?array
    {
        if (empty($row['number']) || empty($row['client'])) return null;
        $client = DB::table('client')->where('id', $row['client'])->first();
        if (! $client) return null;

        $product = ! empty($row['product']) ? DB::table('product')->where('id', $row['product'])->first() : null;
        $program = ! empty($row['program']) ? DB::table('program')->where('id', $row['program'])->first() : null;

        // Consultant: явный из шаблона приоритетнее, чем client.consultant.
        $consultantId = ! empty($row['consultant']) && is_numeric($row['consultant'])
            ? (int) $row['consultant']
            : $client->consultant;
        $consultantName = $consultantId
            ? DB::table('consultant')->where('id', $consultantId)->value('personName')
            : null;

        return [
            'number' => $row['number'],
            'counterpartyContractId' => $row['counterpartyContractId'] ?? null,
            'status' => $row['status'] ?? null,
            'client' => $row['client'],
            'clientName' => $client->personName,
            'consultant' => $consultantId,
            'consultantName' => $consultantName,
            'product' => $row['product'] ?? null,
            'productName' => $product?->name,
            'program' => $row['program'] ?? null,
            'programName' => $program?->name,
            'riskProfile' => $row['riskProfile'] ?? null,
            'currency' => $row['currency'] ?? null,
            'ammount' => $row['ammount'] ?? $row['amount'] ?? 0,
            'createDate' => $row['createDate'] ?? now()->toDateString(),
            'openDate' => $row['openDate'] ?? null,
            'closeDate' => $row['closeDate'] ?? null,
            'comment' => $row['comment'] ?? 'Импорт из Sheets',
        ];
    }

    /**
     * Прогнать строку через все правила валидации.
     * @return list<array{field:string,message:string}>
     */
    public function validate(array $row): array
    {
        $errors = [];

        if (empty($row['number'])) {
            $errors[] = ['field' => 'number', 'message' => 'Номер контракта обязателен'];
        } else {
            $duplicate = DB::table('contract')
                ->where('number', $row['number'])
                ->whereNull('deletedAt')
                ->exists();
            if ($duplicate) {
                $errors[] = ['field' => 'number', 'message' => 'Контракт с таким номером уже существует'];
            }
        }

        if (empty($row['client'])) {
            $errors[] = ['field' => 'client', 'message' => 'Клиент обязателен'];
        } elseif (! DB::table('client')->where('id', $row['client'])->exists()) {
            $errors[] = ['field' => 'client', 'message' => 'Некорректный ID клиента'];
        }

        if (empty($row['product'])) {
            $errors[] = ['field' => 'product', 'message' => 'Продукт обязателен'];
        } elseif (! DB::table('product')->where('id', $row['product'])->exists()) {
            $errors[] = ['field' => 'product', 'message' => 'Продукт не найден в базе'];
        }

        if (! empty($row['program']) && ! DB::table('program')->where('id', $row['program'])->exists()) {
            $errors[] = ['field' => 'program', 'message' => 'Программа не найдена'];
        }

        $amount = $row['ammount'] ?? $row['amount'] ?? null;
        if ($amount === null || $amount === '') {
            $errors[] = ['field' => 'ammount', 'message' => 'Сумма обязательна'];
        } elseif (! is_numeric($amount) || (float) $amount <= 0) {
            $errors[] = ['field' => 'ammount', 'message' => 'Сумма должна быть положительным числом'];
        }

        if (! empty($row['createDate']) && ! strtotime($row['createDate'])) {
            $errors[] = ['field' => 'createDate', 'message' => 'Некорректная дата создания'];
        }
        if (! empty($row['openDate']) && ! strtotime($row['openDate'])) {
            $errors[] = ['field' => 'openDate', 'message' => 'Некорректная дата открытия'];
        }
        if (! empty($row['closeDate']) && ! strtotime($row['closeDate'])) {
            $errors[] = ['field' => 'closeDate', 'message' => 'Некорректная дата закрытия'];
        }

        return $errors;
    }
}
