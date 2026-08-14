<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Бюджет запросов для горячих admin-списков (Этап 5).
 *
 * Ищем N+1 не сканером по коду (он путает коллекции с запросами), а замером:
 * одна и та же страница на 3 и на 15 строках должна стоить ОДИНАКОВОГО числа
 * запросов. Рост = запрос внутри цикла по строкам.
 *
 * Тест остаётся в наборе как бюджет: если кто-то добавит в сборку строки
 * обращение к БД, счётчик разъедется и тест покраснеет.
 */
class AdminListingsQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 1100000;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User();
        $this->admin->id = 1100900;
        $this->admin->email = 'budget@test.local';
        $this->admin->firstName = 'Бюджет';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();
    }

    #[Test]
    public function partners_listing_cost_is_flat(): void
    {
        $this->assertFlatCost('/admin/partners');
    }

    #[Test]
    public function clients_listing_cost_is_flat(): void
    {
        $this->assertFlatCost('/admin/clients');
    }

    #[Test]
    public function contracts_listing_cost_is_flat(): void
    {
        $this->assertFlatCost('/admin/contracts');
    }

    #[Test]
    public function transactions_listing_cost_is_flat(): void
    {
        $this->assertFlatCost('/admin/transactions');
    }

    #[Test]
    public function partner_statuses_cost_is_flat(): void
    {
        $this->assertFlatCost('/admin/partner-statuses');
    }

    // ================================================================

    /**
     * Стоимость страницы не должна зависеть от числа строк на ней.
     */
    private function assertFlatCost(string $path): void
    {
        $this->seedRows(3, offset: 0);
        $small = $this->countQueries($path);

        $this->seedRows(12, offset: 100);
        $large = $this->countQueries($path);

        $this->assertSame(
            $small,
            $large,
            "{$path}: 3 строки → {$small} запросов, 15 строк → {$large}. "
            . 'Разница означает запрос внутри цикла по строкам.'
        );
    }

    private function countQueries(string $path): int
    {
        // Прогрев: первый вызов тянет пользователя и настройки, которые
        // кэшируются в рамках процесса и исказили бы сравнение.
        $this->admin($path)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->admin($path)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Партнёр + клиент + контракт + транзакция на каждую строку. */
    private function seedRows(int $count, int $offset): void
    {
        for ($i = 0; $i < $count; $i++) {
            $n = self::BASE + $offset + $i;

            DB::table('consultant')->insert([
                'id' => $n,
                'personName' => 'Партнёр ' . $n,
                'activity' => 1,
                'active' => true,
                'dateCreated' => '2026-01-01 00:00:00',
            ]);
            DB::table('client')->insert([
                'id' => $n,
                'consultant' => $n,
                'consultantName' => 'Партнёр ' . $n,
                'personName' => 'Клиент ' . $n,
            ]);
            DB::table('contract')->insert([
                'id' => $n,
                'consultant' => $n,
                'client' => $n,
                'consultantName' => 'Партнёр ' . $n,
                'clientName' => 'Клиент ' . $n,
                'number' => 'BQ-' . $n,
                'openDate' => '2026-06-01',
            ]);
            DB::table('transaction')->insert([
                'id' => $n,
                'contract' => $n,
                'amount' => 100_000,
                'amountRUB' => 100_000,
                'currency' => 67,
                'currencyRate' => 1,
                'commissionsAmountRUB' => 40_000,
                'date' => '2026-06-15',
                'dateMonth' => '2026-06',
                'dateYear' => '2026',
            ]);
        }
    }

    private function admin(string $path)
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1' . $path);
    }
}
