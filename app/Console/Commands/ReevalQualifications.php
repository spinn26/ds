<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Промоут-онли переоценка уровня квалификации по НГП.
 *
 * Платформа не повышает `consultant.status_and_lvl` по накоплению НГП
 * автоматически (см. спеку/заметки). Эта команда добирает пропущенные
 * повышения: у кого последний снимок `qualificationLog` показывает уровень
 * ниже положенного по его НГП (или ниже легаси-грандфазера status_and_lvl),
 * поднимает уровень — ТОЛЬКО вверх.
 *
 * Что пишем:
 *   - последний снимок qualificationLog: nominalLevel = calculationLevel = target
 *     (снимок конца месяца → CommissionCalculator берёт его для СЛЕДУЮЩЕГО
 *     месяца, т.е. новый % с 1-го числа след. месяца — по правилу спеки, текущий
 *     месяц ретроактивно не меняется);
 *   - consultant.status_and_lvl = target (чтобы дашборды/пул/штрафы видели уровень).
 *
 * target = max(уровень_по_НГП, status_and_lvl). НГП берём из ЛОГА
 * (qualificationLog.groupVolumeCumulative — истина; consultant-колонка бывает
 * устаревшей). Пустые уровни (cur < 1, НГП=0, нет легаси-уровня) НЕ трогаем.
 *
 * По умолчанию dry-run — печатает список. Применение — флагом --apply.
 * ⚠ Меняет комиссии/пул со следующего месяца. Снимать бэкап БД ДО --apply.
 */
class ReevalQualifications extends Command
{
    protected $signature = 'partners:reeval-qualifications
        {--apply : применить (по умолчанию dry-run — только показать)}
        {--promote-only : прежнее поведение — только повышать, легаси-уровень из карточки не опускать}';

    protected $description = 'Переоценка квалификации по НГП: приводит уровень последнего снимка и карточки к порогам status_levels. Пустые уровни не трогает.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $promoteOnly = (bool) $this->option('promote-only');
        $rows = \App\Services\QualificationReeval::candidates($promoteOnly);

        if (empty($rows)) {
            $this->info('Расхождений нет — все уровни соответствуют НГП.');

            return self::SUCCESS;
        }

        $this->table(
            ['ql_id', 'consultant', 'ФИО', 'НГП', 'уровень (лог)', 'по НГП', 'легаси', '→ target'],
            array_map(fn ($r) => [
                $r->ql_id, $r->consultant, $r->name, $r->ngp,
                $r->cur_lvl, $r->ngp_lvl, $r->legacy_lvl, $r->target,
            ], $rows)
        );
        $up = count(array_filter($rows, fn ($r) => $r->target > $r->cur_lvl));
        $down = count($rows) - $up;
        $this->info(count($rows) . " партнёров к изменению: повышений {$up}, понижений {$down}.");
        if ($down > 0) {
            $this->warn('Понижения затрагивают % группового бонуса со следующего месяца. --promote-only отключает их.');
        }

        if (! $apply) {
            $this->warn('DRY-RUN — ничего не изменено. Для применения: --apply (снимите бэкап БД до этого).');

            return self::SUCCESS;
        }

        $n = \App\Services\QualificationReeval::apply($rows);
        $this->info("Применено изменений: {$n}. Новый % действует со следующего месяца.");

        return self::SUCCESS;
    }
}
