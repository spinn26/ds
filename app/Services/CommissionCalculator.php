<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Каскадный расчёт комиссий по MLM-структуре.
 *
 * Алгоритм:
 * 1. Для транзакции найти контракт → консультанта
 * 2. Рассчитать ЛП (личные продажи) для прямого партнёра
 * 3. Пройтись вверх по структуре (inviter цепочка) и рассчитать ГП
 * 4. Рассчитать комиссии по разнице квалификаций
 * 5. Сохранить в таблицу commission
 */
class CommissionCalculator
{
    /**
     * Рассчитать комиссии для одной транзакции.
     */
    public function calculateForTransaction(int $transactionId): array
    {
        $tx = DB::table('transaction')->where('id', $transactionId)->first();
        if (! $tx) return ['error' => 'Транзакция не найдена'];

        $contract = DB::table('contract')->where('id', $tx->contract)->first();
        if (! $contract) return ['error' => 'Контракт не найден'];

        $consultantId = $contract->consultant;
        if (! $consultantId) return ['error' => 'Консультант не привязан'];

        $consultant = DB::table('consultant')->where('id', $consultantId)->first();
        if (! $consultant) return ['error' => 'Консультант не найден'];

        // Курс валюты
        $currencyRate = (float) ($tx->currencyRate ?? 1);
        $amountRub = (float) ($tx->amountRUB ?? ((float) ($tx->amount ?? 0) * $currencyRate));

        // НДС
        $vat = DB::table('vat')
            ->where('dateFrom', '<=', now())
            ->where('dateTo', '>=', now())
            ->first();
        $vatPercent = (float) ($vat->value ?? 0);
        $amountNoVat = $amountRub / (1 + $vatPercent / 100);

        // dsCommission — тариф для программы контракта
        $dsComPercent = (float) ($tx->dsCommissionPercentage ?? 0);
        if ($dsComPercent <= 0 && $contract->program) {
            $dsCom = DB::table('dsCommission')
                ->where('program', $contract->program)
                ->where('active', true)
                ->where('date', '<=', now())
                ->where('dateFinish', '>=', now())
                ->whereNull('dateDeleted')
                ->first();
            $dsComPercent = (float) ($dsCom->comission ?? 0);
        }

        if ($dsComPercent <= 0) {
            $dsComPercent = 100; // Fallback
        }

        // ЛП (Personal Volume) = amountNoVat * dsCommission% / 10000
        $personalVolume = $amountNoVat * $dsComPercent / 10000;

        // Получить квалификацию прямого партнёра
        $qualLevel = $this->getQualificationLevel($consultantId);
        $qualPercent = $qualLevel ? (float) $qualLevel->percent : 15; // Start = 15%

        // Групповой бонус = ЛП * % квалификации / 100
        $groupBonus = $personalVolume * $qualPercent / 100;
        $groupBonusRub = $groupBonus * 100; // 1 балл = 100 руб

        $commissions = [];

        // 1. Комиссия прямого партнёра (chainOrder = 1)
        $commissions[] = $this->createCommission([
            'transaction' => $transactionId,
            'consultant' => $consultantId,
            'chainOrder' => 1,
            'type' => 'transaction',
            'personalVolume' => round($personalVolume, 6),
            'groupVolume' => round($personalVolume, 6),
            'groupBonus' => round($groupBonus, 6),
            'groupBonusRub' => round($groupBonusRub, 2),
            'percent' => $qualPercent,
            'amount' => round($amountNoVat * $dsComPercent / 100, 2),
            'amountRUB' => round($groupBonusRub, 2),
            'amountUSD' => 0,
            'currency' => $tx->currency ?? 67,
            'date' => $tx->date,
            'dateMonth' => $tx->dateMonth,
            'dateYear' => $tx->dateYear,
            'calculationLevel' => $qualLevel?->id,
        ]);

        // 2. Каскад вверх по структуре (inviter цепочка)
        $currentConsultantId = $consultantId;
        $prevPercent = $qualPercent;
        $chainOrder = 2;
        $visited = [$consultantId]; // защита от зацикливания

        for ($i = 0; $i < 20; $i++) {
            $current = DB::table('consultant')->where('id', $currentConsultantId)->first();
            $inviterId = $current->inviter ?? null;

            if (! $inviterId || in_array($inviterId, $visited)) break;
            $visited[] = $inviterId;

            $inviter = DB::table('consultant')->where('id', $inviterId)->first();
            if (! $inviter) break;

            $inviterLevel = $this->getQualificationLevel($inviterId);
            $inviterPercent = $inviterLevel ? (float) $inviterLevel->percent : 15;

            // Маржинальная разница — разница процентов между наставником и нижестоящим
            $marginPercent = $inviterPercent - $prevPercent;

            if ($marginPercent > 0) {
                $inviterBonus = $personalVolume * $marginPercent / 100;
                $inviterBonusRub = $inviterBonus * 100;

                $commissions[] = $this->createCommission([
                    'transaction' => $transactionId,
                    'consultant' => $inviterId,
                    'chainOrder' => $chainOrder,
                    'type' => 'transaction',
                    'commissionFromOtherConsultant' => $consultantId,
                    'personalVolume' => 0,
                    'groupVolume' => round($personalVolume, 6),
                    'groupBonus' => round($inviterBonus, 6),
                    'groupBonusRub' => round($inviterBonusRub, 2),
                    'percent' => $marginPercent,
                    'amount' => 0,
                    'amountRUB' => round($inviterBonusRub, 2),
                    'amountUSD' => 0,
                    'currency' => $tx->currency ?? 67,
                    'date' => $tx->date,
                    'dateMonth' => $tx->dateMonth,
                    'dateYear' => $tx->dateYear,
                    'calculationLevel' => $inviterLevel?->id,
                ]);

                $chainOrder++;
            }

            $prevPercent = max($prevPercent, $inviterPercent);
            $currentConsultantId = $inviterId;
        }

        // Обновить объёмы на транзакции
        DB::table('transaction')->where('id', $transactionId)->update([
            'personalVolume' => round($personalVolume, 6),
            'groupVolume' => round($personalVolume, 6),
        ]);

        return [
            'success' => true,
            'transactionId' => $transactionId,
            'personalVolume' => round($personalVolume, 6),
            'commissionsCount' => count($commissions),
        ];
    }

    /**
     * Рассчитать комиссии для всех транзакций импорта.
     */
    public function calculateForImport(int $importId): array
    {
        $transactions = DB::table('transaction')
            ->where('comment', 'Импорт #' . $importId)
            ->pluck('id');

        $results = ['total' => $transactions->count(), 'success' => 0, 'errors' => 0];

        foreach ($transactions as $txId) {
            $result = $this->calculateForTransaction($txId);
            if (isset($result['success'])) {
                $results['success']++;
            } else {
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Получить текущий уровень квалификации консультанта.
     */
    private function getQualificationLevel(int $consultantId): ?object
    {
        $consultant = DB::table('consultant')->where('id', $consultantId)->first();
        $levelId = $consultant->status_and_lvl ?? null;

        if (! $levelId) {
            // Попробовать из qualificationLog
            $qLog = DB::table('qualificationLog')
                ->where('consultant', $consultantId)
                ->whereNull('dateDeleted')
                ->orderByDesc('date')
                ->first();
            $levelId = $qLog->calculationLevel ?? $qLog->nominalLevel ?? null;
        }

        if (! $levelId) return null;

        return DB::table('status_levels')->where('id', $levelId)->first();
    }

    /**
     * Создать запись комиссии.
     */
    private function createCommission(array $data): int
    {
        return DB::table('commission')->insertGetId(array_merge($data, [
            'createdAt' => now(),
        ]));
    }
}
