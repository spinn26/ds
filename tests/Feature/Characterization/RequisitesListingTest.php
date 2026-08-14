<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Список реквизитов (/admin/requisites).
 *
 * Сетка ПОД вынос метода в сервис. Метод сложнее предыдущих: помимо шести
 * фильтров он ДЕДУПЛИЦИРУЕТ строки — один реквизит на партнёра, приоритет у
 * подтверждённого, при равенстве берётся свежий. Это поведение и правилось
 * когда-то (у одного партнёра висело четыре строки), поэтому закрепляем.
 */
class RequisitesListingTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE_PARTNER = 1400001;
    private const TERMINATED_PARTNER = 1400002;
    private const SUSPENDED_PARTNER = 1400003;

    /** У активного партнёра ТРИ реквизита — проверяем дедуп. */
    private const REQ_OLD_UNVERIFIED = 1400010;
    private const REQ_VERIFIED = 1400011;
    private const REQ_NEW_UNVERIFIED = 1400012;

    private const REQ_REJECTED = 1400020;
    private const REQ_PENDING = 1400030;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    /**
     * Дедуп: на партнёра одна строка. Подтверждённый выигрывает у более
     * свежего неподтверждённого.
     */
    #[Test]
    public function one_requisite_per_partner_with_verified_winning(): void
    {
        $rows = collect($this->list('')->json('data'));

        $this->assertSame(3, $rows->count(), 'по одной строке на каждого из трёх партнёров');
        $forActive = $rows->firstWhere('consultantId', self::ACTIVE_PARTNER);
        $this->assertSame(
            self::REQ_VERIFIED,
            $forActive['id'],
            'подтверждённый важнее более свежего неподтверждённого'
        );
    }

    /**
     * ⚠ Порядок важен: ДЕДУП применяется ПОСЛЕ фильтра, а не до него.
     *
     * У активного партнёра три реквизита. Без фильтра остаётся подтверждённый.
     * А с verified=false подтверждённый отсеивается фильтром, и дедуп выбирает
     * победителя уже среди двух неподтверждённых — свежий. То есть выдача
     * зависит от порядка шагов, и переставить их местами нельзя.
     */
    #[Test]
    public function dedup_runs_after_the_filter(): void
    {
        $this->assertOnly('verified=true', [self::REQ_VERIFIED]);
        $this->assertOnly('verified=false', [
            self::REQ_NEW_UNVERIFIED, self::REQ_REJECTED, self::REQ_PENDING,
        ]);
    }

    /**
     * Статус проверки: подтверждён / отклонён (есть причина) / ждёт (причины
     * нет). Отклонённый от ждущего отличается ТОЛЬКО наличием причины.
     */
    #[Test]
    public function status_filter_splits_rejected_and_pending(): void
    {
        $this->assertOnly('status=verified', [self::REQ_VERIFIED]);
        $this->assertOnly('status=rejected', [self::REQ_REJECTED]);
        // Тоже после фильтра: у активного партнёра остаётся свежий без причины.
        $this->assertOnly('status=pending', [self::REQ_NEW_UNVERIFIED, self::REQ_PENDING]);
    }

    #[Test]
    public function partner_status_filter_uses_consultant_activity(): void
    {
        // 3 = Терминирован
        $this->assertOnly('partner_status=3', [self::REQ_REJECTED]);
    }

    /**
     * Приостановка выплат: партнёры с активным запросом на смену реквизитов
     * и приостановленные вручную — это РАЗНЫЕ выборки.
     */
    #[Test]
    public function suspend_filter_separates_request_from_manual(): void
    {
        // ⚠ Значение именно 'request', не 'pending': любое другое молча
        // не фильтрует вовсе — выдача возвращается полной.
        $this->assertOnly('suspend=request', [self::REQ_PENDING]);
        $this->assertOnly('suspend=manual', [self::REQ_VERIFIED]);
        $this->assertSame(3, count($this->list('suspend=нечто')->json('data')),
            'неизвестное значение фильтра не сужает выдачу');
    }

    /** Цифровой запрос считается ИНН, текстовый — ФИО владельца. */
    #[Test]
    public function search_switches_between_inn_and_person_name(): void
    {
        $this->assertOnly('search=7701234567', [self::REQ_VERIFIED]);
        $this->assertOnly('search=' . urlencode('Терминированный'), [self::REQ_REJECTED]);
    }

    /** Не нашли партнёра по ФИО — выдача пустая, а не «съехавшая» на другое. */
    #[Test]
    public function search_without_matching_person_returns_nothing(): void
    {
        $this->assertOnly('search=' . urlencode('НетТакогоЧеловека'), []);
    }

    #[Test]
    public function row_carries_partner_name_and_flags(): void
    {
        $rows = collect($this->list('')->json('data'))->keyBy('id');

        $this->assertSame('Активный Партнёр', $rows[self::REQ_VERIFIED]['consultantName']);
        $this->assertTrue($rows[self::REQ_VERIFIED]['verified']);
        $this->assertSame('Нет счёта', $rows[self::REQ_REJECTED]['rejectionReason']);
        $this->assertFalse($rows[self::REQ_PENDING]['verified']);
    }

    // ================================================================

    /** @param list<int> $expected */
    private function assertOnly(string $query, array $expected): void
    {
        $ids = array_column($this->list($query)->json('data'), 'id');
        sort($ids);
        sort($expected);

        $this->assertSame($expected, $ids, 'фильтр: ' . $query);
    }

    private function list(string $query)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/requisites' . ($query ? '?' . $query : ''))
            ->assertOk();
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 1400900;
        $this->admin->email = 'req@test.local';
        $this->admin->firstName = 'Реквизиты';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            'id' => self::ACTIVE_PARTNER, 'personName' => 'Активный Партнёр',
            'activity' => 1, 'payments_suspended' => true,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('consultant')->insert([
            'id' => self::TERMINATED_PARTNER, 'personName' => 'Терминированный Партнёр',
            'activity' => 3, 'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('consultant')->insert([
            'id' => self::SUSPENDED_PARTNER, 'personName' => 'Ждущий Партнёр',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
        ]);

        // Три реквизита одного партнёра: дедуп обязан оставить подтверждённый.
        DB::table('requisites')->insert([
            ['id' => self::REQ_OLD_UNVERIFIED, 'consultant' => self::ACTIVE_PARTNER,
                'inn' => '7701234567', 'verified' => false],
            ['id' => self::REQ_VERIFIED, 'consultant' => self::ACTIVE_PARTNER,
                'inn' => '7701234567', 'verified' => true],
            ['id' => self::REQ_NEW_UNVERIFIED, 'consultant' => self::ACTIVE_PARTNER,
                'inn' => '7701234567', 'verified' => false],
        ]);

        DB::table('requisites')->insert([
            'id' => self::REQ_REJECTED, 'consultant' => self::TERMINATED_PARTNER,
            'inn' => '7809999999', 'verified' => false, 'rejection_reason' => 'Нет счёта',
        ]);
        DB::table('requisites')->insert([
            'id' => self::REQ_PENDING, 'consultant' => self::SUSPENDED_PARTNER,
            'inn' => '5011111111', 'verified' => false,
        ]);

        // Активный запрос на смену реквизитов — «приостановлен по запросу».
        DB::table('bank_requisite_change_requests')->insert([
            'consultant' => self::SUSPENDED_PARTNER,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
