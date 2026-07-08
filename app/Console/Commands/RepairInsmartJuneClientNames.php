<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Восстанавливает ПРАВИЛЬНЫЕ ФИО клиентов на InSmart-контрактах за июнь-2026.
 *
 * Инцидент: чужой «дедуп контрактов по номеру» склеил InSmart-контракты с
 * контрактами ЧУЖИХ клиентов, т.к. 8-символьные hex-номера InSmart совпали с
 * номерами других контрактов. В результате на проде у 29/46 июньских
 * InSmart-контрактов contract.clientName (и привязанная карточка client) стали
 * именами чужих клиентов, а реальный страхователь InSmart потерялся.
 *
 * Источник истины — карта ext(counterpartyContractId) → правильное ФИО,
 * снятая из не-повреждённого снимка (реальные страхователи InSmart, которых
 * не было в системе как карточек). Карта вшита ниже (MAP), 45 заказов.
 *
 * Для каждого июньского InSmart-контракта, где ext есть в карте и текущее
 * clientName != правильному:
 *   1) выставляем contract.clientName = правильное ФИО;
 *   2) ищем ЖИВУЮ карточку client с этим ФИО у ТОГО ЖЕ консультанта (продавца
 *      контракта); если нет — создаём person + client под consultant контракта
 *      («страхователь, которого нет в системе — заводим карточку»);
 *   3) перепривязываем contract.client на правильную карточку.
 *
 * Консультант/транзакции/деньги НЕ трогаются (комиссии считаются по
 * консультанту) → пересчёт не нужен. Старую (чужую) карточку не удаляем —
 * она принадлежит реальному другому клиенту. Идемпотентна. --dry-run — план.
 */
class RepairInsmartJuneClientNames extends Command
{
    protected $signature = 'insmart:repair-june-client-names
        {--dry-run : показать план без изменений}
        {--from=2026-06-01 : начало периода (createDate >=)}
        {--to=2026-07-01 : конец периода (createDate <, не включая)}';

    protected $description = 'InSmart июнь-2026: восстановить верные ФИО клиентов (после дедупа-по-номеру), создать/перепривязать карточки';

    /** ext (counterpartyContractId) => правильное ФИО страхователя. */
    private const MAP = [
        '02c6fc9a-3820-5984-b636-66cd83ccb03b' => 'Миронова София Евгеньевна',
        '04ecc2b3-6ddc-5774-886b-99992596a81f' => 'Шахмуратова Рита Альфировна',
        '069ecd9a-7774-5e08-8438-a81377ef6a0c' => 'Соколов Владимир Николаевич',
        '08778ba8-37c8-56eb-b648-8b6b1f1aaf81' => 'Сайкова Екатерина Олеговна',
        '098046ab-6d7d-5259-997b-f009f2c4fbb2' => 'Аскарова Земфира Василовна',
        '0dd39380-f257-5dc5-bde9-48c3aec2c4a7' => 'Романов Алексей Анатольевич',
        '10481365-35d7-5138-a4ea-cd9a387e5c1b' => 'Буданов Дмитрий Сергееевич',
        '1164886c-3746-53cf-97a2-0a92cc15d4b8' => 'Лунина Наталия Борисовна',
        '13f35876-2898-5ffd-9d1f-2c31b559932c' => 'Чибисов Денис Александрович',
        '19da81e6-acaf-5a03-b304-cb2d1232c6da' => 'Файзрахманова Элеонора Рафаиловна',
        '2429568e-eba4-5cdc-8513-006ee2a252ef' => 'Ялалова Тансулпан Азаматовна',
        '27ece715-8bbf-558c-9adb-a0e15fba4b45' => 'Нургалина Юлия Фатиховна',
        '2e4b1cfe-4396-50fe-9ade-2f250cc73ee5' => 'Осипова Василя Василовна',
        '314971ba-4b8f-5fd3-a9ea-3d9674829540' => 'Шавалиев Равиль Наилович',
        '31af236d-8ecc-5718-8762-56dab65d1fbb' => 'Давлетбердина Земфира Маратовна',
        '34d22c72-fa0e-5611-b9e4-c547eb212ffc' => 'Гареева Дина Муллахметовна',
        '47cf88e5-d97b-5563-a7c2-b260df0b26d2' => 'Кодэлльо Александр Вячеславович',
        '602aa2b9-b60c-5d15-bd5d-175a975682f4' => 'Чапланова Полина Олеговна',
        '6bae1a05-6a42-5d39-8c6c-5f23f0bf6d11' => 'Смоленский Вадим Витальевич',
        '77a991a8-3b10-52ec-9876-f45bb689a299' => 'Лунин Иван Юрьевич',
        '793e6875-3adf-5895-8cad-fb34a60d1cf5' => 'Кулясова Любовь Петровна',
        '7ecfd0f2-52c4-50f0-bbdd-c5578af3ce17' => 'Зарипова Гузалия Зульфатовна',
        '84008096-4a4b-5148-b907-7538eba43273' => 'Соболев Василий Николаевич',
        '855de836-1369-518e-9061-2d6aed3fa912' => 'Новиков Николай Леонидович',
        '8d7bb1f4-cb21-5adf-b8b6-21ae1f3154d1' => 'Угарова Дарья Вадимовна',
        '9522440b-0711-581b-bb0e-1ada78a4f208' => 'Греков Юрий Владимирович',
        '954372e2-5b80-56dd-bdd9-740a73d5c944' => 'Лунина Наталия Борисовна',
        '99f44a08-4cde-5ce1-a779-ea8589f3ea7e' => 'Юдакова Юлия Юрьевна',
        'a3cfa735-a6da-5832-9d03-1e56f1d5aa92' => 'Якупов Наиль Абдуллович',
        'a9b76893-0d24-5745-93eb-1aee5e30d52c' => 'Чебыкина Екатерина Андреевна',
        'ab2fddd1-1764-5d0f-b0eb-ac5c5108a9fb' => 'Сушко Мария Николаевна',
        'aeb82eda-fb9f-5495-9b32-b36611b20ba4' => 'Разумаев Александр Сергеевич',
        'bc57d10d-797a-5e7e-9a83-02d998c2fece' => 'Лазаренко Людмила Сергеевна',
        'bcb8c2e1-558a-5ff6-b122-63ac466d7d5f' => 'Смертина Галина Капитоновна',
        'bce30a79-a80f-56e1-be4b-d1b270a8b401' => 'Баранкова Александра Михайловна',
        'd44b5479-70fa-5bf5-a912-682fef86464d' => 'Беседин Вадим Сергеевич',
        'd7856f60-cbe2-5478-80f6-62b83f105b10' => 'Кулясова Ульяна Александровна',
        'dbd4ee4e-b7e5-517f-a55c-1fd717a209b7' => 'Бельмасов Всеволод Игоревич',
        'e144d68d-cdad-5519-bf41-7f88c52b8de3' => 'Горячева Наталья Викторовна',
        'e1ee0ad8-0b95-50b3-86ee-46de85df196c' => 'Грехова Юлия Сергеевна',
        'e2800c83-abb3-5d20-8b91-e982691c56ce' => 'Кривошатская Екатерина Станиславовна',
        'e5689965-4240-5523-989e-a03a173fd585' => 'Богомолова Елена Сергеевна',
        'ef1789e8-24a0-588c-a7ac-42ef3683fd8c' => 'Фахертдинов Рифат Ирекович',
        'f0346e90-5459-54a3-8dc8-2a617f341bfb' => 'Данилова Физалия Галиевна',
        'f2dbd67d-64f9-5ebc-9fc1-7d29f262f78a' => 'Шагивалиева Алия Марсовна',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        $rows = DB::table('contract as co')
            ->leftJoin('client as cl', 'cl.id', '=', 'co.client')
            ->whereRaw('co.comment ILIKE ?', ['%insmart%'])
            ->whereNull('co.deletedAt')
            ->where('co.createDate', '>=', $from)
            ->where('co.createDate', '<', $to)
            ->whereNotNull('co.counterpartyContractId')
            ->orderBy('co.id')
            ->get([
                'co.id', 'co.number', 'co.counterpartyContractId as ext',
                'co.clientName', 'co.client as oldClient', 'co.consultant', 'co.consultantName',
                'cl.personName as oldClientName',
            ]);

        $reused = 0;
        $created = 0;
        $planned = 0;

        foreach ($rows as $r) {
            $correct = self::MAP[$r->ext] ?? null;
            if ($correct === null) {
                continue; // ext вне карты — не трогаем
            }
            if (trim((string) $r->clientName) === $correct) {
                continue; // уже правильное имя
            }
            $planned++;

            $existing = DB::table('client')
                ->where('personName', $correct)
                ->where('consultant', $r->consultant)
                ->whereNull('dateDeleted')
                ->orderBy('id')
                ->value('id');

            if ($dry) {
                $this->line(sprintf(
                    '  contract#%d «%s»: clientName «%s» → «%s»; карточка %s [консультант %s]',
                    $r->id, $r->number, $r->clientName, $correct,
                    $existing ? "reuse #{$existing}" : 'create',
                    $r->consultantName,
                ));
                $existing ? $reused++ : $created++;
                continue;
            }

            DB::transaction(function () use ($r, $correct, $existing, &$reused, &$created) {
                if ($existing) {
                    $clientId = (int) $existing;
                    $reused++;
                } else {
                    $clientId = $this->createClient($correct, $r->consultant, $r->consultantName);
                    $created++;
                }
                DB::table('contract')->where('id', $r->id)->update([
                    'clientName' => $correct,
                    'client' => $clientId,
                    'changedAt' => now(),
                ]);
            });

            $this->line(sprintf(
                '  ✔ contract#%d «%s»: «%s» → «%s» (%s)',
                $r->id, $r->number, $r->clientName, $correct,
                $existing ? "reuse #{$existing}" : 'new card',
            ));
        }

        $this->info(($dry ? '[DRY-RUN] ' : 'Готово. ')
            ."К правке: {$planned}. Переиспользовано карточек: {$reused}, создано новых: {$created}.");

        return self::SUCCESS;
    }

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
            'source' => 'insmart-name-fix',
            'dateCreated' => now(),
        ]);
    }
}
