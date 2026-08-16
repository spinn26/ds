<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Список контрактов (/admin/contracts): фильтры и сборка строк.
 *
 * Вынесено из AdminDataController (метод занимал 156 строк, девятнадцать
 * фильтров). Как и в остальных списках, query() отдаёт билдер: по нему
 * считаются и total, и итоговая сумма — оба ДО пагинации.
 *
 * Три места, которые легко потерять при правках, отмечены по ходу:
 * общий поиск не трогает клиента, продукт/программа матчатся по ИМЕНИ
 * (у исторических контрактов FK пуст), а прогноз активации — единственная
 * date-only колонка: конец дня к её верхней границе не дописывается.
 */
class ContractsListingService
{
    /** @var list<string> */
    public const FILTERS = [
        'search', 'client', 'client_name', 'consultant_name', 'status', 'number',
        'comment', 'product', 'program', 'setup', 'supplier',
        'created_from', 'created_to', 'opened_from', 'opened_to',
        'closed_from', 'closed_to', 'forecast_from', 'forecast_to',
    ];

    /**
     * Запрос с фильтрами, без сортировки и пагинации.
     *
     * @param array<string, mixed> $filters только заполненные значения
     */
    public function query(array $filters): Builder
    {
        $query = DB::table('contract as c')
            ->leftJoin('program as pr', 'c.program', '=', 'pr.id')
            ->whereNull('c.deletedAt');

        if (isset($filters['search'])) {
            $s = $filters['search'];
            // Generic search = partner (consultant) name + contract number.
            // Client has a dedicated `client_name` filter — keep it out of the OR.
            $query->where(function ($q) use ($s) {
                $q->where('c.consultantName', 'ilike', "%{$s}%")
                  ->orWhere('c.number', 'ilike', "%{$s}%");
            });
        }
        // Точный фильтр по клиенту (id) — используется при переходе из списка
        // клиентов по клику на счётчик контрактов. Надёжнее, чем по ФИО
        // (нет коллизий тёзок). contract.client → client.id.
        if (isset($filters['client'])) {
            $query->where('c.client', $filters['client']);
        }
        if (isset($filters['client_name'])) {
            $query->where('c.clientName', 'ilike', '%' . $filters['client_name'] . '%');
        }
        if (isset($filters['consultant_name'])) {
            $query->where('c.consultantName', 'ilike', '%' . $filters['consultant_name'] . '%');
        }
        if (isset($filters['status'])) $query->whereIn('c.status', (array) $filters['status']);
        if (isset($filters['number'])) $query->where('c.number', 'ilike', '%' . $filters['number'] . '%');
        if (isset($filters['comment'])) $query->where('c.comment', 'ilike', '%' . $filters['comment'] . '%');
        // Продукт: историчесские контракты (Directual) хранят productName,
        // а не FK. Резолвим имя и матчим по productName — та же схема что
        // для программы ниже. Отрицательный id → catalog-only продукт.
        if (isset($filters['product'])) {
            $productId = (int) $filters['product'];
            $productName = $productId < 0
                ? DB::table('products_catalog')->where('id', -$productId)->value('name')
                : DB::table('product')->where('id', $productId)->value('name');
            if ($productName) {
                $query->where('c.productName', $productName);
            } else {
                $query->where('c.product', $productId);
            }
        }
        // Программа: дропдаун дедуплицирован (один id-представитель на
        // имя), поэтому фильтр матчит по contract.programName, чтобы
        // выбор «Жизнь+» поднимал ВСЕ варианты этой программы. Если
        // имя не разрезолвилось (id невалидный) — fallback на FK.
        // Отрицательный id → catalog-only программа (нет legacy-строки).
        if (isset($filters['program'])) {
            $programId = (int) $filters['program'];
            $programName = $programId < 0
                ? DB::table('programs_catalog')->where('id', -$programId)->value('name')
                : DB::table('program')->where('id', $programId)->value('name');
            if ($programName) {
                $query->where('c.programName', $programName);
            } else {
                $query->where('c.program', $programId);
            }
        }
        if (isset($filters['setup'])) $query->where('c.setup', $filters['setup']);
        if (isset($filters['supplier'])) {
            // Каноническое выражение — то же, которым ниже собирается колонка.
            \App\Support\SupplierResolver::applyFilter(
                $query,
                (string) $filters['supplier'],
                'c."productName"',
                \App\Support\SupplierResolver::sqlProviderExpr('pr', null)
            );
        }
        if (isset($filters['created_from'])) $query->where('c.createDate', '>=', $filters['created_from']);
        if (isset($filters['created_to'])) $query->where('c.createDate', '<=', $filters['created_to'] . ' 23:59:59');
        if (isset($filters['opened_from'])) $query->where('c.openDate', '>=', $filters['opened_from']);
        if (isset($filters['opened_to'])) $query->where('c.openDate', '<=', $filters['opened_to'] . ' 23:59:59');
        if (isset($filters['closed_from'])) $query->where('c.closeDate', '>=', $filters['closed_from']);
        if (isset($filters['closed_to'])) $query->where('c.closeDate', '<=', $filters['closed_to'] . ' 23:59:59');
        // Прогноз активации — date-only колонка (без времени).
        if (isset($filters['forecast_from'])) $query->where('c.activation_forecast', '>=', $filters['forecast_from']);
        if (isset($filters['forecast_to'])) $query->where('c.activation_forecast', '<=', $filters['forecast_to']);


        return $query;
    }

    /** Строки страницы → массив для ответа. */
    public function present(Collection $rows): Collection
    {
        // Batch load contract statuses
        $statusIds = $rows->pluck('status')->filter()->unique();
        $contractStatuses = $statusIds->isNotEmpty()
            ? DB::table('contractStatus')->whereIn('id', $statusIds)->pluck('name', 'id')
            : collect();

        // Batch load currencies
        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        return $rows->map(fn ($c) => [
                'id' => $c->id,
                'number' => $c->number,
                'counterpartyContractId' => $c->counterpartyContractId,
                'clientName' => $c->clientName,
                'consultant' => $c->consultant ?? null,
                'consultantName' => $c->consultantName,
                'productName' => $c->productName,
                'programName' => $c->programName,
                'supplierName' => \App\Support\SupplierResolver::resolve($c->productName, $c->supplierName),
                // Реальный страховщик-партнёр для Insmart-продуктов
                // (для тултипа / детальной формы — UI может его игнорировать).
                'supplierSubName' => \App\Support\SupplierResolver::subProvider($c->productName, $c->supplierName),
                'comment' => $c->comment,
                'statusName' => $c->status ? ($contractStatuses[$c->status] ?? null) : null,
                'ammount' => $c->ammount,
                'currencySymbol' => $c->currency ? ($currencies[$c->currency] ?? null) : null,
                'openDate' => $c->openDate,
                // Y-m-d, иначе date-only уезжает на день назад под МСК.
                'activationForecast' => $c->activation_forecast ? substr((string) $c->activation_forecast, 0, 10) : null,
            ]);

    }
}
