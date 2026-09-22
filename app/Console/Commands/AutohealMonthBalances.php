<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\BalanceDriftInspector;
use App\Services\BalanceHealPolicy;
use App\Services\CommissionCalculator;
use App\Services\PeriodFreezeService;
use App\Services\TelegramNotifier;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сторож снимка начислений с автопочинкой.
 *
 * Раньше цикл был такой: сторож нашёл расхождение → Telegram «Починка: php
 * artisan commission:resync-balances» → кто-то (когда-нибудь) зашёл на сервер
 * и выполнил. Пока не выполнил — реестр выплат показывает снимок, а не живые
 * комиссии, и партнёр видит не ту сумму.
 *
 * Теперь ровно то же действие делает команда: нашла расхождение → сама
 * пересобрала снимок → отчиталась ФАКТОМ («было → стало»), а не заданием.
 *
 * Почему это не нарушает запрет авто-пересчётов (2026-06-05): пересборка
 * снимка ничего не считает. `resyncMonth()` переносит в `consultantBalance`
 * уже посчитанные суммы из `commission` и `poolLog` — те самые, что партнёр
 * видит в своих комиссиях. Статусы, квалификации, Отрыв/ОП и пул по-прежнему
 * считаются ТОЛЬКО по кнопке руководителем расчётов.
 *
 * Где автомат останавливается и зовёт человека — см. BalanceHealPolicy.
 *
 *   php artisan finance:autoheal-balances                        # текущий + прошлый
 *   php artisan finance:autoheal-balances --month=2026-09 --dry-run
 */
class AutohealMonthBalances extends Command
{
    protected $signature = 'finance:autoheal-balances
        {--month=* : период YYYY-MM (по умолчанию текущий и прошлый)}
        {--dry-run : показать решение, ничего не писать и не отправлять}
        {--force : снять порог суммы (закрытый месяц и дубли всё равно не чиним)}
        {--no-telegram : не отправлять уведомления}';

    protected $description = 'Найти расхождение снимка consultantBalance и пересобрать его автоматически';

    /** Сколько автопочинок подряд считаем нормой, прежде чем звать на разбор. */
    private const REPEAT_ALARM = 3;

    public function __construct(
        private readonly BalanceDriftInspector $inspector,
        private readonly BalanceHealPolicy $policy,
        private readonly CommissionCalculator $calculator,
        private readonly PeriodFreezeService $freeze,
        private readonly TelegramNotifier $telegram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $months = $this->months();
        if ($months === null) {
            return self::FAILURE;
        }

        foreach ($months as $ym) {
            $this->processMonth($ym);
        }

        return self::SUCCESS;
    }

    /** @return list<string>|null null — аргументы кривые, выходим с ошибкой. */
    private function months(): ?array
    {
        /** @var list<string> $given */
        $given = (array) $this->option('month');
        if (! $given) {
            // Текущий (свежие правки) и прошлый (закрывается и уезжает
            // в выплаты — там ошибка дороже всего).
            return [now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')];
        }

        foreach ($given as $ym) {
            if (! preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $this->error("Период должен быть в формате YYYY-MM, получено: {$ym}");

                return null;
            }
        }

        return array_values($given);
    }

    private function processMonth(string $ym): void
    {
        $report = $this->inspector->inspect($ym);
        $facts = [
            'drifted' => count($report['drifted']),
            'total' => $report['total'],
            'dupPartners' => $report['dupPartners'],
        ];

        $decision = $this->policy->decide($facts, [
            'enabled' => (bool) SystemSetting::value('finance.balance_autoheal', true),
            'historical' => CommissionCalculator::isHistorical($ym),
            'future' => $ym > now()->format('Y-m'),
            'frozen' => $this->freeze->isFrozen((int) substr($ym, 0, 4), (int) substr($ym, 5, 2)),
            'limit' => (float) SystemSetting::value('finance.balance_autoheal_limit', 100000),
            'force' => (bool) $this->option('force'),
        ]);

        $this->line(sprintf('%s: расхождений=%d на %s ₽, дубли у %d, пул у %d → %s (%s)',
            $ym, $facts['drifted'], $this->n($facts['total']), $facts['dupPartners'],
            $report['poolDrift'], $decision['action'], $decision['reason']));

        if ($this->option('dry-run')) {
            return;
        }

        if ($decision['action'] === BalanceHealPolicy::NOTHING) {
            $this->onClean($ym);
        } elseif ($decision['action'] === BalanceHealPolicy::BLOCK) {
            $this->onBlocked($ym, $report, $decision['reason']);
        } else {
            $this->heal($ym, $report);
        }
    }

    /**
     * Пересборка снимка + проверка, что она действительно помогла.
     *
     * @param  array{ym:string, rows:int, drifted:list<array{row:object, accrual:float, pool:float, dups:int}>, total:float, poolDrift:int, dupPartners:int}  $report
     */
    private function heal(string $ym, array $report): void
    {
        // Тот же lock-неймспейс, что у финализации и кнопки «Пересчитать» в
        // реестре: они пересобирают снимок тем же методом, одновременный
        // запуск переписывал бы одни строки. Не взяли лок — не беда:
        // расхождение никуда не денется, починит следующий тик.
        $lock = Cache::lock("finalize:apply:{$ym}", 600);
        if (! $lock->get()) {
            $this->warn("  {$ym}: месяц уже пересчитывается — пропускаем до следующего запуска.");

            return;
        }

        $before = $this->inspector->snapshotTotals($ym);
        try {
            $result = $this->calculator->resyncMonth($ym);
        } catch (\Throwable $e) {
            Log::error('autoheal-balances: resyncMonth failed', ['ym' => $ym, 'error' => $e->getMessage()]);
            $this->error("  {$ym}: пересборка упала — {$e->getMessage()}");
            $this->send([
                '🚨 <b>Автопочинка снимка упала</b>',
                '',
                "Период: <b>{$ym}</b>",
                'Ошибка: ' . mb_substr($e->getMessage(), 0, 200),
                '',
                "Чинить руками: <code>php artisan commission:resync-balances --month={$ym}</code>",
            ]);

            return;
        } finally {
            $lock->release();
        }

        $after = $this->inspector->snapshotTotals($ym);
        $delta = $after['accrued'] - $before['accrued'];

        // Осталось ли что-то после пересборки: если да — дрейф не в снимке,
        // и человеку нужно знать, что автомат исчерпал свои возможности.
        $residual = $this->inspector->inspect($ym);

        Audit::log('balance_autoheal', 'consultantBalance', $ym, [
            'driftedBefore' => count($report['drifted']),
            'driftedAfter' => count($residual['drifted']),
            'accruedBefore' => $before['accrued'],
            'accruedAfter' => $after['accrued'],
            'poolAfter' => $after['pool'],
            'consultants' => $result['consultants'],
        ]);

        $this->info(sprintf('  %s: пересобрано %d партнёров, начислено %s → %s ₽ (Δ %s), осталось расхождений %d',
            $ym, $result['consultants'], $this->n($before['accrued']), $this->n($after['accrued']),
            $this->n($delta), count($residual['drifted'])));

        // Снимок сошёлся, и в деньгах ничего не сдвинулось (дрейф успели
        // починить кнопкой между сверкой и пересборкой) — молчим.
        if (abs($delta) <= BalanceDriftInspector::ACCRUAL_EPS && ! $residual['drifted']) {
            $this->forget($ym);

            return;
        }

        $repeat = $this->countHeal($ym);

        $lines = [
            '🔧 <b>Снимок начислений пересобран автоматически</b>',
            '',
            "Период: <b>{$ym}</b>",
            'Партнёров с расхождением: <b>'.count($report['drifted']).'</b>',
            'Начислено: <b>'.$this->n($before['accrued']).' → '.$this->n($after['accrued']).' ₽</b>'
                .' (Δ '.$this->n($delta).')',
        ];
        if ($report['poolDrift'] > 0) {
            $lines[] = 'Пул подтянут у: <b>'.$report['poolDrift'].'</b>';
        }

        $lines[] = '';
        foreach (array_slice($report['drifted'], 0, 3) as $d) {
            $lines[] = '• '.$this->name($d['row']).' — '.$this->n($d['accrual']).' ₽';
        }

        if ($residual['drifted']) {
            $lines[] = '';
            $lines[] = '🚨 После пересборки осталось расхождение у <b>'.count($residual['drifted'])
                .'</b> партнёров на <b>'.$this->n($residual['total']).' ₽</b> — снимок не виноват, нужен разбор.';
            $lines[] = "Разбор: <code>php artisan finance:diagnose-month {$ym}</code>";
        } elseif ($repeat >= self::REPEAT_ALARM) {
            $lines[] = '';
            $lines[] = "⚠️ Это {$repeat}-я автопочинка этого периода за 30 дней: снимок расходится снова и снова — надо искать источник дрейфа, а не чинить следствие.";
        } else {
            $lines[] = '';
            $lines[] = 'Реестр выплат снова показывает актуальные комиссии. Ручных действий не требуется.';
        }

        $this->send($lines);
        $this->remember($ym, 'clean');
    }

    /**
     * Расхождение есть, но чинить нельзя. Шлём по ФРОНТУ, а не по факту:
     * ежедневное «всё ещё расходится» люди перестают читать через три дня.
     *
     * @param  array{ym:string, rows:int, drifted:list<array{row:object, accrual:float, pool:float, dups:int}>, total:float, poolDrift:int, dupPartners:int}  $report
     */
    private function onBlocked(string $ym, array $report, string $reason): void
    {
        $fingerprint = count($report['drifted']).':'.round($report['total'])
            .':'.$report['poolDrift'].':'.$report['dupPartners'].':'.$reason;

        if ($this->remember($ym, $fingerprint) === $fingerprint) {
            $this->line('  Уведомление не отправлено: картина не изменилась с прошлого запуска.');

            return;
        }

        $lines = [
            '⚠️ <b>Расхождение снимка начислений</b>',
            '',
            "Период: <b>{$ym}</b>",
            'Партнёров с расхождением: <b>'.count($report['drifted']).'</b>',
            'Суммарно: <b>'.$this->n($report['total']).' ₽</b>',
        ];
        if ($report['poolDrift'] > 0) {
            $lines[] = 'Пул разошёлся у: <b>'.$report['poolDrift'].'</b>';
        }
        if ($report['dupPartners'] > 0) {
            $lines[] = 'Дубли commission у: <b>'.$report['dupPartners'].'</b>';
        }

        $lines[] = '';
        foreach (array_slice($report['drifted'], 0, 3) as $d) {
            $lines[] = '• '.$this->name($d['row']).' — '.$this->n($d['accrual']).' ₽';
        }

        $lines[] = '';
        $lines[] = "🚫 Автопочинка не применена: {$reason}.";
        $lines[] = 'Реестр выплат показывает снимок, а не текущие комиссии.';
        $lines[] = "Починка вручную: <code>php artisan commission:resync-balances --month={$ym}</code>";

        $this->send($lines);
    }

    /** Расхождения нет. Отчитываемся, только если в прошлый раз оно было. */
    private function onClean(string $ym): void
    {
        $previous = $this->remember($ym, 'clean');
        if ($previous !== null && $previous !== 'clean') {
            $this->send([
                '✅ <b>Снимок начислений сошёлся</b>',
                '',
                "Период: {$ym}",
                'Расхождений больше нет.',
            ]);
        }
    }

    /**
     * Записать слепок состояния и вернуть предыдущий.
     *
     * Держим дольше интервала запуска: истёкший кэш выглядит как
     * «картина изменилась» и шлёт повтор на ровном месте.
     */
    private function remember(string $ym, string $fingerprint): ?string
    {
        $key = 'balance-autoheal:'.$ym;
        $previous = Cache::get($key);
        Cache::put($key, $fingerprint, now()->addDays(7));

        return is_string($previous) ? $previous : null;
    }

    private function forget(string $ym): void
    {
        Cache::put('balance-autoheal:'.$ym, 'clean', now()->addDays(7));
    }

    /** Счётчик автопочинок месяца за 30 дней — чтобы заметить возвращающийся дрейф. */
    private function countHeal(string $ym): int
    {
        $key = 'balance-autoheal-count:'.$ym;
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addDays(30));

        return $count;
    }

    /** @param  list<string>  $lines */
    private function send(array $lines): void
    {
        if ($this->option('no-telegram')) {
            return;
        }
        $this->telegram->send(implode("\n", $lines));
    }

    private function name(object $row): string
    {
        return mb_substr((string) ($row->personName ?? ('ID '.$row->consultant)), 0, 30);
    }

    private function n(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }
}
