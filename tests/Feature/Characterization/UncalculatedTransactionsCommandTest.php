<?php

namespace Tests\Feature\Characterization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * finance:uncalculated-transactions — транзакции, по которым не посчитаны комиссии.
 *
 * Сценарий взят из августа 2026: ОСАГО из Инсмарта, премия 5 184,21 ₽,
 * КВ 777 ₽ → %ДС 14,9878. Комиссии не посчитались, доход ДС пуст. Отчёт
 * «Комиссии» показывает по сделке 0, а пул — оценку
 * 5 184,21 × 14,9878 / 105 = 740,00 ₽. Ровно на столько они и разошлись.
 */
class UncalculatedTransactionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 970201;
    private const CONTRACT = 970210;
    private const UNCALCULATED = 970220;
    private const CALCULATED = 970221;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\VatRate::flush();
        \App\Support\CurrencyRates::flush();

        DB::table('vat')->delete();
        DB::table('vat')->insert([
            'id' => 970300,
            'value' => 5,
            'dateFrom' => '2020-01-01',
            'dateTo' => '2050-01-01',
        ]);

        DB::table('consultant')->insert([
            'id' => self::PARTNER,
            'personName' => 'Партнёр Без Расчёта',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);

        DB::table('contract')->insert([
            'id' => self::CONTRACT,
            'consultant' => self::PARTNER,
            'number' => '83672898',
            'clientName' => 'Клиент Осаго',
        ]);

        DB::table('transaction')->insert([
            'id' => self::UNCALCULATED,
            'contract' => self::CONTRACT,
            'amount' => 5184.21,
            'amountRUB' => 5184.21,
            'dsCommissionPercentage' => 14.9878,
            'commissionsAmountRUB' => null,
            'currency' => 67,
            'currencyRate' => 1,
            'date' => '2026-08-10 15:54:34',
            'dateMonth' => '2026-08',
            'dateYear' => '2026',
        ]);

        // Посчитанная сделка того же месяца — в выдачу попадать не должна.
        DB::table('transaction')->insert([
            'id' => self::CALCULATED,
            'contract' => self::CONTRACT,
            'amount' => 10_000,
            'amountRUB' => 10_000,
            'dsCommissionPercentage' => 10,
            'commissionsAmountRUB' => 952.38,
            'currency' => 67,
            'currencyRate' => 1,
            'date' => '2026-08-11 10:00:00',
            'dateMonth' => '2026-08',
            'dateYear' => '2026',
        ]);
    }

    #[Test]
    public function lists_transaction_counted_by_pool_but_not_by_report(): void
    {
        $this->artisan('finance:uncalculated-transactions', ['--month' => '2026-08'])
            ->expectsOutputToContain((string) self::UNCALCULATED)
            ->expectsOutputToContain('740.00')
            ->doesntExpectOutputToContain((string) self::CALCULATED)
            ->assertExitCode(0);

        // Без --calculate команда только читает.
        $this->assertNull(DB::table('transaction')->where('id', self::UNCALCULATED)->value('commissionsAmountRUB'));
        $this->assertSame(0, DB::table('commission')->where('transaction', self::UNCALCULATED)->count());
    }

    #[Test]
    public function calculate_option_fills_ds_income(): void
    {
        $this->artisan('finance:uncalculated-transactions', ['--month' => '2026-08', '--calculate' => true])
            ->assertExitCode(0);

        // База 5 184,21 / 1,05 = 4 937,34; × 14,9878% = 740,00 — ровно оценка пула.
        $income = DB::table('transaction')->where('id', self::UNCALCULATED)->value('commissionsAmountRUB');
        $this->assertEqualsWithDelta(740.00, (float) $income, 0.01);
        $this->assertGreaterThan(0, DB::table('commission')
            ->where('transaction', self::UNCALCULATED)
            ->whereNull('deletedAt')
            ->count());
    }

    #[Test]
    public function calculate_option_prints_reason_when_it_fails_again(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update(['dateDeleted' => '2026-08-20 00:00:00']);

        $this->artisan('finance:uncalculated-transactions', ['--month' => '2026-08', '--calculate' => true])
            ->expectsOutputToContain('Консультант не найден или удалён')
            ->assertExitCode(1);

        $this->assertNull(DB::table('transaction')->where('id', self::UNCALCULATED)->value('commissionsAmountRUB'));
    }
}
