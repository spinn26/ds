<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Чистка невидимого мусора в ФИО: краевые пробелы, повторные пробелы внутри и
 * неразрывный пробел (NBSP, chr(160)).
 *
 * Зачем: глазами такие имена неотличимы, а сравниваются как разные. Кейс
 * 13.08.2026 — «Гребенкин Николай␣␣Михайлович» и «Гребенкин Николай␣Михайлович»
 * попадали в одну группу дублей (ключ группы схлопывал пробелы) и тут же
 * получали ярлык «ФИО разные — вероятно, семья», из-за чего настоящие дубли
 * прятались за фильтром «только одинаковые ФИО».
 *
 * По умолчанию только показывает; пишет с --apply.
 */
class NamesNormalizeWhitespace extends Command
{
    protected $signature = 'names:normalize-whitespace
        {--apply : записать изменения (без флага — только показать)}';

    protected $description = 'Убрать краевые/повторные/неразрывные пробелы в ФИО (client, consultant, WebUser)';

    /** @var list<array{table: string, column: string}> */
    private const TARGETS = [
        ['table' => 'client', 'column' => 'personName'],
        ['table' => 'client', 'column' => 'consultantName'],
        ['table' => 'consultant', 'column' => 'personName'],
        ['table' => 'consultant', 'column' => 'inviterName'],
        ['table' => 'WebUser', 'column' => 'firstName'],
        ['table' => 'WebUser', 'column' => 'lastName'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        $total = 0;

        foreach (self::TARGETS as $t) {
            $q = '"' . $t['table'] . '"';
            $c = '"' . $t['column'] . '"';
            // NBSP → пробел, затем схлопывание кратных пробелов и обрезка краёв.
            $norm = "btrim(regexp_replace(replace({$c}, chr(160), ' '), '\s+', ' ', 'g'))";

            $cnt = (int) DB::table(DB::raw($q))
                ->whereRaw("{$c} IS DISTINCT FROM {$norm}")
                ->count();

            $updated = 0;
            if ($apply && $cnt > 0) {
                $updated = DB::update("UPDATE {$q} SET {$c} = {$norm} WHERE {$c} IS DISTINCT FROM {$norm}");
            }

            $rows[] = [$t['table'] . '.' . $t['column'], $cnt, $apply ? $updated : '—'];
            $total += $cnt;
        }

        $this->table(['поле', 'с мусором', 'обновлено'], $rows);

        if (! $apply) {
            $this->line("Всего строк к правке: {$total}. Запись: --apply");
        }

        return self::SUCCESS;
    }
}
