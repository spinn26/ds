<?php

namespace App\Console\Commands;

use App\Services\GenderBackfillService;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Ручной прогон заполнения пола партнёров: сначала предпросмотр, потом запись.
 *
 * Сама логика — в GenderBackfillService, тот же код выполняет миграция
 * 2026_09_03_000100_backfill_partner_gender. Команда остаётся для повторных
 * прогонов: пол пустеет снова, когда заводят новых партнёров без профиля.
 *
 * ⚠ Это правка персональных данных реальных людей, поэтому без --force
 * ничего не пишется — только считается и показывается, кого не опознали.
 */
class BackfillPartnerGender extends Command
{
    protected $signature = 'partners:backfill-gender
        {--force : выполнить запись (без флага — только показать, что изменится)}
        {--limit=0 : обработать не больше N записей (0 — без ограничения)}';

    protected $description = 'Заполнить пустой пол партнёров по отчеству и привести легаси-значения к канону';

    public function handle(GenderBackfillService $service): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $plan = $service->plan($limit ?: null);

        $this->table(['Что', 'Сколько'], [
            ['Пол уже в каноне', $plan['canonical']],
            ['Заполним по отчеству', count($plan['fill'])],
            ['Приведём к канону', count($plan['canonize'])],
            ['Останется без пола', count($plan['unknown'])],
        ]);

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Пробный прогон: ничего не записано. Для записи — --force.');
            if ($plan['unknown']) {
                $this->newLine();
                $this->line('Отчество не распознано (первые 20):');
                foreach (array_slice($plan['unknown'], 0, 20, true) as $id => $name) {
                    $this->line("  WebUser #{$id} — {$name}");
                }
            }

            return self::SUCCESS;
        }

        $updates = $plan['fill'] + $plan['canonize'];
        $written = $service->apply($updates);

        if ($written === 0) {
            $this->info('Обновлять нечего.');

            return self::SUCCESS;
        }

        Audit::log('gender_backfill', 'WebUser', null, [
            'source' => 'command',
            'filled_by_patronymic' => count($plan['fill']),
            'canonized' => count($plan['canonize']),
            'left_without_gender' => count($plan['unknown']),
            // Список нужен, чтобы правку можно было разобрать поимённо;
            // обрезаем, иначе payload раздувается на всю сеть.
            'sample_ids' => array_slice(array_keys($updates), 0, 200),
        ]);

        $this->info("Обновлено записей: {$written}.");

        return self::SUCCESS;
    }
}
