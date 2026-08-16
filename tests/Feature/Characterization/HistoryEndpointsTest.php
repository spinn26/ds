<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Два окна истории: контракта (/admin/contracts/{id}/history) и партнёра
 * (/admin/partners/{id}/change-log).
 *
 * Сетка ПОД вынос обоих методов в сервисы. Методы похожи ровно настолько,
 * чтобы при переносе захотелось «объединить» — а объединять нельзя: у них
 * разные ключи изменений (old/new против from/to), разное сравнение
 * «поменялось ли значение» (строгое против строкового) и разный набор полей,
 * по которым идёт обход (только новые против объединения старых и новых).
 * Каждое из трёх расхождений здесь зафиксировано отдельным тестом.
 */
class HistoryEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = 1600001;
    private const PARTNER = 1600002;
    private const OTHER_PARTNER = 1600003;
    private const CLIENT = 1600004;
    private const AUTHOR = 1600900;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCommon();
    }

    // ======================= История контракта =======================

    /** FK разворачиваются в имена одним батчем, даты — к Y-m-d. */
    #[Test]
    public function contract_history_humanizes_foreign_keys_and_dates(): void
    {
        $this->contractLog(['status' => 2, 'consultant' => self::OTHER_PARTNER, 'openDate' => '2026-02-01 10:30:00'],
            ['status' => 1, 'consultant' => self::PARTNER, 'openDate' => '2026-01-01 00:00:00']);

        $changes = collect($this->contractHistory()[0]['changes'])->keyBy('field');

        $this->assertSame('Активирован', $changes['status']['old']);
        $this->assertSame('Статус', $changes['status']['fieldLabel']);
        $this->assertSame('Первый Партнёр', $changes['consultant']['old']);
        $this->assertSame('Второй Партнёр', $changes['consultant']['new']);
        // Дата приводится к Y-m-d — время в UI не показывается.
        $this->assertSame('2026-01-01', $changes['openDate']['old']);
        $this->assertSame('2026-02-01', $changes['openDate']['new']);
    }

    /** Значение не поменялось — строки в истории нет. */
    #[Test]
    public function contract_history_skips_unchanged_fields(): void
    {
        $this->contractLog(['number' => 'CT-1', 'comment' => 'новый'], ['number' => 'CT-1', 'comment' => 'старый']);

        $fields = array_column($this->contractHistory()[0]['changes'], 'field');

        $this->assertSame(['comment'], $fields, 'number не менялся и попасть в историю не должен');
    }

    /**
     * ⚠ Обход идёт ТОЛЬКО по новым атрибутам: поле, которое есть в old, но
     * которого нет в attributes, в историю не попадает. У партнёра — наоборот
     * (см. partner_change_log_walks_union_of_old_and_new).
     */
    #[Test]
    public function contract_history_walks_new_attributes_only(): void
    {
        $this->contractLog(['comment' => 'новый'], ['comment' => 'старый', 'number' => 'CT-СТАРЫЙ']);

        $fields = array_column($this->contractHistory()[0]['changes'], 'field');

        $this->assertSame(['comment'], $fields);
    }

    /**
     * ⚠ Сравнение строгое: 1 и "1" считаются разными значениями и дают строку
     * в истории. У партнёра сравнение строковое, и такая пара схлопнулась бы
     * (см. partner_change_log_compares_values_as_strings).
     */
    #[Test]
    public function contract_history_compares_values_strictly(): void
    {
        $this->contractLog(['status' => '1'], ['status' => 1]);

        $fields = array_column($this->contractHistory()[0]['changes'], 'field');

        $this->assertSame(['status'], $fields, '1 и "1" при строгом сравнении — изменение');
    }

    /**
     * Смена партнёра пишется мимо Eloquent — в changeConsultantContractLog, и
     * вливается в то же окно, с префиксом id и сортировкой по общей дате.
     */
    #[Test]
    public function contract_history_merges_partner_transfers(): void
    {
        $this->contractLog(['comment' => 'новый'], ['comment' => 'старый'], '2026-03-01 00:00:00');
        DB::table('changeConsultantContractLog')->insert([
            'id' => 1600100, 'contract' => self::CONTRACT, 'webUser' => null,
            'dateCreated' => '2026-04-01 00:00:00',
            'consultantOld' => self::PARTNER, 'consultantNew' => self::OTHER_PARTNER,
            'consultantOldName' => 'Первый Партнёр', 'consultantNewName' => 'Второй Партнёр',
        ]);

        $rows = $this->contractHistory();

        // Перестановка свежее — идёт первой.
        $this->assertSame('transfer-1600100', $rows[0]['id']);
        $this->assertSame('reassign', $rows[0]['event']);
        $this->assertSame('Смена партнёра', $rows[0]['description']);
        $this->assertSame('Первый Партнёр', $rows[0]['changes'][0]['old']);
        $this->assertSame('Второй Партнёр', $rows[0]['changes'][0]['new']);
        $this->assertCount(2, $rows);
    }

    /** Автор без causer — «Система»; с causer — ФИО из WebUser. */
    #[Test]
    public function contract_history_resolves_the_author(): void
    {
        $this->contractLog(['comment' => 'a'], ['comment' => 'b'], '2026-03-02 00:00:00', self::AUTHOR);
        $this->contractLog(['comment' => 'c'], ['comment' => 'd'], '2026-03-01 00:00:00', null);

        $rows = $this->contractHistory();

        $this->assertSame('Историев Автор Автович', $rows[0]['author']);
        $this->assertSame('Система', $rows[1]['author']);
    }

    // ======================= История партнёра =======================

    /** Оба журнала сливаются в одну ленту, с разными префиксами id. */
    #[Test]
    public function partner_change_log_merges_both_journals(): void
    {
        $this->partnerLog(['phone' => '+79990000001'], ['phone' => '+79990000002'], '2026-03-01 00:00:00');
        $this->auditLog('partner_update', ['diff' => ['email' => ['from' => 'a@x.ru', 'to' => 'b@x.ru']]], '2026-04-01 00:00:00');

        $rows = $this->partnerChangeLog();

        $this->assertSame(['u1600200', 'a1600300'], array_column($rows, 'id'));
        $this->assertSame(['audit', 'activity'], array_column($rows, 'source'));
    }

    /**
     * ⚠ Обход идёт по ОБЪЕДИНЕНИЮ старых и новых ключей: поле, исчезнувшее из
     * attributes, всё равно показывается как «стало пусто». В истории
     * контракта — наоборот (см. contract_history_walks_new_attributes_only).
     */
    #[Test]
    public function partner_change_log_walks_union_of_old_and_new(): void
    {
        $this->partnerLog(['phone' => '+79990000001'], ['phone' => '+79990000001', 'nicTG' => '@old']);

        $changes = collect($this->partnerChangeLog()[0]['changes'])->keyBy('field');

        $this->assertArrayHasKey('nicTG', $changes, 'поле было только в old — но показать его надо');
        $this->assertSame('@old', $changes['nicTG']['from']);
        $this->assertNull($changes['nicTG']['to']);
        $this->assertArrayNotHasKey('phone', $changes, 'phone не менялся');
    }

    /**
     * ⚠ Сравнение строковое: 1 и "1" считаются одним и тем же значением.
     * В истории контракта сравнение строгое, и такая пара показалась бы
     * изменением.
     */
    #[Test]
    public function partner_change_log_compares_values_as_strings(): void
    {
        $this->partnerLog(['activity' => 1, 'terminationCount' => 2], ['activity' => '1', 'terminationCount' => 1]);

        $fields = array_column($this->partnerChangeLog()[0]['changes'], 'field');

        $this->assertSame(['terminationCount'], $fields, '1 и "1" — не изменение');
    }

    /** Статус активности и булевы значения показываются по-русски. */
    #[Test]
    public function partner_change_log_renders_activity_and_booleans(): void
    {
        $this->partnerLog(['activity' => 3, 'reinstate_blocked' => true],
            ['activity' => 1, 'reinstate_blocked' => false]);

        $changes = collect($this->partnerChangeLog()[0]['changes'])->keyBy('field');

        $this->assertSame('Активен', $changes['activity']['from']);
        $this->assertSame('Терминирован', $changes['activity']['to']);
        $this->assertSame('Статус активности', $changes['activity']['fieldLabel']);
        // Проверка на пустоту строгая, поэтому false до bool-ветки доходит и
        // показывается как «нет» (а не проваливается в пустое значение).
        $this->assertSame('нет', $changes['reinstate_blocked']['from']);
        $this->assertSame('да', $changes['reinstate_blocked']['to']);
    }

    /**
     * Пустые записи отсекаются: activity без изменений и без комментария, и
     * старые partner_update без diff — в ленте им делать нечего.
     */
    #[Test]
    public function partner_change_log_drops_empty_entries(): void
    {
        $this->partnerLog(['phone' => '+79990000001'], ['phone' => '+79990000001']);
        $this->auditLog('partner_update', ['diff' => []]);

        $this->assertSame([], $this->partnerChangeLog());
    }

    /** Запись без изменений, но с комментарием — остаётся. */
    #[Test]
    public function partner_change_log_keeps_comment_only_entries(): void
    {
        $this->partnerLog(['phone' => '+7999'], ['phone' => '+7999'], '2026-03-01 00:00:00', null, 'Ручная правка');

        $rows = $this->partnerChangeLog();

        $this->assertCount(1, $rows);
        $this->assertSame('Ручная правка', $rows[0]['comment']);
        $this->assertSame([], $rows[0]['changes']);
    }

    /** Автор удалён из WebUser — показываем номер, а не «Система». */
    #[Test]
    public function partner_change_log_falls_back_to_the_user_number(): void
    {
        $this->partnerLog(['phone' => '+7999'], ['phone' => '+7000'], '2026-03-02 00:00:00', 1600999);
        $this->partnerLog(['phone' => '+7999'], ['phone' => '+7111'], '2026-03-01 00:00:00', null);

        $rows = $this->partnerChangeLog();

        $this->assertSame('Пользователь #1600999', $rows[0]['author']);
        $this->assertSame('Система', $rows[1]['author']);
    }

    /** Автор есть, но ФИО у него пустое — тоже показываем номер. */
    #[Test]
    public function partner_change_log_falls_back_when_the_name_is_empty(): void
    {
        $nameless = new User();
        $nameless->id = 1600910;
        $nameless->email = 'nameless@test.local';
        $nameless->firstName = '';
        $nameless->lastName = '';
        $nameless->role = 'admin';
        $nameless->password = bcrypt('secret123');
        $nameless->save();

        $this->partnerLog(['phone' => '+7999'], ['phone' => '+7000'], '2026-03-01 00:00:00', 1600910);

        $this->assertSame('Пользователь #1600910', $this->partnerChangeLog()[0]['author']);
    }

    // ================================================================

    /** @return list<array<string, mixed>> */
    private function contractHistory(): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/contracts/' . self::CONTRACT . '/history')
            ->assertOk()->json('data');
    }

    /** @return list<array<string, mixed>> */
    private function partnerChangeLog(): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/partners/' . self::PARTNER . '/change-log')
            ->assertOk()->json('data');
    }

    private int $activityId = 1600300;

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     */
    private function contractLog(array $new, array $old, string $at = '2026-03-01 00:00:00', ?int $causer = null): void
    {
        $this->activityRow(\App\Models\Contract::class, self::CONTRACT, $new, $old, $at, $causer);
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     */
    private function partnerLog(array $new, array $old, string $at = '2026-03-01 00:00:00', ?int $causer = null, ?string $comment = null): void
    {
        $this->activityRow(\App\Models\Consultant::class, self::PARTNER, $new, $old, $at, $causer, $comment);
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     */
    private function activityRow(string $subject, int $subjectId, array $new, array $old, string $at, ?int $causer, ?string $comment = null): void
    {
        $props = ['attributes' => $new, 'old' => $old];
        if ($comment !== null) {
            $props['comment'] = $comment;
        }

        DB::table('activity_log')->insert([
            'id' => $this->activityId++,
            'log_name' => 'default',
            'description' => 'updated',
            'event' => 'updated',
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'causer_type' => $causer ? User::class : null,
            'causer_id' => $causer,
            'properties' => json_encode($props, JSON_UNESCAPED_UNICODE),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private int $auditId = 1600200;

    /** @param array<string, mixed> $payload */
    private function auditLog(string $action, array $payload, string $at = '2026-03-01 00:00:00'): void
    {
        DB::table('audit_log')->insert([
            'id' => $this->auditId++,
            'user_id' => null,
            'user_email' => null,
            'user_role' => null,
            'action' => $action,
            'entity' => 'consultant',
            'entity_id' => (string) self::PARTNER,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => $at,
        ]);
    }

    private function seedCommon(): void
    {
        $this->admin = new User();
        $this->admin->id = 1600901;
        $this->admin->email = 'history@test.local';
        $this->admin->firstName = 'История';
        $this->admin->lastName = 'Тестовая';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        $author = new User();
        $author->id = self::AUTHOR;
        $author->email = 'author@test.local';
        $author->firstName = 'Автор';
        $author->lastName = 'Историев';
        $author->patronymic = 'Автович';
        $author->role = 'admin';
        $author->password = bcrypt('secret123');
        $author->save();

        DB::table('consultant')->insert([
            ['id' => self::PARTNER, 'personName' => 'Первый Партнёр',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
            ['id' => self::OTHER_PARTNER, 'personName' => 'Второй Партнёр',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
        ]);
        DB::table('client')->insert([
            'id' => self::CLIENT, 'consultant' => self::PARTNER, 'personName' => 'Клиент Первый',
        ]);
        DB::table('contract')->insert([
            'id' => self::CONTRACT, 'consultant' => self::PARTNER, 'client' => self::CLIENT,
            'number' => 'CT-1', 'status' => 1, 'ammount' => 100_000,
            'createDate' => '2026-01-01 00:00:00',
        ]);
    }
}
