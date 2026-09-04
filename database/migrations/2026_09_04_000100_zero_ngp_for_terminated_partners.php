<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Обнулить НГП терминированным и исключённым партнёрам.
 *
 * НГП терминированного сгорает — платформа и так считала его сгоревшим:
 * reRegister() и самовосстановление обнуляли поле при ВОЗВРАТЕ партнёра.
 * Но сама терминация его не трогала, поэтому те, кто ушёл и не вернулся,
 * оставались в списках и выгрузках со старым накопленным объёмом —
 * 262 партнёра на 2 682 923 баллов на момент миграции.
 *
 * Правило закрыто в коде (PartnerStatusService::terminate/forceTerminate),
 * эта миграция чистит уже накопленное.
 *
 * ⚠ Трогаем ТОЛЬКО денормализацию consultant."groupVolumeCumulative".
 * Месячные снимки qualificationLog не трогаем: по ним QualificationReeval
 * считает уровни, по ним же строится история — а история неизменна.
 * Комиссии, пул и балансы не зависят от этого поля.
 *
 * Прежние значения складываем в архивную таблицу: обнуление денег напрямую
 * не меняет, но восстановить исходное состояние должно быть чем.
 */
return new class extends Migration
{
    private const ARCHIVE = 'consultant_ngp_terminated_20260904';

    public function up(): void
    {
        if (! Schema::hasTable(self::ARCHIVE)) {
            DB::statement(
                'CREATE TABLE "' . self::ARCHIVE . '" AS'
                . ' SELECT id, activity, "groupVolumeCumulative", now() AS archived_at'
                // WHERE false — создаём только структуру: строки кладёт INSERT ниже,
                // и только те, которые действительно меняем.
                . ' FROM consultant WHERE false');
        }

        // Архивируем только то, что реально изменим.
        DB::statement(
            'INSERT INTO "' . self::ARCHIVE . '" (id, activity, "groupVolumeCumulative", archived_at)'
            . ' SELECT id, activity, "groupVolumeCumulative", now() FROM consultant'
            . ' WHERE activity IN (3, 5)'
            . ' AND COALESCE("groupVolumeCumulative", 0) <> 0');

        DB::statement(
            'UPDATE consultant SET "groupVolumeCumulative" = 0'
            . ' WHERE activity IN (3, 5)'
            . ' AND COALESCE("groupVolumeCumulative", 0) <> 0');
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::ARCHIVE)) {
            return;
        }

        // Возвращаем последнее заархивированное значение по каждому партнёру.
        DB::statement(
            'UPDATE consultant c SET "groupVolumeCumulative" = a."groupVolumeCumulative"'
            . ' FROM (SELECT DISTINCT ON (id) id, "groupVolumeCumulative"'
            . '         FROM "' . self::ARCHIVE . '" ORDER BY id, archived_at DESC) a'
            . ' WHERE c.id = a.id');
    }
};
