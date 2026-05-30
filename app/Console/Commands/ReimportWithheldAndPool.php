<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Догружает в commission поля withheld* (Directual-расчёт finalization)
 * и таблицу poolLog (исторические выплаты пула) из CSV.
 *
 * Логика:
 *   1) commission.withheld* — UPDATE по id из CSV. Сохраняет точное
 *      состояние финализации как у Directual (на момент 29 мая).
 *      Если ранее наш finalize:apply уже что-то проставил — CSV-значения
 *      перезатирают.
 *   2) poolLog — INSERT (через insertOrIgnore по composite-key
 *      consultant + date). Резолв FK consultant через participantCode.
 *
 * Использовать ОДИН раз после успешного reimport-исторических-данных,
 * чтобы платформа показывала исторические выплаты как они есть в
 * Directual, а не как пересчитала наша новая формула.
 */
class ReimportWithheldAndPool extends Command
{
    protected $signature = 'db:reimport-withheld-pool
        {--csv-dir=storage/app/directual_2026-05-29 : Папка с CSV}
        {--apply : Реально применить (без флага — dry-run)}';

    protected $description = 'Догрузить withheld* в commission + poolLog из CSV';

    public function handle(): int
    {
        $dir = base_path((string) $this->option('csv-dir'));
        $apply = (bool) $this->option('apply');
        $commissionCsv = "{$dir}/commission.csv";
        $poolCsv = "{$dir}/poolLog.csv";

        foreach ([$commissionCsv, $poolCsv] as $p) {
            if (! is_file($p)) {
                $this->error("Файл не найден: {$p}");
                return self::FAILURE;
            }
        }

        // === 1. commission.withheld* UPDATE по CSV id ===
        $this->info('=== commission.withheld* UPDATE ===');
        DB::statement("SET statement_timeout = '600s'");

        $existingIds = [];
        DB::table('commission')->orderBy('id')->each(function ($row) use (&$existingIds) {
            $existingIds[(int) $row->id] = true;
        });
        $this->line("PROD commission rows: " . count($existingIds));

        $toUpdate = [];
        $skipped = 0;
        foreach ($this->csvIter($commissionCsv) as $r) {
            $id = (int) ($r['id'] ?? 0);
            if (! isset($existingIds[$id])) { $skipped++; continue; }
            $wp = $this->toFloat($r['withheldPercent'] ?? null);
            $wfc = $this->toFloat($r['withheldForCommission'] ?? null);
            $wfg = $this->toFloat($r['withheldForGap'] ?? null);
            if ($wp === null && $wfc === null && $wfg === null) continue;
            $toUpdate[$id] = [
                'withheldPercent'       => $wp,
                'withheldForCommission' => $wfc,
                'withheldForGap'        => $wfg,
            ];
        }
        $this->line("К UPDATE (withheld из CSV): " . count($toUpdate));
        $this->line("Пропущено (id не в prod): " . $skipped);

        if ($apply) {
            $i = 0;
            foreach ($toUpdate as $id => $fields) {
                DB::table('commission')->where('id', $id)->update($fields);
                if (++$i % 1000 === 0) $this->line("  updated {$i}…");
            }
            $this->info("commission.withheld* UPDATED: {$i}");
        }

        // === 2. poolLog INSERT ===
        $this->info('');
        $this->info('=== poolLog INSERT ===');

        // Резолв consultant: CSV id → prod id через participantCode
        $consMap = $this->buildConsultantMap($dir);
        $this->line("Consultant map: " . count($consMap));

        // Существующие poolLog по composite-key (consultant, date)
        $existing = [];
        DB::table('poolLog')->orderBy('id')->each(function ($row) use (&$existing) {
            $k = $this->plKey((int) $row->consultant, $row->date);
            $existing[$k] = true;
        });
        $this->line("Existing poolLog rows: " . count($existing));

        $insert = [];
        $skipNoCons = 0;
        $skipDup = 0;
        foreach ($this->csvIter($poolCsv) as $r) {
            $consCsv = (int) ($r['consultant'] ?? 0);
            $cons = $consMap[$consCsv] ?? null;
            if (! $cons) { $skipNoCons++; continue; }
            $date = $this->toTs($r['date'] ?? null);
            $k = $this->plKey($cons, $date);
            if (isset($existing[$k])) { $skipDup++; continue; }
            $insert[] = [
                'consultant'       => $cons,
                'poolBonus'        => $this->toFloat($r['poolBonus'] ?? null),
                'networkGroupBonus'=> null, // FK на networkGroupBonus — резолв сложен, пропускаем
                'date'             => $date,
                'createdAt'        => $this->toTs($r['@dateCreated'] ?? $r['createdAt'] ?? null),
            ];
        }
        $this->line("К INSERT: " . count($insert));
        $this->line("Пропущено (no consultant): " . $skipNoCons);
        $this->line("Пропущено (duplicate): " . $skipDup);

        if ($apply && $insert) {
            DB::table('poolLog')->insertOrIgnore($insert);
            $this->info("poolLog INSERTED: " . count($insert));
        }

        if (! $apply) {
            $this->info('Dry-run. --apply для commit.');
        }
        return self::SUCCESS;
    }

    private function buildConsultantMap(string $dir): array
    {
        // CSV consultant.id → participantCode
        $csv = [];
        foreach ($this->csvIter("{$dir}/consultant.csv") as $r) {
            $code = trim((string) ($r['participantCode'] ?? ''));
            if ($code !== '') $csv[(int) $r['id']] = $code;
        }
        // PROD participantCode → id
        $prod = [];
        DB::table('consultant')->whereNotNull('participantCode')
            ->orderBy('id')->each(function ($row) use (&$prod) {
                $code = trim((string) $row->participantCode);
                if ($code !== '' && ! isset($prod[$code])) $prod[$code] = (int) $row->id;
            });
        $out = [];
        foreach ($csv as $csvId => $code) {
            if (isset($prod[$code])) $out[$csvId] = $prod[$code];
        }
        return $out;
    }

    private function plKey(int $cons, ?string $date): string
    {
        $d = $date ? substr($date, 0, 10) : '';
        return "{$cons}|{$d}";
    }

    private function csvIter(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if (! $fh) return;
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $headers = fgetcsv($fh, 0, ';', '"', '\\');
        if (! $headers) { fclose($fh); return; }
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        while (($row = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
            if ($row === [null]) continue;
            $assoc = [];
            foreach ($headers as $i => $h) $assoc[$h] = $row[$i] ?? null;
            yield $assoc;
        }
        fclose($fh);
    }

    private function toFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        return (float) str_replace(',', '.', (string) $v);
    }

    private function toTs(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = (string) $v;
        if (ctype_digit($s) && strlen($s) >= 10) {
            $secs = (int) (strlen($s) >= 13 ? substr($s, 0, 10) : $s);
            return date('Y-m-d H:i:s', $secs);
        }
        return $s;
    }
}
