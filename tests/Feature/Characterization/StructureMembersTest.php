<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Структура партнёра (GET /structure) — строки участников.
 *
 * Сетка ПОД вынос. Показатели каждой строки собираются из нескольких
 * источников, и приоритет между ними — то, что легче всего сломать:
 *   - ЛП и ГП берутся из ПОСЛЕДНЕЙ записи журнала квалификаций, а колонки
 *     карточки остаются лишь запасным вариантом: они денормализованы и
 *     устаревают;
 *   - НГП берётся из последней записи с НЕПУСТЫМ накопительным ГП: самая
 *     свежая строка может быть строкой финализа Отрыв/ОП с пустым значением,
 *     и она не должна обнулять показатель;
 *   - части ФИО берутся из логина, а не из склеенного имени карточки.
 */
class StructureMembersTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 3100001;
    private const CHILD = 3100002;

    private User $user;
    private int $seq = 3100100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Источники показателей ----------------

    /** ЛП и ГП приходят из последней записи журнала, а не из карточки. */
    #[Test]
    public function the_volumes_come_from_the_latest_log_entry(): void
    {
        DB::table('consultant')->where('id', self::CHILD)
            ->update(['personalVolume' => 9_999, 'groupVolume' => 8_888]);
        $this->qlog(self::CHILD, '2026-06-15', ['personalVolume' => 100, 'groupVolume' => 200]);
        $this->qlog(self::CHILD, '2026-07-15', ['personalVolume' => 500, 'groupVolume' => 700]);

        $row = $this->member(self::CHILD);

        $this->assertEqualsWithDelta(500, $row['personalVolume'], 0.01, 'самая свежая запись');
        $this->assertEqualsWithDelta(700, $row['groupVolume'], 0.01);
    }

    /** Без записей журнала показатели падают на колонки карточки. */
    #[Test]
    public function the_card_columns_are_the_fallback(): void
    {
        DB::table('consultant')->where('id', self::CHILD)
            ->update(['personalVolume' => 42, 'groupVolume' => 84]);

        $row = $this->member(self::CHILD);

        $this->assertEqualsWithDelta(42, $row['personalVolume'], 0.01);
        $this->assertEqualsWithDelta(84, $row['groupVolume'], 0.01);
    }

    /**
     * ⚠ НГП берётся из последней записи с НЕПУСТЫМ накопительным ГП. Строка
     * финализа Отрыв/ОП приходит позже и с пустым значением — она не должна
     * обнулять показатель.
     */
    #[Test]
    public function the_cumulative_volume_skips_the_penalty_row(): void
    {
        $this->qlog(self::CHILD, '2026-07-10', ['groupVolumeCumulative' => 4_200]);
        $this->qlog(self::CHILD, '2026-07-31', ['groupVolumeCumulative' => null]);

        $this->assertEqualsWithDelta(4_200,
            $this->member(self::CHILD)['groupVolumeCumulative'], 0.01);
    }

    // ---------------- ФИО ----------------

    /** Части ФИО берутся из логина, а не разбором склеенного имени. */
    #[Test]
    public function the_name_parts_come_from_the_login(): void
    {
        $row = $this->member(self::CHILD);

        $this->assertSame('Дочерний', $row['lastName']);
        $this->assertSame('Партнёр', $row['firstName']);
        $this->assertSame('Отчествович', $row['patronymic']);
    }

    // ---------------- Квалификация ----------------

    /** Уровень квалификации отдаётся номером и названием. */
    #[Test]
    public function the_qualification_carries_level_and_title(): void
    {
        $levelId = (int) DB::table('status_levels')->where('level', 4)->value('id');
        DB::table('consultant')->where('id', self::CHILD)->update(['status_and_lvl' => $levelId]);

        $qual = $this->member(self::CHILD)['qualification'];

        $this->assertSame(4, $qual['level']);
        $this->assertSame('ФК', $qual['title']);
    }

    // ================================================================

    /** @return array<string, mixed> */
    private function member(int $id): array
    {
        $rows = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/structure')
            ->assertOk()->json();

        $byId = collect($rows['data'])->keyBy('id');

        $this->assertArrayHasKey($id, $byId->all(), 'партнёр есть в структуре');

        return $byId[$id];
    }

    /** @param array<string, mixed> $attrs */
    private function qlog(int $consultant, string $date, array $attrs = []): void
    {
        DB::table('qualificationLog')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => $consultant,
            'date' => $date,
            'personalVolume' => 0, 'groupVolume' => 0, 'groupVolumeCumulative' => 0,
            'nominalLevel' => 1, 'calculationLevel' => 1,
            'createdAt' => $date . ' 00:00:00',
        ], $attrs));
    }

    private function seedFixture(): void
    {
        $this->user = new User();
        $this->user->id = 3100900;
        $this->user->email = 'structure@test.local';
        $this->user->firstName = 'Корневой';
        $this->user->lastName = 'Партнёр';
        $this->user->role = 'consultant';
        $this->user->password = bcrypt('secret123');
        $this->user->save();

        $child = new User();
        $child->id = 3100901;
        $child->email = 'child@test.local';
        $child->firstName = 'Партнёр';
        $child->lastName = 'Дочерний';
        $child->patronymic = 'Отчествович';
        $child->role = 'consultant';
        $child->password = bcrypt('secret123');
        $child->save();

        DB::table('consultant')->insert([
            'id' => self::ROOT, 'webUser' => $this->user->id,
            'personName' => 'Корневой Партнёр', 'activity' => 1, 'active' => true,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('consultant')->insert([
            'id' => self::CHILD, 'webUser' => $child->id, 'inviter' => self::ROOT,
            'personName' => 'Дочерний Партнёр Отчествович', 'activity' => 1, 'active' => true,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
    }
}
