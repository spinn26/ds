<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Несколько SMTP-ящиков, выбираемых через админ-UI.
 *
 * Один ящик помечен is_default=true — он используется, если в
 * applyRuntimeConfig() не передали явный id. Контроллер/Job могут
 * выбрать конкретный ящик для рассылки/системного письма.
 *
 * .env-настройки MAIL_* при наличии хотя бы одного ящика в БД
 * игнорируются (см. applyRuntimeConfig).
 */
class MailSettingsService
{
    /** Все ящики, отсортированные: default первым, потом по имени. */
    public function list(): Collection
    {
        return DB::table('mail_settings')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?object
    {
        return DB::table('mail_settings')->where('id', $id)->first();
    }

    public function default(): ?object
    {
        return DB::table('mail_settings')->where('is_default', true)->first()
            ?? DB::table('mail_settings')->orderBy('id')->first();
    }

    /**
     * Backward-compat: legacy-код, не знающий про множественные ящики,
     * получает default (или единственный).
     */
    public function current(): ?object
    {
        return $this->default();
    }

    /**
     * Создать/обновить ящик. $id=null → insert, иначе update.
     * Возвращает свежую запись.
     */
    public function save(?int $id, array $data): object
    {
        $payload = [
            'name' => $data['name'] ?? 'Без названия',
            'host' => $data['host'] ?? null,
            'port' => (int) ($data['port'] ?? 587),
            'username' => $data['username'] ?? null,
            'password' => $data['password'] ?? null,
            'encryption' => $data['encryption'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'updated_at' => now(),
        ];

        if ($id) {
            DB::table('mail_settings')->where('id', $id)->update($payload);
            return $this->find($id);
        }

        // Первый ящик автоматически становится default'ом.
        $payload['is_default'] = ! DB::table('mail_settings')->exists();
        $payload['created_at'] = now();
        $newId = DB::table('mail_settings')->insertGetId($payload);

        return $this->find($newId);
    }

    /**
     * Удалить ящик. Если удаляем default — назначаем default любому из
     * оставшихся (с наименьшим id), чтобы рассылки не сломались.
     */
    public function delete(int $id): bool
    {
        $row = $this->find($id);
        if (! $row) return false;

        DB::transaction(function () use ($row) {
            DB::table('mail_settings')->where('id', $row->id)->delete();
            if ($row->is_default) {
                $heir = DB::table('mail_settings')->orderBy('id')->first();
                if ($heir) {
                    DB::table('mail_settings')->where('id', $heir->id)->update(['is_default' => true]);
                }
            }
        });

        return true;
    }

    public function setDefault(int $id): bool
    {
        if (! $this->find($id)) return false;

        DB::transaction(function () use ($id) {
            DB::table('mail_settings')->where('is_default', true)->update(['is_default' => false]);
            DB::table('mail_settings')->where('id', $id)->update(['is_default' => true]);
        });

        return true;
    }

    /**
     * Подменяет config/mail.php-настройки на лету выбранным ящиком (или
     * default, если $id не передан). Возвращает true, если применённый
     * ящик имеет полный набор полей; иначе false (caller должен
     * прервать отправку).
     */
    public function applyRuntimeConfig(?int $id = null): bool
    {
        $s = $id ? $this->find($id) : $this->default();
        if (! $s || ! $s->host || ! $s->from_address) {
            return false;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $s->host,
            'port' => $s->port ?: 587,
            'encryption' => $s->encryption ?: null,
            'username' => $s->username,
            'password' => $s->password,
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ]);
        Config::set('mail.from', [
            'address' => $s->from_address,
            'name' => $s->from_name ?: $s->from_address,
        ]);

        return true;
    }
}
