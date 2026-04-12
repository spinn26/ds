<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Support\Facades\DB;

trait HasTeamTree
{
    /**
     * Рекурсивно собрать ВСЕ ID потомков из consultantStructure (все уровни вглубь).
     */
    protected function getAllDescendants(int $parentId): array
    {
        $allIds = [];
        $currentLevel = [$parentId];

        for ($i = 0; $i < 20; $i++) {
            $children = DB::table('consultantStructure')
                ->whereIn('parent', $currentLevel)
                ->pluck('child')
                ->toArray();

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return array_unique($allIds);
    }

    /**
     * Получить все ID команды (потомки + сам консультант).
     */
    protected function getTeamIds(int $consultantId): array
    {
        $ids = $this->getAllDescendants($consultantId);
        $ids[] = $consultantId;
        return $ids;
    }
}
