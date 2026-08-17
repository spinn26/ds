<?php

namespace App\Services;

use App\Models\Consultant;
use App\Support\Audit;
use App\Support\LegacyId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Сохранение карточки партнёра (PUT /admin/partners/{id}).
 *
 * Вынесено из AdminDataController (метод занимал 215 строк). Код перенесён
 * дословно: набор правил, порядок операций и содержимое журналов прежние.
 *
 * Обновляются ТОЛЬКО присланные поля — пустая форма не должна ничего
 * затирать. Остальное, что легко потерять при правках:
 *   - роль, пароль и блокировку правит только admin, у прочего staff эти
 *     поля молча отбрасываются (иначе staff выдал бы себе admin);
 *   - у партнёра с логином контакты живут в WebUser, у партнёра БЕЗ логина —
 *     в собственных колонках consultant (893 импортированных ФК);
 *   - новое ФИО каскадом расходится по видимым денорм-копиям;
 *   - смена наставника через форму = перестановка: запись в Историю
 *     перестановок плюс пересчёт цепочки, иначе перевод «теряется».
 */
class PartnerUpdateService
{
    /** Возвращает id сохранённого партнёра. */
    public function update(Request $request, int $id): int
    {
        $consultant = Consultant::findOrFail($id);
        // Strict: только роль admin может менять role/password/isBlocked.
        // isAdmin() в User модели пускает ещё и backoffice — это не то.
        $isAdmin = $request->user()->hasAnyRole(['admin']);

        // ФИО: только кириллица + пробел/дефис. Поля sometimes — если они
        // вообще пришли в запросе, валидируем формат; если null/пусто,
        // правило regex автоматически пропускается (nullable).
        // Легаси-значения пола приходят из Directual по-русски («Мужской»/
        // «Женский»); приводим к канону male/female до валидации, иначе
        // in:male,female отклонит сохранение старой записи.
        if ($request->has('gender')) {
            $request->merge(['gender' => $this->normalizeGender($request->input('gender'))]);
        }

        $cyrillicRegex = '/^[А-Яа-яЁё][А-Яа-яЁё\s\-]*$/u';
        $data = $request->validate([
            // consultant fields
            'participantCode' => ['nullable', 'string', 'max:64',
                "unique:consultant,participantCode,{$id},id",
            ],
            'inviter' => ['nullable', 'integer', 'exists:consultant,id'],
            // web user fields
            'firstName' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'patronymic' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            // Уникальность по WebUser проверяем ТОЛЬКО у партнёров с логином:
            // у остальных почта лежит в собственной колонке consultant, и
            // правило запрещало сохранить карточку, если такая почта есть у
            // чьего-то логина, — блокируя ровно те записи без входа, ради
            // которых колонку и заводили.
            'email' => array_filter(['sometimes', 'nullable', 'email', 'max:255',
                $consultant->webUser ? "unique:WebUser,email,{$consultant->webUser},id" : null,
            ]),
            'phone' => ['sometimes', 'nullable', 'string', 'max:64', new \App\Rules\ValidPhone],
            'nicTG' => ['sometimes', 'nullable', 'string', 'max:128'],
            'gender' => ['sometimes', 'nullable', 'in:male,female'],
            'birthDate' => ['sometimes', 'nullable', 'date'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isBlocked' => ['sometimes', 'boolean'],
            'newPassword' => ['sometimes', 'nullable', 'string',
                'min:8', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers(),
            ],
        ], [
            'firstName.regex' => 'Имя — только русские буквы',
            'lastName.regex' => 'Фамилия — только русские буквы',
            'patronymic.regex' => 'Отчество — только русские буквы',
        ]);

        // Critical поля доступны только admin'у — иначе любой staff
        // мог бы выдать себе/коллеге роль admin или сбросить пароль.
        if (! $isAdmin) {
            unset($data['role'], $data['newPassword'], $data['isBlocked']);
        }

        // diff: per-field {from, to} — даёт возможность построить
        // «История изменений» в карточке партнёра. Старые значения
        // снимаем ДО апдейта, новые — после нормализации.
        $diff = [];
        $inviterTransfer = null;
        $authorId = $request->user()?->id;

        DB::transaction(function () use ($consultant, $data, &$diff, &$inviterTransfer, $authorId) {
            $this->applyConsultantFields($consultant, $data, $diff, $inviterTransfer);

            // Контакты живут либо в WebUser, либо в самой карточке.
            if ($consultant->webUser) {
                $this->applyWebUserFields($consultant, $data, $diff);
            } else {
                $this->applyCardFields($consultant, $data, $diff);
            }

            $consultant->save();

            // Каскад смены ФИО в видимые денорм-копии имени консультанта, чтобы
            // оно поменялось ВЕЗДЕ: пригласитель у приглашённых, консультант в
            // контрактах и клиентах. Внутренние calc-денормы (баланс/qualLog)
            // не трогаем — их переписывают раннеры, часть заморожена cutoff'ом.
            if ($consultant->wasChanged('personName')) {
                $this->propagateConsultantName($consultant->id, $consultant->personName);
            }

            // Смена наставника → запись в Историю перестановок (формат createTransfer).
            if ($inviterTransfer) {
                DB::table('changeConsultantInviterLog')->insert([
                    'id'             => LegacyId::next('changeConsultantInviterLog'),
                    'dateCreated'    => now(),
                    'webUser'        => $authorId,
                    'consultant'     => $consultant->id,
                    'consultantName' => $consultant->personName,
                    'inviterOld'     => $inviterTransfer['oldId'],
                    'inviterOldName' => $inviterTransfer['oldName'],
                    'inviterNew'     => $inviterTransfer['newId'],
                    'inviterNewName' => $inviterTransfer['newName'],
                    'triggeredBy'    => 'Форма партнёра',
                ]);
            }
        });

        // Пересчёт комиссионной цепочки за открытые периоды (как в createTransfer).
        if ($inviterTransfer) {
            \App\Jobs\RecomputeTransferChainJob::dispatch('partner', (int) $consultant->id);
        }

        // В audit_log пишем только если действительно что-то поменялось,
        // иначе «История изменений» забивалась бы пустыми «нажал Сохранить».
        if (! empty($diff)) {
            Audit::log('partner_update', 'consultant', $consultant->id, [
                'diff' => $diff,
            ]);
        }

        return $consultant->id;
    }

    /**
     * Канонизация пола: легаси-значения Directual («Мужской»/«Женский»),
     * однобуквенные коды и en-варианты → «male»/«female». null — если пусто
     * или нераспознано (тогда пол просто не меняется/очищается).
     */
    private function normalizeGender($v): ?string
    {
        $s = mb_strtolower(trim((string) $v));
        if ($s === '') return null;
        if (in_array($s, ['male', 'm', 'м', 'муж', 'мужской'], true)) return 'male';
        if (in_array($s, ['female', 'f', 'ж', 'жен', 'женский'], true)) return 'female';
        return null;
    }

    /**
     * Распространить новое ФИО консультанта по всем видимым денорм-копиям,
     * чтобы имя поменялось ВЕЗДЕ за один заход:
     *   - inviterName у всех, кого этот консультант пригласил;
     *   - consultantName во всех его контрактах;
     *   - consultantName во всех его клиентских карточках.
     * Логи изменений (changeConsultant*Log) и calc-денормы (баланс/qualLog)
     * намеренно не трогаем — первые историчны, вторые переписывают раннеры.
     */
    private function propagateConsultantName(int $consultantId, ?string $newName): void
    {
        DB::table('consultant')->where('inviter', $consultantId)
            ->update(['inviterName' => $newName]);
        DB::table('contract')->where('consultant', $consultantId)
            ->update(['consultantName' => $newName]);
        DB::table('client')->where('consultant', $consultantId)
            ->update(['consultantName' => $newName]);
    }

    /**
     * Колонки самой карточки: реф-код и наставник.
     *
     * ⚠ Смена наставника через форму — это перестановка: её надо занести в
     * Историю перестановок и пересчитать цепочку, иначе перевод «теряется».
     * Денормализованное имя наставника держим в синхроне с FK.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $diff
     */
    private function applyConsultantFields(object $consultant, array $data, array &$diff, ?array &$inviterTransfer): void
    {
        // --- consultant columns ---
        $consultantFields = ['participantCode', 'inviter'];
        foreach ($consultantFields as $col) {
            if (! array_key_exists($col, $data)) continue;
            $old = $consultant->{$col};
            $new = $data[$col] ?: null;
            if ((string) $old !== (string) $new) {
                $diff[$col] = ['from' => $old, 'to' => $new];
            }
        }
        if (array_key_exists('participantCode', $data)) {
            $consultant->participantCode = $data['participantCode'] ?: null;
        }
        if (array_key_exists('inviter', $data)) {
            $prevInviterId = $consultant->inviter;
            $prevInviterName = $consultant->inviterName;
            $newInviterId = $data['inviter'] ?: null;
            $consultant->inviter = $newInviterId;
            // Денорм-имя пригласителя держим в синхроне с FK — иначе
            // мини-профиль/списки показывают старого пригласителя.
            $consultant->inviterName = $newInviterId
                ? DB::table('consultant')->where('id', $newInviterId)->value('personName')
                : null;
            // Смена наставника через форму = перестановка. Фиксируем, чтобы
            // ниже записать в Историю перестановок и запустить пересчёт —
            // иначе перевод «теряется» (инцидент Салькова 2026-08).
            if ((int) $prevInviterId !== (int) $newInviterId) {
                $inviterTransfer = [
                    'oldId' => $prevInviterId, 'oldName' => $prevInviterName,
                    'newId' => $newInviterId, 'newName' => $consultant->inviterName,
                ];
            }
        }

    }

    /**
     * Контакты партнёра С логином: они живут в WebUser.
     *
     * ⚠ Блокировка отзывает токены — иначе залогиненный партнёр продолжает
     * работать до их истечения. Правка ФИО каскадом уходит в personName.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $diff
     */
    private function applyWebUserFields(object $consultant, array $data, array &$diff): void
    {
            $current = DB::table('WebUser')->where('id', $consultant->webUser)->first();

            $userUpdates = [];
            $map = ['firstName', 'lastName', 'patronymic', 'email', 'phone', 'nicTG', 'gender', 'birthDate', 'role'];
            foreach ($map as $col) {
                if (! array_key_exists($col, $data)) continue;
                $new = $data[$col] ?: null;
                $old = $current->{$col} ?? null;
                if ((string) $old !== (string) $new) {
                    $diff[$col] = ['from' => $old, 'to' => $new];
                }
                $userUpdates[$col] = $new;
            }
            if (array_key_exists('isBlocked', $data)) {
                $newBlocked = (bool) $data['isBlocked'];
                $oldBlocked = (bool) ($current->isBlocked ?? false);
                if ($newBlocked !== $oldBlocked) {
                    $diff['isBlocked'] = ['from' => $oldBlocked, 'to' => $newBlocked];
                }
                $userUpdates['isBlocked'] = $newBlocked;
            }
            if (! empty($data['newPassword'])) {
                $userUpdates['password'] = \Illuminate\Support\Facades\Hash::make($data['newPassword']);
                $diff['password'] = ['from' => '***', 'to' => '***'];
            }
            if (! empty($userUpdates)) {
                DB::table('WebUser')->where('id', $consultant->webUser)->update($userUpdates);
            }

            // При блокировке отзываем токены — иначе залогиненный партнёр
            // работает до истечения токена (≤7 дней).
            if (! empty($userUpdates['isBlocked'])) {
                \App\Models\User::find($consultant->webUser)?->tokens()->delete();
            }

            // Keep consultant.personName in sync with WebUser name parts
            if (isset($userUpdates['firstName']) || isset($userUpdates['lastName']) || isset($userUpdates['patronymic'])) {
                $u = DB::table('WebUser')->where('id', $consultant->webUser)->first();
                $consultant->personName = trim("{$u->lastName} {$u->firstName} {$u->patronymic}");
            }
    }

    /**
     * Контакты партнёра БЕЗ логина: WebUser нет, всё пишется в собственные
     * колонки карточки. Раньше этой ветки не было вовсе, и правка карточки
     * такого партнёра молча не сохранялась (893 импортированных ФК).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $diff
     */
    private function applyCardFields(object $consultant, array $data, array &$diff): void
    {
            // Партнёр без логина: WebUser'а, куда писать контакты, нет —
            // ведём их в собственных колонках. Раньше вся эта ветка
            // отсутствовала, и правка карточки такого партнёра молча
            // не сохранялась (893 импортированных ФК).
            foreach (['email', 'phone', 'birthDate'] as $col) {
                if (! array_key_exists($col, $data)) continue;
                $new = $data[$col] ?: null;
                $old = $consultant->{$col};
                if ((string) $old !== (string) $new) {
                    $diff[$col] = ['from' => $old, 'to' => $new];
                }
                $consultant->{$col} = $new;
            }

            $hasNameEdit = array_key_exists('lastName', $data)
                || array_key_exists('firstName', $data)
                || array_key_exists('patronymic', $data);
            if ($hasNameEdit) {
                $parts = preg_split('/\s+/u', trim((string) $consultant->personName)) ?: [];
                $name = trim(implode(' ', array_filter([
                    $data['lastName'] ?? ($parts[0] ?? null),
                    $data['firstName'] ?? ($parts[1] ?? null),
                    $data['patronymic'] ?? ($parts[2] ?? null),
                ])));
                if ($name !== '' && $name !== (string) $consultant->personName) {
                    $diff['personName'] = ['from' => $consultant->personName, 'to' => $name];
                    $consultant->personName = $name;
                }
            }
    }
}
