<?php

namespace Tests\Feature\Characterization;

use App\Jobs\RecomputeTransferChainJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Безопасность обходов дерева партнёров.
 *
 * Обход по inviter встречается в коде несколько раз, и часть копий шла БЕЗ
 * ограничения глубины: цикл в данных увёл бы такой запрос в бесконечность.
 * Циклов сегодня нет (проверено на проде: максимальная глубина 10, циклов 0),
 * но структура правится руками, поэтому лимит здесь — страховка, а не
 * оптимизация.
 *
 * Второй сюжет: мягко удалённый партнёр в СЕРЕДИНЕ ветки. Его живые потомки
 * обязаны оставаться в поддереве пересчёта — иначе перестановка перестанет
 * доходить до них. На проде такой узел есть (один).
 */
class TreeTraversalSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 2600001;
    private const MIDDLE_DELETED = 2600002;
    private const LEAF = 2600003;

    /** Узлы цикла: A → B → A. */
    private const CYCLE_A = 2600010;
    private const CYCLE_B = 2600011;

    /**
     * ⚠ Мягко удалённый партнёр в середине ветки НЕ обрывает поддерево:
     * его живые потомки остаются в пересчёте. Поэтому фильтр по dateDeleted
     * здесь намеренно отсутствует — с ним перестановка переставала бы
     * доходить до нижних уровней.
     */
    #[Test]
    public function a_deleted_node_does_not_cut_off_its_live_descendants(): void
    {
        $this->partner(self::ROOT, null);
        $this->partner(self::MIDDLE_DELETED, self::ROOT, deleted: true);
        $this->partner(self::LEAF, self::MIDDLE_DELETED);

        $ids = $this->subtreeOf(self::ROOT);

        $this->assertContains(self::LEAF, $ids,
            'потомок удалённого узла обязан попасть в поддерево пересчёта');
    }

    /**
     * Цикл в структуре не должен подвешивать обход. Проверяем на самом
     * рискованном варианте — поддереве задания пересчёта.
     */
    #[Test]
    public function a_cycle_does_not_hang_the_subtree_walk(): void
    {
        // Цикл нельзя завести одной вставкой — мешает внешний ключ на inviter,
        // поэтому замыкаем его вторым шагом, как это и происходит в жизни:
        // структуру правят руками уже после создания узлов.
        $this->partner(self::CYCLE_A, null);
        $this->partner(self::CYCLE_B, self::CYCLE_A);
        DB::table('consultant')->where('id', self::CYCLE_A)
            ->update(['inviter' => self::CYCLE_B]);

        $ids = $this->subtreeOf(self::CYCLE_A);

        $this->assertContains(self::CYCLE_A, $ids);
        $this->assertContains(self::CYCLE_B, $ids);
        $this->assertLessThan(10, count($ids), 'обход завершился, а не намотал повторы');
    }

    // ================================================================

    /** @return list<int> */
    private function subtreeOf(int $rootId): array
    {
        $job = new RecomputeTransferChainJob('partner', $rootId);
        $method = new \ReflectionMethod($job, 'subtreeConsultantIds');
        $method->setAccessible(true);

        return $method->invoke($job);
    }

    private function partner(int $id, ?int $inviter, bool $deleted = false): void
    {
        DB::table('consultant')->insert([
            'id' => $id,
            'personName' => 'Узел ' . $id,
            'inviter' => $inviter,
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
            'dateDeleted' => $deleted ? '2026-02-01 00:00:00' : null,
        ]);
    }
}
