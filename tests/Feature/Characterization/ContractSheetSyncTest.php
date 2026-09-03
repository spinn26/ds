<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use App\Services\ApiSettingsService;
use App\Services\GoogleSheetsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Забор данных из таблицы «Парус/Акцент» (ТЗ от 03.09.2026).
 *
 * Закрепляем правила, цена ошибки в которых — деньги и чужие контракты:
 *   - сопоставление по ID (§4.1), а при пустом ID — по номеру, и тогда ID
 *     проставляется обратно в лист;
 *   - таблица перезаписывает платформу (§4.2);
 *   - ФИО клиента и партнёра не перезаписываются никогда: расхождение
 *     останавливает ВСЮ синхронизацию и не пишет ни строчки (§4.2, §5);
 *   - «Активирован» тянет дату открытия из столбца B, пустой B дату не
 *     затирает (§4.3);
 *   - каждая правка попадает в историю контракта с основанием.
 */
class ContractSheetSyncTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = 2400001;
    private const PARTNER = 2400002;
    private const CONTRACT = 2400010;
    private const PRODUCT = 2400020;
    private const PROGRAM = 2400030;

    private User $admin;
    private SpyingSheetsWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = new SpyingSheetsWriter();
        $this->app->instance(GoogleSheetsWriter::class, $this->writer);

        $this->seedFixture();
    }

    // ---------------- Сопоставление ----------------

    /**
     * Пустой столбец C: ищем по номеру и проставляем ID обратно в лист —
     * со второго прогона связка живёт уже по ID, как требует §4.1.
     */
    #[Test]
    public function an_empty_id_column_matches_by_number_and_is_filled_back(): void
    {
        $this->sheet([
            ['Активирован', '15.08.2026', '', '137АК',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '2 836,00', '₽'],
        ]);

        $this->sync()->assertOk()->assertJsonPath('updated', 1);

        $this->assertSame('Лист1!C2', $this->writer->batched[0]['range']);
        $this->assertSame(self::CONTRACT, $this->writer->batched[0]['values'][0][0]);
    }

    /** Заполненный ID главнее номера: по нему и ищем, номер при этом правим. */
    #[Test]
    public function a_filled_id_wins_over_the_number(): void
    {
        $this->sheet([
            ['Активирован', '', (string) self::CONTRACT, 'НОВЫЙ-НОМЕР',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '100,00', '₽'],
        ]);

        $this->sync()->assertOk()->assertJsonPath('updated', 1);

        $this->assertSame('НОВЫЙ-НОМЕР', DB::table('contract')->where('id', self::CONTRACT)->value('number'));
    }

    /**
     * ⛔ Синхронизация НИЧЕГО не создаёт: в ТЗ этого нет, контракты заводятся
     * на платформе при поступлении сделки. Незнакомый номер — в отчёт.
     */
    #[Test]
    public function an_unknown_number_is_reported_not_created(): void
    {
        $before = DB::table('contract')->count();

        $this->sheet([
            ['Активирован', '01.09.2026', '', 'СОВСЕМ-НОВЫЙ',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '5 000,00', '₽'],
        ]);

        $this->sync()
            ->assertOk()
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('errors.0.reason', 'контракт «СОВСЕМ-НОВЫЙ» не найден на платформе');

        $this->assertSame($before, DB::table('contract')->count());
    }

    // ---------------- Обновление ----------------

    /** Итог сверки: статус, дата открытия и фактическая сумма. */
    #[Test]
    public function matching_rows_overwrite_the_platform(): void
    {
        $this->sheet([
            ['Активирован', '15.08.2026', '', '137АК',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '2 836,00', '₽'],
        ]);

        $this->sync()->assertOk();

        $c = DB::table('contract')->where('id', self::CONTRACT)->first();
        $this->assertSame(1, (int) $c->status, 'статус стал «Активирован»');
        $this->assertEqualsWithDelta(2836.0, (float) $c->ammount, 0.01);
        $this->assertSame('2026-08-15', substr((string) $c->openDate, 0, 10));
    }

    /** ⚠ Пустой столбец B не затирает уже проставленную дату открытия. */
    #[Test]
    public function an_empty_open_date_column_leaves_the_date_alone(): void
    {
        DB::table('contract')->where('id', self::CONTRACT)->update(['openDate' => '2026-07-01 00:00:00']);

        $this->sheet([
            ['Активирован', '', '', '137АК',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '1 000,00', '₽'],
        ]);

        $this->sync()->assertOk();

        $this->assertSame('2026-07-01',
            substr((string) DB::table('contract')->where('id', self::CONTRACT)->value('openDate'), 0, 10));
    }

    /** Каждая правка видна в истории контракта с основанием и автором. */
    #[Test]
    public function every_change_lands_in_the_contract_history(): void
    {
        $this->sheet([
            ['Активирован', '', '', '137АК',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '4 242,00', '₽'],
        ]);

        $this->sync()->assertOk();

        $log = DB::table('activity_log')
            ->where('subject_type', \App\Models\Contract::class)
            ->where('subject_id', self::CONTRACT)
            ->orderByDesc('id')->first();

        $this->assertNotNull($log, 'запись в истории есть');
        $this->assertStringContainsString('таблицы Парус/Акцент', (string) $log->description);
        $this->assertSame($this->admin->id, (int) $log->causer_id, 'автор — тот, кто запустил');

        $props = json_decode((string) $log->properties, true);
        $this->assertEqualsWithDelta(100.0, (float) $props['old']['ammount'], 0.01, 'старое значение');
        $this->assertEqualsWithDelta(4242.0, (float) $props['attributes']['ammount'], 0.01, 'новое значение');
    }

    // ---------------- ФИО останавливают всё ----------------

    /**
     * ⛔ Расхождение по ФИО не правится автоматически: смена клиента или
     * партнёра двигает деньги по дереву наставников. Синхронизация
     * останавливается целиком — даже правка суммы в этой же пачке не идёт.
     */
    #[Test]
    public function a_name_mismatch_stops_the_whole_sync_and_writes_nothing(): void
    {
        $this->sheet([
            ['Активирован', '', '', '137АК',
                'Совсем Другой Человек', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '9 999,00', '₽'],
        ]);

        $this->sync()
            ->assertStatus(422)
            ->assertJsonPath('status', 'name_mismatch')
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('nameMismatches.0.field', 'клиента');

        $this->assertEqualsWithDelta(100.0,
            (float) DB::table('contract')->where('id', self::CONTRACT)->value('ammount'), 0.01);
        $this->assertSame([], $this->writer->batched, 'в таблицу тоже ничего не писали');
    }

    /** Регистр и лишние пробелы в ФИО различием не считаются. */
    #[Test]
    public function names_differing_only_by_case_or_spacing_are_equal(): void
    {
        $this->sheet([
            ['Активирован', '', '', '137АК',
                'флерина   ирина Александровна', '  Литвинов Юрий Геннадьевич ',
                'ЗПИФ Акцент', 'Акцент-4', '555,00', '₽'],
        ]);

        $this->sync()->assertOk()->assertJsonPath('updated', 1);
    }

    // ---------------- Ошибки строк ----------------

    /** Строку без номера сопоставить не с чем — уходит в отчёт. */
    #[Test]
    public function a_row_without_a_number_is_reported(): void
    {
        $before = DB::table('contract')->count();

        $this->sheet([
            ['Закрыто нереализовано', '', '', '', 'Жукова Елизавета Владимировна',
                'Магдиева Алина Рафисовна', 'ЗПИФ Акцент', 'Акцент-5', '0,00', '₽'],
        ]);

        $this->sync()
            ->assertOk()
            ->assertJsonPath('errors.0.reason', 'не заполнен номер контракта — сопоставить не с чем');

        $this->assertSame($before, DB::table('contract')->count());
    }

    /**
     * Строка с неразобранным справочным полем не применяется целиком:
     * записать половину правок хуже, чем не записать ничего.
     */
    #[Test]
    public function a_row_with_an_unknown_product_is_skipped_entirely(): void
    {
        $this->sheet([
            ['Активирован', '', (string) self::CONTRACT, 'НОВЫЙ-НОМЕР',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'Такого продукта нет', 'Акцент-4', '777,00', '₽'],
        ]);

        $this->sync()->assertOk()->assertJsonPath('updated', 0);

        $this->assertSame('137АК', DB::table('contract')->where('id', self::CONTRACT)->value('number'));
    }

    // ---------------- Откат ----------------

    /** Откат возвращает контракту значения, которые были до прогона. */
    #[Test]
    public function a_run_can_be_rolled_back(): void
    {
        $this->sheet([
            ['Активирован', '15.08.2026', '', '137АК',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '2 836,00', '₽'],
        ]);

        $runId = $this->sync()->assertOk()->json('runId');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/contracts/sheet-sync/runs/{$runId}/rollback")
            ->assertOk()
            ->assertJsonPath('restored', 1);

        $c = DB::table('contract')->where('id', self::CONTRACT)->first();
        $this->assertEqualsWithDelta(100.0, (float) $c->ammount, 0.01, 'сумма вернулась');
        $this->assertSame(3, (int) $c->status, 'статус вернулся в «Комплайнс»');
        $this->assertNull($c->openDate, 'дата открытия вернулась в пустую');
    }

    /**
     * ⛔ Поле, поправленное руками ПОСЛЕ синхронизации, откат не трогает:
     * иначе он затёр бы более свежую правку человека.
     */
    #[Test]
    public function a_rollback_skips_fields_edited_after_the_sync(): void
    {
        $this->sheet([
            ['Активирован', '', '', '137АК',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '2 836,00', '₽'],
        ]);

        $runId = $this->sync()->assertOk()->json('runId');

        // Человек поправил сумму уже после прогона.
        DB::table('contract')->where('id', self::CONTRACT)->update(['ammount' => 7777]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/contracts/sheet-sync/runs/{$runId}/rollback")
            ->assertOk()
            ->assertJsonPath('skipped.0.reason', 'значение меняли после синхронизации');

        $this->assertEqualsWithDelta(7777.0,
            (float) DB::table('contract')->where('id', self::CONTRACT)->value('ammount'), 0.01,
            'ручная правка уцелела');
    }

    /** Повторный откат того же прогона запрещён. */
    #[Test]
    public function a_run_cannot_be_rolled_back_twice(): void
    {
        $this->sheet([
            ['Активирован', '', '', '137АК',
                'Флерина Ирина Александровна', 'Литвинов Юрий Геннадьевич',
                'ЗПИФ Акцент', 'Акцент-4', '2 836,00', '₽'],
        ]);

        $runId = $this->sync()->assertOk()->json('runId');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/contracts/sheet-sync/runs/{$runId}/rollback")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/contracts/sheet-sync/runs/{$runId}/rollback")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Этот прогон уже откачен');
    }

    /** Недоступное API — понятный текст, а не стектрейс (§5). */
    #[Test]
    public function an_unreachable_sheets_api_returns_a_readable_message(): void
    {
        Http::fake(['sheets.googleapis.com/*' => Http::response('nope', 500)]);

        $this->sync()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ошибка подключения к таблице, попробуйте позже');
    }

    // ================================================================

    /** @param list<list<string>> $rows строки БЕЗ шапки */
    private function sheet(array $rows): void
    {
        $header = ['Статус', 'открыт', 'ID', 'Номер', 'клиент', 'партнёр ',
            'Продукт ', 'Программа', 'Сумма по акту', 'валюта по акту'];

        Http::fake([
            'sheets.googleapis.com/*' => Http::response(['values' => array_merge([$header], $rows)]),
        ]);
    }

    private function sync(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/contracts/sheet-sync');
    }

    private function seedFixture(): void
    {
        app(ApiSettingsService::class)->set('google.sheets.api_key', 'test-key');

        $this->admin = new User();
        $this->admin->id = 2400900;
        $this->admin->email = 'sheetsync@test.local';
        $this->admin->firstName = 'Синх';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            'id' => self::PARTNER, 'personName' => 'Литвинов Юрий Геннадьевич',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
        ]);
        // Партнёр контракта берётся из карточки клиента — так же, как в
        // AdminDataController::storeContract.
        DB::table('client')->insert([
            'id' => self::CLIENT, 'personName' => 'Флерина Ирина Александровна',
            'consultant' => self::PARTNER,
        ]);

        // `product`/`program` в public — вьюхи над каталогами.
        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT, 'name' => 'ЗПИФ Акцент', 'active' => true,
        ]);
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM, 'name' => 'Акцент-4', 'product_id' => self::PRODUCT,
        ]);

        DB::table('contract')->insert([
            'id' => self::CONTRACT,
            'number' => '137АК',
            'client' => self::CLIENT,
            'clientName' => 'Флерина Ирина Александровна',
            'consultant' => self::PARTNER,
            'consultantName' => 'Литвинов Юрий Геннадьевич',
            'product' => self::PRODUCT,
            'productName' => 'ЗПИФ Акцент',
            'program' => self::PROGRAM,
            'programName' => 'Акцент-4',
            'ammount' => 100,
            'currency' => 67,
            'status' => 3, // Комплайнс — ждёт сверки
            'createDate' => '2026-08-01 00:00:00',
        ]);
    }
}

/** Перехватывает запись ID обратно в лист. */
class SpyingSheetsWriter extends GoogleSheetsWriter
{
    /** @var list<array{range: string, values: list<list<mixed>>}> */
    public array $batched = [];

    public function batchUpdateValues(string $spreadsheetId, array $data): void
    {
        $this->batched = array_merge($this->batched, $data);
    }
}
