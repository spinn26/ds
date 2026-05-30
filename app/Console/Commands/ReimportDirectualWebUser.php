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
            'webuser'    => $this->processWebUser($path, $apply),
            'person'     => $this->processPerson($path, $apply),
            // ВНИМАНИЕ: FK-колонки (inviter, webUser, consultant, person, client,
            // product, program) НЕ обновляем — Directual integer id ≠ prod
            // integer id для большинства записей (сдвиг нумерации), это бы
            // привело к массовому смешению связей. Обновляем только non-FK
            // поля (личные данные, статусы, суммы, даты).
            // consultant.status/activity (FK), qualificationLog (integer в prod, в
            // Directual массив id) — пропускаем. Status в prod динамически
            // пересчитывается через partners:check-statuses.
            'consultant' => $this->processSimple($path, $apply, 'consultant', 'participantCode',
                ['personName', 'inviterName', 'personalVolume', 'groupVolume',
                 'dateActivity', 'dateDeactivity', 'dateDeterministic', 'dateDeterministicPlan']),
            'client'     => $this->processSimple($path, $apply, 'client', 'idDs',
                ['personName', 'consultantName', 'active',
                 'dateChanged', 'comment', 'lastActivityDate', 'source']),
            'contract'   => $this->processSimple($path, $apply, 'contract', 'number',
                ['ammount', 'currency', 'openDate', 'closeDate', 'status', 'comment']),
            default      => $this->bail("Неизвестная таблица: {$table}. Допустимо: webuser, person, consultant, client, contract."),
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
     * UPSERT person по email; fallback ключ — phone+lastName для записей без email.
     */
    private function processPerson(string $path, bool $apply): int
    {
        $rows = $this->parseCsv($path);
        $this->info("CSV прочитано: " . count($rows) . " строк");

        // CSV: group by primary key (email или phone+lastName)
        $csvByEmail = [];
        $csvByPhoneName = [];
        $noKey = 0;
        foreach ($rows as $r) {
            $email = mb_strtolower(trim((string) ($r['email'] ?? '')));
            if ($email !== '') {
                $prev = $csvByEmail[$email] ?? null;
                if ($prev === null || $this->moreRecent($r, $prev)) {
                    $csvByEmail[$email] = $r;
                }
                continue;
            }
            $phone = preg_replace('/[^0-9]/', '', (string) ($r['phone'] ?? ''));
            $lname = mb_strtolower(trim((string) ($r['lastName'] ?? '')));
            if ($phone !== '' && $lname !== '') {
                $k = "{$phone}|{$lname}";
                $prev = $csvByPhoneName[$k] ?? null;
                if ($prev === null || $this->moreRecent($r, $prev)) {
                    $csvByPhoneName[$k] = $r;
                }
                continue;
            }
            $noKey++;
        }
        $this->info("CSV person с email: " . count($csvByEmail) . ", по phone+name: " . count($csvByPhoneName) . ", без ключа: " . $noKey);

        // PROD: same maps
        $prodByEmail = [];
        $prodByPhoneName = [];
        DB::table('person')->orderBy('id')->each(function ($row) use (&$prodByEmail, &$prodByPhoneName) {
            $email = mb_strtolower(trim((string) ($row->email ?? '')));
            if ($email !== '') {
                if (! isset($prodByEmail[$email])) $prodByEmail[$email] = $row;
                return;
            }
            $phone = preg_replace('/[^0-9]/', '', (string) ($row->phone ?? ''));
            $lname = mb_strtolower(trim((string) ($row->lastName ?? '')));
            if ($phone !== '' && $lname !== '') {
                $k = "{$phone}|{$lname}";
                if (! isset($prodByPhoneName[$k])) $prodByPhoneName[$k] = $row;
            }
        });
        $this->info("PROD person с email: " . count($prodByEmail) . ", по phone+name: " . count($prodByPhoneName));

        $toUpdate = [];
        $toInsert = [];
        // Сначала по email — это сильный ключ.
        foreach ($csvByEmail as $email => $csvRow) {
            $prod = $prodByEmail[$email] ?? null;
            if ($prod) {
                $diff = $this->diffPerson($prod, $csvRow);
                if ($diff) $toUpdate[(int) $prod->id] = $diff;
            } else {
                $toInsert[] = $csvRow;
            }
        }
        // Затем по phone+lastName для записей которые без email.
        foreach ($csvByPhoneName as $k => $csvRow) {
            $prod = $prodByPhoneName[$k] ?? null;
            if ($prod) {
                $diff = $this->diffPerson($prod, $csvRow);
                if ($diff) $toUpdate[(int) $prod->id] = $diff;
            } else {
                $toInsert[] = $csvRow;
            }
        }

        $this->line('');
        $this->info('=== СВОДКА (person) ===');
        $this->line("К UPDATE: " . count($toUpdate));
        $this->line("К INSERT (отложено): " . count($toInsert));
        $this->line("Без ключа в CSV (пропущено): " . $noKey);
        $this->line("PROD-only: " . (count($prodByEmail) + count($prodByPhoneName) - count($toUpdate)));

        if (! $apply) {
            $this->line('');
            $this->info('Dry-run. --apply для commit.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn('=== APPLY person UPDATE ===');
        DB::transaction(function () use ($toUpdate) {
            foreach ($toUpdate as $id => $diff) {
                $diff['dateChanged'] = now()->toIso8601String();
                DB::table('person')->where('id', $id)->update($diff);
            }
        });
        $this->info("UPDATE: " . count($toUpdate));
        $this->warn("INSERT отложен: " . count($toInsert));
        return self::SUCCESS;
    }

    /**
     * Универсальный UPSERT для таблиц с natural unique key
     * (consultant.participantCode, client.idDs, contract.number).
     *
     * @param  list<string>  $writableFields  поля для UPDATE (id/key исключены)
     */
    private function processSimple(string $path, bool $apply, string $table, string $keyCol, array $writableFields): int
    {
        $rows = $this->parseCsv($path);
        $this->info("CSV прочитано: " . count($rows) . " строк (таблица {$table}, ключ {$keyCol})");

        // CSV: group by key (для дублей — самая свежая по dateChanged)
        $csvByKey = [];
        $noKey = 0;
        foreach ($rows as $r) {
            $k = trim((string) ($r[$keyCol] ?? ''));
            if ($k === '') { $noKey++; continue; }
            $prev = $csvByKey[$k] ?? null;
            if ($prev === null || $this->moreRecent($r, $prev)) {
                $csvByKey[$k] = $r;
            }
        }
        $this->info("CSV с ключом '{$keyCol}': " . count($csvByKey) . ", без ключа: {$noKey}");

        // PROD: group by key
        $prodByKey = [];
        DB::table($table)->orderBy('id')->each(function ($row) use (&$prodByKey, $keyCol) {
            $k = trim((string) ($row->{$keyCol} ?? ''));
            if ($k !== '' && ! isset($prodByKey[$k])) $prodByKey[$k] = $row;
        });
        $this->info("PROD {$table} с ключом '{$keyCol}': " . count($prodByKey));

        $toUpdate = [];
        $toInsert = [];
        foreach ($csvByKey as $k => $csvRow) {
            $prod = $prodByKey[$k] ?? null;
            if ($prod) {
                $diff = $this->genericDiff($prod, $csvRow, $writableFields);
                if ($diff) $toUpdate[(int) $prod->id] = $diff;
            } else {
                $toInsert[] = $csvRow;
            }
        }

        $this->line('');
        $this->info("=== СВОДКА ({$table}) ===");
        $this->line("К UPDATE: " . count($toUpdate));
        $this->line("К INSERT (отложено): " . count($toInsert));
        $this->line("Без ключа в CSV: {$noKey}");
        $this->line("PROD-only: " . max(0, count($prodByKey) - count($toUpdate)));

        if (! $apply) {
            $this->line('');
            $this->info('Dry-run. --apply для commit.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn("=== APPLY {$table} UPDATE ===");
        $errors = 0;
        DB::transaction(function () use ($toUpdate, $table, &$errors) {
            foreach ($toUpdate as $id => $diff) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'dateChanged')) {
                        $diff['dateChanged'] = now()->toIso8601String();
                    }
                    DB::table($table)->where('id', $id)->update($diff);
                } catch (\Throwable $e) {
                    $errors++;
                    if ($errors <= 3) {
                        $this->warn("  id={$id}: " . substr($e->getMessage(), 0, 200));
                    }
                }
            }
            if ($errors > 0) {
                throw new \RuntimeException("FK/constraint violations: {$errors}. Rollback.");
            }
        });
        $this->info("UPDATE: " . count($toUpdate));
        $this->warn("INSERT отложен: " . count($toInsert));
        return self::SUCCESS;
    }

    /**
     * Diff prod-row vs csv-row по списку writable полей.
     * Игнорирует поля которых нет в csv или равных текущим.
     */
    private function genericDiff(object $prod, array $csv, array $writable): array
    {
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

    private function diffPerson(object $prod, array $csv): array
    {
        // person.text-only schema → city/taxResidency можно безопасно
        // обновлять (нет FK). Не трогаем: id, email (matching key),
        // password, role, isAuthorization, isBlocked, status.
        $writable = [
            'lastName', 'firstName', 'patronymic', 'phone', 'nicTG',
            'birthDate', 'gender', 'taxResidency', 'city', 'comment',
            'agreement', 'boughtProRost',
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
        // НЕ обновляем city/taxResidency — это FK на city/taxResidency
        // таблицы, в Directual может быть id которого нет в prod (например
        // Сочи=342 в Directual, в нашей city.id отсутствует) → 23503 FK
        // violation. Если эти таблицы надо синхронизировать — отдельно,
        // отдельной командой по справочникам.
        $writable = [
            'lastName', 'firstName', 'patronymic', 'phone', 'nicTG',
            'birthDate', 'gender', 'comment',
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
