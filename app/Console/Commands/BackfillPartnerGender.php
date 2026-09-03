<?php

namespace App\Console\Commands;

use App\Support\Audit;
use App\Support\Gender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Заполнение пустого пола партнёров по отчеству + канонизация легаси-значений.
 *
 * Зачем: пол нужен для аналитики по сети, а в профиле он заполнен далеко не у
 * всех — поле необязательное, и в Directual его часто не переносили. Русское
 * отчество даёт пол однозначно (-вич/-ыч → мужской, -вна/-чна → женский),
 * поэтому пробел закрывается без опроса людей. Правила — App\Support\Gender.
 *
 * Две отдельные операции, обе считаются и показываются раздельно:
 *   1. ЗАПОЛНЕНИЕ — пол пуст, отчество распознано → пишем male/female;
 *   2. КАНОНИЗАЦИЯ — пол хранится по-русски («Мужской») → приводим к канону.
 *      Смысл значения не меняется, но фильтры и валидация ждут male/female.
 *
 * ⚠ Это правка персональных данных реальных людей, поэтому:
 *   - по умолчанию НИЧЕГО не пишем без --force: пустой прогон только считает;
 *   - имя и фамилия в расчёт не берутся (Женя, Саша, нерусские имена);
 *   - неопознанное отчество остаётся пустым — в отчёте это «Не определён»;
 *   - факт прогона и количества уходят в audit_log.
 *
 * Пол хранится ТОЛЬКО в WebUser: у партнёров без логина такой колонки нет
 * вовсе, для них пол вычисляется на лету при построении отчёта.
 */
class BackfillPartnerGender extends Command
{
    protected $signature = 'partners:backfill-gender
        {--force : выполнить запись (без флага — только показать, что изменится)}
        {--limit=0 : обработать не больше N записей (0 — без ограничения)}';

    protected $description = 'Заполнить пустой пол партнёров по отчеству и привести легаси-значения к канону';

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));

        // Только партнёры: WebUser, на который ссылается живая карточка.
        $query = DB::table('WebUser as wu')
            ->join('consultant as c', 'c.webUser', '=', 'wu.id')
            ->whereNull('c.dateDeleted')
            ->select(['wu.id', 'wu.gender', 'wu.patronymic', 'c.personName']);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $filled = [];      // id => пол, проставленный по отчеству
        $canonized = [];   // id => пол в каноне вместо русского написания
        $unknown = [];     // id => ФИО, где отчество не распознано
        $alreadyOk = 0;

        foreach ($query->get() as $u) {
            $canonical = Gender::normalize($u->gender);
            $raw = trim((string) $u->gender);

            if ($canonical !== null) {
                if ($raw === $canonical) {
                    $alreadyOk++;
                } else {
                    $canonized[$u->id] = $canonical;
                }

                continue;
            }

            $guess = Gender::resolve(null, $u->patronymic, $u->personName);
            if ($guess !== null) {
                $filled[$u->id] = $guess;
            } else {
                $unknown[$u->id] = $u->personName;
            }
        }

        $this->table(['Что', 'Сколько'], [
            ['Пол уже в каноне', $alreadyOk],
            ['Заполним по отчеству', count($filled)],
            ['Приведём к канону', count($canonized)],
            ['Останется без пола', count($unknown)],
        ]);

        if (! $write) {
            $this->line('');
            $this->warn('Пробный прогон: ничего не записано. Для записи — --force.');
            if ($unknown) {
                $this->line('');
                $this->line('Отчество не распознано (первые 20):');
                foreach (array_slice($unknown, 0, 20, true) as $id => $name) {
                    $this->line("  WebUser #{$id} — {$name}");
                }
            }

            return self::SUCCESS;
        }

        $updates = $filled + $canonized;
        if (! $updates) {
            $this->info('Обновлять нечего.');

            return self::SUCCESS;
        }

        // Пишем пачками по значению: два UPDATE вместо тысячи.
        DB::transaction(function () use ($updates) {
            foreach ([Gender::MALE, Gender::FEMALE] as $value) {
                $ids = array_keys(array_filter($updates, fn ($v) => $v === $value));
                if ($ids) {
                    DB::table('WebUser')->whereIn('id', $ids)->update(['gender' => $value]);
                }
            }
        });

        Audit::log('gender_backfill', 'WebUser', null, [
            'filled_by_patronymic' => count($filled),
            'canonized' => count($canonized),
            'left_without_gender' => count($unknown),
            // Список нужен, чтобы правку можно было разобрать поимённо;
            // обрезаем, иначе payload раздувается на всю сеть.
            'sample_ids' => array_slice(array_keys($updates), 0, 200),
        ]);

        $this->info('Обновлено записей: ' . count($updates) . '.');

        return self::SUCCESS;
    }
}
