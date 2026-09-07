<?php

namespace App\Services\Reports;

use App\Support\Age;
use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.3 — реестр выплат для бухгалтерии. */
class PaymentRegistryReport extends AbstractReportType
{
    public function key(): string { return 'payment_registry'; }
    public function headers(): array
    {
        // ⚠ «Налоговый режим» и «Дата рождения» добавлены В КОНЕЦ намеренно
        // (запрос от 03.09.2026): у бухгалтерии есть сводные и шаблоны,
        // завязанные на позиции колонок, и вставка в середину сдвинула бы всё
        // вправо. Смысловое место — рядом с ИНН и ФИО; перенесём, когда будет
        // ясно, что ничего не сломается.
        return ['ФИО', 'Активность',
            'Сальдо', 'Начислено', 'Прочее', 'Пул',
            'Итого начислено', 'Итого к оплате', 'Оплачено',
            'ИП', 'ОГРН', 'ИНН', 'Адрес', 'Верифицировано',
            'Р/с', 'К/с', 'БИК', 'Банк',
            'Налоговый режим', 'Дата рождения'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $consultants = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->get(['id', 'personName', 'activity', 'webUser', 'birthDate']);
        $names = DB::table('directory_of_activities')->pluck('name', 'id');

        // «Начислено» = только транзакционные комиссии (transaction IS NOT NULL).
        // Раньше суммировались все типы, и legacy nonTransactional попадал и сюда,
        // и (по канону снимка rebuildBalance) должен быть в «Прочее» → расхождение.
        $accruedByCons = DB::table('commission')
            ->whereNull('deletedAt')
            ->whereNotNull('transaction')
            ->whereBetween('date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE("amountRUB", 0)) as accrued'))
            ->groupBy('consultant')->pluck('accrued', 'consultant');

        // «Прочее» = ручные other_accruals + legacy nonTransactional (commission
        // без transaction) — единообразно с UI-реестром и снимком consultantBalance
        // (accruedNonTransactional). Раньше экспорт legacy-слой терял.
        $otherByCons = DB::table('other_accruals')
            ->whereBetween('accrual_date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE(amount, 0)) as other'))
            ->groupBy('consultant')->pluck('other', 'consultant');

        $legacyOtherByCons = DB::table('commission')
            ->whereNull('deletedAt')
            ->whereNull('transaction')
            ->whereBetween('date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE("amountRUB", 0)) as other'))
            ->groupBy('consultant')->pluck('other', 'consultant');

        $poolByCons = DB::table('poolLog')
            ->whereBetween('date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE("poolBonus", 0)) as pool'))
            ->groupBy('consultant')->pluck('pool', 'consultant');

        $paidByCons = DB::table('consultantPayment as cp')
            ->join('consultantBalance as cb', 'cb.id', '=', 'cp.consultantBalance')
            ->whereBetween('cp.paymentDate', [$from, $to])
            ->whereIn('cp.status', [1, 2])
            ->select('cb.consultant', DB::raw('SUM(COALESCE(cp.amount, 0)) as paid'))
            ->groupBy('cb.consultant')->pluck('paid', 'consultant');

        // Сальдо = входящий остаток с прошлых периодов (per spec ✅Отчет Реестр Выплат):
        // остаток последнего снимка до начала отчётного периода ПЛЮС ручные
        // корректировки прошлых месяцев, которых в снимке нет. Считается тем же
        // IncomingBalance, что и колонка «Сальдо» в реестре: расхождение между
        // экраном и выгрузкой здесь уже случалось, держим один источник.
        $balanceFromMonth = \Carbon\Carbon::parse($from)->format('Y-m');
        $balanceByCons = \App\Services\IncomingBalance::forMonth($balanceFromMonth);

        // Реальные имена таблиц в legacy-схеме: `requisites` (юр) + `bankrequisites` (банк).
        // bankrequisites привязаны к requisites через requisites.id (FK), не к consultant напрямую.
        // tax_regime заполняется при проверке ИНН (Checko/DaData) и у части
        // партнёров пуст — в выгрузке это пустая ячейка.
        $reqs = DB::table('requisites')
            ->whereNull('deletedAt')
            ->select(['id', 'consultant', 'individualEntrepreneur', 'ogrn', 'inn', 'address', 'verified', 'tax_regime'])
            ->get()->keyBy('consultant');

        $bankByReq = DB::table('bankrequisites')
            ->select(['requisites', 'accountNumber', 'correspondentAccount', 'bankBik', 'bankName'])
            ->get()->keyBy('requisites');

        // Дата рождения: у партнёра с логином она в WebUser, у остальных — в
        // собственной колонке карточки (там varchar с «18.02.1980»). Age::date
        // приводит оба формата к Y-m-d: иначе в одной колонке Excel окажутся
        // два формата и сортировка по ней работать не будет.
        $webUserIds = $consultants->pluck('webUser')->filter()->unique();
        $birthByWebUser = $webUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webUserIds)->pluck('birthDate', 'id')
            : collect();

        $rows = [];
        foreach ($consultants as $c) {
            $accrued = (float) ($accruedByCons[$c->id] ?? 0);
            $other = (float) ($otherByCons[$c->id] ?? 0) + (float) ($legacyOtherByCons[$c->id] ?? 0);
            $pool = (float) ($poolByCons[$c->id] ?? 0);
            $paid = (float) ($paidByCons[$c->id] ?? 0);
            $balance = (float) ($balanceByCons[$c->id] ?? 0);
            if (! $accrued && ! $other && ! $pool && ! $paid && ! $balance) continue;

            $totalAccrued = $accrued + $other + $pool;
            $totalPayable = $balance + $totalAccrued;
            $r = $reqs[$c->id] ?? null;
            $b = $r ? ($bankByReq[$r->id] ?? null) : null;
            $birthRaw = ($c->webUser ? ($birthByWebUser[$c->webUser] ?? null) : null) ?: $c->birthDate;
            $rows[] = [
                $c->personName,
                $c->activity ? ($names[$c->activity] ?? '') : '',
                $this->n($balance), $this->n($accrued), $this->n($other), $this->n($pool),
                $this->n($totalAccrued), $this->n($totalPayable), $this->n($paid),
                $r?->individualEntrepreneur ?? '', $r?->ogrn ?? '',
                $r?->inn ?? '', $r?->address ?? '',
                $r?->verified ? 'true' : 'false',
                $b?->accountNumber ?? '', $b?->correspondentAccount ?? '',
                $b?->bankBik ?? '', $b?->bankName ?? '',
                $r?->tax_regime ?? '',
                Age::date($birthRaw) ?? '',
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0] ?? '', $b[0] ?? ''));
        return $rows;
    }
}
