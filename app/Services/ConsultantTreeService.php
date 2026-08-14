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

    /** Терминирован (3) или исключён (5) — такой партнёр цель переноса не принимает. */
    private function isInactive(?int $activity): bool
    {
        return in_array($activity, [
            PartnerActivity::Terminated->value,
            PartnerActivity::Excluded->value,
        ], true);
    }
}
