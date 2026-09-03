<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GenderBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Заполнение пола партнёров по отчеству.
 *
 * Это правка персональных данных реальных людей, поэтому сетка закрепляет
 * границы: что заполняем, что канонизируем и чего не трогаем ни при каких
 * условиях. Тот же сервис выполняет миграция на деплое, так что ошибка здесь
 * уезжает сразу на всю сеть.
 */
class GenderBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** Пол пуст, отчество распознаётся — заполняем. */
    private const EMPTY_WITH_PATRONYMIC = 1500001;
    /** Пол по-русски из Directual — приводим к канону, смысл не меняем. */
    private const LEGACY_VALUE = 1500002;
    /** Пол уже в каноне — не трогаем вовсе. */
    private const ALREADY_CANONICAL = 1500003;
    /** Ни пола, ни распознаваемого отчества — оставляем пустым. */
    private const UNRESOLVABLE = 1500004;
    /** Партнёр удалён — в выборку не попадает. */
    private const DELETED = 1500005;

    private GenderBackfillService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
        $this->service = $this->app->make(GenderBackfillService::class);
    }

    #[Test]
    public function the_plan_sorts_partners_into_buckets(): void
    {
        $plan = $this->service->plan();

        $this->assertSame(['female'], array_values($plan['fill']));
        $this->assertSame([self::EMPTY_WITH_PATRONYMIC + 700], array_keys($plan['fill']));

        $this->assertSame(['male'], array_values($plan['canonize']));
        $this->assertSame(1, $plan['canonical'], 'уже канонический — только считается');
        $this->assertSame([self::UNRESOLVABLE + 700], array_keys($plan['unknown']));
    }

    #[Test]
    public function applying_writes_only_the_planned_rows(): void
    {
        $plan = $this->service->plan();
        $written = $this->service->apply($plan['fill'] + $plan['canonize']);

        $this->assertSame(2, $written);
        $this->assertSame('female', $this->genderOf(self::EMPTY_WITH_PATRONYMIC + 700));
        $this->assertSame('male', $this->genderOf(self::LEGACY_VALUE + 700), 'русское значение приведено к канону');
        $this->assertSame('female', $this->genderOf(self::ALREADY_CANONICAL + 700), 'канонический не тронут');
        // Значение осталось ровно таким, каким было (в фикстуре — пустая
        // строка): по имени не гадаем и мусор на null не подменяем.
        $this->assertSame('', $this->genderOf(self::UNRESOLVABLE + 700), 'гадать по имени не пытаемся');
    }

    /** Удалённые партнёры в заливку не попадают. */
    #[Test]
    public function deleted_partners_are_skipped(): void
    {
        $plan = $this->service->plan();
        $touched = array_keys($plan['fill'] + $plan['canonize'] + $plan['unknown']);

        $this->assertNotContains(self::DELETED + 700, $touched);
        $this->service->apply($plan['fill'] + $plan['canonize']);
        $this->assertNull($this->genderOf(self::DELETED + 700));
    }

    /** Повторный прогон ничего не меняет: заполнять уже нечего. */
    #[Test]
    public function a_second_run_is_a_no_op(): void
    {
        $first = $this->service->plan();
        $this->service->apply($first['fill'] + $first['canonize']);

        $second = $this->service->plan();

        $this->assertSame([], $second['fill']);
        $this->assertSame([], $second['canonize']);
        $this->assertSame(0, $this->service->apply([]));
    }

    // ================================================================

    private function genderOf(int $webUserId): ?string
    {
        $value = DB::table('WebUser')->where('id', $webUserId)->value('gender');

        return $value !== null ? (string) $value : null;
    }

    private function seedFixture(): void
    {
        // WebUser.id = consultant.id + 700 — так в проверках видно, к какому
        // партнёру относится логин.
        $rows = [
            [self::EMPTY_WITH_PATRONYMIC, 'Петрова', 'Мария', 'Сергеевна', null, 'Петрова Мария Сергеевна', false],
            [self::LEGACY_VALUE, 'Иванов', 'Иван', 'Петрович', 'Мужской', 'Иванов Иван Петрович', false],
            [self::ALREADY_CANONICAL, 'Сидорова', 'Анна', 'Ильинична', 'female', 'Сидорова Анна Ильинична', false],
            [self::UNRESOLVABLE, 'Ким', 'Саша', '', '', 'Ким Саша', false],
            [self::DELETED, 'Удалённый', 'Партнёр', 'Сергеевич', null, 'Удалённый Партнёр Сергеевич', true],
        ];

        foreach ($rows as [$consultantId, $last, $first, $patronymic, $gender, $personName, $deleted]) {
            $webUserId = $consultantId + 700;

            $u = new User();
            $u->id = $webUserId;
            $u->email = "gender{$webUserId}@test.local";
            $u->role = 'consultant';
            $u->lastName = $last;
            $u->firstName = $first;
            $u->patronymic = $patronymic;
            $u->gender = $gender;
            $u->password = bcrypt('secret123');
            $u->save();

            DB::table('consultant')->insert([
                'id' => $consultantId,
                'webUser' => $webUserId,
                'personName' => $personName,
                'activity' => 1,
                'dateCreated' => '2026-01-01 00:00:00',
                'dateDeleted' => $deleted ? '2026-05-01 00:00:00' : null,
            ]);
        }
    }
}
