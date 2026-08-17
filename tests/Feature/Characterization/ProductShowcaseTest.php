<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Витрина продуктов партнёра (GET /products).
 *
 * Сетка ПОД вынос. Витрина решает, что партнёр вообще может открыть,
 * поэтому здесь закреплены правила доступа и источники данных:
 *   - единственный источник витрины — products_catalog; legacy `product`
 *     остаётся якорем для внешних ключей, но в UI не светится;
 *   - партнёр в статусе ФК или Резидент курсы не проходит — витрина открыта
 *     по активности; у клиента гейт по курсам жив;
 *   - «Тест пройден» считается по журналу прохождений, а НЕ по
 *     consultant.soldProducts: тот не заполняется при сдаче теста, и флаг
 *     навсегда оставался ложным;
 *   - Insmart открывается сразу после акцепта документов, без обучения;
 *   - предпросмотр для staff (?includeDrafts) показывает всё и всё открывает,
 *     но доступен только admin/backoffice/head.
 */
class ProductShowcaseTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2600001;
    private const PRODUCT = 2600010;
    private const HIDDEN = 2600011;
    private const DRAFT = 2600012;
    private const INSMART = 2600013;
    private const COURSE = 2600020;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Состав витрины ----------------

    /** Скрытые от партнёра и неактивные продукты в витрину не идут. */
    #[Test]
    public function the_showcase_hides_inactive_and_invisible_products(): void
    {
        $names = array_column($this->showcase(), 'name');

        $this->assertContains('Видимый продукт', $names);
        $this->assertNotContains('Скрытый от партнёра', $names);
        $this->assertNotContains('Черновик', $names);
    }

    /**
     * ⚠ Предпросмотр для staff показывает ВСЁ (включая черновики и скрытые) и
     * всё открывает — иначе проверить витрину до публикации нечем.
     */
    #[Test]
    public function the_staff_preview_shows_everything(): void
    {
        $this->user->role = 'admin';
        $this->user->save();

        $names = array_column($this->showcase(['includeDrafts' => 1]), 'name');

        $this->assertContains('Черновик', $names);
        $this->assertContains('Скрытый от партнёра', $names);
    }

    /** Партнёру предпросмотр недоступен — он видит обычную витрину. */
    #[Test]
    public function a_partner_cannot_open_the_staff_preview(): void
    {
        $names = array_column($this->showcase(['includeDrafts' => 1]), 'name');

        $this->assertNotContains('Черновик', $names,
            'роль consultant не даёт предпросмотра');
    }

    /** Поиск сужает витрину, а список категорий остаётся полным. */
    #[Test]
    public function the_search_narrows_products_but_not_categories(): void
    {
        $body = $this->body(['search' => 'Видимый']);

        $this->assertCount(1, $body['products']);
        $this->assertContains('Инвестиции', array_column($body['categories'], 'name'));
        $this->assertContains('Страхование', array_column($body['categories'], 'name'),
            'категория другого продукта остаётся в фильтре');
    }

    // ---------------- Доступность ----------------

    /** Пока курс не пройден, продукт с курсом закрыт. */
    #[Test]
    public function a_product_with_an_unfinished_course_stays_locked(): void
    {
        $row = $this->product('Видимый продукт');

        $this->assertFalse($row['available']);
        $this->assertFalse($row['testPassed']);
    }

    /**
     * ⚠ «Тест пройден» берётся из журнала прохождений. Раньше брали из
     * consultant.soldProducts, который сдача теста не заполняет, — флаг
     * навсегда оставался ложным.
     */
    #[Test]
    public function passing_the_course_opens_the_product(): void
    {
        DB::table('education_course_completions')->insert([
            'user_id' => $this->user->id, 'course_id' => self::COURSE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->product('Видимый продукт');

        $this->assertTrue($row['available']);
        $this->assertTrue($row['testPassed']);
    }

    /**
     * ⚠ Партнёр в статусе ФК или Резидент курсы не проходит: витрина открыта
     * по активности целиком.
     */
    #[Test]
    public function a_partner_status_opens_the_whole_showcase(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update(['status' => 2]);

        $this->assertTrue($this->product('Видимый продукт')['available'],
            'у ФК гейт по курсам не применяется');
    }

    /** Insmart открывается акцептом документов, а не обучением. */
    #[Test]
    public function insmart_is_gated_by_the_acceptance_only(): void
    {
        $this->assertFalse($this->product('Страхование Insmart')['available']);

        DB::table('consultant')->where('id', self::PARTNER)->update(['acceptance' => true]);

        $this->assertTrue($this->product('Страхование Insmart')['available']);
    }

    // ---------------- Программы ----------------

    /**
     * Программы берутся из каталога — там их правит админ.
     *
     * ⚠ Ветка «фолбэк на legacy `program`, если каталожных программ нет»
     * сейчас НЕНАБЛЮДАЕМА: legacy `program` стал представлением над
     * programs_catalog, то есть оба источника отдают одни и те же строки.
     * Проверить её можно будет, только если представление снимут.
     */
    #[Test]
    public function programs_come_from_the_catalog(): void
    {
        $names = array_column($this->product('Видимый продукт')['programs'], 'name');

        $this->assertContains('Каталожная программа', $names);
    }

    /** Программа, скрытая от партнёра, в витрину не идёт. */
    #[Test]
    public function a_program_hidden_from_partners_is_not_listed(): void
    {
        $names = array_column($this->product('Видимый продукт')['programs'], 'name');

        $this->assertNotContains('Скрытая программа', $names);
    }

    // ================================================================

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function body(array $params = []): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?' . http_build_query($params))
            ->assertOk()->json();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function showcase(array $params = []): array
    {
        return $this->body($params)['products'];
    }

    /** @return array<string, mixed> */
    private function product(string $name): array
    {
        $row = collect($this->showcase())->firstWhere('name', $name);
        $this->assertNotNull($row, 'продукт «' . $name . '» отсутствует в витрине');

        return $row;
    }

    private function seedFixture(): void
    {
        $this->user = new User();
        $this->user->id = 2600900;
        $this->user->email = 'showcase@test.local';
        $this->user->firstName = 'Витрина';
        $this->user->lastName = 'Тестовая';
        $this->user->role = 'consultant';
        $this->user->password = bcrypt('secret123');
        $this->user->save();

        // Справочник статусов партнёра: на него смотрит внешний ключ
        // consultant.status, а в схему-фикстуру он не попал.
        DB::table('status')->insert([
            ['id' => 1, 'title' => 'Клиент'],
            ['id' => 2, 'title' => 'Финансовый консультант'],
            ['id' => 3, 'title' => 'Резидент'],
        ]);

        DB::table('consultant')->insert([
            'id' => self::PARTNER, 'webUser' => $this->user->id,
            'personName' => 'Витринный Партнёр', 'activity' => 1,
            'active' => false, 'status' => 1, 'acceptance' => false,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);

        DB::table('products_catalog')->insert([
            ['id' => self::PRODUCT, 'name' => 'Видимый продукт', 'type' => 'Инвестиции',
                'active' => true, 'visible_to_resident' => true, 'legacy_product_id' => self::PRODUCT],
            ['id' => self::HIDDEN, 'name' => 'Скрытый от партнёра', 'type' => 'Страхование',
                'active' => true, 'visible_to_resident' => false, 'legacy_product_id' => self::HIDDEN],
            ['id' => self::DRAFT, 'name' => 'Черновик', 'type' => 'Инвестиции',
                'active' => false, 'visible_to_resident' => true, 'legacy_product_id' => self::DRAFT],
            ['id' => self::INSMART, 'name' => 'Страхование Insmart', 'type' => 'Страхование',
                'active' => true, 'visible_to_resident' => true, 'legacy_product_id' => self::INSMART],
        ]);

        DB::table('programs_catalog')->insert([
            ['id' => 2600030, 'product_id' => self::PRODUCT, 'name' => 'Каталожная программа',
                'active' => true, 'visible_to_resident' => true, 'legacy_program_id' => 2600030],
            ['id' => 2600031, 'product_id' => self::PRODUCT, 'name' => 'Скрытая программа',
                'active' => true, 'visible_to_resident' => false, 'legacy_program_id' => 2600031],
        ]);

        DB::table('education_courses')->insert([
            'id' => self::COURSE, 'title' => 'Курс по продукту',
            'product_id' => self::PRODUCT, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
