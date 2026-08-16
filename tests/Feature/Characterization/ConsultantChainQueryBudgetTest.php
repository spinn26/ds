<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Бюджет запросов для «цепочки наставников» (Этап 5).
 *
 * Обход шёл циклом: на КАЖДЫЙ уровень — запрос за консультантом плюс запрос
 * за названием квалификации. То есть стоимость росла линейно с глубиной, и
 * ветка из десяти уровней стоила два десятка round-trip к БД.
 *
 * Тест меряет не время (оно шумит), а ЧИСЛО запросов, и требует, чтобы оно
 * НЕ зависело от глубины: сравниваем цепочку из 3 и из 10 уровней.
 */
class ConsultantChainQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 1000000;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User();
        $this->admin->id = 1000900;
        $this->admin->email = 'chain@test.local';
        $this->admin->firstName = 'Цепочка';
        $this->admin->lastName = 'Тестовая';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();
    }

    #[Test]
    public function chain_query_count_does_not_grow_with_depth(): void
    {
        $shallow = $this->seedChain(3);
        $deep = $this->seedChain(10, offset: 100);

        // Прогрев: первый запрос за сессию оплачивает разовые подгрузки
        // (права, настройки), и без него счётчик зависел бы от того, в каком
        // порядке PHPUnit добрался до этого теста, а не от глубины цепочки.
        $this->admin('/admin/consultants/' . $shallow . '/chain')->assertOk();

        $shallowQueries = $this->countQueries(
            fn () => $this->admin('/admin/consultants/' . $shallow . '/chain')->assertOk()
        );
        $deepQueries = $this->countQueries(
            fn () => $this->admin('/admin/consultants/' . $deep . '/chain')->assertOk()
        );

        $this->assertSame(
            $shallowQueries,
            $deepQueries,
            "число запросов зависит от глубины: 3 уровня → {$shallowQueries}, 10 уровней → {$deepQueries}"
        );
    }

    #[Test]
    public function chain_returns_every_level_from_the_node_upwards(): void
    {
        $leaf = $this->seedChain(4);

        $chain = $this->admin('/admin/consultants/' . $leaf . '/chain')->assertOk()->json('chain');

        $this->assertCount(4, $chain, 'сам узел + три предка');
        $this->assertSame($leaf, (int) $chain[0]['id'], 'первым идёт сам узел');
        $this->assertSame(0, $chain[0]['depth']);
        $this->assertSame(3, $chain[3]['depth'], 'глубина растёт от узла к корню');
        $this->assertNotEmpty($chain[0]['personName']);
        $this->assertNotNull($chain[0]['level'], 'название квалификации подставлено');
    }

    /**
     * Партнёрская цепочка обрывается на самом смотрящем: выше своей ветки
     * партнёр структуру не видит. Правка перевела метод на общий обход, и
     * обрыв теперь делает контроллер — поведение должно остаться прежним.
     */
    #[Test]
    public function team_chain_stops_at_the_viewer(): void
    {
        $leaf = $this->seedChain(5, offset: 300);

        // Смотрящий — середина цепочки (третий сверху).
        $viewerId = self::BASE + 300 + 2;
        $viewer = new User();
        $viewer->id = 1000901;
        $viewer->email = 'viewer@test.local';
        $viewer->firstName = 'Смотрящий';
        $viewer->lastName = 'Партнёр';
        $viewer->role = 'consultant';
        $viewer->password = bcrypt('secret123');
        $viewer->save();
        DB::table('consultant')->where('id', $viewerId)->update(['webUser' => $viewer->id]);

        $chain = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/contracts/team/' . $leaf . '/chain')
            ->assertOk()
            ->json('chain');

        $ids = array_map(fn ($r) => (int) $r['id'], $chain);
        $this->assertSame($viewerId, end($ids), 'последний в цепочке — сам смотрящий');
        $this->assertTrue($chain[count($chain) - 1]['isViewer']);
        $this->assertFalse($chain[0]['isViewer']);
        $this->assertNotContains(self::BASE + 300, $ids, 'выше смотрящего ветка не показывается');
    }

    // ================================================================

    /** Возвращает id САМОГО НИЖНЕГО узла цепочки заданной длины. */
    private function seedChain(int $levels, int $offset = 0): int
    {
        $levelId = (int) DB::table('status_levels')->where('level', 3)->value('id');

        $previous = null;
        $id = 0;
        for ($i = 0; $i < $levels; $i++) {
            $id = self::BASE + $offset + $i;
            DB::table('consultant')->insert([
                'id' => $id,
                'inviter' => $previous,
                'personName' => 'Узел ' . $id,
                'activity' => 1,
                'status_and_lvl' => $levelId,
                'dateCreated' => '2026-01-01 00:00:00',
            ]);
            $previous = $id;
        }

        return $id;   // последний созданный — самый нижний
    }

    private function countQueries(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function admin(string $path)
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1' . $path);
    }
}
