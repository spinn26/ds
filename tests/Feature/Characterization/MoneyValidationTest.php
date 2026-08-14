<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Валидация денежных эндпоинтов.
 *
 * Правила переехали из тел контроллеров в FormRequest. Перенос обязан быть
 * дословным, поэтому тест фиксирует не «что-то отвалидировалось», а конкретные
 * поля-нарушители и тексты кастомных сообщений: именно там при переносе
 * незаметно меняется поведение.
 */
class MoneyValidationTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 990001;
    private const BALANCE = 990010;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User();
        $this->admin->id = 990100;
        $this->admin->email = 'money@test.local';
        $this->admin->firstName = 'Финанс';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            'id' => self::PARTNER,
            'personName' => 'Партнёр Денежный',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);

        DB::table('consultantBalance')->insert([
            'id' => self::BALANCE,
            'consultant' => self::PARTNER,
            'dateMonth' => '2026-07',
            'dateYear' => '2026',
            'accruedTotal' => 50_000,
            'totalPayable' => 50_000,
            'payed' => 0,
            'remaining' => 50_000,
        ]);
    }

    // ---------------- Прочие начисления ----------------

    #[Test]
    public function charge_requires_all_four_fields(): void
    {
        $this->call_('/admin/charges', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consultant', 'type', 'amount', 'comment']);
    }

    #[Test]
    public function charge_rejects_unknown_consultant_and_type(): void
    {
        $this->call_('/admin/charges', [
            'consultant' => 999999,
            'type' => 'магия',
            'amount' => 100,
            'comment' => 'тест',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consultant', 'type']);
    }

    /** Legacy-типы принимаются наравне с rub/points — обратная совместимость. */
    #[Test]
    public function charge_accepts_legacy_types(): void
    {
        foreach (['rub', 'points', 'bonus', 'penalty', 'compensation'] as $type) {
            $this->call_('/admin/charges', [
                'consultant' => self::PARTNER,
                'type' => $type,
                'amount' => 10,
                'comment' => 'тип ' . $type,
            ])->assertJsonMissingValidationErrors(['type']);
        }
    }

    /** Отрицательная сумма допустима: «Прочие» умеют и удержания. */
    #[Test]
    public function charge_allows_negative_amount(): void
    {
        $this->call_('/admin/charges', [
            'consultant' => self::PARTNER,
            'type' => 'rub',
            'amount' => -500,
            'comment' => 'удержание',
        ])->assertJsonMissingValidationErrors(['amount']);
    }

    // ---------------- Выплаты ----------------

    #[Test]
    public function payment_requires_positive_amount(): void
    {
        $this->call_('/admin/payment-registry/' . self::BALANCE . '/payments', ['amount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->call_('/admin/payment-registry/' . self::BALANCE . '/payments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function payment_accepts_the_minimal_amount(): void
    {
        $this->call_('/admin/payment-registry/' . self::BALANCE . '/payments', [
            'amount' => 0.01,
            'comment' => 'копейка',
        ])->assertJsonMissingValidationErrors(['amount', 'comment']);
    }

    // ---------------- Курсы валют ----------------

    #[Test]
    public function currency_rates_require_period_and_at_least_one_rate(): void
    {
        $this->call_('/admin/currencies/rates', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period', 'rates']);
    }

    /** Кастомные сообщения — часть контракта API, их видит оператор. */
    #[Test]
    public function currency_rates_keep_their_custom_messages(): void
    {
        $r = $this->call_('/admin/currencies/rates', ['period' => '2026/07', 'rates' => []]);

        $r->assertStatus(422);
        $this->assertSame(
            'Период указывается как ГГГГ-ММ.',
            $r->json('errors.period.0')
        );
        $this->assertSame(
            'Укажите хотя бы один курс.',
            $r->json('errors.rates.0'),
            'пустой массив курсов ловится тем же сообщением, что и отсутствие поля'
        );
    }

    #[Test]
    public function currency_rates_validate_nested_items(): void
    {
        $this->call_('/admin/currencies/rates', [
            'period' => '2026-07',
            'rates' => [['currencyId' => 999999, 'rate' => -1]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rates.0.currencyId', 'rates.0.rate']);
    }

    /** Имя `post` занято базовым TestCase — свой хелпер зовём иначе. */
    private function call_(string $path, array $payload)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1' . $path, $payload);
    }
}
