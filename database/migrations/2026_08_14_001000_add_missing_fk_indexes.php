<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под внешние ключи, у которых их не было.
 *
 * В схеме 435 FK, и у 135 однокоплоночных нет индекса на дочерней стороне.
 * Индексировать все — вредно (лишние записи при вставке), поэтому берём
 * только те, что лежат на реальных путях запросов и каскадов:
 *
 *   commission.qualificationLog        — пересчёт/удаление qLog тянет комиссии
 *                                        по таблице в 177 МБ (seq scan).
 *   consultantBalance.qualificationLog — джойн баланса на квалификацию месяца.
 *   consultantPayment.consultantBalance— выплаты по балансу (реестр выплат).
 *   consultantProgramsData.consultant  — агрегаты партнёра по продуктам.
 *   clientsIndicators.client           — показатели клиента (112 тыс. строк).
 *   logAcceptance.consultant           — акцепт оферты по партнёру.
 *   requisites.consultant              — checkAccess() дёргает при каждом
 *                                        входе в «Продукты».
 *   contract.product / contract.program— фильтры отчётов и матрицы продаж.
 *
 * CONCURRENTLY — чтобы не брать ACCESS EXCLUSIVE на боевых таблицах; из-за
 * этого миграция идёт вне транзакции ($withinTransaction = false).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0:string,1:string,2:string}> [индекс, таблица, колонка] */
    private array $indexes = [
        ['commission_quallog_idx', 'commission', 'qualificationLog'],
        ['consultantbalance_quallog_idx', 'consultantBalance', 'qualificationLog'],
        ['consultantpayment_balance_idx', 'consultantPayment', 'consultantBalance'],
        ['consultantprogramsdata_consultant_idx', 'consultantProgramsData', 'consultant'],
        ['clientsindicators_client_idx', 'clientsIndicators', 'client'],
        ['logacceptance_consultant_idx', 'logAcceptance', 'consultant'],
        ['requisites_consultant_idx', 'requisites', 'consultant'],
        ['contract_product_idx', 'contract', 'product'],
        ['contract_program_idx', 'contract', 'program'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$index, $table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON public."%s" ("%s")',
                $index,
                $table,
                $column
            ));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$index]) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS public.{$index}");
        }
    }
};
