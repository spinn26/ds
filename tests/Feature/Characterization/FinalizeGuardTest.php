<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Защита финализации месяца и след в журнале.
 *
 * ⚠ Инцидент июля 2026. Финализацию за июль запустили 14 ИЮЛЯ — в середине
 * месяца, когда продаж за него ещё не было в системе. Она честно записала
 * снимки с нулевыми ЛП и ГП, а НГП считается как «база до месяца + ГП
 * месяца», и потому замер на июньском значении у 147 партнёров. Когда в
 * августе комиссии посчитали, полную финализацию не перезапустили.
 *
 * Отсюда два правила, которые держит этот тест:
 *   - применять финализацию к НЕЗАВЕРШЁННОМУ месяцу нельзя: данные заведомо
 *     неполные, а снимок перетирает предыдущий. Превью при этом разрешено —
 *     смотреть прогноз по текущему месяцу нормально;
 *   - каждый запуск пишется в журнал. Раньше запуски пересчётов не
 *     фиксировались вовсе, и вопрос «кто и что запускал» разрешить было
 *     нечем: в audit_log лежали только входы и правки сущностей.
 */
class FinalizeGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User();
        $this->admin->id = 3600900;
        $this->admin->email = 'finalize@test.local';
        $this->admin->firstName = 'Финализ';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();
    }

    // ---------------- Гард незавершённого месяца ----------------

    /** ⚠ Применить финализацию к текущему, ещё не закончившемуся месяцу нельзя. */
    #[Test]
    public function applying_to_the_running_month_is_refused(): void
    {
        $now = now();

        $this->apply($now->year, $now->month)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'не завершён'));
    }

    /** Будущий месяц — тем более. */
    #[Test]
    public function applying_to_a_future_month_is_refused(): void
    {
        $next = now()->addMonth();

        $this->apply($next->year, $next->month)->assertStatus(422);
    }

    /** Завершённый месяц применяется как обычно. */
    #[Test]
    public function a_finished_month_is_allowed(): void
    {
        $prev = now()->subMonth();

        $this->apply($prev->year, $prev->month)->assertOk();
    }

    /**
     * Превью по текущему месяцу остаётся доступным: смотреть прогноз нормально,
     * запрет касается только записи.
     */
    #[Test]
    public function previewing_the_running_month_stays_allowed(): void
    {
        $now = now();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/finalize/preview', [
                'year' => $now->year, 'month' => $now->month,
            ])->assertOk();
    }

    // ---------------- След в журнале ----------------

    /** Применение финализации оставляет запись в журнале: кто, когда, за что. */
    #[Test]
    public function applying_is_recorded_in_the_audit_log(): void
    {
        $prev = now()->subMonth();

        $this->apply($prev->year, $prev->month)->assertOk();

        $row = DB::table('audit_log')->where('action', 'finalize_apply')->first();

        $this->assertNotNull($row, 'запуск обязан попасть в журнал');
        $this->assertSame($this->admin->email, $row->user_email);

        $payload = json_decode((string) $row->payload, true);
        $this->assertSame($prev->format('Y-m'), $payload['period']);
    }

    /** Отказ гарда тоже виден в журнале — иначе непонятно, что попытка была. */
    #[Test]
    public function a_refused_run_is_recorded_too(): void
    {
        $now = now();

        $this->apply($now->year, $now->month)->assertStatus(422);

        $row = DB::table('audit_log')->where('action', 'finalize_refused')->first();

        $this->assertNotNull($row);
        $this->assertSame($now->format('Y-m'), json_decode((string) $row->payload, true)['period']);
    }

    // ================================================================

    private function apply(int $year, int $month)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/finalize/apply', ['year' => $year, 'month' => $month]);
    }
}
