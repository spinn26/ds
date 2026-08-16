<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Окно истории контракта (/admin/contracts/{id}/history).
 *
 * Вынесено из AdminDataController (метод занимал 145 строк). Код перенесён
 * дословно: состав событий, порядок и подписи полей не меняются.
 *
 * Две вещи, которые легко сломать при правках:
 *   - обход идёт ТОЛЬКО по новым атрибутам, поэтому поле, исчезнувшее из
 *     attributes, в историю не попадает (у истории партнёра — наоборот);
 *   - смена партнёра пишется мимо Eloquent, в changeConsultantContractLog,
 *     и вливается в ту же ленту отдельным блоком.
 */
class ContractHistoryService
{
    public function forContract(int $id): Collection
    {
        $rows = DB::table('activity_log')
            ->where('subject_type', \App\Models\Contract::class)
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $causerIds = $rows->pluck('causer_id')->filter()->unique();
        $causers = $causerIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $causerIds)->select(['id', 'firstName', 'lastName', 'patronymic'])->get()->keyBy('id')
            : collect();

        $fieldLabels = [
            'number' => '№ контракта',
            'counterpartyContractId' => 'ИД контрагента',
            'client' => 'Клиент', 'consultant' => 'Партнёр',
            'product' => 'Продукт', 'program' => 'Программа',
            'status' => 'Статус', 'currency' => 'Валюта',
            'ammount' => 'Сумма', 'amount' => 'Сумма',
            'country' => 'Страна оформления',
            'createDate' => 'Дата создания',
            'openDate' => 'Дата открытия',
            'closeDate' => 'Дата закрытия',
            'riskProfile' => 'Риск-профиль',
            'setup' => 'Сетап',
            'type' => 'Тип (страх.)',
            'comment' => 'Комментарий',
        ];

        // Собираем все ID, чтобы резолвить human-friendly значения FK одним батчем.
        $idsByField = [
            'client' => [], 'consultant' => [], 'product' => [], 'program' => [],
            'status' => [], 'currency' => [], 'country' => [], 'riskProfile' => [], 'setup' => [],
        ];
        foreach ($rows as $r) {
            $props = json_decode($r->properties ?: '{}', true);
            foreach (['old', 'attributes'] as $bucket) {
                foreach ($props[$bucket] ?? [] as $field => $val) {
                    if (isset($idsByField[$field]) && $val !== null && $val !== '') {
                        $idsByField[$field][] = (int) $val;
                    }
                }
            }
        }
        $resolveFn = function (string $table, string $col, array $ids): array {
            $ids = array_values(array_unique(array_filter($ids)));
            if (! $ids) return [];
            return DB::table($table)->whereIn('id', $ids)->pluck($col, 'id')->toArray();
        };
        $maps = [
            'client'      => $resolveFn('client', 'personName', $idsByField['client']),
            'consultant'  => $resolveFn('consultant', 'personName', $idsByField['consultant']),
            'product'     => $resolveFn('product', 'name', $idsByField['product']),
            'program'     => $resolveFn('program', 'name', $idsByField['program']),
            'status'      => $resolveFn('contractStatus', 'name', $idsByField['status']),
            'currency'    => $resolveFn('currency', 'symbol', $idsByField['currency']),
            'country'     => $resolveFn('country', 'countryNameRu', $idsByField['country']),
            'riskProfile' => $resolveFn('riskProfile', 'name', $idsByField['riskProfile']),
            'setup'       => $resolveFn('setup', 'setup', $idsByField['setup']),
        ];

        $humanize = function ($field, $val) use ($maps) {
            if ($val === null || $val === '') return null;
            if (isset($maps[$field][$val])) return $maps[$field][$val];
            // Даты приводим к Y-m-d
            if (in_array($field, ['createDate', 'openDate', 'closeDate'], true)) {
                try { return (new \DateTimeImmutable((string) $val))->format('Y-m-d'); } catch (\Throwable) { return $val; }
            }
            return $val;
        };

        $data = $rows->map(function ($r) use ($causers, $fieldLabels, $humanize) {
            $props = json_decode($r->properties ?: '{}', true);
            $changes = [];
            $oldValues = $props['old'] ?? [];
            $newValues = $props['attributes'] ?? [];
            foreach ($newValues as $field => $newVal) {
                $oldVal = $oldValues[$field] ?? null;
                if ($oldVal === $newVal) continue;
                $changes[] = [
                    'field' => $field,
                    'fieldLabel' => $fieldLabels[$field] ?? $field,
                    'old' => $humanize($field, $oldVal),
                    'new' => $humanize($field, $newVal),
                ];
            }

            $causer = $r->causer_id ? ($causers[$r->causer_id] ?? null) : null;
            $author = $causer
                ? trim("{$causer->lastName} {$causer->firstName} {$causer->patronymic}")
                : 'Система';

            return [
                'id' => $r->id,
                'createdAt' => $r->created_at,
                'description' => $r->description,
                'event' => $r->event,
                'author' => $author,
                'changes' => $changes,
            ];
        });

        // Смена партнёра пишется напрямую в changeConsultantContractLog (в обход
        // Eloquent-модели, поэтому её нет в activity_log). Вливаем эти события в
        // единое окно истории — per spec §4 «все изменения контракта».
        $transfers = DB::table('changeConsultantContractLog')
            ->where('contract', $id)
            ->orderByDesc('dateCreated')
            ->limit(200)
            ->get();

        if ($transfers->isNotEmpty()) {
            $tUserIds = $transfers->pluck('webUser')->filter()->unique();
            $tUsers = $tUserIds->isNotEmpty()
                ? DB::table('WebUser')->whereIn('id', $tUserIds)->select(['id', 'firstName', 'lastName', 'patronymic'])->get()->keyBy('id')
                : collect();

            $transferRows = $transfers->map(function ($t) use ($tUsers) {
                $u = $t->webUser ? ($tUsers[$t->webUser] ?? null) : null;
                $author = $u
                    ? trim("{$u->lastName} {$u->firstName} {$u->patronymic}")
                    : 'Система';

                return [
                    'id' => 'transfer-' . $t->id,
                    'createdAt' => $t->dateCreated,
                    'description' => 'Смена партнёра',
                    'event' => 'reassign',
                    'author' => $author,
                    'changes' => [[
                        'field' => 'consultant',
                        'fieldLabel' => 'Партнёр',
                        'old' => $t->consultantOldName,
                        'new' => $t->consultantNewName,
                    ]],
                ];
            });

            $data = $data->concat($transferRows)->sortByDesc('createdAt')->values();
        }

        return $data;
    }
}
