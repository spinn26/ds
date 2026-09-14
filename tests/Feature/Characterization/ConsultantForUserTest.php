<?php

namespace Tests\Feature\Characterization;

use App\Models\Consultant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Карточка партнёра для аккаунта входа — Consultant::forUser и связь
 * User::consultantRecord.
 *
 * У одного аккаунта бывает несколько карточек, лишние мягко удалены. Прежний
 * `where('webUser', …)->first()` без сортировки отдавал любую: на проде
 * 14.09.2026 у Дроздовой (webUser 578: удалённые 1393 и 1478, живая 1422) это
 * была удалённая 1478 — кабинет показывал пустую карточку, а сделки из виджета
 * Инсмарта ложились на удалённого партнёра.
 */
class ConsultantForUserTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 2900001;
    private const DELETED_ONLY_ACCOUNT = 2900002;

    protected function setUp(): void
    {
        parent::setUp();

        // Как у Дроздовой: старый удалённый дубль, живая карточка, новый удалённый.
        DB::table('consultant')->insert([
            $this->card(2900011, self::ACCOUNT, '2025-01-25 19:00:00'),
            $this->card(2900012, self::ACCOUNT, null),
            $this->card(2900013, self::ACCOUNT, '2025-02-02 19:00:00'),
            $this->card(2900021, self::DELETED_ONLY_ACCOUNT, '2025-03-01 00:00:00'),
            $this->card(2900022, self::DELETED_ONLY_ACCOUNT, '2025-04-01 00:00:00'),
        ]);
    }

    #[Test]
    public function live_card_wins_over_deleted_duplicates(): void
    {
        $this->assertSame(2900012, Consultant::forUser(self::ACCOUNT)?->id);
    }

    /** Живой карточки нет — отдаём самую новую удалённую, а не случайную. */
    #[Test]
    public function newest_deleted_card_when_account_has_no_live_one(): void
    {
        $this->assertSame(2900022, Consultant::forUser(self::DELETED_ONLY_ACCOUNT)?->id);
    }

    #[Test]
    public function unknown_or_empty_account_has_no_card(): void
    {
        $this->assertNull(Consultant::forUser(2900999));
        $this->assertNull(Consultant::forUser(null));
    }

    /** Политики доступа читают связь — порядок у неё тот же. */
    #[Test]
    public function user_relation_picks_the_same_card(): void
    {
        $user = new User();
        $user->id = self::ACCOUNT;
        $user->exists = true;

        $this->assertSame(2900012, $user->consultantRecord?->id);
    }

    private function card(int $id, int $account, ?string $deletedAt): array
    {
        return [
            'id' => $id,
            'personName' => 'Партнёр ' . $id,
            'activity' => 1,
            'dateCreated' => '2025-01-01 00:00:00',
            'webUser' => $account,
            'dateDeleted' => $deletedAt,
        ];
    }
}
