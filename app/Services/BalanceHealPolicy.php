<?php

namespace App\Services;

/**
 * Правило: чинить ли расхождение снимка `consultantBalance` автоматически.
 *
 * Вынесено отдельным классом без БД — решение о ДЕНЬГАХ должно читаться одним
 * экраном и проверяться тестом (tests/Unit/BalanceHealPolicyTest), а не
 * собираться по ифам внутри команды.
 *
 * Автопочинка — это `CommissionCalculator::resyncMonth()`: она НЕ считает
 * деньги заново (запрет авто-пересчётов от 2026-06-05 не нарушается), а
 * переносит в снимок уже посчитанные суммы из `commission` и `poolLog`.
 * Поэтому по умолчанию чинить безопасно — но есть случаи, где «перенести как
 * есть» означает узаконить ошибку, и там нужен человек:
 *
 *   • закрытый месяц (period_closures) — снимок уже ушёл в выплаты, молча
 *     менять «Начислено» задним числом нельзя (инвариант заморозки периода);
 *   • дубли в `commission` — ресинк перенесёт в реестр задвоенную сумму, и
 *     партнёру заплатят дважды. Сначала разбирают дубли, потом чинят снимок;
 *   • расхождение выше порога — 27.08.2026 фантомный период раздулся до
 *     4,4 млн ₽; автомат такого масштаба не пишет, а зовёт человека. Порог правится
 *     в админке (finance.balance_autoheal_limit), `--force` его снимает.
 */
class BalanceHealPolicy
{
    /** Расхождения нет — делать нечего. */
    public const NOTHING = 'nothing';

    /** Чиним: пересобираем снимок из commission/poolLog. */
    public const HEAL = 'heal';

    /** Расхождение есть, но автомат к нему не прикасается — нужен человек. */
    public const BLOCK = 'block';

    /**
     * @param  array{drifted:int, total:float, dupPartners:int}  $facts  что показала сверка
     * @param  array{enabled:bool, historical:bool, future:bool, frozen:bool, limit:float, force:bool}  $ctx
     * @return array{action:string, reason:string}
     */
    public function decide(array $facts, array $ctx): array
    {
        if ($facts['drifted'] === 0) {
            return ['action' => self::NOTHING, 'reason' => 'расхождений нет'];
        }

        if ($ctx['historical']) {
            return [
                'action' => self::BLOCK,
                'reason' => 'период исторический (< ' . CommissionCalculator::HISTORICAL_CUTOFF . ') — снимок неизменен',
            ];
        }

        if ($ctx['future']) {
            return ['action' => self::BLOCK, 'reason' => 'период ещё не наступил — пересобирать нечего'];
        }

        if (! $ctx['enabled']) {
            return ['action' => self::BLOCK, 'reason' => 'автопочинка выключена настройкой finance.balance_autoheal'];
        }

        if ($ctx['frozen']) {
            return [
                'action' => self::BLOCK,
                'reason' => 'месяц закрыт — сначала разморозка админом, потом commission:resync-balances',
            ];
        }

        if ($facts['dupPartners'] > 0) {
            return [
                'action' => self::BLOCK,
                'reason' => 'дубли commission у ' . $facts['dupPartners'] . ' партнёров — ресинк перенёс бы в реестр задвоенную сумму',
            ];
        }

        if (! $ctx['force'] && $facts['total'] > $ctx['limit']) {
            return [
                'action' => self::BLOCK,
                'reason' => 'расхождение ' . number_format($facts['total'], 2, '.', ' ')
                    . ' ₽ выше порога ' . number_format($ctx['limit'], 2, '.', ' ') . ' ₽',
            ];
        }

        return ['action' => self::HEAL, 'reason' => 'снимок пересобирается из commission/poolLog'];
    }
}
