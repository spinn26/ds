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
        // Явная связка «клиент = партнёр» (13.08.2026): без неё слияние
        // обнуляло признак, который сама же платформа и проставила.
        'client_partner' => 'partner_consultant_id',
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

        $result = DB::transaction(function () use ($from, $to, $fromId, $toId, $apply) {
            $deleted = $this->deleteEmptyRows($toId, $apply);
            $moved = [];
            foreach (self::REPOINT as $table => $column) {
                // Псевдоключ client_partner — вторая колонка той же таблицы
                // client (наставник и признак «партнёр» живут порознь).
                $table = $table === 'client_partner' ? 'client' : $table;
                $q = DB::table($table)->where($column, $fromId);
                $count = $q->count();
                if ($count === 0) {
                    continue;
                }
                $moved[$table.'.'.$column] = $count;
                if ($apply) {
                    DB::table($table)->where($column, $fromId)->update([$column => $toId]);
                }
            }

            if ($apply) {
                // Логин переезжает на остающуюся запись, если своего у неё
                // нет: иначе партнёр после слияния входит в кабинет и попадает
                // на опустошённую удалённую запись — модель Consultant не
                // отфильтровывает мягко удалённых.
                if ($from->webUser && ! $to->webUser) {
                    DB::table('consultant')->where('id', $toId)->update(['webUser' => $from->webUser]);
                }

                DB::table('consultant')->where('id', $fromId)->update([
                    'dateDeleted' => now(),
                    'active' => false,
                    'webUser' => $from->webUser && ! $to->webUser ? null : $from->webUser,
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
     * ⚠ На qualificationLog и consultantBalance смотрят 28 внешних ключей из
     * доброго десятка legacy-таблиц (комиссии, выплаты, документы, триггеры
     * отчётов). Слепое удаление «пустой» строки падало на первой же ссылке:
     * consultantBalance ссылается на qualificationLog, и лог сносился раньше
     * баланса (Виноградов 510 → 1713, 13.08.2026). Поэтому теперь перед
     * удалением каждый id проверяется на входящие ссылки ПО ВСЕМ таблицам, а
     * порядок обратный зависимостям: баллы → баланс → логи.
     *
     * Строка, на которую кто-то ссылается, остаётся жить. Дубль периода в
     * выдаче — меньшее зло, чем разрыв ссылки в расчётных таблицах.
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
            })->pluck('id')->all();

        $balance = DB::table('consultantBalance')->where('consultant', $toId)
            ->whereRaw('coalesce("accruedTotal",0) = 0')
            ->whereRaw('coalesce("totalPayable",0) = 0')
            ->whereRaw('coalesce(payed,0) = 0')
            ->whereRaw('coalesce(remaining,0) = 0')
            ->pluck('id')->all();

        // Баллы удаляем первыми: на них никто не ссылается, зато они держат логи.
        $points = $this->unreferenced('calculationConsultantPoints', $points, []);
        if ($apply && $points) {
            DB::table('calculationConsultantPoints')->whereIn('id', $points)->delete();
        }

        // Баланс — вторым: он сам ссылается на логи, а на него смотрят выплаты
        // и документы.
        $balance = $this->unreferenced('consultantBalance', $balance, []);
        if ($apply && $balance) {
            DB::table('consultantBalance')->whereIn('id', $balance)->delete();
        }

        // Логи — последними, уже без учёта строк, удалённых выше.
        $doomedLogs = $this->unreferenced('qualificationLog', $doomedLogs, [
            'calculationConsultantPoints' => $points,
            'consultantBalance' => $balance,
        ]);
        if ($apply && $doomedLogs) {
            DB::table('qualificationLog')->whereIn('id', $doomedLogs)->delete();
        }

        return array_filter([
            'consultantBalance' => count($balance),
            'qualificationLog' => count($doomedLogs),
            'calculationConsultantPoints' => count($points),
        ]);
    }

    /**
     * Оставить из $ids только те, на которые НИКТО не ссылается.
     *
     * Список ссылающихся таблиц берём из самой базы (pg_constraint), а не
     * зашиваем: legacy-схема Directual держит на qualificationLog 20+ ссылок,
     * и любой зашитый список устареет молча — падением на проде.
     *
     * $ignore — строки, которые в этой же операции уже удалены (в незакрытой
     * транзакции они ещё видны запросу).
     *
     * @param  list<int>  $ids
     * @param  array<string, list<int>>  $ignore
     * @return list<int>
     */
    private function unreferenced(string $table, array $ids, array $ignore): array
    {
        if (! $ids) {
            return [];
        }

        $refs = DB::select(<<<'SQL'
            SELECT src.relname AS tablica, a.attname AS kolonka
            FROM pg_constraint con
            JOIN pg_class src ON src.oid = con.conrelid
            JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[1]
            WHERE con.contype = 'f' AND con.confrelid = to_regclass(?)
            SQL, ['"'.$table.'"']);

        foreach ($refs as $ref) {
            if (! $ids) {
                break;
            }
            $q = DB::table($ref->tablica)->whereIn($ref->kolonka, $ids);
            if (! empty($ignore[$ref->tablica])) {
                $q->whereNotIn('id', $ignore[$ref->tablica]);
            }
            $busy = $q->pluck($ref->kolonka)->map(fn ($v) => (int) $v)->all();
            if ($busy) {
                $ids = array_values(array_diff($ids, $busy));
            }
        }

        return $ids;
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
