<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use App\Services\Reports\PaymentRegistryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Колонки «Налоговый режим» и «Дата рождения» в выгрузке реестра выплат
 * (запрос от 03.09.2026).
 *
 * Обе добавлены В КОНЕЦ строки: у бухгалтерии есть шаблоны, завязанные на
 * позиции колонок. Тест это и закрепляет — вместе с тем, что дата рождения
 * приводится к одному формату, откуда бы она ни пришла.
 */
class PaymentRegistryReportColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** Партнёр с логином: дата рождения в WebUser, режим — в реквизитах. */
    private const WITH_LOGIN = 1600001;
    /** Партнёр без логина: дата рождения в карточке, формат «d.m.Y». */
    private const NO_LOGIN = 1600002;

    private const WEB_USER = 1600800;
    private const PERIOD_FROM = '2026-06-01';
    private const PERIOD_TO = '2026-06-30 23:59:59';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    /** @return array<string, array<int, mixed>> строки отчёта по ФИО */
    private function rows(): array
    {
        $report = $this->app->make(PaymentRegistryReport::class);
        $out = [];
        foreach ($report->rows(self::PERIOD_FROM, self::PERIOD_TO, []) as $row) {
            $out[$row[0]] = $row;
        }

        return $out;
    }

    /** Новые колонки идут последними и не сдвигают прежние. */
    #[Test]
    public function the_new_columns_are_appended_at_the_end(): void
    {
        $headers = $this->app->make(PaymentRegistryReport::class)->headers();

        $this->assertCount(20, $headers);
        $this->assertSame('Налоговый режим', $headers[18]);
        $this->assertSame('Дата рождения', $headers[19]);
        $this->assertSame('Банк', $headers[17], 'банковский блок остался на месте');
    }

    #[Test]
    public function the_tax_regime_comes_from_the_requisites(): void
    {
        $this->assertSame('УСН', $this->rows()['Логинов Партнёр Сергеевич'][18]);
    }

    /** Реквизитов нет — пустая ячейка, а не «нет данных» и не прочерк. */
    #[Test]
    public function a_partner_without_requisites_gets_an_empty_cell(): void
    {
        $this->assertSame('', $this->rows()['Безлогинов Партнёр Петрович'][18]);
    }

    #[Test]
    public function the_birth_date_comes_from_the_login(): void
    {
        $this->assertSame('1988-03-14', $this->rows()['Логинов Партнёр Сергеевич'][19]);
    }

    /**
     * У партнёра без логина дата лежит в собственной колонке карточки, и там
     * varchar с «d.m.Y» — в выгрузке всё равно единый Y-m-d, иначе в одной
     * колонке Excel окажутся два формата.
     */
    #[Test]
    public function the_birth_date_falls_back_to_the_partner_card(): void
    {
        $this->assertSame('1980-02-18', $this->rows()['Безлогинов Партнёр Петрович'][19]);
    }

    // ================================================================

    private function seedFixture(): void
    {
        $u = new User();
        $u->id = self::WEB_USER;
        $u->email = 'registry@test.local';
        $u->role = 'consultant';
        $u->lastName = 'Логинов';
        $u->firstName = 'Партнёр';
        $u->patronymic = 'Сергеевич';
        $u->birthDate = '1988-03-14 00:00:00';
        $u->password = bcrypt('secret123');
        $u->save();

        DB::table('consultant')->insert([
            'id' => self::WITH_LOGIN,
            'webUser' => self::WEB_USER,
            'personName' => 'Логинов Партнёр Сергеевич',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
            'birthDate' => null,
        ]);
        DB::table('consultant')->insert([
            'id' => self::NO_LOGIN,
            'webUser' => null,
            'personName' => 'Безлогинов Партнёр Петрович',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
            // Наследство Directual: строка вместо даты.
            'birthDate' => '18.02.1980',
        ]);

        // Реквизиты только у первого — у второго колонка режима должна
        // остаться пустой.
        DB::table('requisites')->insert([
            'id' => 1600100,
            'consultant' => self::WITH_LOGIN,
            'individualEntrepreneur' => 'ИП Логинов',
            'inn' => '770000000012',
            'ogrn' => '312770000000123',
            'address' => 'г. Москва',
            'verified' => true,
            'tax_regime' => 'УСН',
        ]);

        // Без начислений строка в отчёт не попадает — даём обоим «прочее».
        DB::table('other_accruals')->insert([
            ['id' => 1600200, 'consultant' => self::WITH_LOGIN, 'type' => 'bonus',
                'amount' => 1000, 'points' => 0, 'accrual_date' => '2026-06-10 00:00:00'],
            ['id' => 1600201, 'consultant' => self::NO_LOGIN, 'type' => 'bonus',
                'amount' => 500, 'points' => 0, 'accrual_date' => '2026-06-11 00:00:00'],
        ]);
    }
}
