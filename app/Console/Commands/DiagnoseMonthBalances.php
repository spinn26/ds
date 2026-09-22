<?php

namespace App\Console\Commands;

use App\Services\BalanceDriftInspector;
use App\Services\CommissionCalculator;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only разбор месяца: почему «Итого начислено» расходится с продажами,
 * а «Пул» в реестре нулевой.
 *
 * Печатает по каждому партнёру с расхождением:
 *   снимок consultantBalance vs live SUM(commission) — начисления;
 *   accruedPool vs SUM(poolLog) — пул;
 *   сумму удержаний месяца (отрыв/ОП) и число дублей commission.
 *
 *   php artisan finance:diagnose-month 2026-07
 *   php artisan finance:diagnose-month 2026-07 --consultant=256
 *
 * ⚠ Это инструмент РАЗБОРА для человека. В расписании стоит не он, а
 * `finance:autoheal-balances`: тот же запрос (BalanceDriftInspector), но
 * расхождение он ещё и чинит сам. Здесь же --notify оставлен для ручного
 * «показать картину в чат», без починки.
 */
class DiagnoseMonthBalances extends Command
{
    protected $signature = 'finance:diagnose-month
        {ym : период YYYY-MM}
        {--consultant= : только этот consultant.id}
        {--limit=25 : сколько строк расхождений печатать}
        {--notify : отправить в Telegram, если картина расхождений изменилась}';

    protected $description = 'Показать расхождения снимка consultantBalance с commission/poolLog за месяц (только чтение)';

    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly BalanceDriftInspector $inspector,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ym = (string) $this->argument('ym');
        if (! preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $this->error('Период должен быть в формате YYYY-MM');

            return self::FAILURE;
        }
        if (CommissionCalculator::isHistorical($ym)) {
            $this->warn("Период {$ym} исторический (< ".CommissionCalculator::HISTORICAL_CUTOFF.') — снимок неизменен по правилам расчёта.');
        }

        $only = $this->option('consultant') ? (int) $this->option('consultant') : null;
        $limit = (int) $this->option('limit');

        $report = $this->inspector->inspect($ym, $only);
        $drifted = $report['drifted'];

        $this->info("Период {$ym}: строк — ".$report['rows'].', с расхождением — '.count($drifted)
            .", пул разошёлся у {$report['poolDrift']}, дубли commission у {$report['dupPartners']}");
        $this->table(
            ['ID', 'Партнёр', 'снимок', 'live', 'Δ начисл.', 'пул снимок', 'пул log', 'удержано', 'дубли'],
            array_map(fn ($d) => [
                $d['row']->consultant,
                mb_substr((string) ($d['row']->personName ?? '—'), 0, 28),
                $this->n((float) $d['row']->snap_tx + (float) $d['row']->snap_nontx),
                $this->n((float) $d['row']->live_tx + (float) $d['row']->live_nontx),
                $this->n($d['accrual']),
                $this->n((float) $d['row']->snap_pool),
                $this->n((float) $d['row']->live_pool),
                $this->n((float) $d['row']->gap + (float) $d['row']->op),
                $d['dups'] > 0 ? (string) $d['dups'] : '',
            ], array_slice($drifted, 0, $limit))
        );

        if ($drifted) {
            $this->newLine();
            $this->comment('Δ начисл. > 0 — снимок выше строк commission (в отчёте «Итого начислено» без удержаний).');
            $this->comment("Починка: php artisan commission:resync-balances --month={$ym}");
            $this->comment('Обычно чинить руками не нужно: это делает finance:autoheal-balances по расписанию.');
        }

        if ($this->option('notify')) {
            $this->notify($ym, $report);
        }

        return self::SUCCESS;
    }

    /**
     * Оповещение о дрейфе снимка — по фронту, а не по факту.
     *
     * Расхождение может держаться неделями, и ежедневное «всё ещё
     * расходится» люди перестают читать через три дня. Поэтому отправляем
     * только когда картина ИЗМЕНИЛАСЬ: дрейф появился, вырос, уменьшился или
     * ушёл. Слепок сравнения — число партнёров с расхождением плюс суммарная
     * величина, округлённая до рубля.
     *
     * @param  array{ym:string, rows:int, drifted:list<array{row:\stdClass, accrual:float, pool:float, dups:int}>, total:float, poolDrift:int, dupPartners:int}  $report
     */
    private function notify(string $ym, array $report): void
    {
        $drifted = $report['drifted'];
        $total = $report['total'];

        $fingerprint = count($drifted).':'.round($total).':'.$report['poolDrift'].':'.$report['dupPartners'];
        $cacheKey = 'balance-drift:'.$ym;
        $previous = Cache::get($cacheKey);

        // Держим слепок дольше, чем интервал запуска, иначе истёкший кэш
        // выглядит как «изменилось» и шлёт повтор на ровном месте.
        Cache::put($cacheKey, $fingerprint, now()->addDays(7));

        if ($fingerprint === $previous) {
            $this->line('Уведомление не отправлено: картина не изменилась с прошлого запуска.');

            return;
        }

        if (! $drifted) {
            // Молчим, если и раньше было чисто: первый запуск не должен
            // рапортовать «всё хорошо» по каждому месяцу.
            if ($previous !== null) {
                $this->telegram->send(
                    "✅ <b>Снимок начислений сошёлся</b>\n\nПериод: {$ym}\nРасхождений больше нет."
                );
            }

            return;
        }

        $lines = [
            '⚠️ <b>Расхождение снимка начислений</b>',
            '',
            "Период: <b>{$ym}</b>",
            'Партнёров с расхождением: <b>'.count($drifted).'</b>',
            'Суммарно: <b>'.$this->n($total).' ₽</b>',
        ];
        if ($report['poolDrift'] > 0) {
            $lines[] = 'Пул разошёлся у: <b>'.$report['poolDrift'].'</b>';
        }
        if ($report['dupPartners'] > 0) {
            $lines[] = 'Дубли commission у: <b>'.$report['dupPartners'].'</b>';
        }

        // Три крупнейших — чтобы по сообщению было видно масштаб, а не только факт.
        $lines[] = '';
        foreach (array_slice($drifted, 0, 3) as $d) {
            $name = mb_substr((string) ($d['row']->personName ?? ('ID '.$d['row']->consultant)), 0, 30);
            $lines[] = '• '.$name.' — '.$this->n($d['accrual']).' ₽';
        }

        $lines[] = '';
        $lines[] = 'Реестр выплат показывает снимок, а не текущие комиссии.';
        $lines[] = "Починка: <code>php artisan commission:resync-balances --month={$ym}</code>";

        $this->telegram->send(implode("\n", $lines));
        $this->info('Уведомление отправлено в Telegram.');
    }

    private function n(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }
}
