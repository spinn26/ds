<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перенос контактов из person в собственные колонки client (email/phone/
 * birthDate/city). Копируем ТОЛЬКО из ВЕРНОЙ person — где ФИО связанной person
 * совпадает с client.personName. Клиенты с чужой/пустой person (хвост 936+236)
 * не трогаем: их контакты недоступны, заполнятся вручную в карточке.
 *
 * Идемпотентно, только заполняет пустые (COALESCE), деньги не затрагивает.
 * --overwrite — перезаписать даже уже заполненные client-контакты.
 */
class BackfillClientContacts extends Command
{
    protected $signature = 'clients:backfill-contacts
        {--dry-run : показать план без изменений}
        {--overwrite : перезаписать уже заполненные контакты client}';

    protected $description = 'Перенести контакты из верной person в client (client владеет контактами)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        // Кандидаты: живой клиент со связанной person, ФИО которой совпадает.
        $matchJoin = <<<'SQL'
            FROM client cl
            JOIN person p ON p.id = cl.person
            WHERE cl."dateDeleted" IS NULL
              AND cl."personName" IS NOT NULL
              AND btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
                = btrim(lower(cl."personName"))
            SQL;

        $total = (int) (DB::selectOne("SELECT count(*) c $matchJoin")->c ?? 0);
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Клиентов с верной person: {$total}");

        if ($dry) {
            $sample = DB::select("SELECT cl.id, cl.\"personName\", p.email, p.phone $matchJoin ORDER BY cl.id LIMIT 3");
            foreach ($sample as $s) {
                $this->line("  #{$s->id} {$s->personName} — {$s->email} / {$s->phone}");
            }
            return self::SUCCESS;
        }

        // Массовый UPDATE. Без --overwrite заполняем только пустые client-поля
        // (COALESCE(cl.col, p.col)); с --overwrite — берём person как истину.
        $set = $overwrite
            ? 'email = p.email, phone = p.phone, "birthDate" = p."birthDate", city = p.city'
            : 'email = COALESCE(cl.email, p.email),
               phone = COALESCE(cl.phone, p.phone),
               "birthDate" = COALESCE(cl."birthDate", p."birthDate"),
               city = COALESCE(cl.city, p.city)';

        $affected = DB::update(<<<SQL
            UPDATE client cl
            SET $set, "dateChanged" = now()
            FROM person p
            WHERE p.id = cl.person
              AND cl."dateDeleted" IS NULL
              AND cl."personName" IS NOT NULL
              AND btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
                = btrim(lower(cl."personName"))
            SQL);

        $this->info("Готово. Обновлено клиентов: {$affected}.");
        $this->warn('⚠ Если включена выгрузка в Google Sheets — прогони sheets:export-platform.');

        return self::SUCCESS;
    }
}
