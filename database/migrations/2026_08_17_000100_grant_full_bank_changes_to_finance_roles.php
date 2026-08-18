<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Полный доступ к разделу «Смена реквизитов» для расчётов и финменеджера.
 *
 * Подтверждение смены банковского счёта раньше было закрыто жёстким списком
 * ролей (`role:admin,finance`) и сетку прав не читало вовсе. Теперь маршруты
 * идут через `permission:bank-changes,full`, поэтому уровни в группах надо
 * привести в соответствие с фактическим кругом допущенных:
 *
 *   - calculations (руководитель по расчётам, Богданова) — раздел ей и нужен,
 *     заявки проверяет она; в сетке стояла «Правка», подтверждать не могла;
 *   - finance — подтверждать МОГ по прежнему role-гейту, хотя в сетке стоял
 *     только «Просмотр». Поднимаем до «Полного», чтобы переход на сетку
 *     никого не лишил доступа молча.
 *
 * Значения ниже — минимальные: у кого уровень уже выше или равен, не трогаем.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const GROUPS = ['calculations', 'finance'];

    public function up(): void
    {
        foreach (self::GROUPS as $key) {
            $row = DB::table('permission_groups')->where('key', $key)->first();
            if (! $row) {
                continue;
            }

            $perms = json_decode((string) $row->permissions, true) ?: [];
            if (($perms['bank-changes'] ?? null) === 'full') {
                continue;
            }

            $perms['bank-changes'] = 'full';
            DB::table('permission_groups')->where('key', $key)->update([
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Возврат к прежним уровням: расчётам «Правка», финменеджеру «Просмотр».
     * Трогаем только то, что подняли сами, — если уровень уже другой, значит
     * его поменяли из админки, и перетирать чужое решение нельзя.
     */
    public function down(): void
    {
        $previous = ['calculations' => 'edit', 'finance' => 'view'];

        foreach ($previous as $key => $level) {
            $row = DB::table('permission_groups')->where('key', $key)->first();
            if (! $row) {
                continue;
            }

            $perms = json_decode((string) $row->permissions, true) ?: [];
            if (($perms['bank-changes'] ?? null) !== 'full') {
                continue;
            }

            $perms['bank-changes'] = $level;
            DB::table('permission_groups')->where('key', $key)->update([
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
};
