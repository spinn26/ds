<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Справочники формы контракта (/admin/contracts/form-data).
 *
 * Сетка ПОД вынос метода в сервис. Метод собирает девять списков из двух
 * поколений схемы сразу — legacy product/program (сейчас это ПРЕДСТАВЛЕНИЯ над
 * каталогом) и products_catalog/programs_catalog. Отсюда особенности, которые
 * при переносе теряются молча:
 *   - «поставщик» — это COALESCE(vendorName, providerName), причём Insmart
 *     схлопывается в одну строку и поднимается наверх списка;
 *   - products_catalog.provider_name в поставщики НЕ добавляется намеренно;
 *   - записи, которых нет в legacy, отдаются с ОТРИЦАТЕЛЬНЫМ id — так фронт
 *     отличает каталог от legacy;
 *   - валюты фильтруются по selectable, сетапы — по белому списку кодов.
 */
class ContractFormDataTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT = 1700020;
    private const PRODUCT_CATALOG_ONLY = 1700021;
    private const INSMART_PRODUCT = 1700022;

    private const PROGRAM_VENDOR = 1700030;
    private const PROGRAM_PROVIDER = 1700031;
    private const PROGRAM_INSMART = 1700032;

    private const SETUP_PARTNER = 1700040;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Поставщики ----------------

    /** Канал дистрибуции (vendorName) важнее страховщика (providerName). */
    #[Test]
    public function vendor_wins_over_provider(): void
    {
        $suppliers = $this->formData()['suppliers'];

        $this->assertContains('Канал ГГА', $suppliers);
        $this->assertNotContains('Страховщик Ренессанс', $suppliers,
            'у программы заполнен vendorName — providerName в список не идёт');
        // У программы без vendorName в дело идёт providerName.
        $this->assertContains('Страховщик Одинокий', $suppliers);
    }

    /** Все варианты написания Insmart схлопываются в одну строку — и наверх. */
    #[Test]
    public function insmart_is_collapsed_and_pinned_to_the_top(): void
    {
        $suppliers = $this->formData()['suppliers'];

        $this->assertSame('Insmart', $suppliers[0]);
        $this->assertSame(1, count(array_filter($suppliers, fn ($s) => stripos($s, 'smart') !== false)));
    }

    /**
     * 🐞 Вендор из нового каталога в список НЕ попадает — и это баг, а не
     * замысел: источник 2 складывает значения через `each(fn ($v) => $supSet[$v]
     * = true)`, а замыкание захватывает массив ПО ЗНАЧЕНИЮ, так что весь блок
     * ничего не делает. Тест фиксирует поведение как есть, чтобы вынос метода
     * в сервис был проверяемо равносильным; починка идёт отдельным коммитом,
     * который эту проверку и перевернёт.
     */
    #[Test]
    public function catalog_vendor_is_lost(): void
    {
        $this->assertNotContains('Каталожный вендор', $this->formData()['suppliers']);
    }

    /**
     * ⚠ products_catalog.provider_name в поставщики НЕ добавляется: там у части
     * продуктов лежит конечный страховщик, а поставщиком считается канал.
     * Выбор такого значения в фильтре вернул бы пустой список.
     */
    #[Test]
    public function product_catalog_provider_name_is_not_a_supplier(): void
    {
        $this->assertNotContains('Провайдер Продукта', $this->formData()['suppliers']);
    }

    /** Сортировка натуральная и без учёта регистра, Insmart — вне сортировки. */
    #[Test]
    public function suppliers_are_sorted_naturally(): void
    {
        $rest = array_slice($this->formData()['suppliers'], 1);
        $sorted = $rest;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);

        $this->assertSame($sorted, $rest);
    }

    // ---------------- Программы и продукты ----------------

    /** Программы из каталога отдаются с отрицательным id. */
    #[Test]
    public function catalog_only_programs_carry_a_negative_id(): void
    {
        $programs = collect($this->formData()['programs']);

        $this->assertTrue($programs->contains('id', self::PROGRAM_VENDOR),
            'legacy-программа отдаётся своим id');
        $this->assertTrue($programs->contains('id', -self::PROGRAM_VENDOR),
            'она же, как запись каталога без legacy_program_id, отдаётся с минусом');
    }

    /** Продукты из каталога — тоже с отрицательным id и с catalogId. */
    #[Test]
    public function catalog_only_products_carry_a_negative_id(): void
    {
        $products = collect($this->formData()['products']);
        $row = $products->firstWhere('id', -self::PRODUCT_CATALOG_ONLY);

        $this->assertNotNull($row);
        $this->assertSame(self::PRODUCT_CATALOG_ONLY, $row['catalogId']);
        $this->assertFalse($row['hasProperty']);
    }

    /** У связанного с каталогом продукта проброшен catalogId и флаги. */
    #[Test]
    public function linked_product_exposes_catalog_id_and_flags(): void
    {
        $row = collect($this->formData()['products'])->firstWhere('id', self::PRODUCT);

        $this->assertSame(self::PRODUCT, $row['catalogId']);
        $this->assertTrue($row['hasProperty']);
        $this->assertTrue($row['hasTerm']);
        $this->assertFalse($row['hasYearKv']);
    }

    /** Неактивные продукты без записи в каталоге в список не идут. */
    #[Test]
    public function inactive_products_are_hidden(): void
    {
        DB::table('products_catalog')->insert([
            'id' => 1700099, 'name' => 'Выключенный продукт',
            'active' => false, 'legacy_product_id' => null,
        ]);

        $ids = array_column($this->formData()['products'], 'id');

        $this->assertNotContains(1700099, $ids);
        $this->assertNotContains(-1700099, $ids);
    }

    /** Списки отсортированы по имени. */
    #[Test]
    public function programs_and_products_are_sorted_by_name(): void
    {
        $data = $this->formData();

        foreach (['programs', 'products'] as $key) {
            $names = array_column($data[$key], 'name');
            $sorted = $names;
            sort($sorted);
            $this->assertSame($sorted, $names, $key . ' должны идти по имени');
        }
    }

    // ---------------- Простые справочники ----------------

    /** Валюты — только те, что можно выбрать в форме. */
    #[Test]
    public function currencies_are_limited_to_selectable(): void
    {
        $ids = array_column($this->formData()['currencies'], 'id');
        $expected = DB::table('currency')->where('selectable', true)->orderBy('id')->pluck('id')->all();

        $this->assertSame($expected, $ids);
        $this->assertNotEmpty($ids);
    }

    /** Сетапы — только из белого списка кодов, имя склеивается с ФИО. */
    #[Test]
    public function setups_are_limited_to_the_allowed_codes(): void
    {
        $setups = $this->formData()['setups'];

        $this->assertCount(1, $setups);
        $this->assertSame('565395 Сетапов Сетап', $setups[0]['name']);
    }

    // ================================================================

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/contracts/form-data')
            ->assertOk()->json();
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 1700900;
        $this->admin->email = 'formdata@test.local';
        $this->admin->firstName = 'Форма';
        $this->admin->lastName = 'Тестовая';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        // product/program — представления над каталогом, сеем каталог.
        DB::table('products_catalog')->insert([
            [
                'id' => self::PRODUCT, 'name' => 'Аполлон', 'active' => true,
                'legacy_product_id' => self::PRODUCT,
                'provider_name' => 'Провайдер Продукта',
                'has_property' => true, 'has_term' => true, 'has_year_kv' => false,
            ],
            [
                'id' => self::PRODUCT_CATALOG_ONLY, 'name' => 'Байкал', 'active' => true,
                'legacy_product_id' => null,
                'provider_name' => null,
                'has_property' => false, 'has_term' => false, 'has_year_kv' => false,
            ],
            [
                'id' => self::INSMART_PRODUCT, 'name' => 'Страхование Insmart', 'active' => true,
                'legacy_product_id' => self::INSMART_PRODUCT,
                'provider_name' => null,
                'has_property' => false, 'has_term' => false, 'has_year_kv' => false,
            ],
        ]);

        DB::table('programs_catalog')->insert([
            [
                'id' => self::PROGRAM_VENDOR, 'product_id' => self::PRODUCT,
                'name' => 'Программа с каналом', 'active' => true,
                'provider_name' => 'Страховщик Ренессанс', 'vendor' => 'Канал ГГА',
            ],
            [
                'id' => self::PROGRAM_PROVIDER, 'product_id' => self::PRODUCT,
                'name' => 'Программа без канала', 'active' => true,
                'provider_name' => 'Страховщик Одинокий', 'vendor' => null,
            ],
            [
                'id' => self::PROGRAM_INSMART, 'product_id' => self::INSMART_PRODUCT,
                'name' => 'Программа Insmart', 'active' => true,
                'provider_name' => 'inssmart', 'vendor' => 'Каталожный вендор',
            ],
            // Пары, на которых натуральная сортировка расходится с побайтовой:
            // «Канал 9» < «Канал 10» только при натуральной, а «банк альфа»
            // встаёт перед «Банк Бета» только без учёта регистра.
            [
                'id' => 1700033, 'product_id' => self::PRODUCT,
                'name' => 'Программа Ю', 'active' => true,
                'provider_name' => null, 'vendor' => 'Канал 9',
            ],
            [
                'id' => 1700034, 'product_id' => self::PRODUCT,
                'name' => 'Программа Я', 'active' => true,
                'provider_name' => null, 'vendor' => 'Канал 10',
            ],
            [
                'id' => 1700035, 'product_id' => self::PRODUCT,
                'name' => 'Программа Э', 'active' => true,
                'provider_name' => null, 'vendor' => 'банк альфа',
            ],
            [
                'id' => 1700036, 'product_id' => self::PRODUCT,
                'name' => 'Программа Ы', 'active' => true,
                'provider_name' => null, 'vendor' => 'Банк Бета',
            ],
        ]);

        DB::table('consultant')->insert([
            'id' => self::SETUP_PARTNER, 'personName' => 'Сетапов Сетап',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('setup')->insert([
            // Код из белого списка — попадёт в выдачу.
            ['id' => 1700050, 'setup' => '565395', 'consultant' => self::SETUP_PARTNER],
            // Код не из списка — не попадёт.
            ['id' => 1700051, 'setup' => '999999', 'consultant' => self::SETUP_PARTNER],
        ]);
    }
}
