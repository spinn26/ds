<?php

namespace Tests\Feature\Characterization;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use App\Services\PartnerStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ХАРАКТЕРИЗУЮЩИЙ тест статусов партнёра (Этап 0).
 *
 * Терминация — операция с побочными эффектами на деньги: контракты и клиенты
 * уезжают на ближайшего активного вышестоящего, а каскад комиссий
 * терминированным не платит. Поэтому фиксируем не только смену статуса, но и
 * перенос портфеля с записями в журналы.
 *
 * Пороги берём из system_settings (они в schema-фикстуре, значения прода):
 * min_lp 500, window_days 120, max_terminations 4, self_reinstate_limit 3.
 *
 * Структура: ROOT → MENTOR → PARTNER, у партнёра контракт и клиент.
 */
class PartnerStatusCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 940001;
    private const MENTOR = 940002;
    private const PARTNER = 940003;

    private const CONTRACT = 940010;
    private const CLIENT = 940020;

    /** Плейсхолдер «Неизвестный консультант» — фолбэк, когда аплайна нет. */
    private const UNKNOWN = 536;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStructure();
    }

    // ================================================================
    // Регистрация и активация
    // ================================================================

    #[Test]
    public function register_sets_deadline_from_the_configured_window(): void
    {
        $c = $this->consultant(self::PARTNER);
        $c->activity = PartnerActivity::Active;   // заведомо иной статус
        $c->save();

        app(PartnerStatusService::class)->register($c);

        $fresh = $this->consultant(self::PARTNER);
        $this->assertSame(PartnerActivity::Registered, $fresh->activity);
        $this->assertSame(
            now()->addDays(PartnerActivity::activationDays())->toDateString(),
            $fresh->activationDeadline->toDateString(),
            'дедлайн = сегодня + окно активации из настроек'
        );
        $this->assertSame(0, (int) $fresh->terminationCount);
    }

    /** Активация только из «Зарегистрирован» и только при ЛП >= порога. */
    #[Test]
    public function activation_requires_registered_status_and_the_lp_threshold(): void
    {
        $service = app(PartnerStatusService::class);
        $threshold = PartnerActivity::activationPoints();

        $c = $this->consultant(self::PARTNER);
        $c->activity = PartnerActivity::Registered;
        $c->personalVolume = $threshold - 1;
        $c->save();

        $this->assertFalse($service->activate($c), 'ЛП ниже порога — не активируем');
        $this->assertSame(PartnerActivity::Registered, $this->consultant(self::PARTNER)->activity);

        $c->personalVolume = $threshold;
        $c->save();

        $this->assertTrue($service->activate($c), 'ровно порог — активируем');

        $fresh = $this->consultant(self::PARTNER);
        $this->assertSame(PartnerActivity::Active, $fresh->activity);
        $this->assertTrue((bool) $fresh->active);
        $this->assertNotNull($fresh->dateActivity);
        $this->assertNotNull($fresh->yearPeriodEnd);
        $this->assertNotEmpty($fresh->participantCode, 'реф-код выдаётся при активации');
    }

    /** Повторная активация уже активного — no-op. */
    #[Test]
    public function activating_an_active_partner_does_nothing(): void
    {
        $c = $this->consultant(self::PARTNER);
        $c->activity = PartnerActivity::Active;
        $c->personalVolume = 10_000;
        $c->save();

        $this->assertFalse(app(PartnerStatusService::class)->activate($c));
    }

    /** Форс-активация игнорирует и статус, и порог, и чистит следы терминации. */
    #[Test]
    public function force_activation_ignores_status_and_threshold(): void
    {
        $c = $this->consultant(self::PARTNER);
        $c->activity = PartnerActivity::Terminated;
        $c->personalVolume = 0;
        $c->dateDeactivity = now()->subDay();
        $c->save();

        $this->assertTrue(app(PartnerStatusService::class)->forceActivate($c, 'по решению владельца'));

        $fresh = $this->consultant(self::PARTNER);
        $this->assertSame(PartnerActivity::Active, $fresh->activity);
        $this->assertNull($fresh->dateDeactivity, 'маркер терминации снят — иначе партнёр остаётся в выборках терминаций');
        $this->assertNotNull($fresh->dateDeterministic);

        $log = $this->lastStatusLog(self::PARTNER);
        $this->assertSame('manual', $log->source);
        $this->assertStringContainsString('по решению владельца', (string) $log->comment);
    }

    // ================================================================
    // Терминация и перенос портфеля
    // ================================================================

    #[Test]
    public function termination_moves_portfolio_to_the_nearest_active_upline(): void
    {
        $service = app(PartnerStatusService::class);
        $c = $this->consultant(self::PARTNER);

        $status = $service->terminate($c, 'тест');

        $this->assertSame(PartnerActivity::Terminated, $status);

        $fresh = $this->consultant(self::PARTNER);
        $this->assertSame(PartnerActivity::Terminated, $fresh->activity);
        $this->assertFalse((bool) $fresh->active);
        $this->assertSame(1, (int) $fresh->terminationCount, 'счётчик терминаций растёт');
        $this->assertNotNull($fresh->dateDeactivity);

        // Портфель уехал наверх — иначе контракт висит на терминированном,
        // а каскад комиссий ему не платит: доля просто остаётся компании.
        $this->assertSame(
            self::MENTOR,
            (int) DB::table('contract')->where('id', self::CONTRACT)->value('consultant')
        );
        $this->assertSame(
            self::MENTOR,
            (int) DB::table('client')->where('id', self::CLIENT)->value('consultant')
        );

        // Денормализованное имя переезжает вместе со связью.
        $this->assertSame(
            'Наставник',
            DB::table('contract')->where('id', self::CONTRACT)->value('consultantName')
        );

        $this->assertSame(1, DB::table('changeConsultantContractLog')->count(), 'перенос записан в журнал');
        $this->assertSame(1, DB::table('changeConsultantClientLog')->count());
    }

    /** Терминированный наставник пропускается — портфель идёт выше. */
    #[Test]
    public function terminated_upline_is_skipped_when_choosing_the_target(): void
    {
        DB::table('consultant')->where('id', self::MENTOR)
            ->update(['activity' => PartnerActivity::Terminated->value]);

        app(PartnerStatusService::class)->terminate($this->consultant(self::PARTNER), 'тест');

        $this->assertSame(
            self::ROOT,
            (int) DB::table('contract')->where('id', self::CONTRACT)->value('consultant'),
            'через терминированного наставника перепрыгиваем к корню'
        );
    }

    /** Активного вышестоящего нет — портфель уходит «Неизвестному консультанту». */
    #[Test]
    public function portfolio_falls_back_to_the_unknown_consultant(): void
    {
        DB::table('consultant')->insert([
            'id' => self::UNKNOWN,
            'personName' => 'Неизвестный консультант',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('consultant')->whereIn('id', [self::MENTOR, self::ROOT])
            ->update(['activity' => PartnerActivity::Terminated->value]);

        app(PartnerStatusService::class)->terminate($this->consultant(self::PARTNER), 'тест');

        $this->assertSame(
            self::UNKNOWN,
            (int) DB::table('contract')->where('id', self::CONTRACT)->value('consultant')
        );
        $this->assertStringContainsString(
            'Неизвестный',
            (string) DB::table('changeConsultantContractLog')->value('triggeredBy')
        );
    }

    /**
     * Исключение наступает, когда возвращаться уже нечем: попытки
     * самовосстановления исчерпаны. Именно попытки, а не «терминаций стало N» —
     * иначе последняя попытка была бы недостижима.
     */
    #[Test]
    public function termination_excludes_when_no_reinstatements_left(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update([
            'reinstatement_count' => PartnerActivity::selfReinstateLimit(),
        ]);

        $status = app(PartnerStatusService::class)
            ->terminate($this->consultant(self::PARTNER), 'тест');

        $this->assertSame(PartnerActivity::Excluded, $status);
        $this->assertSame(PartnerActivity::Excluded, $this->consultant(self::PARTNER)->activity);
        $this->assertStringContainsString('восстановления исчерпаны', (string) $this->lastStatusLog(self::PARTNER)->comment);
    }

    /** Терминировать можно только «Зарегистрирован» и «Активен». */
    #[Test]
    public function excluded_partner_is_not_terminated_again(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)
            ->update(['activity' => PartnerActivity::Excluded->value]);

        $status = app(PartnerStatusService::class)
            ->terminate($this->consultant(self::PARTNER), 'тест');

        $this->assertSame(PartnerActivity::Excluded, $status);
        $this->assertSame(0, DB::table('changeConsultantContractLog')->count(), 'портфель не трогаем');
    }

    // ================================================================
    // Откат ошибочной терминации
    // ================================================================

    #[Test]
    public function restore_returns_status_and_portfolio(): void
    {
        $service = app(PartnerStatusService::class);
        $service->terminate($this->consultant(self::PARTNER), 'ошибка оператора');

        $result = $service->restoreFromTermination($this->consultant(self::PARTNER), 'разобрались');

        $this->assertSame(1, $result['contracts']);
        $this->assertSame(1, $result['clients']);
        $this->assertSame([], $result['skipped']);

        $fresh = $this->consultant(self::PARTNER);
        $this->assertSame(PartnerActivity::Active, $fresh->activity, 'статус вернулся к тому, что был ДО терминации');
        $this->assertNull($fresh->dateDeactivity);
        $this->assertSame(0, (int) $fresh->terminationCount, 'ошибочная терминация не приближает к исключению');

        $this->assertSame(
            self::PARTNER,
            (int) DB::table('contract')->where('id', self::CONTRACT)->value('consultant')
        );
        $this->assertSame(
            self::PARTNER,
            (int) DB::table('client')->where('id', self::CLIENT)->value('consultant')
        );
    }

    /** Уведённое ПОСЛЕ терминации дальше — не возвращаем вслепую. */
    #[Test]
    public function restore_skips_entities_moved_elsewhere_afterwards(): void
    {
        $service = app(PartnerStatusService::class);
        $service->terminate($this->consultant(self::PARTNER), 'ошибка');

        // Кто-то перевёл контракт ещё дальше — к корню.
        DB::table('contract')->where('id', self::CONTRACT)->update(['consultant' => self::ROOT]);

        $result = $service->restoreFromTermination($this->consultant(self::PARTNER));

        $this->assertSame(0, $result['contracts'], 'контракт не возвращён');
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('сейчас у #' . self::ROOT, $result['skipped'][0]);
        $this->assertSame(1, $result['clients'], 'клиент вернулся — его никто не двигал');
    }

    /** Откат применим только к терминированным. */
    #[Test]
    public function restore_refuses_an_active_partner(): void
    {
        $result = app(PartnerStatusService::class)
            ->restoreFromTermination($this->consultant(self::PARTNER));

        $this->assertSame(0, $result['contracts']);
        $this->assertStringContainsString('не терминирован', $result['status']);
    }

    // ================================================================
    // Самовосстановление
    // ================================================================

    #[Test]
    public function self_reinstate_resets_points_and_opens_a_new_window(): void
    {
        $service = app(PartnerStatusService::class);
        $service->terminate($this->consultant(self::PARTNER), 'просрочка');

        $result = $service->selfReinstate($this->consultant(self::PARTNER));

        $this->assertTrue($result['ok']);
        $this->assertSame(PartnerActivity::selfReinstateLimit() - 1, $result['attemptsLeft']);

        $fresh = $this->consultant(self::PARTNER);
        $this->assertSame(PartnerActivity::Registered, $fresh->activity);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->personalVolume, 0.001, 'баллы обнуляются');
        $this->assertEqualsWithDelta(0.0, (float) $fresh->groupVolume, 0.001);
        $this->assertNull($fresh->yearPeriodEnd);
        $this->assertNull($fresh->dateDeactivity);
        $this->assertSame(1, (int) $fresh->reinstatement_count);
        $this->assertFalse((bool) $fresh->acceptance, 'акцепт сбрасывается — документы принимаются заново');
        $this->assertTrue((bool) $fresh->reinstate_mentor_pending, 'шаг выбора наставника обязателен');

        // Портфель НЕ возвращается: иначе можно было бы двигать деньги,
        // уходя в терминацию и возвращаясь.
        $this->assertSame(
            self::MENTOR,
            (int) DB::table('contract')->where('id', self::CONTRACT)->value('consultant')
        );
    }

    /** Лимит попыток исчерпан — отказ с причиной. */
    #[Test]
    public function self_reinstate_is_refused_when_the_limit_is_spent(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update([
            'activity' => PartnerActivity::Terminated->value,
            'reinstatement_count' => PartnerActivity::selfReinstateLimit(),
        ]);

        $result = app(PartnerStatusService::class)
            ->selfReinstate($this->consultant(self::PARTNER));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['attemptsLeft']);
        $this->assertSame(PartnerActivity::Terminated, $this->consultant(self::PARTNER)->activity);
    }

    /** Из «Исключён» сам не возвращается. */
    #[Test]
    public function excluded_partner_cannot_self_reinstate(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)
            ->update(['activity' => PartnerActivity::Excluded->value]);

        $result = app(PartnerStatusService::class)
            ->selfReinstate($this->consultant(self::PARTNER));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Исключён', $result['message']);
    }

    // ================================================================
    // Кроновые проверки
    // ================================================================

    /** Просроченное окно активации при недоборе ЛП → терминация. */
    #[Test]
    public function expired_registration_is_terminated(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update([
            'activity' => PartnerActivity::Registered->value,
            'activationDeadline' => now()->subDay(),
            'personalVolume' => PartnerActivity::activationPoints() - 1,
        ]);

        $count = app(PartnerStatusService::class)->checkExpiredRegistrations();

        $this->assertSame(1, $count);
        $this->assertSame(PartnerActivity::Terminated, $this->consultant(self::PARTNER)->activity);
    }

    /** Порог набран — просрочка не терминирует. */
    #[Test]
    public function expired_registration_with_enough_points_survives(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update([
            'activity' => PartnerActivity::Registered->value,
            'activationDeadline' => now()->subDay(),
            'personalVolume' => PartnerActivity::activationPoints(),
        ]);

        $this->assertSame(0, app(PartnerStatusService::class)->checkExpiredRegistrations());
        $this->assertSame(PartnerActivity::Registered, $this->consultant(self::PARTNER)->activity);
    }

    /**
     * Продление годового периода ОБНУЛЯЕТ ЛП.
     *
     * Без обнуления партнёр без единой сделки продлевался бы вечно: поле
     * пересчитывается только после расчёта комиссии, а её у него нет.
     */
    #[Test]
    public function renewing_the_year_period_resets_personal_volume(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update([
            'activity' => PartnerActivity::Active->value,
            'yearPeriodEnd' => now()->subDay(),
            'personalVolume' => PartnerActivity::activationPoints() + 10,
        ]);

        $this->assertSame(0, app(PartnerStatusService::class)->checkExpiredActivePeriods(),
            'порог набран — не терминируем');

        $fresh = $this->consultant(self::PARTNER);
        $this->assertSame(PartnerActivity::Active, $fresh->activity);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->personalVolume, 0.001, 'ЛП периода обнулён');
        $this->assertTrue($fresh->yearPeriodEnd->isFuture(), 'период продлён');
    }

    // ================================================================

    private function consultant(int $id): Consultant
    {
        $c = Consultant::find($id);
        $this->assertNotNull($c);

        return $c;
    }

    private function lastStatusLog(int $consultantId): object
    {
        $row = DB::table('chageConsultanStatusLog')
            ->where('consultant', $consultantId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row, 'смена статуса не залогирована');

        return $row;
    }

    private function seedStructure(): void
    {
        foreach ([
            [self::ROOT, null, 'Корень'],
            [self::MENTOR, self::ROOT, 'Наставник'],
            [self::PARTNER, self::MENTOR, 'Партнёр'],
        ] as [$id, $inviter, $name]) {
            DB::table('consultant')->insert([
                'id' => $id,
                'inviter' => $inviter,
                'personName' => $name,
                'activity' => PartnerActivity::Active->value,
                'active' => true,
                'terminationCount' => 0,
                'reinstatement_count' => 0,
                // Активный партнёр документы уже принял: без этого сброс
                // акцепта при самовосстановлении неотличим от его отсутствия.
                'acceptance' => true,
                'dateCreated' => '2026-01-01 00:00:00',
            ]);
        }

        DB::table('contract')->insert([
            'id' => self::CONTRACT,
            'consultant' => self::PARTNER,
            'consultantName' => 'Партнёр',
            'number' => 'PS-0001',
        ]);

        DB::table('client')->insert([
            'id' => self::CLIENT,
            'consultant' => self::PARTNER,
            'consultantName' => 'Партнёр',
            'personName' => 'Клиент Тестовый',
        ]);
    }
}
