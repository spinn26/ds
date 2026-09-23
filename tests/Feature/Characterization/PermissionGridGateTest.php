<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сетка «Группы и права» управляет доступом на самом деле.
 *
 * Раньше денежные и системные действия были закрыты ЖЁСТКИМИ списками ролей
 * (`role:admin,calculations` и подобными), а раздел в /admin/permissions на
 * них не влиял вовсе: что бы ни стояло в колонке, доступ не менялся. Сетка
 * выглядела рабочей, но была декоративной, и расходилась с фактическим
 * доступом в обе стороны.
 *
 * Теперь маршруты идут через `permission:<раздел>,full`. Этот тест держит
 * именно это свойство: доступ определяется УРОВНЕМ группы в разделе, а не
 * именем роли. Проверяем на представителях каждого раздела — по одному
 * маршруту, потому что механизм для всех один.
 */
class PermissionGridGateTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 3500100;

    /**
     * Раздел → маршрут, который им закрыт.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function guardedRoutes(): array
    {
        return [
            'транзакции' => ['transactions', 'POST', '/api/v1/admin/transactions/1/calculate'],
            'импорт' => ['import', 'POST', '/api/v1/admin/transaction-import/1/rollback'],
            'валюты' => ['currencies', 'POST', '/api/v1/admin/currencies/vat'],
            'прочие начисления' => ['charges', 'POST', '/api/v1/admin/charges'],
            'пул' => ['pool', 'POST', '/api/v1/admin/pool/preview'],
            'реестр выплат' => ['payments', 'POST', '/api/v1/admin/payment-registry/recalc'],
            'комиссии' => ['commissions', 'POST', '/api/v1/admin/finalize/preview'],
            'доступность отчётов' => ['reports-access', 'POST', '/api/v1/admin/periods/close'],
        ];
    }

    /**
     * ⚠ Уровень «Просмотр» на разделе НЕ даёт выполнять действие, даже если
     * роль исторически это умела.
     */
    #[Test]
    #[DataProvider('guardedRoutes')]
    public function a_view_level_is_refused(string $section, string $method, string $url): void
    {
        $this->setLevel('backoffice', $section, 'view');

        $this->call($method, $url, [], [], [], $this->headers($this->user('backoffice')))
            ->assertForbidden();
    }

    /**
     * ⚠ И «Правка» тоже: денежные и системные действия требуют полного
     * уровня. Это не придирка — на этих маршрутах закрытие периода, пул и
     * реестр выплат.
     */
    #[Test]
    #[DataProvider('guardedRoutes')]
    public function an_edit_level_is_refused(string $section, string $method, string $url): void
    {
        $this->setLevel('backoffice', $section, 'edit');

        $this->call($method, $url, [], [], [], $this->headers($this->user('backoffice')))
            ->assertForbidden();
    }

    /**
     * Полный уровень пропускает гейт. Дальше запрос может упасть на
     * валидации или ненайденной записи — это уже не про права, поэтому
     * проверяем только, что ответ НЕ 403.
     *
     * ⚠ Подопытная роль здесь backoffice, а не support: у support (как и у
     * head, invest, education, corrections) поверх сетки висит СПЛОШНОЙ
     * запрет на запись вне своего домена, и сетка его не перебивает.
     */
    #[Test]
    #[DataProvider('guardedRoutes')]
    public function a_full_level_passes_the_gate(string $section, string $method, string $url): void
    {
        $this->setLevel('backoffice', $section, 'full');

        $status = $this->call($method, $url, [], [], [], $this->headers($this->user('backoffice')))
            ->status();

        $this->assertNotSame(403, $status, 'полный уровень не должен упираться в права');
    }

    // ---------------- Роли под сплошным read-only гардом ----------------

    /**
     * ⚠ Руководитель (head) — read-only по всей админке, КРОМЕ секций, которые
     * гард осознанно отдал матрице. «Отчёты» — одна из них: уровень в колонке
     * решает, может ли руководитель сгенерировать отчёт.
     *
     * До 22.09.2026 матрица показывала «Полный», кнопка в UI была, а сервер
     * отвечал 403 — матрица обещала одно, платформа делала другое.
     */
    #[Test]
    public function the_head_generates_a_report_when_the_grid_allows_it(): void
    {
        $this->setLevel('head', 'reports', 'full');

        $status = $this->call('POST', '/api/v1/admin/reports/generate', [], [], [],
            $this->headers($this->user('head')))->status();

        $this->assertNotSame(403, $status, 'полный уровень на «Отчётах» пропускает руководителя');
    }

    /** Уровень «Просмотр» в той же колонке генерацию не даёт. */
    #[Test]
    public function the_head_is_refused_with_a_view_level(): void
    {
        $this->setLevel('head', 'reports', 'view');

        $this->call('POST', '/api/v1/admin/reports/generate', [], [], [],
            $this->headers($this->user('head')))->assertForbidden();
    }

    /**
     * ⚠ Делегирование матрице — точечное. В разделах, которые гард НЕ отдавал,
     * руководитель остаётся read-only, даже если в матрице стоит «Полный»:
     * часть уровней там проставлена миграциями ради видимости пункта меню.
     */
    #[Test]
    public function the_head_stays_read_only_outside_the_delegated_sections(): void
    {
        $this->setLevel('head', 'charges', 'full');

        $this->call('POST', '/api/v1/admin/charges', [], [], [],
            $this->headers($this->user('head')))->assertForbidden();
    }

    /** Партнёр не проходит ни один раздел, какой бы уровень ни стоял. */
    #[Test]
    #[DataProvider('guardedRoutes')]
    public function a_partner_never_passes(string $section, string $method, string $url): void
    {
        $this->setLevel('consultant', $section, 'full');

        $this->call($method, $url, [], [], [], $this->headers($this->user('consultant')))
            ->assertForbidden();
    }

    // ================================================================

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        $this->actingAs($user, 'sanctum');

        return ['Accept' => 'application/json'];
    }

    private function setLevel(string $group, string $section, string $level): void
    {
        $row = DB::table('permission_groups')->where('key', $group)->first();
        $perms = $row ? (json_decode((string) $row->permissions, true) ?: []) : [];
        $perms[$section] = $level;

        DB::table('permission_groups')->updateOrInsert(
            ['key' => $group],
            [
                'name' => $group,
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]
        );
    }

    private function user(string $role): User
    {
        $u = new User();
        $u->id = $this->seq++;
        $u->email = 'grid' . $u->id . '@test.local';
        $u->role = $role;
        $u->lastName = 'Тестов';
        $u->firstName = 'Тест';
        $u->password = bcrypt('secret123');
        $u->save();

        return $u;
    }
}
