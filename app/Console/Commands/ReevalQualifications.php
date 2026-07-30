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
    protected $signature = 'partners:reeval-qualifications {--apply : применить (по умолчанию dry-run — только показать)}';

    protected $description = 'Промоут-онли переоценка квалификации по НГП: добирает пропущенные повышения (последний снимок лога + status_and_lvl). Пустые уровни не трогает.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = \App\Services\QualificationReeval::candidates();

        if (empty($rows)) {
            $this->info('Нет партнёров к повышению — все уровни соответствуют НГП.');

            return self::SUCCESS;
        }

        $this->table(
            ['ql_id', 'consultant', 'ФИО', 'НГП', 'уровень (лог)', 'по НГП', 'легаси', '→ target'],
            array_map(fn ($r) => [
                $r->ql_id, $r->consultant, $r->name, $r->ngp,
                $r->cur_lvl, $r->ngp_lvl, $r->legacy_lvl, $r->target,
            ], $rows)
        );
        $this->info(count($rows) . ' партнёров к повышению.');

        if (! $apply) {
            $this->warn('DRY-RUN — ничего не изменено. Для применения: --apply (снимите бэкап БД до этого).');

            return self::SUCCESS;
        }

        $n = \App\Services\QualificationReeval::apply($rows);
        $this->info("Применено: {$n} повышений. Новый % действует со следующего месяца.");

        return self::SUCCESS;
    }
}
