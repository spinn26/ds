<?php

use App\Services\GenderBackfillService;
use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;

/**
 * Разовое заполнение пустого пола партнёров по отчеству + канонизация
 * легаси-значений («Мужской» → male).
 *
 * Зачем миграцией, а не только командой: пол нужен для аналитики по сети
 * (отчёт «Демография сети»), а консоли прода ни у кого под рукой нет —
 * миграция единственное, что выполняется на деплое само. Логика общая с
 * командой `partners:backfill-gender`, живёт в GenderBackfillService.
 *
 * Что делает и, главное, чего НЕ делает:
 *   - заполняет пол только там, где он ПУСТ, и только если распознано
 *     отчество (-вич/-ыч → male, -вна/-чна → female);
 *   - приводит уже заполненные русские значения к канону male/female —
 *     смысл не меняется;
 *   - НИКОГДА не трогает уже канонический пол и не гадает по имени или
 *     фамилии: «Женя», «Саша» и нерусские имена ошибаются слишком часто,
 *     а это персональные данные реальных людей.
 *
 * Что сделано — видно в audit_log (действие gender_backfill, source=migration):
 * количества и id обновлённых записей.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var GenderBackfillService $service */
        $service = app(GenderBackfillService::class);

        $plan = $service->plan();
        $updates = $plan['fill'] + $plan['canonize'];
        if (! $updates) {
            return;
        }

        $service->apply($updates);

        Audit::log('gender_backfill', 'WebUser', null, [
            'source' => 'migration',
            'filled_by_patronymic' => count($plan['fill']),
            'canonized' => count($plan['canonize']),
            'left_without_gender' => count($plan['unknown']),
            // Здесь список полный, а не срез: это единственный способ
            // разобрать правку поимённо и при необходимости откатить руками.
            'filled_ids' => array_keys($plan['fill']),
            'canonized_ids' => array_keys($plan['canonize']),
        ]);
    }

    /**
     * Откат намеренно пустой.
     *
     * Обнулять пол обратно опаснее, чем оставить: после прогона его могли
     * поправить руками в карточке партнёра, и откат затёр бы живую правку.
     * Если нужно отменить именно эту заливку — берите filled_ids из
     * audit_log и чистите точечно.
     */
    public function down(): void
    {
        // no-op, см. докблок
    }
};
