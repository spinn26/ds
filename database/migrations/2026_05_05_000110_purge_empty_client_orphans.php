<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Очистка «обрывков» в client — soft-deleted записей без personName,
 * person и webUser. Это артефакт массового неотработанного удаления
 * черновиков 2024-12-16 → 2025-02-10 (~112 записей у 43 партнёров).
 * После фикса /clients они уже не показывались в UI, но оставались
 * в БД и вылезали в любых отчётах/выгрузках, не фильтрующих
 * dateDeleted.
 *
 * Чистим только тех, кто НЕ зацеплен contract (FK по contract.client).
 * 4 записи остаются как «якорь» для исторических контрактов — они
 * уже dateDeleted и в UI не видны.
 *
 * clientsIndicators (analytics history) удаляются каскадно — для
 * клиента-обрывка эти строки бессмысленны без идентификации.
 *
 * Down() не восстанавливает данные (DELETE необратим). Только
 * сообщает в лог что миграция была откачена.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Снимок «удаляемых» — обрывки БЕЗ contract-ссылок.
            $deletableIds = DB::table('client')
                ->whereNotNull('dateDeleted')
                ->where(function ($q) {
                    $q->whereNull('personName')->orWhereRaw("TRIM(\"personName\") = ''");
                })
                ->whereNull('person')
                ->whereNull('webUser')
                ->whereNotIn('id', function ($sub) {
                    $sub->select('client')->from('contract')->whereNotNull('client');
                })
                ->pluck('id')
                ->all();

            if (empty($deletableIds)) {
                return;
            }

            // Каскад: clientsIndicators → client.
            $indicators = DB::table('clientsIndicators')
                ->whereIn('client', $deletableIds)
                ->delete();

            // consultant.clients тоже может ссылаться, но в текущих
            // данных таких ссылок нет; на всякий случай — отвяжем.
            $unlinked = DB::table('consultant')
                ->whereIn('clients', $deletableIds)
                ->update(['clients' => null]);

            $clientCount = DB::table('client')
                ->whereIn('id', $deletableIds)
                ->delete();

            if (app()->runningInConsole()) {
                echo "  purge_empty_client_orphans: deleted {$clientCount} clients, "
                   . "{$indicators} clientsIndicators, "
                   . "unlinked {$unlinked} consultant rows\n";
            }
        });
    }

    public function down(): void
    {
        if (app()->runningInConsole()) {
            echo "  purge_empty_client_orphans: down() — данные не восстановимы (DELETE необратим)\n";
        }
    }
};
