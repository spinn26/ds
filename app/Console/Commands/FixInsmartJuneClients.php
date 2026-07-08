<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Чиним «перепутанных клиентов» на InSmart-контрактах за июнь-2026.
 *
 * Проблема: при внешней заливке июньских InSmart-контрактов колонка
 * contract.client (FK на карточку client) была проставлена НЕВЕРНО — она
 * указывает на чужую карточку клиента, тогда как contract.clientName
 * (страхователь из InSmart) — корректный. У большинства таких контрактов
 * правильной карточки клиента в системе вообще нет.
 *
 * Источник истины — contract.clientName. Для каждого контракта, где
 * personName привязанной карточки != clientName:
 *   1) ищем ЖИВУЮ карточку client с personName = clientName у ТОГО ЖЕ
 *      консультанта (контракт-консультант не трогаем) — если есть, берём её;
 *   2) иначе создаём новую person + client под consultant контракта
 *      («пришёл клиент, которого нет в системе — заводим карточку»);
 *   3) перепривязываем contract.client на правильную карточку.
 *
 * Деньги НЕ двигаются: consultant/consultantName и транзакции не меняются,
 * комиссии считаются по консультанту, а не по карточке клиента, поэтому
 * пересчёт не нужен. Меняется только атрибуция клиента.
 *
 * --dry-run — печатает план без записи. Идемпотентна: повторный запуск после
 * применения находит 0 расхождений.
 */
class FixInsmartJuneClients extends Command
{
    protected $signature = 'insmart:fix-june-clients
        {--dry-run : показать план без изменений}
        {--from=2026-06-01 : начало периода (createDate >=)}
        {--to=2026-07-01 : конец периода (createDate <, не включая)}';

    protected $description = 'InSmart июнь-2026: чинит перепутанный contract.client по clientName (создаёт карточку, если клиента нет)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        // Кандидаты: живые InSmart-контракты за период, где имя привязанной
        // карточки не совпадает с clientName (страхователем).
        $rows = DB::table('contract as co')
            ->join('client as cl', 'cl.id', '=', 'co.client')
            ->whereRaw('co.comment ILIKE ?', ['%insmart%'])
            ->whereNull('co.deletedAt')
            ->where('co.createDate', '>=', $from)
            ->where('co.createDate', '<', $to)
            ->whereRaw("coalesce(co.\"clientName\",'') <> ''")
            ->whereRaw("coalesce(co.\"clientName\",'') <> coalesce(cl.\"personName\",'')")
            ->orderBy('co.id')
            ->get([
                'co.id', 'co.number', 'co.clientName', 'co.client as oldClient',
                'co.consultant', 'co.consultantName',
                'cl.personName as oldClientName',
            ]);

        $this->info(($dry ? '[DRY-RUN] ' : '')."InSmart-контрактов с перепутанным клиентом ({$from}..{$to}): ".$rows->count());
        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $reused = 0;
        $created = 0;

        foreach ($rows as $r) {
            $name = trim((string) $r->clientName);
            $consultantId = $r->consultant;

            // 1) Существующая живая карточка того же клиента у этого консультанта?
            $existing = DB::table('client')
                ->where('personName', $name)
                ->where('consultant', $consultantId)
                ->whereNull('dateDeleted')
                ->orderBy('id')
                ->value('id');

            if ($dry) {
                $action = $existing
                    ? "reuse client#{$existing}"
                    : 'create client';
                $this->line(sprintf(
                    '  contract#%d «%s»: client#%d (%s) → %s [%s], консультант %s',
                    $r->id, $r->number, $r->oldClient, $r->oldClientName,
                    $name, $action, $r->consultantName,
                ));
                $existing ? $reused++ : $created++;
                continue;
            }

            DB::transaction(function () use ($r, $name, $consultantId, $existing, &$reused, &$created) {
                if ($existing) {
                    $clientId = (int) $existing;
                    $reused++;
                } else {
                    $clientId = $this->createClient($name, $consultantId, $r->consultantName);
                    $created++;
                }

                DB::table('contract')->where('id', $r->id)->update([
                    'client' => $clientId,
                    'clientName' => $name, // уже корректно, но фиксируем денорм
                    'changedAt' => now(),
                ]);
            });

            $this->line(sprintf(
                '  ✔ contract#%d «%s»: %s → client %s (%s)',
                $r->id, $r->number, $r->oldClientName,
                $existing ? "reuse #{$existing}" : 'new', $name,
            ));
        }

        $this->info(($dry ? '[DRY-RUN] ' : 'Готово. ')."Переиспользовано карточек: {$reused}, создано новых: {$created}.");

        return self::SUCCESS;
    }

    /**
     * Создаёт person + client для страхователя, которого нет в системе.
     * ФИО парсим как «Фамилия Имя Отчество» (как в InsmartIntegrationService).
     */
    private function createClient(string $fio, ?int $consultantId, ?string $consultantName): int
    {
        $parts = preg_split('/\s+/u', trim($fio));
        $lastName = $parts[0] ?? $fio;
        $firstName = $parts[1] ?? '';
        $patronymic = $parts[2] ?? null;

        $personId = DB::table('person')->insertGetId([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'patronymic' => $patronymic,
            'role' => 'client',
            'dateCreated' => now()->toIso8601String(),
        ]);

        return (int) DB::table('client')->insertGetId([
            'person' => $personId,
            'personName' => $fio,
            'consultant' => $consultantId,
            'consultantName' => $consultantName,
            'active' => true,
            'source' => 'insmart-fix',
            'dateCreated' => now(),
        ]);
    }
}
