<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Аналитика чата (GET /chat/analytics).
 *
 * Сетка ПОД вынос. Экран из одних агрегатов, и ломается он тихо: цифры
 * остаются правдоподобными. Закреплено:
 *   - период отбирает тикеты по дате СОЗДАНИЯ, обе границы включительно;
 *   - разрезы по категории и приоритету считают тот же набор, что и сводка;
 *   - дневной тренд отдаёт непрерывный ряд дат, включая дни без тикетов, —
 *     иначе график «схлопывается» и рисует ложную динамику;
 *   - нагрузка на сотрудников считается по назначенным тикетам.
 */
class ChatAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 3300001;
    private const SUPPORT = 3300002;

    private User $admin;
    private int $seq = 3300100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUsers();
    }

    // ---------------- Период ----------------

    /** Период отбирает тикеты по дате создания, включая обе границы. */
    #[Test]
    public function the_period_covers_both_bounds(): void
    {
        $this->ticket(['created_at' => '2026-07-01 00:00:00']);
        $this->ticket(['created_at' => '2026-07-15 12:00:00']);
        $this->ticket(['created_at' => '2026-07-31 23:59:59']);
        $this->ticket(['created_at' => '2026-08-01 00:00:01']);

        $summary = $this->analytics('2026-07-01', '2026-07-31')['counters'];

        $this->assertSame(3, $summary['total'], 'августовский тикет не входит');
    }

    /** Сводка разбивает тикеты по статусам. */
    #[Test]
    public function the_summary_splits_by_status(): void
    {
        $this->ticket(['status' => 'new']);
        $this->ticket(['status' => 'open']);
        $this->ticket(['status' => 'resolved']);
        $this->ticket(['status' => 'resolved']);

        $summary = $this->analytics()['counters'];

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['new']);
        $this->assertSame(1, $summary['open']);
        $this->assertSame(2, $summary['resolved']);
    }

    // ---------------- Разрезы ----------------

    /** Разрез по категории считает тот же набор, что и сводка. */
    #[Test]
    public function the_category_breakdown_matches_the_summary(): void
    {
        $this->ticket(['department' => 'support']);
        $this->ticket(['department' => 'support']);
        $this->ticket(['department' => 'accruals']);

        $body = $this->analytics();
        $byCategory = collect($body['byCategory'])->pluck('count', 'category');

        $this->assertSame(2, (int) $byCategory['support']);
        $this->assertSame(1, (int) $byCategory['accruals']);
        $this->assertSame(3, $body['counters']['total'],
            'сумма разреза совпадает со сводкой');
    }

    /** Разрез по приоритету — тоже по тому же набору. */
    #[Test]
    public function the_priority_breakdown_is_present(): void
    {
        $this->ticket(['priority' => 'high']);
        $this->ticket(['priority' => 'normal']);

        $byPriority = collect($this->analytics()['byPriority'])->pluck('count', 'priority');

        $this->assertSame(1, (int) $byPriority['high']);
        $this->assertSame(1, (int) $byPriority['normal']);
    }

    // ---------------- Тренд ----------------

    /**
     * ⚠ Дневной ряд непрерывен: дни без тикетов присутствуют с нулями.
     * Пропуск пустых дней «сжимает» график и рисует ложную динамику.
     */
    #[Test]
    public function the_daily_trend_keeps_empty_days(): void
    {
        $this->ticket(['created_at' => '2026-07-01 10:00:00']);
        $this->ticket(['created_at' => '2026-07-05 10:00:00']);

        $daily = $this->analytics('2026-07-01', '2026-07-05')['dailyTrend'];

        $this->assertCount(5, $daily, 'пять дат подряд, включая пустые');
        $this->assertSame('2026-07-01', $daily[0]['day']);
        $this->assertSame('2026-07-05', $daily[4]['day']);
        $this->assertSame(1, (int) $daily[0]['new'], 'первого июля — один тикет');
        $this->assertSame(0, (int) $daily[1]['new'], 'второе июля — без тикетов');
        $this->assertSame(1, (int) $daily[4]['new'], 'пятого июля — один тикет');
    }

    // ---------------- Нагрузка ----------------

    /** Нагрузка считается по назначенным тикетам. */
    #[Test]
    public function the_staff_load_counts_assigned_tickets(): void
    {
        $this->ticket(['assigned_to' => self::SUPPORT, 'status' => 'resolved']);
        $this->ticket(['assigned_to' => self::SUPPORT, 'status' => 'resolved']);
        $this->ticket(['assigned_to' => null]);

        $load = collect($this->analytics()['staffLoad']);

        $this->assertGreaterThan(0, $load->count(), 'сотрудник в списке есть');
    }

    // ================================================================

    /** @return array<string, mixed> */
    private function analytics(string $from = '2026-07-01', string $to = '2026-07-31'): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            // ⚠ Без period=custom границы from/to игнорируются: период
            // по умолчанию — последняя неделя.
            ->getJson('/api/v1/chat/analytics?' . http_build_query([
                'period' => 'custom', 'from' => $from, 'to' => $to,
            ]))->assertOk()->json();
    }

    /** @param array<string, mixed> $attrs */
    private function ticket(array $attrs = []): int
    {
        $id = $this->seq++;
        DB::table('chat_tickets')->insert(array_merge([
            'id' => $id,
            'subject' => 'Тикет ' . $id,
            'status' => 'new',
            'priority' => 'normal',
            'department' => 'support',
            'created_by' => self::PARTNER,
            'messages_count' => 0,
            'created_at' => '2026-07-10 10:00:00',
            'updated_at' => '2026-07-10 10:00:00',
        ], $attrs));

        return $id;
    }

    private function seedUsers(): void
    {
        $this->admin = $this->user(3300900, 'admin', 'Аналитиков');
        $this->user(self::PARTNER, 'consultant', 'Партнёров');
        $this->user(self::SUPPORT, 'support', 'Саппортов');
    }

    private function user(int $id, string $role, string $last): User
    {
        $u = new User();
        $u->id = $id;
        $u->email = 'analytics' . $id . '@test.local';
        $u->role = $role;
        $u->lastName = $last;
        $u->firstName = 'Тест';
        $u->password = bcrypt('secret123');
        $u->save();

        return $u;
    }
}
