<?php

namespace Tests\Feature\Characterization;

use App\Enums\PartnerActivity;
use App\Services\ConsultantTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Тесты единого обхода дерева партнёров (Этап 2).
 *
 * Сюда сводятся размноженные по коду `WITH RECURSIVE`. Каждый метод обязан
 * сохранять семантику того места, откуда переехал, поэтому различия
 * (входит ли корень, фильтруются ли мягко удалённые) закреплены явно.
 *
 * Дерево:
 *
 *      ROOT
 *       ├── A ── A1 ── A2
 *       └── B (мягко удалён) ── B1   ← ветка за удалённым родителем
 *      OTHER (отдельный корень)
 */
class ConsultantTreeServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 960001;
    private const A = 960002;
    private const A1 = 960003;
    private const A2 = 960004;
    private const B_DELETED = 960005;
    private const B1 = 960006;
    private const OTHER = 960007;

    private ConsultantTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = app(ConsultantTreeService::class);
        $this->seedTree();
    }

    #[Test]
    public function subtree_includes_the_roots_themselves(): void
    {
        $ids = $this->tree->subtreeIds([self::ROOT]);

        sort($ids);
        $this->assertSame([self::ROOT, self::A, self::A1, self::A2], $ids);
    }

    /**
     * Несколько корней обходятся за один запрос. Повторы во ВХОДЕ снимаются,
     * а вот пересечение поддеревьев даёт повторы в ВЫХОДЕ: A попадает и как
     * собственный корень, и как потомок ROOT.
     *
     * ⚠ Это сохранённое поведение обеих сведённых копий (UNION ALL без
     * дедупликации). Оба вызывающих скармливают результат в whereIn, где
     * повторы безразличны. Дедупликацию здесь не делаем намеренно: она
     * изменила бы форму возвращаемого массива, а рефакторинг поведение не
     * меняет.
     */
    #[Test]
    public function subtree_accepts_several_roots_and_keeps_overlaps(): void
    {
        $ids = $this->tree->subtreeIds([self::ROOT, self::A, self::OTHER, self::ROOT]);

        sort($ids);
        $this->assertSame(
            [self::ROOT, self::A, self::A, self::A1, self::A1, self::A2, self::A2, self::OTHER],
            $ids,
            'пересечение поддеревьев даёт повторы — как и в исходных копиях'
        );
        $this->assertSame(
            [self::ROOT, self::A, self::A1, self::A2, self::OTHER],
            array_values(array_unique($ids)),
            'состав при этом верный'
        );
    }

    /**
     * Мягко удалённый узел обрывает ветку целиком: живой B1 не всплывает
     * через удалённого родителя. Это защита от orphan-веток.
     */
    #[Test]
    public function soft_deleted_node_cuts_the_branch_below_it(): void
    {
        $ids = $this->tree->subtreeIds([self::ROOT]);

        $this->assertNotContains(self::B_DELETED, $ids);
        $this->assertNotContains(self::B1, $ids, 'ветка за удалённым родителем не протаскивается');
    }

    #[Test]
    public function subtree_of_nothing_is_empty(): void
    {
        $this->assertSame([], $this->tree->subtreeIds([]));
        $this->assertSame([], $this->tree->subtreeIds([0, -1]));
    }

    #[Test]
    public function descendants_exclude_the_root(): void
    {
        $ids = $this->tree->descendantIds(self::ROOT);

        sort($ids);
        $this->assertSame([self::A, self::A1, self::A2], $ids, 'сам корень не входит');
    }

    #[Test]
    public function descendants_of_a_leaf_are_empty(): void
    {
        $this->assertSame([], $this->tree->descendantIds(self::A2));
    }

    // ================================================================
    // Обход вверх
    // ================================================================

    #[Test]
    public function upline_chain_goes_from_nearest_to_farthest(): void
    {
        $chain = array_map(fn ($r) => (int) $r->id, $this->tree->uplineChain(self::A2));

        $this->assertSame([self::A1, self::A, self::ROOT], $chain);
    }

    #[Test]
    public function nearest_active_upline_skips_terminated_and_excluded(): void
    {
        DB::table('consultant')->where('id', self::A1)
            ->update(['activity' => PartnerActivity::Terminated->value]);
        DB::table('consultant')->where('id', self::A)
            ->update(['activity' => PartnerActivity::Excluded->value]);

        $this->assertSame(
            self::ROOT,
            $this->tree->nearestActiveUplineId(self::A2),
            'перепрыгиваем и терминированного, и исключённого'
        );
    }

    #[Test]
    public function nearest_active_upline_is_null_at_the_top(): void
    {
        $this->assertNull($this->tree->nearestActiveUplineId(self::ROOT));
    }

    /**
     * ⚠ Мягко удалённые в обходе ВВЕРХ намеренно не фильтруются — так вели
     * себя все три сведённые копии. Менять это рефакторингом нельзя: перенос
     * портфеля при терминации выбрал бы другую цель.
     */
    #[Test]
    public function upline_does_not_filter_soft_deleted(): void
    {
        $this->assertSame(
            self::B_DELETED,
            $this->tree->nearestActiveUplineId(self::B1),
            'удалённый, но активный родитель всё ещё считается целью'
        );
    }

    // ================================================================

    private function seedTree(): void
    {
        foreach ([
            [self::ROOT, null, null],
            [self::A, self::ROOT, null],
            [self::A1, self::A, null],
            [self::A2, self::A1, null],
            [self::B_DELETED, self::ROOT, '2026-03-01 00:00:00'],
            [self::B1, self::B_DELETED, null],
            [self::OTHER, null, null],
        ] as [$id, $inviter, $deleted]) {
            DB::table('consultant')->insert([
                'id' => $id,
                'inviter' => $inviter,
                'personName' => 'Узел ' . $id,
                'activity' => PartnerActivity::Active->value,
                'dateDeleted' => $deleted,
                'dateCreated' => '2026-01-01 00:00:00',
            ]);
        }
    }
}
