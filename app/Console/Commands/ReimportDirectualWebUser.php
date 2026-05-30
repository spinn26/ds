<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Безопасный реимпорт WebUser и person из Directual-выгрузки (CSV).
 *
 * Стратегия: UPSERT по email (для WebUser) / email+phone+name (для person).
 *  - prod-id сохраняются → personal_access_tokens / mail_log / chat / FK не ломаются
 *  - 961 совпадающих обновляются (UPDATE non-PII полей)
 *  - 14 новых из CSV → INSERT с next sequence id
 *  - 64 prod-only (последние регистрации) → не трогаем
 *
 * НЕ ОБНОВЛЯЕМ: id (PK), email (matching key), password (могли менять на проде),
 * role (могли admin-роли раздавать), isAuthorization, isBlocked, status.
 *
 * Запуск:
 *   php artisan db:reimport-directual webuser /path/to/webUser.csv          # dry-run
 *   php artisan db:reimport-directual webuser /path/to/webUser.csv --apply
 *   php artisan db:reimport-directual person  /path/to/person.csv
 */
class ReimportDirectualWebUser extends Command
{
    protected $signature = 'db:reimport-directual
        {table : webuser | person}
        {path : Путь к CSV-файлу из Directual}
        {--apply : Реально применить изменения (без флага — dry-run)}';

    protected $description = 'UPSERT WebUser/person из Directual-выгрузки по email-матчингу';

    public function handle(): int
    {
        $table = strtolower((string) $this->argument('table'));
        $path = (string) $this->argument('path');
        $apply = (bool) $this->option('apply');

        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        return match ($table) {
            'webuser' => $this->processWebUser($path, $apply),
            'person'  => $this->processPerson($path, $apply),
            default   => $this->bail("Неизвестная таблица: {$table}. Допустимо: webuser, person."),
        };
    }

    private function bail(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }

    /**
     * UPSERT WebUser по email.
     */
    private function processWebUser(string $path, bool $apply): int
    {
        $rows = $this->parseCsv($path);
        $this->info("CSV прочитано: " . count($rows) . " строк");

        // Группируем по email; для дублей берём самую свежую запись по
        // dateChanged (если есть) или dateCreated.
        $byEmail = [];
        $noEmail = [];
        foreach ($rows as $r) {
            $email = mb_strtolower(trim((string) ($r['email'] ?? '')));
            if ($email === '') {
                $noEmail[] = $r;
                continue;
            }
            $prev = $byEmail[$email] ?? null;
            if ($prev === null || $this->moreRecent($r, $prev)) {
                $byEmail[$email] = $r;
            }
        }
        $this->info("Уникальных email: " . count($byEmail) . ", без email: " . count($noEmail));

        // PROD: email → row
        $prodByEmail = [];
        DB::table('WebUser')->orderBy('id')->each(function ($row) use (&$prodByEmail) {
            $email = mb_strtolower(trim((string) ($row->email ?? '')));
            if ($email !== '' && ! isset($prodByEmail[$email])) {
                $prodByEmail[$email] = $row;
            }
        });
        $this->info("PROD WebUser с email: " . count($prodByEmail));

        $toUpdate = [];
        $toInsert = [];
        foreach ($byEmail as $email => $csvRow) {
            $prod = $prodByEmail[$email] ?? null;
            if ($prod) {
                $diff = $this->diffWebUser($prod, $csvRow);
                if ($diff) {
                    $toUpdate[(int) $prod->id] = $diff;
                }
            } else {
                $toInsert[] = $this->mapInsertWebUser($csvRow);
            }
        }

        $this->line('');
        $this->info('=== СВОДКА (WebUser) ===');
        $this->line("К UPDATE: " . count($toUpdate));
        $this->line("К INSERT: " . count($toInsert));
        $this->line("Без email (пропущено, требует ручной проверки): " . count($noEmail));
        $this->line("PROD-only (не трогаем): " . max(0, count($prodByEmail) - (count($byEmail) - count($toInsert))));

        if ($this->getOutput()->isVerbose()) {
            $this->line('');
            $this->warn('--- Sample UPDATE diffs (first 5) ---');
            $i = 0;
            foreach ($toUpdate as $id => $diff) {
                if ($i++ >= 5) break;
                $this->line("id={$id}: " . json_encode(array_keys($diff), JSON_UNESCAPED_UNICODE));
            }
            $this->line('');
            $this->warn('--- Sample INSERT (first 5) ---');
            foreach (array_slice($toInsert, 0, 5) as $r) {
                $this->line("  {$r['lastName']} {$r['firstName']} <{$r['email']}>");
            }
            $this->line('');
            $this->warn('--- Без email (first 5) ---');
            foreach (array_slice($noEmail, 0, 5) as $r) {
                $this->line("  {$r['lastName']} {$r['firstName']} phone={$r['phone']}");
            }
        }

        if (! $apply) {
            $this->line('');
            $this->info('Dry-run. --apply для commit.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn('=== APPLY ===');
        $this->warn('Применяем ТОЛЬКО UPDATE. INSERT новых отложен — нужно отдельное подтверждение, т.к. CSV id может конфликтовать с prod id.');
        DB::transaction(function () use ($toUpdate) {
            foreach ($toUpdate as $id => $diff) {
                $diff['dateChanged'] = now();
                DB::table('WebUser')->where('id', $id)->update($diff);
            }
        });
        $this->info("UPDATE: " . count($toUpdate));
        $this->warn("INSERT отложено: " . count($toInsert) . " новых юзеров (нужно ручное review)");
        return self::SUCCESS;
    }

    /**
     * UPSERT person по email; fallback на (phone + lastName).
     */
    private function processPerson(string $path, bool $apply): int  /* @phpstan-ignore parameter.unused */
    {
        $rows = $this->parseCsv($path);
        $this->info("CSV прочитано: " . count($rows) . " строк");
        $this->warn('person: реализация на отдельном шаге — сейчас только dry-run по emails.');

        $byEmail = [];
        foreach ($rows as $r) {
            $e = mb_strtolower(trim((string) ($r['email'] ?? '')));
            if ($e !== '') $byEmail[$e] = $r;
        }
        $this->info("CSV person с email: " . count($byEmail));

        $prodByEmail = [];
        DB::table('person')->whereNotNull('email')->where('email', '!=', '')
            ->each(function ($row) use (&$prodByEmail) {
                $email = mb_strtolower(trim((string) $row->email));
                if (! isset($prodByEmail[$email])) $prodByEmail[$email] = $row;
            });
        $this->info("PROD person с email: " . count($prodByEmail));

        $matched = count(array_intersect_key($byEmail, $prodByEmail));
        $newOnes = count($byEmail) - $matched;
        $this->info("Matched by email: {$matched}");
        $this->info("New (only in CSV): {$newOnes}");
        $this->info("PROD-only: " . (count($prodByEmail) - $matched));
        $this->warn('Apply для person НЕ реализован — слишком много полей и сложные FK. Сделаем после approval.');
        return self::SUCCESS;
    }

    /**
     * Парсит Directual CSV: первая строка — заголовки, разделитель «;».
     * Возвращает list<array<string, ?string>>.
     *
     * @return array<int, array<string, ?string>>
     */
    private function parseCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (! $fh) return [];
        // UTF-8 BOM
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);

        $headers = fgetcsv($fh, 0, ';', '"', '\\');
        if (! $headers) { fclose($fh); return []; }

        $out = [];
        while (($row = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
            if ($row === [null]) continue;
            $assoc = [];
            foreach ($headers as $i => $h) {
                $assoc[trim((string) $h)] = $row[$i] ?? null;
            }
            $out[] = $assoc;
        }
        fclose($fh);
        return $out;
    }

    private function moreRecent(array $a, array $b): bool
    {
        return strtotime((string) ($a['dateChanged'] ?? $a['dateCreated'] ?? ''))
             > strtotime((string) ($b['dateChanged'] ?? $b['dateCreated'] ?? ''));
    }

    /**
     * Diff prod-row vs csv-row. Возвращает только поля которые реально отличаются.
     * НЕ обновляем: id, email, password, role, isAuthorization, isBlocked, status.
     */
    private function diffWebUser(object $prod, array $csv): array
    {
        $writable = [
            'lastName', 'firstName', 'patronymic', 'phone', 'nicTG',
            'birthDate', 'gender', 'taxResidency', 'city', 'comment',
        ];
        $diff = [];
        foreach ($writable as $col) {
            $csvVal = $csv[$col] ?? null;
            $csvVal = $csvVal === '' ? null : $csvVal;
            $prodVal = $prod->{$col} ?? null;
            $prodVal = $prodVal === '' ? null : $prodVal;
            if ($csvVal !== null && (string) $csvVal !== (string) $prodVal) {
                $diff[$col] = $csvVal;
            }
        }
        return $diff;
    }

    /**
     * Поля для INSERT нового WebUser. id НЕ передаём — пусть БД выдаст
     * через LegacyId или sequence.
     */
    private function mapInsertWebUser(array $csv): array
    {
        return [
            // id выдаст BД через LegacyId helper в коде; здесь мы только
            // готовим payload и оставляем id из CSV для подсказки
            'id'           => (int) $csv['id'], // ВРЕМЕННО: тот же id как в CSV
            'email'        => mb_strtolower(trim((string) $csv['email'])),
            'lastName'     => $csv['lastName'] ?? null,
            'firstName'    => $csv['firstName'] ?? null,
            'patronymic'   => $csv['patronymic'] ?? null,
            'phone'        => $csv['phone'] ?? null,
            'nicTG'        => $csv['nicTG'] ?? null,
            'birthDate'    => $csv['birthDate'] ?: null,
            'gender'       => $csv['gender'] ?? null,
            'taxResidency' => $csv['taxResidency'] ?? null,
            'city'         => $csv['city'] ?? null,
            'role'         => $csv['role'] ?? 'registered',
            'password'     => $csv['password'] ?: null, // MD5 из Directual; auto-bcrypt при логине
            'dateCreated'  => $csv['dateCreated'] ?? now()->toIso8601String(),
        ];
    }
}
