<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Черновики транзакций для раздела «Ручной ввод транзакций».
 * Изолированы от боевой `transaction`, чтобы существующая аналитика и FK
 * не затрагивались. На фиксации запись переезжает в `transaction` через
 * insert + CommissionCalculator, а draft удаляется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_draft', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('contract');
            $table->unsignedInteger('consultant')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->unsignedInteger('currency')->nullable();
            $table->decimal('currencyRate', 18, 6)->nullable();
            $table->date('date')->nullable();
            $table->text('comment')->nullable();
            $table->string('parameter', 50)->nullable();
            $table->unsignedInteger('yearKV')->nullable();
            $table->decimal('dsCommissionPercentage', 8, 4)->nullable();
            $table->boolean('commissionOverride')->default(false);
            $table->boolean('customCommission')->default(false);
            $table->decimal('dsCommissionAbsolute', 18, 2)->nullable();
            $table->jsonb('previewCalc')->nullable();
            $table->unsignedInteger('createdBy')->nullable();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();

            $table->index('contract', 'transaction_draft_contract_idx');
            $table->index('consultant', 'transaction_draft_consultant_idx');
            $table->index('createdBy', 'transaction_draft_createdby_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_draft');
    }
};
