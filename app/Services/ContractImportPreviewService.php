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
        $merged = array_merge($existing, $patch);
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

        return [
            'number' => $row['number'],
            'counterpartyContractId' => $row['counterpartyContractId'] ?? null,
            'status' => $row['status'] ?? null,
            'client' => $row['client'],
            'clientName' => $client->personName,
            'consultant' => $client->consultant,
            'consultantName' => $client->consultant
                ? DB::table('consultant')->where('id', $client->consultant)->value('personName')
                : null,
            'product' => $row['product'] ?? null,
            'productName' => $product?->name,
            'program' => $row['program'] ?? null,
            'programName' => $program?->name,
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
