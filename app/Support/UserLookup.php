<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Батч-резолв WebUser.id → краткая карточка пользователя {id,name,email,role}.
 * Используется модулем задач для постановщика/исполнителя/наблюдателей,
 * чтобы не плодить N+1.
 */
class UserLookup
{
    /** @param iterable<int> $ids @return array<int,array> keyed by id */
    public static function map($ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($i) => (int) $i)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('WebUser')
            ->whereIn('id', $ids)
            ->get(['id', 'firstName', 'lastName', 'email', 'role'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => [
                'id' => (int) $u->id,
                'name' => trim("{$u->firstName} {$u->lastName}") ?: ($u->email ?? "#{$u->id}"),
                'email' => $u->email,
                'role' => $u->role,
            ]])
            ->all();
    }

    /** Одиночный пользователь или null. */
    public static function one(?int $id): ?array
    {
        if (! $id) {
            return null;
        }

        return self::map([$id])[$id] ?? null;
    }
}
