<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use App\Services\DadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Заведение реквизитов партнёра (POST /requisites).
 *
 * Сетка ПОД вынос. Правило здесь одно, но принципиальное:
 *
 * ⚠ Автоматическая верификация ОТКЛЮЧЕНА ПОЛНОСТЬЮ (решение 2026-05-27).
 * DaData отдаёт только статус «ИП» из ЕГРИП, но НЕ режим налогообложения —
 * он в другом реестре ФНС, к которому интеграции нет. Партнёр обязан быть
 * ИП на УСН, проверить это нечем, поэтому ВСЕ реквизиты уходят на ручную
 * проверку финменеджеру. Любая правка, включающая авто-верификацию обратно,
 * должна начинаться с интеграции, а не с этого кода.
 *
 * Остальное: ликвидированное ИП и несовпадение ФИО не принимаются вовсе.
 */
class SetupRequisitesTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 3200001;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    /**
     * ⚠ Даже у полностью валидного ИП реквизиты остаются НЕподтверждёнными
     * и уходят на ручную проверку.
     */
    #[Test]
    public function a_valid_entrepreneur_still_needs_manual_verification(): void
    {
        $this->fakeDadata([
            'found' => true, 'type' => 'INDIVIDUAL', 'status' => 'ACTIVE',
            'name' => 'Реквизитов Партнёр Тестович',
            'inn' => '770708389765', 'ogrn' => '304770000000001',
        ]);

        $this->submit()->assertOk();

        $row = DB::table('requisites')->where('consultant', self::PARTNER)->first();

        $this->assertNotNull($row, 'реквизиты сохранены');
        $this->assertFalse((bool) $row->verified, 'но НЕ подтверждены автоматически');
    }

    /** Ликвидированное ИП не принимается. */
    #[Test]
    public function a_liquidated_entrepreneur_is_rejected(): void
    {
        $this->fakeDadata([
            'found' => true, 'type' => 'INDIVIDUAL', 'status' => 'LIQUIDATED',
            'name' => 'Реквизитов Партнёр Тестович',
            'inn' => '770708389765',
        ]);

        $this->submit()->assertStatus(422);
        $this->assertSame(0, DB::table('requisites')->count());
    }

    /** ИНН, которого нет в реестре, не принимается. */
    #[Test]
    public function an_unknown_inn_is_rejected(): void
    {
        $this->fakeDadata(['found' => false]);

        $this->submit()->assertStatus(422);
        $this->assertSame(0, DB::table('requisites')->count());
    }

    /**
     * ⚠ Юрлицо НЕ отклоняется: реквизиты принимаются и уходят на ручную
     * проверку с причиной «ИНН юр. лица — требуется проверка бенефициара».
     * Я ожидал отказа — поведение оказалось мягче.
     */
    #[Test]
    public function a_legal_entity_goes_to_manual_review(): void
    {
        $this->fakeDadata([
            'found' => true, 'type' => 'LEGAL', 'status' => 'ACTIVE',
            'name' => 'ООО «Реквизиты»', 'inn' => '7707083893',
            'ogrn' => '1027700000001',
        ]);

        $this->submit()->assertOk();

        $this->assertFalse((bool) DB::table('requisites')
            ->where('consultant', self::PARTNER)->value('verified'));
    }

    /**
     * 🐞 Ответ реестра БЕЗ ОГРН роняет запрос пятисоткой: текст тикета
     * финменеджеру подставляет $fns['ogrn'] без защиты. Партнёр в этот
     * момент видит ошибку, хотя реквизиты уже сохранены.
     *
     * Тест фиксирует дефект как есть; починка — следующим коммитом.
     */
    #[Test]
    public function an_answer_without_ogrn_currently_fails(): void
    {
        $this->fakeDadata([
            'found' => true, 'type' => 'INDIVIDUAL', 'status' => 'ACTIVE',
            'name' => 'Реквизитов Партнёр Тестович', 'inn' => '770708389765',
        ]);

        $this->submit()->assertStatus(500);
    }

    // ================================================================

    private function submit()
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/requisites', [
                'inn' => '770708389765',
                'bankName' => 'Тестовый банк',
                'bankBik' => '044525225',
                'accountNumber' => '40802810000000000001',
                'correspondentAccount' => '30101810400000000225',
            ]);
    }

    /** @param array<string, mixed> $answer */
    private function fakeDadata(array $answer): void
    {
        $this->instance(DadataService::class, new class($answer) extends DadataService {
            /** @param array<string, mixed> $answer */
            public function __construct(private array $answer)
            {
            }

            /** @return array<string, mixed> */
            public function findByInn(string $inn): array
            {
                return $this->answer;
            }
        });
    }

    private function seedFixture(): void
    {
        $this->user = new User();
        $this->user->id = 3200900;
        $this->user->email = 'requisites@test.local';
        $this->user->firstName = 'Партнёр';
        $this->user->lastName = 'Реквизитов';
        $this->user->patronymic = 'Тестович';
        $this->user->role = 'consultant';
        $this->user->password = bcrypt('secret123');
        $this->user->save();

        // Справочник статусов реквизитов: на него смотрит внешний ключ
        // requisites.status, а в схему-фикстуру он не попал.
        DB::table('status_requisites')->insert([
            ['id' => 1, 'level' => 1, 'name' => 'backoffice'],
            ['id' => 2, 'level' => 2, 'name' => 'consultant'],
            ['id' => 3, 'level' => 3, 'name' => 'verified'],
        ]);

        DB::table('consultant')->insert([
            'id' => self::PARTNER, 'webUser' => $this->user->id,
            'personName' => 'Реквизитов Партнёр Тестович',
            'activity' => 1, 'active' => true,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
    }
}
