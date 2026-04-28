<?php

namespace Tests\Unit;

use App\Services\ReportGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke-тесты для ReportGenerator: валидация структуры заголовков
 * для каждого из 7 типов отчётов (per spec ✅Отчеты §3).
 */
class ReportGeneratorTest extends TestCase
{
    #[Test]
    public function service_resolves_from_container(): void
    {
        $gen = $this->app->make(ReportGenerator::class);
        $this->assertInstanceOf(ReportGenerator::class, $gen);
    }

    #[Test]
    public function each_report_type_has_correct_headers(): void
    {
        $gen = $this->app->make(ReportGenerator::class);

        $expected = [
            'revenue_expenses' => 3,             // Продукт, Доход, Расход
            'partner_status' => 4,                // ФИО, Статус, фактическая, плановая
            'payment_registry' => 18,             // ФИО+Активность+8 баланс+5 реквизиты+4 банк
            'qualifications' => 10,               // База(2) + 4 prev + 4 cur
            'commissions' => 15,                  // Сделка(7) + Эконом ДС(2) + Эконом партнёра(4) + Аналитика(2)
            'finrez_commissions' => 18,
            'finrez_transactions' => 18,
        ];

        foreach ($expected as $type => $count) {
            $headers = $gen->headersFor($type);
            $this->assertCount($count, $headers, "Type {$type} should have {$count} headers");
        }
    }

    #[Test]
    public function unknown_type_returns_empty_headers(): void
    {
        $gen = $this->app->make(ReportGenerator::class);
        $this->assertSame([], $gen->headersFor('unknown_type'));
    }

    #[Test]
    public function csv_output_starts_with_utf8_bom(): void
    {
        $gen = $this->app->make(ReportGenerator::class);
        $reflection = new \ReflectionClass($gen);
        $method = $reflection->getMethod('toCsv');
        $method->setAccessible(true);

        $csv = $method->invoke($gen, ['Foo', 'Bar'], [['a', 'b']]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'CSV должен начинаться с UTF-8 BOM для Excel');
    }
}
