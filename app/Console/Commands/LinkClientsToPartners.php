<?php

namespace App\Console\Commands;

use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Заполнение client.partner_consultant_id — явной связи «карточка клиента =
 * партнёр» взамен общего person.
 *
 * Раньше признак считался как client.person = consultant.person. Связь
 * ненадёжна: id person разошлись при консолидации Directual, поэтому часть пар
 * ведёт на другого человека, а у большинства партнёров person не заполнен
 * вовсе и признак не определялся.
 *
 * ⚠ ФИО совпадает — обязательное условие ЛЮБОГО правила. Телефон и почта
 * сплошь общие у семьи (родитель оформляет полис на детей со своим номером —
 * инцидент 2026-08-06), без сверки ФИО связка склеит родственников.
 * ⚠ Пары берём только однозначные: и клиент, и партнёр должны встречаться в
 * выборке ровно один раз, иначе тёзки уедут не на того.
 */
class LinkClientsToPartners extends Command
{
    protected $signature = 'clients:link-partners
        {--apply : записать связи (без флага — только показать)}
        {--relink : перезаписывать уже проставленные связи}';

    protected $description = 'Проставить client.partner_consultant_id (клиент является партнёром)';

    /**
     * Кандидаты: живой клиент и живой партнёр с ОДИНАКОВЫМ ФИО, сошедшиеся
     * хотя бы по одному признаку — телефон (последние 10 цифр) или почта.
     * source нужен только для отчёта, на выбор он не влияет.
     */
    private function candidatesSql(): string
    {
        $clientPhone = Phone::sql('cl.phone');
        $userPhone = Phone::sql('w.phone');

        return <<<SQL
            WITH pairs AS (
                SELECT cl.id AS client_id,
                       c.id  AS consultant_id,
                       CASE
                           WHEN {$clientPhone} IS NOT NULL AND {$clientPhone} = {$userPhone} THEN 'phone'
                           ELSE 'email'
                       END AS source
                FROM client cl
                JOIN consultant c
                  ON c."dateDeleted" IS NULL
                 AND btrim(lower(c."personName")) = btrim(lower(cl."personName"))
                LEFT JOIN "webUser" w ON w.id = c."webUser"
                WHERE cl."dateDeleted" IS NULL
                  AND nullif(btrim(coalesce(cl."personName", '')), '') IS NOT NULL
                  AND (
                        ({$clientPhone} IS NOT NULL AND {$clientPhone} = {$userPhone})
                     OR (nullif(btrim(lower(coalesce(cl.email, ''))), '') IS NOT NULL
                         AND btrim(lower(cl.email)) = btrim(lower(coalesce(w.email, ''))))
                  )
            ),
            uniq AS (
                SELECT client_id, min(consultant_id) AS consultant_id, min(source) AS source
                FROM pairs
                GROUP BY client_id
                HAVING count(DISTINCT consultant_id) = 1
            )
            SELECT u.client_id, u.consultant_id, u.source
            FROM uniq u
            WHERE (SELECT count(*) FROM uniq u2 WHERE u2.consultant_id = u.consultant_id) = 1
            SQL;
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $relink = (bool) $this->option('relink');

        $rows = DB::select($this->candidatesSql());
        if ($rows === []) {
            $this->info('Кандидатов нет.');

            return self::SUCCESS;
        }

        $bySource = [];
        foreach ($rows as $r) {
            $bySource[$r->source] = ($bySource[$r->source] ?? 0) + 1;
        }
        $this->info('Однозначных пар: ' . count($rows) . ' (' . json_encode($bySource, JSON_UNESCAPED_UNICODE) . ').');

        if (! $apply) {
            $this->line('Сухой прогон. Повтори с --apply, чтобы записать.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;
        DB::transaction(function () use ($rows, $relink, &$written, &$skipped) {
            foreach (array_chunk($rows, 500) as $chunk) {
                foreach ($chunk as $r) {
                    $q = DB::table('client')->where('id', $r->client_id);
                    if (! $relink) {
                        // Уже проставленное руками оператора не перетираем.
                        $q->whereNull('partner_consultant_id');
                    }
                    $affected = $q->update(['partner_consultant_id' => $r->consultant_id]);
                    $affected > 0 ? $written++ : $skipped++;
                }
            }
        });

        $this->info("Проставлено связей: {$written}, пропущено (уже заполнено): {$skipped}.");

        return self::SUCCESS;
    }
}
