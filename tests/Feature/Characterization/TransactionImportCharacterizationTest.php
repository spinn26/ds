<?php

namespace Tests\Feature\Characterization;

use App\Jobs\ImportTransactionsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ХАРАКТЕРИЗУЮЩИЙ тест массового импорта транзакций (Этап 0).
 *
 * Импорт — единственная точка, через которую деньги заходят пачками: одна
 * ошибка разбора множится на тысячу строк. Проверяем CSV-ветку (та же
 * валидация и та же вставка, что у Google Sheets, но без сети).
 *
 * Что закреплено:
 *   - разбор сумм с неразрывными пробелами (инцидент Робо: «7 754,00» → 7);
 *   - курс берётся по дате СТРОКИ, а не «последний в справочнике»;
 *   - ненайденный контракт отменяет импорт целиком, без частичной заливки;
 *   - неоднозначное частичное совпадение номера — ошибка, а не «первый попался»;
 *   - строки закрытого периода пропускаются предупреждением, остальные грузятся;
 *   - отсутствие курса валюты — ошибка строки, а не курс 1:1.
 */
class TransactionImportCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const CONSULTANT = 930001;
    private const CONTRACT_A = 930010;
    private const CONTRACT_B = 930011;
    private const IMPORT_LOG = 930100;

    private const RUB = 67;
    private const USD = 5;

    /**
     * ⚠ Заголовки подобраны так, чтобы ключевое слово стояло в НИЖНЕМ регистре.
     * parseCsv приводит шапку байтовым strtolower(), который кириллицу не
     * трогает, поэтому «Сумма» и «Дата» не распознаются НИКОГДА. Отдельный тест
     * ниже фиксирует это как есть — см. отчёт по Этапу 0.
     */
    private const HEADERS = ['Номер контракта', 'Общая сумма', 'Плановая дата'];

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\CurrencyRates::flush();
        \App\Support\VatRate::flush();
        $this->seedBase();
        $this->csvPath = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
    }

    protected function tearDown(): void
    {
        if (is_file($this->csvPath)) {
            @unlink($this->csvPath);
        }
        parent::tearDown();
    }

    /**
     * Базовый проход: две строки, разные контракты. Суммы приходят в формате
     * выгрузки — с НЕРАЗРЫВНЫМ пробелом-разделителем разрядов и запятой.
     * Именно на нём импорт Робо резал «7 754,00» до 7.
     */
    #[Test]
    public function imports_rows_and_parses_amounts_with_nbsp(): void
    {
        $nbsp = "\u{00A0}";
        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', "7{$nbsp}754,00", '15.07.2026'],
            ['IMP-B', '1 200.50', '2026-07-20'],
        ]);

        $this->runImport();

        $log = $this->log();
        $this->assertSame('success', $log->status);
        $this->assertSame(2, (int) $log->total_rows);
        $this->assertSame(2, (int) $log->success_count);
        $this->assertSame(0, (int) $log->error_count);

        $rows = DB::table('transaction')->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $this->assertEqualsWithDelta(7_754.0, (float) $rows[0]->amount, 0.01, 'NBSP не должен резать сумму');
        $this->assertEqualsWithDelta(7_754.0, (float) $rows[0]->amountRUB, 0.01, 'рубли: курс 1');
        $this->assertSame('2026-07', $rows[0]->dateMonth);
        $this->assertSame('2026', $rows[0]->dateYear);
        $this->assertSame(self::CONTRACT_A, (int) $rows[0]->contract);
        $this->assertSame('Импорт #' . self::IMPORT_LOG, $rows[0]->comment);

        $this->assertEqualsWithDelta(1_200.50, (float) $rows[1]->amount, 0.01);

        $ids = json_decode((string) $log->created_ids, true);
        $this->assertCount(2, $ids, 'id загруженных строк пишутся в лог — по ним работает откат');
    }

    /**
     * Курс — по дате СТРОКИ. Две долларовые строки в разных месяцах должны
     * конвертироваться по разным курсам, а не по «последнему в справочнике»:
     * донос майских выплат в июле иначе уезжал по июльскому курсу.
     */
    #[Test]
    public function currency_rate_is_taken_per_row_date(): void
    {
        // В фикстуре лежат НАСТОЯЩИЕ курсы с прода — убираем долларовые, иначе
        // они конкурируют с тестовыми за тот же месяц и выигрывают по порядку.
        DB::table('currencyRate')->where('currency', self::USD)->delete();
        \App\Support\CurrencyRates::flush();

        // ⚠ id задаём явно: у legacy-таблицы currencyRate нет сиквенса.
        DB::table('currencyRate')->insert([
            ['id' => 930200, 'currency' => self::USD, 'rate' => 80, 'date' => '2026-06-01 03:00:00'],
            ['id' => 930201, 'currency' => self::USD, 'rate' => 90, 'date' => '2026-07-01 03:00:00'],
        ]);

        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', '100', '15.06.2026'],
            ['IMP-B', '100', '15.07.2026'],
        ]);

        $this->runImport(currencyId: self::USD);

        $rows = DB::table('transaction')->orderBy('date')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(8_000.0, (float) $rows[0]->amountRUB, 0.01, 'июнь по 80');
        $this->assertEqualsWithDelta(9_000.0, (float) $rows[1]->amountRUB, 0.01, 'июль по 90');
    }

    /** Ненайденный контракт отменяет ВЕСЬ импорт: частичной заливки не бывает. */
    #[Test]
    public function unknown_contract_aborts_the_whole_import(): void
    {
        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', '1000', '15.07.2026'],
            ['НЕТ-ТАКОГО', '2000', '15.07.2026'],
        ]);

        $this->runImport();

        $log = $this->log();
        $this->assertSame('error', $log->status);
        $this->assertSame(0, DB::table('transaction')->count(), 'ни одной строки не загружено');
        $this->assertStringContainsString('НЕТ-ТАКОГО', (string) $log->errors);
    }

    /**
     * Частичное совпадение принимается ТОЛЬКО когда оно единственное.
     * Раньше брался первый попавшийся, и «1001» уезжал в «10010».
     */
    #[Test]
    public function ambiguous_partial_match_is_an_error(): void
    {
        DB::table('contract')->insert([
            ['id' => 930020, 'consultant' => self::CONSULTANT, 'number' => 'IMP-A-1'],
            ['id' => 930021, 'consultant' => self::CONSULTANT, 'number' => 'IMP-A-2'],
        ]);

        $this->writeCsv([
            self::HEADERS,
            ['IMP-A-', '1000', '15.07.2026'],
        ]);

        $this->runImport();

        $log = $this->log();
        $this->assertSame('error', $log->status);
        $this->assertStringContainsString('несколько совпадений', (string) $log->errors);
        $this->assertSame(0, DB::table('transaction')->count());
    }

    /** Единственное частичное совпадение проходит, но с предупреждением. */
    #[Test]
    public function unique_partial_match_passes_with_warning(): void
    {
        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', '1000', '15.07.2026'],
            ['MP-B', '2000', '15.07.2026'],   // частичное совпадение с IMP-B
        ]);

        $this->runImport();

        $log = $this->log();
        $this->assertSame('success', $log->status);
        $this->assertSame(2, (int) $log->success_count);
        $this->assertStringContainsString('частичному', (string) $log->warnings);
    }

    /** Строка в закрытом периоде пропускается, остальные грузятся. */
    #[Test]
    public function frozen_period_row_is_skipped_not_fatal(): void
    {
        DB::table('period_closures')->insert([
            'year' => 2026, 'month' => 6, 'closed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', '1000', '15.06.2026'],   // закрытый период
            ['IMP-B', '2000', '15.07.2026'],   // открытый
        ]);

        $this->runImport();

        $log = $this->log();
        $this->assertSame('success', $log->status);
        $this->assertSame(1, (int) $log->success_count, 'загружена только открытая строка');
        $this->assertStringContainsString('закрытом периоде', (string) $log->warnings);

        $rows = DB::table('transaction')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(self::CONTRACT_B, (int) $rows[0]->contract);
    }

    /**
     * Нет курса валюты на месяц строки — ошибка строки.
     * ⚠ Раньше CurrencyRates молча возвращал 1.0, и доллар приравнивался к
     * рублю: amountRUB занижался примерно в 80 раз по всей цепочке.
     */
    #[Test]
    public function missing_currency_rate_is_a_row_error(): void
    {
        // Убираем все долларовые курсы из фикстуры — воспроизводим дыру в
        // справочнике, ради которой фолбэк «курс 1.0» и был отключён.
        DB::table('currencyRate')->where('currency', self::USD)->delete();
        \App\Support\CurrencyRates::flush();

        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', '100', '15.07.2026'],
        ]);

        $this->runImport(currencyId: self::USD);

        $log = $this->log();
        $this->assertSame('error', $log->status);
        $this->assertStringContainsString('курс', mb_strtolower((string) $log->errors));
        $this->assertSame(0, DB::table('transaction')->count());
    }

    /** Отрицательная сумма — сторно, это законная строка. */
    #[Test]
    public function negative_amount_is_accepted_as_storno(): void
    {
        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', '-5000', '15.07.2026'],
        ]);

        $this->runImport();

        $this->assertSame('success', $this->log()->status);
        $row = DB::table('transaction')->first();
        $this->assertEqualsWithDelta(-5_000.0, (float) $row->amount, 0.01);
    }

    /**
     * ⚠ ФИКСАЦИЯ ЖИВОГО БАГА, а не желаемого поведения.
     *
     * parseCsv приводит шапку через байтовый strtolower(), который кириллицу
     * не трогает. Поэтому «Сумма» и «Дата» — самые естественные заголовки
     * русской выгрузки — не распознаются: колонки теряются молча. Сумма
     * становится 0 (ноль разрешён осознанно, для сделок без движения денег),
     * дата — сегодняшней. Импорт при этом рапортует success.
     *
     * Тест закрепляет текущее поведение, чтобы рефакторинг его не сдвинул
     * незаметно. Когда баг починят (mb_strtolower), тест обязан покраснеть —
     * и его надо будет переписать на ожидаемый результат.
     */
    #[Test]
    public function capitalised_cyrillic_headers_are_silently_ignored(): void
    {
        $this->writeCsv([
            ['Номер контракта', 'Сумма', 'Дата'],
            ['IMP-A', '7500', '15.07.2026'],
        ]);

        $this->runImport();

        $this->assertSame('success', $this->log()->status, 'импорт не жалуется');

        $row = DB::table('transaction')->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, (float) $row->amount, 0.01, 'колонка «Сумма» потеряна');
        $this->assertSame(now()->format('Y-m'), $row->dateMonth, 'колонка «Дата» потеряна → сегодня');
    }

    /** Исходный CSV удаляется после импорта — файлы не копятся в temp. */
    #[Test]
    public function source_csv_is_removed_afterwards(): void
    {
        $this->writeCsv([
            self::HEADERS,
            ['IMP-A', '1000', '15.07.2026'],
        ]);

        $this->runImport();

        $this->assertFileDoesNotExist($this->csvPath);
    }

    // ================================================================

    private function runImport(?int $currencyId = self::RUB): void
    {
        (new ImportTransactionsJob(
            source: 'csv',
            sourceRef: $this->csvPath,
            counterpartyId: null,
            currencyId: $currencyId,
            importLogId: self::IMPORT_LOG,
            userId: 1,
            tracker: 'test-tracker',
        ))->handle();
    }

    private function log(): object
    {
        $row = DB::table('transaction_import_log')->where('id', self::IMPORT_LOG)->first();
        $this->assertNotNull($row);

        return $row;
    }

    /** @param list<list<string>> $rows */
    private function writeCsv(array $rows): void
    {
        $fh = fopen($this->csvPath, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ';');
        }
        fclose($fh);
    }

    private function seedBase(): void
    {
        DB::table('consultant')->insert([
            'id' => self::CONSULTANT,
            'personName' => 'Импорт Тестовый',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);

        DB::table('contract')->insert([
            ['id' => self::CONTRACT_A, 'consultant' => self::CONSULTANT, 'number' => 'IMP-A'],
            ['id' => self::CONTRACT_B, 'consultant' => self::CONSULTANT, 'number' => 'IMP-B'],
        ]);

        DB::table('transaction_import_log')->insert([
            'id' => self::IMPORT_LOG,
            'status' => 'pending',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
