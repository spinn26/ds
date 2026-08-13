<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Слияние двух карточек клиента (дубли одного человека) в одну.
 *
 * Зеркало PartnerMergeService для клиентов: оператор выбирает, какая карточка
 * остаётся, всё с второй переезжает на неё, вторая уходит в мягкое удаление.
 * Суммы не меняются — это один человек, меняется владелец строк.
 *
 * ⚠ Контракты переезжают вместе со всем остальным, поэтому сначала всегда
 * предпросмотр: оператор видит, сколько строк и каких переедет.
 */
class ClientMergeService
{
    /** Куда смотрит карточка клиента: таблица → колонка. */
    private const REPOINT = [
        'contract' => 'client',
        'WebUser' => 'client',
        'changeConsultantClientLog' => 'client',
        'clientFamily' => 'client',
        'clientGoal' => 'client',
        'clientsCapital' => 'client',
        'clientsIndicators' => 'client',
        'consultant' => 'clients',
        'dataPermutationTrigger' => 'client',
        'exportLogClients' => 'clients',
        'getInsmartOrderWebHookData' => 'client',
        'indicatorsHistory' => 'client',
        'meeting' => 'client',
        'notification' => 'client',
    ];

    /** Контакты карточки: пустые поля приёмника добираем из источника. */
    private const CONTACT_FIELDS = ['email', 'phone', 'birthDate', 'city', 'nicTG', 'gender', 'taxResidency', 'comment'];

    /**
     * @return array{ok:bool, message:string, moved:array<string,int>, filled:array<string,mixed>, totals:array<string,mixed>}
     */
    public function merge(int $fromId, int $toId, bool $apply): array
    {
        if ($fromId === $toId) {
            return $this->fail('Нельзя слить карточку саму с собой.');
        }

        $from = DB::table('client')->where('id', $fromId)->first();
        $to = DB::table('client')->where('id', $toId)->first();
        if (! $from || ! $to) {
            return $this->fail('Одна из карточек не найдена.');
        }
        if ($from->dateDeleted) {
            return $this->fail('Карточка-источник уже удалена.');
        }
        if ($to->dateDeleted) {
            return $this->fail('Карточка-приёмник удалена — выберите живую.');
        }

        $result = DB::transaction(function () use ($from, $to, $fromId, $toId, $apply) {
            $moved = [];
            foreach (self::REPOINT as $table => $column) {
                $count = DB::table($table)->where($column, $fromId)->count();
                if ($count === 0) {
                    continue;
                }
                $moved[$table] = $count;
                if ($apply) {
                    DB::table($table)->where($column, $fromId)->update([$column => $toId]);
                }
            }

            // Контакты не теряем: что пусто у приёмника — берём из источника.
            $filled = [];
            foreach (self::CONTACT_FIELDS as $field) {
                $mine = trim((string) ($to->{$field} ?? ''));
                $theirs = trim((string) ($from->{$field} ?? ''));
                if ($mine === '' && $theirs !== '') {
                    $filled[$field] = $theirs;
                }
            }

            if ($apply) {
                if ($filled) {
                    DB::table('client')->where('id', $toId)->update($filled + ['dateChanged' => now()]);
                }

                DB::table('client')->where('id', $fromId)->update([
                    'dateDeleted' => now(),
                    'comment' => trim(($from->comment ?? '').' [слита с карточкой '.$toId.' '.now()->format('d.m.Y').']'),
                ]);

                Audit::log('merge', 'client', $fromId, [
                    'into' => $toId, 'moved' => $moved, 'filled' => array_keys($filled),
                ]);
            }

            return [$moved, $filled];
        });

        [$moved, $filled] = $result;

        return [
            'ok' => true,
            'message' => $apply
                ? "Слито: «{$from->personName}» (id {$fromId}) → id {$toId}."
                : 'Предпросмотр: будет перенесено строк — '.array_sum($moved)
                    .($filled ? ', дозаполнено полей — '.count($filled) : ''),
            'moved' => $moved,
            'filled' => $filled,
            'totals' => $this->totals($toId),
        ];
    }

    /** Контрольные цифры карточки — оператор сверяет их до и после. */
    public function totals(int $clientId): array
    {
        return [
            'contracts' => DB::table('contract')->where('client', $clientId)->whereNull('deletedAt')->count(),
            'transactions' => DB::table('transaction as t')
                ->join('contract as c', 'c.id', '=', 't.contract')
                ->where('c.client', $clientId)->whereNull('c.deletedAt')->count(),
        ];
    }

    /** @return array{ok:bool, message:string, moved:array<string,int>, filled:array<string,mixed>, totals:array<string,mixed>} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'moved' => [], 'filled' => [], 'totals' => []];
    }
}
