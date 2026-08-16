<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Превью черновика ручной транзакции: расчёт БЕЗ записи.
 *
 * Вынесено из ManualTransactionController (метод занимал 268 строк плюс
 * четыре помощника, которыми пользовался только он). Код перенесён дословно.
 *
 * ⚠ Превью обязано совпадать с фактическим начислением — по нему оператор
 * решает, фиксировать ли сделку. Отсюда правила, которые нельзя ослаблять
 * без сверки с ManualDraftPreviewTest:
 *   - тариф не найден → нули и признак tariffMissing, а НЕ 100 %: подстановка
 *     ста процентов делала доход ДС равным всей сумме без НДС;
 *   - нет ставки НДС на дату сделки → ошибка строкой, а не расчёт по нулю;
 *   - курс и НДС берутся по дате СДЕЛКИ, не по сегодняшней, иначе превью и
 *     факт разъезжаются;
 *   - в цепочке наставник получает МАРЖУ, планка не опускается
 *     (max с достигнутым процентом), а терминированным не начисляется, хотя
 *     их процент планку всё равно сдвигает.
 */
class ManualDraftPreviewService
{
    public function __construct(
        private readonly CommissionCalculator $calculator,
    ) {}

    /** Превью-расчёт (без записи). Дублирует ключевые шаги CommissionCalculator. */
    /**
     * @param callable $parametersFor загрузчик свойств программы: он нужен и
     *        другим методам контроллера, поэтому остался там
     * @return array<string, mixed>
     */
    public function compute(object $draft, callable $parametersFor): array
    {
        if ($draft->amount === null || $draft->amount === '' || ! $draft->date || ! $draft->contract) {
            return ['ready' => false];
        }

        $contract = DB::table('contract')->where('id', $draft->contract)->first();
        if (! $contract || ! $contract->consultant) return ['ready' => false];

        // Курс — по дате сделки, тем же резолвером, что и при фиксации: иначе
        // превью и факт разъедутся (курс в черновике мог быть проставлен до того,
        // как оператор поставил дату).
        // Превью отдаёт ошибку строкой, а не 500-й: список черновиков
        // сериализуется целиком, и один черновик без курса не должен уносить
        // всю страницу.
        try {
            $rate = \App\Support\CurrencyRates::forDate(
                $draft->currency ? (int) $draft->currency : null,
                $draft->date
            );
        } catch (\RuntimeException $e) {
            return ['ready' => false, 'error' => $e->getMessage()];
        }
        $amountRub = (float) $draft->amount * $rate;

        // НДС — по дате самой транзакции (draft.date), не now(): превью должно
        // совпадать с фактическим начислением по ставке на дату сделки.
        // Нет ставки на дату — превью не «считаем по 0%», а показываем ту же
        // ошибку, которой ответит расчёт: иначе оператор видит завышенный на
        // всю ставку доход ДС и фиксирует сделку вслепую.
        $vatPercent = \App\Support\VatRate::percent($draft->date);
        if ($vatPercent === null) {
            return [
                'ready' => false,
                'error' => 'Не найдена ставка НДС на ' . $draft->date
                    . ' — заведите период в справочнике НДС.',
            ];
        }
        $amountNoVat = $amountRub / (1 + $vatPercent / 100);

        $programRow = $contract->program
            ? DB::table('program')->where('id', $contract->program)->first()
            : null;

        $productRow = $contract->product
            ? DB::table('product')->where('id', $contract->product)->first()
            : null;
        $isMedlife = $productRow && stripos((string) $productRow->name, 'medlife') !== false
            || $productRow && stripos((string) $productRow->name, 'медлайф') !== false;

        // Для продуктов с has_year_kv (EVO, Medlife, Manhattan Trust и др.)
        // Свойство скрыто в UI; parameter выводим из yearKV автоматически.
        // Ищем commissionCalcProperty у программы, чей title содержит нужный год.
        $resolvedParameter = $draft->parameter;
        if (($draft->productHasYearKv ?? false) && $resolvedParameter === null && $draft->yearKV !== null) {
            $yearNum = (int) $draft->yearKV;
            $params = $parametersFor([(int) $contract->program])[$contract->program] ?? [];
            foreach ($params as $p) {
                if (preg_match('/(?<!\d)' . $yearNum . '(?!\d)/', $p['title'])) {
                    $resolvedParameter = (string) $p['id'];
                    break;
                }
            }
        }

        $dsPercent = $this->resolveDsPercent($draft, $contract, $programRow, $isMedlife, $resolvedParameter);

        // Своя комиссия: пользователь сам ввёл сумму ДохДС → %ДС обратным расчётом.
        // Сравнение с нулём по модулю: у сторно и сумма, и доход ДС отрицательные
        // (см. тот же гард в CommissionCalculator) — иначе превью показывало бы 0%.
        $incomeDS = $amountNoVat * $dsPercent / 100;
        if ($draft->customCommission && abs((float) $draft->dsCommissionAbsolute) > 0.000001) {
            $incomeDS = (float) $draft->dsCommissionAbsolute;
            $dsPercent = abs($amountNoVat) > 0.000001 ? round($incomeDS / $amountNoVat * 100, 4) : 0;
        }

        // Тариф не найден ни в одном источнике (и «своя комиссия» его не заменила).
        // Раньше здесь подставлялись 100%: превью показывало красивый расчёт, а при
        // фиксации доход ДС становился равен всей сумме без НДС — завышение в 10-30
        // раз (кейс «Брокер+»). Теперь калькулятор в такой ситуации возвращает
        // ошибку, и превью обязано показать то же самое: нули и явный признак,
        // а не выдуманную ставку.
        $tariffMissing = $dsPercent <= 0;

        // Личный объём (баллы). Срок нужен методу annualized_term (Vantage).
        $points = $this->computePoints($programRow, $amountNoVat, $amountRub, $dsPercent,
            $contract->term !== null ? (float) $contract->term : null);

        // Цепочка наставников: вверх по inviter, маржинальная разница процентов.
        $consultantId = (int) $contract->consultant;

        // Spec ✅Бизнес-логика «Неизвестного консультанта»: 0% и без каскада.
        if ($consultantId === \App\Services\CommissionCalculator::UNKNOWN_CONSULTANT_ID) {
            return [
                'ready' => true,
                'tariffMissing' => $tariffMissing,
                'amountRUB' => round($amountRub, 2),
                'amountNoVat' => round($amountNoVat, 2),
                'vat' => round($amountRub - $amountNoVat, 2),
                'vatPercent' => $vatPercent,
                'dsCommissionPercentage' => round($dsPercent, 4),
                'incomeDS' => round($incomeDS, 2),
                // Доход ДС в валюте — и в ветке неизвестного ФК: сумма та же,
                // просто вся остаётся компании. Без этих полей колонка
                // «Доход ДС (валюта)» у таких строк молча пустовала бы.
                'incomeDSCurrency' => $rate > 0 ? round($incomeDS / $rate, 2) : null,
                'currencySymbol' => $this->currencyInfo($draft->currency ? (int) $draft->currency : null)['symbol'],
                'isForeignCurrency' => $this->currencyInfo($draft->currency ? (int) $draft->currency : null)['isForeign'],
                'currencyRate' => round($rate, 6),
                'personalVolume' => round($points, 4),
                'partnersTotal' => 0,
                'profitDS' => round($incomeDS, 2),
                'chain' => [[
                    'consultantId' => $consultantId,
                    'name' => 'Неизвестный консультант',
                    'percent' => 0,
                    'lp' => round($points, 2),
                    'gp' => 0,
                    'points' => 0,
                    'sum' => 0,
                    'isDirect' => true,
                    'isUnknown' => true,
                ]],
                'unknownConsultant' => true,
            ];
        }

        $chain = $this->buildChain($consultantId, $draft, $points);

        $partnersTotal = array_sum(array_column($chain, 'sum'));
        $profitDS = round($incomeDS - $partnersTotal, 2);

        $usdRate = \App\Support\CurrencyRates::usdForDate($draft->date);
        $incomeDsUsd = $usdRate > 0 ? round($incomeDS / $usdRate, 2) : 0;
        $amountNoVatUsd = $usdRate > 0 ? round($amountNoVat / $usdRate, 2) : 0;

        // Доход ДС в ВАЛЮТЕ контракта (ТЗ 2026-08-07): оператору по валютным
        // сделкам нужен доход в долларах/евро, а не рублёвый эквивалент, чтобы
        // не пересчитывать курс руками. Курс — тот же $rate, которым выше
        // считали amountRUB, поэтому цифры сходятся обратно.
        $currencyInfo = $this->currencyInfo($draft->currency ? (int) $draft->currency : null);
        $incomeDsCurrency = $rate > 0 ? round($incomeDS / $rate, 2) : null;

        return [
            'ready' => true,
            'tariffMissing' => $tariffMissing,
            'amountRUB' => round($amountRub, 2),
            'amountNoVat' => round($amountNoVat, 2),
            'amountNoVatUSD' => $amountNoVatUsd,
            'vat' => round($amountRub - $amountNoVat, 2),
            'vatPercent' => $vatPercent,
            'dsCommissionPercentage' => round($dsPercent, 4),
            'incomeDS' => round($incomeDS, 2),
            'incomeDSUSD' => $incomeDsUsd,
            // Доход ДС в валюте контракта + чем его подписать. isForeign=false
            // для рублёвых — фронт по нему решает, показывать ли колонку.
            'incomeDSCurrency' => $incomeDsCurrency,
            'currencySymbol' => $currencyInfo['symbol'],
            'isForeignCurrency' => $currencyInfo['isForeign'],
            'currencyRate' => round($rate, 6),
            'personalVolume' => round($points, 4),
            'partnersTotal' => round($partnersTotal, 2),
            'profitDS' => $profitDS,
            'chain' => $chain,
        ];
    }

    /**
     * Символ валюты и признак «иностранная» (не рубль). Нужен превью, чтобы
     * подписать доход ДС в валюте и решить, показывать ли его вообще.
     * RUB (id 67) считаем рублём и при пустом значении — импорт Directual
     * оставлял currency=NULL у части рублёвых строк.
     *
     * @return array{symbol:string, isForeign:bool}
     */
    private function currencyInfo(?int $currencyId): array
    {
        $rubId = \App\Support\CurrencyRates::RUB_CURRENCY_ID;
        if ($currencyId === null || $currencyId === $rubId) {
            return ['symbol' => '₽', 'isForeign' => false];
        }

        static $cache = [];
        if (! array_key_exists($currencyId, $cache)) {
            $cache[$currencyId] = DB::table('currency')->where('id', $currencyId)->value('symbol');
        }

        return [
            'symbol' => (string) ($cache[$currencyId] ?: ''),
            'isForeign' => true,
        ];
    }

    private function computePoints(?object $program, float $amountNoVat, float $amountRub, float $dsPercent, ?float $term = null): float
    {
        $method = $program->pointsMethod ?? null;
        $fixed = $program?->fixedCost !== null ? (float) $program->fixedCost : null;
        $min = $program?->pointsMin !== null ? (float) $program->pointsMin : null;
        return match ($method) {
            'cost_div_100' => ($fixed ?? $amountRub) / 100,
            'amount_div_100' => $amountRub / 100,
            'fixed' => (float) ($min ?? 0),
            // Vantage Platinum II: ЛП = взнос × 12 × срок × %ДС / 10000.
            'annualized_term' => $amountRub * 12 * (float) ($term ?? 0) * $dsPercent / 10000,
            // Паритет с CommissionCalculator::computePoints — ЛП от «Дохода ДС
            // без НДС» (amountNoVat), как и default (Медлайф). Раньше брали
            // amountRub (с НДС) → Axevil расходился (фидбек владельца 2026-07-08).
            'amount_x_dsPercent' => $amountNoVat * $dsPercent / 10000,
            default => $amountNoVat * $dsPercent / 10000,
        };
    }

    /**
     * Терминированный (3) / исключённый (5) партнёр не получает начислений.
     * Паритет с CommissionCalculator::isInactiveForCommission. null/прочее —
     * считаем активным (безопаснее начислить, чем ошибочно срезать).
     */
    private function isInactiveActivity(int|string|null $activity): bool
    {
        return in_array((int) $activity, [
            \App\Enums\PartnerActivity::Terminated->value,
            \App\Enums\PartnerActivity::Excluded->value,
        ], true);
    }

    /**
     * Уровень + стартовый % для превью — делегируем в CommissionCalculator,
     * чтобы превью считало ТЕМИ ЖЕ правилами, что и факт (максимум
     * nominalLevel/calculationLevel + стартовый % из настройки). Раньше здесь
     * был свой расчёт (nominalLevel ?? calculationLevel, хардкод 15), из-за
     * чего превью расходилось с начислением.
     */
    private function resolveQual(int $consultantId, ?string $date): array
    {
        return $this->calculator->resolveLevelForPreview($consultantId, $date);
    }

    /**
     * %ДС по приоритету: явный override → наследование у первой транзакции
     * контракта (Medlife) → тариф по свойству → ставка программы → тарифная
     * сетка. Ничего не нашли — остаётся ноль, и это отдельный признак:
     * подставлять сто процентов нельзя, доход ДС становился равен всей сумме
     * без НДС.
     */
    private function resolveDsPercent(object $draft, object $contract, ?object $programRow, bool $isMedlife, $resolvedParameter): float
    {
        // %ДС: override → Medlife: первая транзакция → программа → справочник → 100%
        $dsPercent = (float) ($draft->dsCommissionPercentage ?? 0);

        // Medlife: если override не задан явно — наследуем от первой
        // зафиксированной транзакции на этом контракте (per spec §2.2 «Изменить»).
        if ($dsPercent <= 0 && $isMedlife && $contract->id) {
            $firstTx = DB::table('transaction')
                ->where('contract', $contract->id)
                ->whereNull('deletedAt')
                ->whereNotNull('dsCommissionPercentage')
                ->orderBy('date')
                ->orderBy('id')
                ->first(['dsCommissionPercentage']);
            if ($firstTx && $firstTx->dsCommissionPercentage > 0) {
                $dsPercent = (float) $firstTx->dsCommissionPercentage;
            }
        }

        // Property-specific тариф побеждает scalar program.dsPercent при заданном
        // свойстве — превью=факт (см. CommissionCalculator::calculateInTransaction).
        // Иначе Апфронт (IB) получал бы ставку МФ 30% вместо 1.8%.
        if ($dsPercent <= 0 && $resolvedParameter !== null && $contract->program) {
            $byProperty = \App\Services\CommissionCalculator::resolveLegacyDsCommission(
                (int) $contract->program,
                $contract->term ?? null,
                $resolvedParameter,
                $draft->date ?? null,
            );
            if ($byProperty !== null && $byProperty > 0) {
                $dsPercent = (float) $byProperty;
            }
        }
        if ($dsPercent <= 0 && $programRow && $programRow->dsPercent !== null) {
            $dsPercent = (float) $programRow->dsPercent;
        }
        if ($dsPercent <= 0 && $contract->program) {
            // Fallback без свойства. Тот же резолвер, что в каскаде
            // (program × term × год КВ × дата). $resolvedParameter =
            // commissionCalcProperty.id (для has_year_kv выведен из yearKV выше).
            $dsPercent = (float) (\App\Services\CommissionCalculator::resolveLegacyDsCommission(
                (int) $contract->program,
                $contract->term ?? null,
                $resolvedParameter ?? null,
                $draft->date ?? null,
            ) ?? 0);
        }

        return $dsPercent;
    }

    /**
     * Цепочка выплат: прямой партнёр и наставники вверх по inviter.
     *
     * ⚠ Наставник получает МАРЖУ — разницу процентов, а планка не опускается
     * (max с достигнутым). Терминированным не начисляется, но их процент
     * планку всё равно сдвигает: следующий активный получает свой обычный
     * инкремент, а не расширенный.
     *
     * @return list<array<string, mixed>>
     */
    private function buildChain(int $consultantId, object $draft, float $points): array
    {
        $directQual = $this->resolveQual($consultantId, $draft->date);
        $directPercent = $directQual['percent'];

        // Терминированного (3) / исключённого (5) прямого партнёра НЕ начисляем —
        // паритет с CommissionCalculator: его «доля» остаётся у компании, но
        // проценты/ЛП посчитаны как база для каскада вверх. Иначе превью
        // показывало комиссию терминированному (кейс Шефер А.П., activity=3).
        $directRow = DB::table('consultant')->where('id', $consultantId)->first();
        $directInactive = $this->isInactiveActivity($directRow->activity ?? null);

        $chain = [];
        $chain[] = [
            'consultantId' => $consultantId,
            'name' => $directRow->personName ?? null,
            'percent' => $directPercent,
            'lp' => round($points, 2),       // ЛП у прямого партнёра
            'gp' => 0,                       // ГП у прямого = 0 (его собственная продажа не ГП)
            'points' => $directInactive ? 0 : round($points * $directPercent / 100, 2),
            'sum' => $directInactive ? 0 : round($points * $directPercent, 2),
            'isDirect' => true,
            'inactive' => $directInactive,
        ];

        $current = $consultantId;
        $prevPercent = $directPercent;
        $visited = [$consultantId];
        for ($i = 0; $i < 20; $i++) {
            $row = DB::table('consultant')->where('id', $current)->first();
            $inviterId = $row->inviter ?? null;
            if (! $inviterId || in_array($inviterId, $visited)) break;
            $visited[] = $inviterId;

            $inviter = DB::table('consultant')->where('id', $inviterId)->first();
            if (! $inviter) break;

            $invQual = $this->resolveQual($inviterId, $draft->date);
            $margin = $invQual['percent'] - $prevPercent;

            // Терминированного/исключённого наставника не начисляем (паритет с
            // CommissionCalculator): маржа не выплачивается, его «слой»
            // поглощается компанией. prevPercent всё равно сдвигаем на его % —
            // следующий активный наставник получает свой обычный инкремент.
            $invInactive = $this->isInactiveActivity($inviter->activity ?? null);
            $paid = $margin > 0 && ! $invInactive;

            $chain[] = [
                'consultantId' => $inviterId,
                'name' => $inviter->personName,
                'percent' => $invQual['percent'],
                'lp' => 0,                       // ЛП у наставника = 0 (продажа не его)
                'gp' => round($points, 2),       // ГП у наставника = объём, поднявшийся снизу
                'points' => $paid ? round($points * $margin / 100, 2) : 0,
                'sum' => $paid ? round($points * $margin, 2) : 0,
                'isDirect' => false,
                'inactive' => $invInactive,
            ];

            $prevPercent = max($prevPercent, $invQual['percent']);
            $current = $inviterId;
        }


        return $chain;
    }
}
