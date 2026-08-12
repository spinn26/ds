<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Слияние двух записей партнёра (дубли одного человека) в одну.
 *
 * Возник из ручного разбора 12.08.2026: у Ставничего 29 057 ₽ остатка висели
 * на записи-клоне БЕЗ логина (следы FK-backfill консолидации Directual), и
 * партнёр не видел свои деньги в кабинете. Логика вынесена в сервис, чтобы
 * оператор делал это сам из админки, а не через SQL.
 *
 * Правило: строки-пустышки у приёмника удаляем, строки с данными — присваиваем.
 * Суммы не меняются: это один человек, меняется только владелец строк.
 * Комиссии НЕ пересчитываются — закрытые периоды остаются как есть.
 */
class PartnerMergeService
{
    /** Таблицы, где ссылка на партнёра просто переезжает. */
    private const REPOINT = [
        'contract' => 'consultant',
        'client' => 'consultant',
        'commission' => 'consultant',
        'consultantBalance' => 'consultant',
        'qualificationLog' => 'consultant',
        'partnerAcceptance' => 'consultant',
        'logAcceptance' => 'consultant',
        'contestrating' => 'consultant',
        'calculationConsultantPoints' => 'consultant',
        'consultantProgramsData' => 'consultant',
    ];

    /**
     * @return array{ok:bool, message:string, moved:array<string,int>, deleted:array<string,int>, totals:array<string,mixed>}
     */
    public function merge(int $fromId, int $toId, bool $apply): array
    {
        if ($fromId === $toId) {
            return $this->fail('Нельзя слить запись саму с собой.');
        }

        $from = DB::table('consultant')->where('id', $fromId)->first();
        $to = DB::table('consultant')->where('id', $toId)->first();
        if (! $from || ! $to) {
            return $this->fail('Одна из записей не найдена.');
        }
        if ($from->dateDeleted) {
            return $this->fail('Запись-источник уже удалена.');
        }
        if ($to->dateDeleted) {
            return $this->fail('Запись-приёмник удалена — выберите живую.');
        }
        // Нижестоящие у источника: их пришлось бы переподвешивать, а это уже
        // перестановка структуры со своими последствиями для комиссий.
        $downline = DB::table('consultant')->where('inviter', $fromId)->whereNull('dateDeleted')->count();
        if ($downline > 0) {
            return $this->fail("У записи-источника {$downline} нижестоящих — сначала перенесите их через «Перестановки».");
        }

        $result = DB::transaction(function () use ($from, $fromId, $toId, $apply) {
            $deleted = $this->deleteEmptyRows($toId, $apply);
            $moved = [];
            foreach (self::REPOINT as $table => $column) {
                $q = DB::table($table)->where($column, $fromId);
                $count = $q->count();
                if ($count === 0) {
                    continue;
                }
                $moved[$table] = $count;
                if ($apply) {
                    DB::table($table)->where($column, $fromId)->update([$column => $toId]);
                }
            }

            if ($apply) {
                DB::table('consultant')->where('id', $fromId)->update([
                    'dateDeleted' => now(),
                    'active' => false,
                    // Реф-код освобождаем: у клонов он дублировал код приёмника,
                    // а живая ссылка должна вести на одну запись.
                    'participantCode' => null,
                    'comment' => trim(($from->comment ?? '') . ' [слит с id ' . $toId . ' ' . now()->format('d.m.Y') . ']'),
                ]);
                \App\Support\Audit::log('merge', 'consultant', $fromId, [
                    'into' => $toId, 'moved' => $moved, 'deleted' => $deleted,
                ]);
            }

            return [$moved, $deleted];
        });

        [$moved, $deleted] = $result;

        return [
            'ok' => true,
            'message' => $apply
                ? "Слито: «{$from->personName}» (id {$fromId}) → id {$toId}."
                : "Предпросмотр: будет перенесено строк — " . array_sum($moved) . ', удалено пустых — ' . array_sum($deleted) . '.',
            'moved' => $moved,
            'deleted' => $deleted,
            'totals' => $this->totals($toId),
        ];
    }

    /**
     * Пустые строки приёмника, которые иначе задвоятся с данными источника:
     * баланс по периодам, логи квалификации и баллы с нулевыми объёмами.
     *
     * ⚠ Порядок важен: calculationConsultantPoints ссылается на
     * qualificationLog внешним ключом, поэтому баллы удаляем ПЕРВЫМИ.
     *
     * @return array<string,int>
     */
    private function deleteEmptyRows(int $toId, bool $apply): array
    {
        $doomedLogs = DB::table('qualificationLog')->where('consultant', $toId)
            ->whereRaw('coalesce("personalVolume",0) = 0')
            ->whereRaw('coalesce("groupVolume",0) = 0')
            ->whereRaw('coalesce("groupVolumeCumulative",0) = 0')
            ->pluck('id')->all();

        $points = DB::table('calculationConsultantPoints')->where('consultant', $toId)
            ->where(function ($q) use ($doomedLogs) {
                $q->whereRaw('coalesce("sumPoints",0) = 0');
                if ($doomedLogs) {
                    $q->orWhereIn('qualificationLog', $doomedLogs);
                }
            });
        $pointsCount = $points->count();

        $balance = DB::table('consultantBalance')->where('consultant', $toId)
            ->whereRaw('coalesce("accruedTotal",0) = 0')
            ->whereRaw('coalesce("totalPayable",0) = 0')
            ->whereRaw('coalesce(payed,0) = 0')
            ->whereRaw('coalesce(remaining,0) = 0');
        $balanceCount = $balance->count();

        if ($apply) {
            if ($pointsCount) {
                $points->delete();
            }
            if ($doomedLogs) {
                DB::table('qualificationLog')->whereIn('id', $doomedLogs)->delete();
            }
            if ($balanceCount) {
                $balance->delete();
            }
        }

        return array_filter([
            'consultantBalance' => $balanceCount,
            'qualificationLog' => count($doomedLogs),
            'calculationConsultantPoints' => $pointsCount,
        ]);
    }

    /** Контрольные цифры приёмника — оператор сверяет их до и после. */
    public function totals(int $consultantId): array
    {
        return [
            'contracts' => DB::table('contract')->where('consultant', $consultantId)->whereNull('deletedAt')->count(),
            'clients' => DB::table('client')->where('consultant', $consultantId)->whereNull('dateDeleted')->count(),
            'commissions' => DB::table('commission')->where('consultant', $consultantId)->whereNull('deletedAt')->count(),
            'remaining' => round((float) DB::table('consultantBalance')->where('consultant', $consultantId)->sum('remaining'), 2),
            'accrued' => round((float) DB::table('consultantBalance')->where('consultant', $consultantId)->sum('accruedTotal'), 2),
        ];
    }

    /** @return array{ok:bool, message:string, moved:array<string,int>, deleted:array<string,int>, totals:array<string,mixed>} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'moved' => [], 'deleted' => [], 'totals' => []];
    }
}
