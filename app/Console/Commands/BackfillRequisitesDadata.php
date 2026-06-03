<?php

namespace App\Console\Commands;

use App\Services\DadataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Backfill existing requisites from ЕГРИП (DaData) and auto-verify matches.
 *
 * Why: until now the partner-profile save (ProfileController::updateRequisites)
 * stored only what the client submitted — наименование/ОГРНИП/адрес/дата could
 * be empty, and nothing was auto-verified. The endpoint now pulls ЕГРИП data
 * server-side and auto-verifies when the record is an active ИП (12 digits,
 * ACTIVE) whose ФИО matches the profile. This command applies the same logic
 * to the rows that were saved BEFORE the fix.
 *
 * For each active requisite with an ИНН:
 *   — fetch ЕГРИП (cached 1h, shared key with the controllers);
 *   — fill empty наименование/ОГРНИП/адрес/дата from ЕГРИП;
 *   — auto-verify (requisite.verified + status=3 + bankrequisites.verified +
 *     consultant.statusRequisites=3) when ИП + ACTIVE + ФИО match + bank present.
 *
 * Safe by default: dry-run unless --apply. Before applying, the old values of
 * every changed row are dumped to storage/app for rollback. DaData free tier is
 * 10k/day; each unique ИНН costs one call (cache dedups repeats).
 */
class BackfillRequisitesDadata extends Command
{
    protected $signature = 'requisites:backfill-dadata
        {--apply : Persist the changes (otherwise dry-run preview only)}
        {--limit=0 : Process at most N requisites (0 = all)}';

    protected $description = 'Backfill requisites from ЕГРИП (DaData) and auto-verify ИП whose ФИО matches the profile';

    public function handle(DadataService $dadata): int
    {
        if (! $dadata->isConfigured()) {
            $this->error('DaData API key не настроен (/admin/api-keys). Backfill невозможен.');
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

        // Active requisites with an ИНН + the owning consultant + WebUser ФИО.
        $query = DB::table('requisites as r')
            ->join('consultant as c', 'c.id', '=', 'r.consultant')
            ->join('WebUser as w', 'w.id', '=', 'c.webUser')
            ->whereNull('r.deletedAt')
            ->whereNotNull('r.inn')
            ->where('r.inn', '!=', '')
            ->orderBy('r.id')
            ->select([
                'r.id', 'r.inn', 'r.individualEntrepreneur', 'r.ogrn', 'r.address',
                'r.registrationDate', 'r.email', 'r.phone', 'r.verified', 'r.status',
                'r.consultant', 'c.statusRequisites', 'w.firstName', 'w.lastName', 'w.patronymic',
            ]);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();
        $this->info("Реквизитов к обработке: {$rows->count()}");
        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $stamp = now()->format('Ymd_His');
        $rollbackPath = storage_path("app/requisites_backfill_rollback_{$stamp}.csv");
        $fh = null;
        if ($apply) {
            $fh = fopen($rollbackPath, 'w');
            fputcsv($fh, [
                'requisite_id', 'old_individualEntrepreneur', 'old_ogrn', 'old_address',
                'old_registrationDate', 'old_verified', 'old_status',
                'consultant_id', 'old_statusRequisites', 'old_bank_verified',
            ]);
        }

        $filled = 0;
        $verified = 0;
        $deverified = 0;
        $preview = [];

        foreach ($rows as $r) {
            $cleanInn = preg_replace('/\D/', '', (string) $r->inn);
            if (strlen($cleanInn) !== 10 && strlen($cleanInn) !== 12) {
                continue;
            }

            $fns = Cache::remember("dadata:inn:{$cleanInn}", 3600, fn () => $dadata->findByInn($cleanInn));
            if (empty($fns['found'])) {
                continue;
            }

            // Дозаполняем только ПУСТЫЕ поля — не перетираем то, что уже заполнено.
            $reqUpdate = [];
            if (empty($r->individualEntrepreneur) && ! empty($fns['name'])) {
                $reqUpdate['individualEntrepreneur'] = mb_substr($fns['name'], 0, 255);
            }
            if (empty($r->ogrn) && ! empty($fns['ogrn'])) {
                $reqUpdate['ogrn'] = $fns['ogrn'];
            }
            if (empty($r->address) && ! empty($fns['address'])) {
                $reqUpdate['address'] = mb_substr($fns['address'], 0, 500);
            }
            if (empty($r->registrationDate) && ! empty($fns['registrationDate'])) {
                $reqUpdate['registrationDate'] = $fns['registrationDate'];
            }

            $bank = DB::table('bankrequisites')
                ->where('requisites', $r->id)
                ->whereNull('deletedAt')
                ->first(['id', 'verified', 'beneficiaryName', 'beneficiaryInn',
                    'bankName', 'bankBik', 'accountNumber', 'correspondentAccount']);

            // Эффективные значения ПОСЛЕ дозаполнения (для проверки полноты).
            $eff = fn ($field, $col) => array_key_exists($field, $reqUpdate)
                ? $reqUpdate[$field]
                : ($r->{$col} ?? null);
            $filledVal = fn ($v) => $v !== null && trim((string) $v) !== '';

            // Полнота — обязательное условие верификации (2026-06-03): все поля
            // карточки ИП + банковские. Получатель проставляем сами на верификации.
            $complete = $filledVal($eff('individualEntrepreneur', 'individualEntrepreneur'))
                && $filledVal($r->inn)
                && $filledVal($eff('ogrn', 'ogrn'))
                && $filledVal($eff('address', 'address'))
                && $filledVal($r->email)
                && $filledVal($r->phone)
                && $bank !== null
                && $filledVal($bank->bankName)
                && $filledVal($bank->bankBik)
                && $filledVal($bank->accountNumber)
                && $filledVal($bank->correspondentAccount);

            $isIndividual = ($fns['type'] ?? null) === 'INDIVIDUAL';
            $isActive = ($fns['status'] ?? null) === 'ACTIVE';
            $fioCheck = $dadata->compareFio($fns['fio'] ?? null, $r->lastName, $r->firstName, $r->patronymic);
            $eligible = $isIndividual && $isActive && ($fioCheck['match'] ?? false) && $complete;

            $wasVerified = (bool) $r->verified;
            $needsVerify = $eligible && ! $wasVerified;
            // Снимаем verified с тех, кто больше не проходит (неполные данные / ФИО).
            $needsDeverify = ! $eligible && $wasVerified;

            if (empty($reqUpdate) && ! $needsVerify && ! $needsDeverify) {
                continue;
            }

            if (! empty($reqUpdate)) {
                $filled++;
            }
            if ($needsVerify) {
                $verified++;
            }
            if ($needsDeverify) {
                $deverified++;
            }

            if (count($preview) < 30) {
                $action = $needsVerify ? '✓ verify'
                    : ($needsDeverify ? '✗ de-verify' : ($eligible ? '(already)' : '—'));
                $preview[] = [
                    $r->id,
                    $cleanInn,
                    trim(($r->lastName ?? '').' '.($r->firstName ?? '')),
                    empty($reqUpdate) ? '—' : implode(',', array_keys($reqUpdate)),
                    $action,
                ];
            }

            if (! $apply) {
                continue;
            }

            fputcsv($fh, [
                $r->id, $r->individualEntrepreneur, $r->ogrn, $r->address,
                $r->registrationDate, $r->verified ? 1 : 0, $r->status,
                $r->consultant, $r->statusRequisites, $bank ? ($bank->verified ? 1 : 0) : '',
            ]);

            DB::transaction(function () use ($r, $reqUpdate, $eligible, $needsDeverify, $bank, $fns, $cleanInn) {
                $set = $reqUpdate;
                if ($eligible) {
                    $set['verified'] = true;
                    $set['status'] = 3;
                } elseif ($needsDeverify) {
                    $set['verified'] = false;
                    $set['status'] = 2;
                }
                if (! empty($set)) {
                    $set['dateChange'] = now();
                    DB::table('requisites')->where('id', $r->id)->update($set);
                }

                if ($eligible) {
                    if ($bank) {
                        DB::table('bankrequisites')->where('id', $bank->id)->update([
                            'verified' => true,
                            'beneficiaryName' => $fns['name'] ?? $bank->beneficiaryName,
                            'beneficiaryInn' => $fns['inn'] ?? $cleanInn,
                            'dateChange' => now(),
                        ]);
                    }
                    DB::table('consultant')->where('id', $r->consultant)->update([
                        'statusRequisites' => 3,
                    ]);
                } elseif ($needsDeverify) {
                    if ($bank) {
                        DB::table('bankrequisites')->where('id', $bank->id)->update([
                            'verified' => false,
                            'dateChange' => now(),
                        ]);
                    }
                    DB::table('consultant')->where('id', $r->consultant)->update([
                        'statusRequisites' => 2,
                    ]);
                }
            });
        }

        if ($preview) {
            $this->table(['req', 'inn', 'fio', 'filled', 'action'], $preview);
            if ($rows->count() > 30) {
                $this->line('… (показаны первые '.count($preview).' изменений)');
            }
        }

        $this->info("Дозаполнено полями: {$filled} · авто-верифицировано: {$verified} · снято verified: {$deverified}");

        if (! $apply) {
            $this->warn('DRY-RUN — изменения не записаны. Повторите с --apply, чтобы применить.');
            return self::SUCCESS;
        }

        fclose($fh);
        $this->info("Rollback-снимок: {$rollbackPath}");
        $this->info('Готово.');
        return self::SUCCESS;
    }
}
