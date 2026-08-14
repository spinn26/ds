<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use Illuminate\Support\Facades\DB;

/**
 * Единственная реализация обхода дерева партнёров по `consultant.inviter`.
 *
 * Зачем сервис: обход был размножен по коду — 15 отдельных `WITH RECURSIVE`
 * плюс ручные циклы, и копии разошлись по лимиту глубины (20 / 25 / без
 * лимита), по фильтру `dateDeleted` и по тому, входит ли корень в результат.
 * Каждая правка правила требовала найти все копии, а найти их было нечем.
 *
 * ⚠ Реальные параметры дерева (замер на проде 2026-08-14): максимальная
 * глубина цепочки — 10, циклов `inviter` нет, самородителей нет. Поэтому
 * лимит 25 здесь — предохранитель от порчи данных, а не рабочее ограничение:
 * при появлении цикла `UNION ALL` без лимита крутился бы вечно.
 *
 * Методы добавляются по мере переноса вызывающих: сюда переезжает только то,
 * что удалось свести без изменения поведения.
 */
class ConsultantTreeService
{
    /**
     * Предохранитель от циклов в legacy-структуре. Совпадает с лимитом,
     * который стоял во всех трёх сведённых upstream-копиях.
     */
    public const MAX_DEPTH = 25;

    /**
     * id ближайшего вышестоящего, который НЕ терминирован и НЕ исключён.
     * null — если такого нет (дошли до корня или до NULL).
     *
     * На этот резолвер опирается перенос портфеля при терминации: контракты и
     * клиенты не должны оставаться на партнёре, которому каскад комиссий уже
     * не платит.
     *
     * ⚠ Мягко удалённые (`dateDeleted`) намеренно НЕ отфильтрованы: так вели
     * себя все три сведённые копии, и менять это здесь нельзя — перенос
     * портфеля выбрал бы другую цель. Отдельный вопрос, надо ли их пропускать,
     * решается не рефакторингом.
     */
    public function nearestActiveUplineId(int $consultantId): ?int
    {
        foreach ($this->uplineChain($consultantId) as $row) {
            if (! $this->isInactive((int) $row->activity)) {
                return (int) $row->id;
            }
        }

        return null;
    }

    /**
     * Цепочка вышестоящих от ближнего к дальнему, БЕЗ самого партнёра.
     *
     * @return list<object{id:int, activity:?int, depth:int}>
     */
    public function uplineChain(int $consultantId, int $maxDepth = self::MAX_DEPTH): array
    {
        return DB::select(
            'WITH RECURSIVE up AS (
                SELECT id, inviter, activity, 0 AS depth FROM consultant WHERE id = ?
                UNION ALL
                SELECT c.id, c.inviter, c.activity, up.depth + 1
                FROM consultant c JOIN up ON c.id = up.inviter
                WHERE up.depth < ?
             )
             SELECT id, activity, depth FROM up WHERE depth > 0 ORDER BY depth',
            [$consultantId, $maxDepth]
        );
    }

    /**
     * Поддерево от указанных корней, ВКЛЮЧАЯ сами корни.
     *
     * Мягко удалённые отсекаются и в корнях, и на каждом шаге вглубь — иначе
     * через soft-deleted родителя протаскивается живая orphan-ветка.
     *
     * ⚠ Лимита глубины здесь нет: так вели себя обе сведённые копии
     * (PartnerSalesMatrixController). Циклов в проде нет (замер 2026-08-14,
     * максимальная глубина 10), но при их появлении запрос уйдёт в вечный
     * цикл — это осознанно сохранённое поведение, а не рекомендация.
     *
     * @param  list<int>  $rootIds
     * @return list<int>
     */
    public function subtreeIds(array $rootIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $rootIds))));
        if (! $ids) {
            return [];
        }

        $rows = DB::select(
            'WITH RECURSIVE tree AS (
                SELECT id FROM consultant
                 WHERE id = ANY(?::int[]) AND "dateDeleted" IS NULL
                UNION ALL
                SELECT c.id FROM consultant c
                  JOIN tree ON c.inviter = tree.id
                 WHERE c."dateDeleted" IS NULL
            )
            SELECT id FROM tree',
            ['{' . implode(',', $ids) . '}']
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * Все потомки корня, БЕЗ самого корня. Мягко удалённые отсекаются, лимита
     * глубины нет — семантика StructureController::descendantIds, откуда метод
     * и переехал.
     *
     * @return list<int>
     */
    public function descendantIds(int $rootId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE descendants AS (
                SELECT id FROM consultant
                 WHERE inviter = ? AND "dateDeleted" IS NULL
                UNION ALL
                SELECT c.id FROM consultant c
                  JOIN descendants d ON c.inviter = d.id
                 WHERE c."dateDeleted" IS NULL
            )
            SELECT id FROM descendants',
            [$rootId]
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * Цепочка «сам узел → его наставник → … → корень» ОДНИМ запросом, вместе
     * с названием квалификации каждого уровня.
     *
     * Заменяет ручной цикл, который на каждый уровень делал два обращения к БД
     * (строка консультанта + название уровня): десять уровней стоили два
     * десятка round-trip. Теперь стоимость постоянная.
     *
     * Защита от циклов та же, что была в цикле: путь накапливается в массиве,
     * и узел, уже встречавшийся в пути, обход обрывает — плюс жёсткий лимит
     * глубины. Порядок и состав полей совпадают с прежним выводом.
     *
     * @return list<object{id:int, personName:?string, inviter:?int, level:?string, depth:int}>
     */
    public function chainFrom(int $startId, int $maxDepth = self::MAX_DEPTH): array
    {
        return DB::select(
            'WITH RECURSIVE chain AS (
                SELECT c.id, c."personName", c.inviter, c.status_and_lvl,
                       0 AS depth, ARRAY[c.id] AS path
                  FROM consultant c
                 WHERE c.id = ?
                UNION ALL
                SELECT c.id, c."personName", c.inviter, c.status_and_lvl,
                       ch.depth + 1, ch.path || c.id
                  FROM consultant c
                  JOIN chain ch ON c.id = ch.inviter
                 WHERE ch.depth + 1 < ?
                   AND NOT c.id = ANY(ch.path)
            )
            SELECT ch.id, ch."personName", ch.inviter, sl.title AS level, ch.depth
              FROM chain ch
              LEFT JOIN status_levels sl ON sl.id = ch.status_and_lvl
             ORDER BY ch.depth',
            [$startId, $maxDepth]
        );
    }

    /** Терминирован (3) или исключён (5) — такой партнёр цель переноса не принимает. */
    private function isInactive(?int $activity): bool
    {
        return in_array($activity, [
            PartnerActivity::Terminated->value,
            PartnerActivity::Excluded->value,
        ], true);
    }
}
