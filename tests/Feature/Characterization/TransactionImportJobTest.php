<?php

namespace Tests\Feature\Characterization;

use App\Jobs\ImportTransactionsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Импорт транзакций из файла (ImportTransactionsJob, ветка CSV).
 *
 * Сетка ПОД вынос. У этого импорта длинный список инцидентов, и почти каждое
 * правило здесь — след одного из них:
 *   - неразрывные пробелы из выгрузок Excel/Sheets ломали суммы («7 754,00»
 *     превращалось в 7);
 *   - заголовки приводились к нижнему регистру побайтово, из-за чего «Сумма»
 *     и «Дата» не распознавались НИКОГДА: сумма уходила в ноль, дата — в
 *     сегодняшнюю, а импорт рапортовал об успехе;
 *   - частичное совпадение номера брало первого попавшегося кандидата, и
 *     деньги уходили в чужой контракт;
 *   - отсутствие курса на месяц строки приводило к тихому пересчёту один к
 *     одному, занижая рублёвую сумму примерно в восемьдесят раз.
 *
 * Общий принцип, который тоже закреплён: импорт АТОМАРЕН. Любая ошибка
 * валидации отменяет всю пачку, включая заведомо хорошие строки, — оператор
 * чинит источник и грузит заново, в базе не остаётся половины выгрузки. Под
 * это правило не подпадают закрытые периоды: строка в закрытом месяце —
 * предупреждение и пропуск, а не ошибка.
 */
class TransactionImportJobTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2500001;
    private const CLIENT = 2500002;
    private const PRODUCT = 2500010;
    private const PROGRAM = 2500020;
    private const CONTRACT = 2500030;
    private const OTHER_CONTRACT = 2500031;

    private User $admin;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ds-import-' . getmypid();
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $this->seedFixture();
    }

    // ---------------- Разбор чисел и заголовков ----------------

    /**
     * ⚠ Неразрывные пробелы — разделители разрядов из Excel и Google Sheets.
     * Обычный str_replace их не снимал, и «7 754,00» превращалось в 7.
     */
    #[Test]
    public function non_breaking_spaces_do_not_break_the_amount(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-0001', "7\xC2\xA0754,00", '2026-07-15'],
        ]);

        $this->assertSame(1, $log->success_count, (string) $log->errors);
        $this->assertEqualsWithDelta(7754.0, (float) $this->lastTransaction()->amount, 0.01);
    }

    /**
     * ⚠ Русские заголовки «Сумма» и «Дата» обязаны распознаваться. Приведение
     * к нижнему регистру должно быть многобайтовым — иначе колонки терялись
     * молча, а импорт рапортовал об успехе.
     */
    #[Test]
    public function cyrillic_headers_are_recognised(): void
    {
        $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-0001', '1000', '2026-07-15'],
        ]);

        $tx = $this->lastTransaction();

        $this->assertEqualsWithDelta(1000.0, (float) $tx->amount, 0.01, 'сумма не должна уйти в ноль');
        $this->assertStringStartsWith('2026-07-15', (string) $tx->date, 'дата не должна подмениться сегодняшней');
    }

    /** Процент с запятой (русская локаль Excel) приводится к точке. */
    #[Test]
    public function a_comma_decimal_percent_is_normalised(): void
    {
        $this->import([
            ['Номер контракта', 'Сумма', 'Дата', 'Процент ДС'],
            ['IMP-0001', '1000', '2026-07-15', '2,8'],
        ]);

        $this->assertEqualsWithDelta(2.8,
            (float) $this->lastTransaction()->dsCommissionPercentage, 0.0001);
    }

    // ---------------- Поиск контракта ----------------

    /** Точное совпадение номера — обычный путь. */
    #[Test]
    public function an_exact_number_matches(): void
    {
        $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-0001', '1000', '2026-07-15'],
        ]);

        $this->assertSame(self::CONTRACT, (int) $this->lastTransaction()->contract);
    }

    /**
     * ⚠ Частичное совпадение принимается ТОЛЬКО когда кандидат один. Раньше
     * брался первый попавшийся, и «1001» матчил «10010» — деньги уходили в
     * чужой контракт.
     */
    #[Test]
    public function an_ambiguous_partial_match_is_an_error_listing_candidates(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-000', '1000', '2026-07-15'],
        ]);

        $this->assertSame(0, $log->success_count);
        $this->assertStringContainsString('несколько совпадений', (string) $log->errors);
        $this->assertStringContainsString('IMP-0001', (string) $log->errors, 'кандидаты перечислены');
    }

    /** Единственное частичное совпадение проходит, но с предупреждением. */
    #[Test]
    public function a_unique_partial_match_passes_with_a_warning(): void
    {
        DB::table('contract')->where('id', self::OTHER_CONTRACT)->delete();

        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-000', '1000', '2026-07-15'],
        ]);

        $this->assertSame(1, $log->success_count);
        $this->assertStringContainsString('частичному', (string) $log->warnings);
    }

    /**
     * ⚠ Импорт АТОМАРЕН: одна ошибка валидации отменяет всю пачку, включая
     * заведомо хорошие строки. Оператор чинит источник и грузит заново — так
     * в базе не остаётся половины выгрузки.
     *
     * Закрытые периоды под это правило НЕ подпадают: они предупреждение, а не
     * ошибка (см. отдельный тест).
     */
    #[Test]
    public function a_single_bad_row_cancels_the_whole_import(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['НЕТ-ТАКОГО', '1000', '2026-07-15'],
            ['IMP-0001', '2000', '2026-07-15'],
        ]);

        $this->assertSame(0, (int) $log->success_count, 'хорошая строка тоже не загрузилась');
        $this->assertSame(0, DB::table('transaction')->count(), 'в базе пусто');
        $this->assertStringContainsString('не найден', (string) $log->errors);
        $this->assertSame('error', (string) $log->status);
    }

    // ---------------- Суммы, курс, закрытый период ----------------

    /** Отрицательная сумма — сторно, оно разрешено. */
    #[Test]
    public function a_negative_amount_is_a_valid_reversal(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-0001', '-5000', '2026-07-15'],
        ]);

        $this->assertSame(1, $log->success_count, (string) $log->errors);
        $this->assertEqualsWithDelta(-5000.0, (float) $this->lastTransaction()->amount, 0.01);
    }

    /**
     * ⚠ Нет курса на месяц строки — это ошибка, а НЕ тихий пересчёт один к
     * одному: прежний фолбэк занижал рублёвую сумму примерно в восемьдесят
     * раз для валютных сделок.
     */
    #[Test]
    public function a_missing_rate_is_an_error_not_a_one_to_one_fallback(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-0001', '1000', '2026-07-15'],
        ], currency: 2500050);

        $this->assertSame(0, $log->success_count);
        $this->assertSame(1, $log->error_count);
        $this->assertSame(0, DB::table('transaction')->where('contract', self::CONTRACT)->count());
    }

    /** Строка в закрытом периоде пропускается с предупреждением. */
    #[Test]
    public function a_row_in_a_closed_period_is_skipped_with_a_warning(): void
    {
        DB::table('period_closures')->insert([
            'year' => 2026, 'month' => 7,
            'closed_at' => now(), 'closed_by' => $this->admin->id,
        ]);

        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-0001', '1000', '2026-07-15'],
            ['IMP-0002', '2000', '2026-08-15'],
        ]);

        $this->assertSame(1, $log->success_count, 'августовская строка прошла');
        $this->assertStringContainsString('закрытом периоде', (string) $log->warnings);
    }

    /** Нераспознанная дата — ошибка строки с понятным текстом. */
    #[Test]
    public function an_unparsable_date_is_a_row_error(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-0001', '1000', 'не дата вовсе'],
        ]);

        $this->assertSame(1, $log->error_count);
        $this->assertStringContainsString('дату', (string) $log->errors);
    }

    // ---------------- Свойство ----------------

    /** Свойство по названию резолвится в справочник, без учёта регистра. */
    #[Test]
    public function a_property_is_resolved_by_its_title(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата', 'Свойство'],
            ['IMP-0001', '1000', '2026-07-15', 'стандарт'],
        ]);

        $this->assertSame(1, $log->success_count, (string) $log->errors);
        $this->assertSame(1, (int) $this->lastTransaction()->commissionCalcProperty,
            '«Стандарт» из справочника commissionCalcProperty');
    }

    /** Нераспознанное свойство не теряется молча — оператор получает предупреждение. */
    #[Test]
    public function an_unknown_property_warns_instead_of_silently_dropping(): void
    {
        $log = $this->import([
            ['Номер контракта', 'Сумма', 'Дата', 'Свойство'],
            ['IMP-0001', '1000', '2026-07-15', 'Неведомое'],
        ]);

        $this->assertSame(1, $log->success_count);
        $this->assertStringContainsString('не распознано', (string) $log->warnings);
        $this->assertNull($this->lastTransaction()->commissionCalcProperty);
    }

    // ================================================================

    /** @param list<list<string>> $rows */
    private function import(array $rows, ?int $currency = null): object
    {
        $path = $this->dir . '/import-' . uniqid() . '.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        fclose($handle);

        $logId = DB::table('transaction_import_log')->insertGetId([
            'status' => 'running',
            'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        (new ImportTransactionsJob(
            source: 'csv',
            sourceRef: $path,
            counterpartyId: null,
            currencyId: $currency ?? 67,
            importLogId: (int) $logId,
            userId: $this->admin->id,
            tracker: 'test-' . $logId,
        ))->handle();

        return DB::table('transaction_import_log')->where('id', $logId)->first();
    }

    private function lastTransaction(): object
    {
        return DB::table('transaction')->orderByDesc('id')->first();
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 2500900;
        $this->admin->email = 'import@test.local';
        $this->admin->firstName = 'Импорт';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('vat')->delete();
        DB::table('vat')->insert([
            'id' => 1, 'value' => 20,
            'dateFrom' => '2000-01-01', 'dateTo' => '2099-12-31',
        ]);
        $cache = new \ReflectionProperty(\App\Support\VatRate::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT, 'name' => 'Импортный продукт', 'active' => true,
            'legacy_product_id' => self::PRODUCT,
        ]);
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM, 'product_id' => self::PRODUCT,
            'name' => 'Импортная программа', 'active' => true,
            'legacy_program_id' => self::PROGRAM, 'ds_percent' => 10,
        ]);
        DB::table('consultant')->insert([
            'id' => self::PARTNER, 'personName' => 'Импортный Партнёр',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('client')->insert([
            'id' => self::CLIENT, 'consultant' => self::PARTNER, 'personName' => 'Импортный Клиент',
        ]);

        foreach ([[self::CONTRACT, 'IMP-0001'], [self::OTHER_CONTRACT, 'IMP-0002']] as [$id, $number]) {
            DB::table('contract')->insert([
                'id' => $id,
                'consultant' => self::PARTNER, 'client' => self::CLIENT,
                'clientName' => 'Импортный Клиент',
                'product' => self::PRODUCT, 'program' => self::PROGRAM,
                'number' => $number, 'status' => 1, 'ammount' => 100_000,
                'createDate' => '2026-07-01 00:00:00', 'openDate' => '2026-07-01 00:00:00',
            ]);
        }
    }
}
