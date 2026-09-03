<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Реестр выплат (/admin/payment-registry).
 *
 * Сетка ПОД вынос. Экран показывает, сколько партнёру платить, поэтому здесь
 * закреплены ровно те правила, расхождение по которым уже разводило реестр с
 * бухгалтерским экспортом:
 *   - «Сальдо» = остаток ПРЕДЫДУЩЕГО периода, а не b.balance текущего: ночной
 *     перенос remaining→balance запаздывает, и сальдо показывалось нулём;
 *   - в «Сальдо» входят и ручные начисления прошлых месяцев: снимок про
 *     other_accruals не знает, и без добавки правка за прошлый месяц не
 *     гасила сальдо следующего;
 *   - «Начислено» и «Пул» читаются ТОЛЬКО из снимка, без живого пересчёта
 *     (решение 2026-06-05 «деньги считаются по кнопке»);
 *   - удержания месяца берутся построчно из commission, а не из колонок
 *     consultantBalance: те не пишет ни один раннер и они всегда нулевые;
 *   - итоги в шапке суммируются из СТРОК, иначе шапка расходится с таблицей.
 */
class PaymentRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2300001;
    private const OTHER = 2300002;
    private const DELETED = 2300003;

    private User $admin;
    private int $seq = 2300100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Сальдо ----------------

    /**
     * ⚠ Сальдо — это остаток ПРОШЛОГО периода, а не колонка balance текущего:
     * перенос remaining→balance запаздывает, и реестр показывал ноль там, где
     * бухгалтерский экспорт показывал десятки тысяч.
     */
    #[Test]
    public function the_opening_balance_comes_from_the_previous_period(): void
    {
        $this->balance(self::PARTNER, '2026-05', ['remaining' => 50_000]);
        $this->balance(self::PARTNER, '2026-06', ['balance' => 0, 'remaining' => 0]);

        $row = $this->row(2026, 6);

        $this->assertEqualsWithDelta(50_000, $row['balance'], 0.01);
    }

    /** Берётся ближайший предыдущий период, а не самый первый. */
    #[Test]
    public function the_opening_balance_takes_the_closest_earlier_period(): void
    {
        $this->balance(self::PARTNER, '2026-03', ['remaining' => 10_000]);
        $this->balance(self::PARTNER, '2026-05', ['remaining' => 50_000]);
        $this->balance(self::PARTNER, '2026-06', ['balance' => 0]);

        $this->assertEqualsWithDelta(50_000, $this->row(2026, 6)['balance'], 0.01);
    }

    /**
     * ⚠ Ручные начисления прошлых месяцев ВХОДЯТ в сальдо.
     *
     * Снимок consultantBalance собирается только из commission и про
     * other_accruals не знает. Пока сальдо читалось из одного снимка, правка
     * «Прочих начислений» за прошлый месяц не гасила сальдо следующего:
     * у Лунина (03.09.2026) +7 500 «возврат за стратсессию» за 30.06.2026
     * стоял в начислениях, а июль всё равно открывался с −7 500.
     */
    #[Test]
    public function manual_accruals_of_earlier_months_land_in_the_opening_balance(): void
    {
        $this->balance(self::PARTNER, '2026-06', ['remaining' => -7_500]);
        $this->balance(self::PARTNER, '2026-07', ['balance' => 0]);
        DB::table('other_accruals')->insert([
            'consultant' => self::PARTNER, 'amount' => 7_500, 'points' => 0,
            'type' => 'rub', 'accrual_date' => '2026-06-30 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0, $this->row(2026, 7)['balance'], 0.01);
    }

    /**
     * ...а начисление САМОГО выбранного месяца в сальдо не попадает: оно уже
     * посчитано в «Прочем». Иначе те же рубли легли бы в строку дважды.
     */
    #[Test]
    public function manual_accruals_of_the_selected_month_stay_out_of_the_opening_balance(): void
    {
        $this->balance(self::PARTNER, '2026-06', ['remaining' => 1_000]);
        $this->balance(self::PARTNER, '2026-07', ['balance' => 0]);
        DB::table('other_accruals')->insert([
            'consultant' => self::PARTNER, 'amount' => 500, 'points' => 0,
            'type' => 'rub', 'accrual_date' => '2026-07-10 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->row(2026, 7);

        $this->assertEqualsWithDelta(1_000, $row['balance'], 0.01);
        $this->assertEqualsWithDelta(500, $row['other'], 0.01);
    }

    // ---------------- Начислено: только снимок ----------------

    /**
     * ⛔ «Начислено» и «Пул» — строго из снимка. Свежие строки commission,
     * не попавшие в снимок, показываться НЕ должны: это и был бы тот самый
     * живой пересчёт, который убрали решением «деньги по кнопке».
     */
    #[Test]
    public function accruals_come_from_the_snapshot_only(): void
    {
        $this->balance(self::PARTNER, '2026-06', [
            'accruedTransactional' => 1_000, 'accruedPool' => 200,
        ]);
        // Свежая комиссия мимо снимка — в «Начислено» попасть не должна.
        $this->commission(self::PARTNER, '2026-06', ['amountRUB' => 999_999]);

        $row = $this->row(2026, 6);

        $this->assertEqualsWithDelta(1_000, $row['accrued'], 0.01);
        $this->assertEqualsWithDelta(200, $row['pool'], 0.01);
    }

    /**
     * «Прочее» = снимок nonTransactional ПЛЮС ручные начисления месяца.
     * Ручные читаются живьём — это ввод оператора, а не расчёт.
     */
    #[Test]
    public function manual_accruals_are_added_live_to_the_other_column(): void
    {
        $this->balance(self::PARTNER, '2026-06', ['accruedNonTransactional' => 300]);
        DB::table('other_accruals')->insert([
            'consultant' => self::PARTNER, 'amount' => 700, 'points' => 0,
            'type' => 'bonus', 'accrual_date' => '2026-06-15 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Начисление соседнего месяца в выдачу не идёт.
        DB::table('other_accruals')->insert([
            'consultant' => self::PARTNER, 'amount' => 5_000, 'points' => 0,
            'type' => 'bonus', 'accrual_date' => '2026-07-15 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertEqualsWithDelta(1_000, $this->row(2026, 6)['other'], 0.01);
    }

    // ---------------- Удержания ----------------

    /**
     * ⚠ Удержания месяца считаются построчно из commission. Колонки
     * consultantBalance для этого не годятся — их не пишет ни один раннер.
     */
    #[Test]
    public function withholdings_are_summed_from_the_commission_rows(): void
    {
        $this->balance(self::PARTNER, '2026-06', [
            'accruedTransactional' => 1_000,
            // Заведомо неверные значения в снимке — их брать нельзя.
            'withheldForGap' => 777, 'withheldForCommissions' => 888,
        ]);
        $this->commission(self::PARTNER, '2026-06', ['withheldForGap' => 100]);
        $this->commission(self::PARTNER, '2026-06', ['withheldForCommission' => 50]);

        $row = $this->row(2026, 6);

        $this->assertEqualsWithDelta(100, $row['withheldForGap'], 0.01);
        $this->assertEqualsWithDelta(50, $row['withheldForCommissions'], 0.01);
        // «Начислено до удержаний» = начислено + отрыв + ОП.
        $this->assertEqualsWithDelta(1_150, $row['accruedBeforeGap'], 0.01);
    }

    /** Удалённые строки цепочки в удержания не идут. */
    #[Test]
    public function deleted_commission_rows_do_not_count_as_withholdings(): void
    {
        $this->commission(self::PARTNER, '2026-06', ['withheldForGap' => 100]);
        $this->commission(self::PARTNER, '2026-06', [
            'withheldForGap' => 900, 'deletedAt' => '2026-06-20 00:00:00',
        ]);
        $this->balance(self::PARTNER, '2026-06', []);

        $this->assertEqualsWithDelta(100, $this->row(2026, 6)['withheldForGap'], 0.01);
    }

    // ---------------- Арифметика строки и итогов ----------------

    /** К выплате = сальдо + начислено; остаток = к выплате − выплачено. */
    #[Test]
    public function the_row_arithmetic_holds(): void
    {
        $this->balance(self::PARTNER, '2026-05', ['remaining' => 2_000]);
        $this->balance(self::PARTNER, '2026-06', [
            'accruedTransactional' => 1_000,
            'accruedNonTransactional' => 300,
            'accruedPool' => 200,
            'payed' => 500,
        ]);

        $row = $this->row(2026, 6);

        $this->assertEqualsWithDelta(1_500, $row['accruedTotal'], 0.01, '1000 + 300 + 200');
        $this->assertEqualsWithDelta(3_500, $row['totalPayable'], 0.01, 'сальдо 2000 + начислено 1500');
        $this->assertEqualsWithDelta(3_000, $row['remaining'], 0.01, 'минус выплаченные 500');
    }

    /** Итоги шапки собираются из строк — иначе шапка расходится с таблицей. */
    #[Test]
    public function the_header_totals_are_summed_from_the_rows(): void
    {
        $this->balance(self::PARTNER, '2026-06', ['accruedTransactional' => 1_000]);
        $this->balance(self::OTHER, '2026-06', ['accruedTransactional' => 2_500]);

        $totals = $this->list(2026, 6)['totals'];

        $this->assertSame(2, $totals['rows']);
        $this->assertEqualsWithDelta(3_500, $totals['accruedTransactional'], 0.01);
        $this->assertEqualsWithDelta(3_500, $totals['totalPayable'], 0.01);
    }

    // ---------------- Состав выдачи ----------------

    /** Мягко удалённые партнёры в реестр не попадают. */
    #[Test]
    public function soft_deleted_partners_are_excluded(): void
    {
        $this->balance(self::PARTNER, '2026-06', ['accruedTransactional' => 100]);
        $this->balance(self::DELETED, '2026-06', ['accruedTransactional' => 999]);

        $ids = array_column($this->list(2026, 6)['items'], 'consultantId');

        $this->assertContains(self::PARTNER, $ids);
        $this->assertNotContains(self::DELETED, $ids, 'удалённый служебный аккаунт тянул свой остаток в итог');
    }

    /**
     * Имя берётся живое, а денормализованное — только фолбэком: у части строк
     * оно битое после переноса из Directual.
     */
    #[Test]
    public function the_live_name_wins_over_the_denormalized_one(): void
    {
        $this->balance(self::PARTNER, '2026-06', ['consultantPersonName' => 'Битое Имя Из Снимка']);

        $this->assertSame('Реестров Партнёр', $this->row(2026, 6)['personName']);
    }

    /** Поиск идёт по тому же живому имени. */
    #[Test]
    public function the_search_uses_the_live_name(): void
    {
        $this->balance(self::PARTNER, '2026-06', ['consultantPersonName' => 'Битое Имя Из Снимка']);
        $this->balance(self::OTHER, '2026-06', []);

        $this->assertSame([self::PARTNER],
            array_column($this->list(2026, 6, ['search' => 'Реестров'])['items'], 'consultantId'));
        $this->assertSame([],
            array_column($this->list(2026, 6, ['search' => 'Битое'])['items'], 'consultantId'));
    }

    /** Фильтр «ФК с отрывом» опирается на удержание отрыва, а не на любое. */
    #[Test]
    public function the_detachment_filter_looks_at_the_gap_withholding(): void
    {
        $this->balance(self::PARTNER, '2026-06', []);
        $this->balance(self::OTHER, '2026-06', []);
        $this->commission(self::PARTNER, '2026-06', ['withheldForGap' => 100]);
        // У второго только ОП-штраф — под фильтр отрыва он не подходит.
        $this->commission(self::OTHER, '2026-06', ['withheldForCommission' => 100]);

        $this->assertSame([self::PARTNER],
            array_column($this->list(2026, 6, ['withDetachment' => 1])['items'], 'consultantId'));
        $this->assertSame([self::OTHER],
            array_column($this->list(2026, 6, ['withOp' => 1])['items'], 'consultantId'));
    }

    /** Реквизиты: подтверждённость и приостановка выплат видны в строке. */
    #[Test]
    public function the_row_flags_requisites_and_suspension(): void
    {
        $this->balance(self::PARTNER, '2026-06', []);
        DB::table('requisites')->insert([
            'id' => $this->seq++, 'consultant' => self::PARTNER, 'verified' => true,
        ]);
        DB::table('consultant')->where('id', self::PARTNER)->update(['payments_suspended' => true]);

        $row = $this->row(2026, 6);

        $this->assertTrue($row['verifiedRequisites']);
        $this->assertTrue($row['paymentsSuspended']);
    }

    // ================================================================

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function list(int $year, int $month, array $extra = []): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/payment-registry?' . http_build_query(
                array_merge(['year' => $year, 'month' => $month], $extra)
            ))->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function row(int $year, int $month): array
    {
        $items = collect($this->list($year, $month)['items'])->keyBy('consultantId');

        return $items[self::PARTNER];
    }

    /** @param array<string, mixed> $attrs */
    private function balance(int $consultant, string $dm, array $attrs): void
    {
        DB::table('consultantBalance')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => $consultant,
            'dateMonth' => $dm,
            'dateYear' => substr($dm, 0, 4),
            'balance' => 0, 'accruedTransactional' => 0, 'accruedNonTransactional' => 0,
            'accruedPool' => 0, 'accruedTotal' => 0, 'totalPayable' => 0,
            'payed' => 0, 'remaining' => 0,
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function commission(int $consultant, string $dm, array $attrs): void
    {
        DB::table('commission')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => $consultant,
            'transaction' => null,
            'dateMonth' => $dm,
            'dateYear' => substr($dm, 0, 4),
            'chainOrder' => 1,
            'amountRUB' => 0,
            'createdAt' => $dm . '-15 00:00:00',
        ], $attrs));
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 2300900;
        $this->admin->email = 'registry@test.local';
        $this->admin->firstName = 'Реестр';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            ['id' => self::PARTNER, 'personName' => 'Реестров Партнёр',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
            ['id' => self::OTHER, 'personName' => 'Второй Партнёр',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
        ]);
        // Отдельной вставкой: у строки лишний столбец, а батч требует
        // одинакового набора ключей во всех строках.
        DB::table('consultant')->insert([
            'id' => self::DELETED, 'personName' => 'Удалённый Служебный',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
            'dateDeleted' => '2026-02-01 00:00:00',
        ]);
    }
}
