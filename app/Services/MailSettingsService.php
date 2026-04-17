<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Reads SMTP config from the mail_settings table (single row) and overrides
 * Laravel's mailer config at runtime. Used by MailController for admin-driven
 * broadcasts — the .env mail settings are ignored when the DB row is present.
 */
class MailSettingsService
{
    public function current(): ?object
    {
        return DB::table('mail_settings')->first();
    }

    public function save(array $data): object
    {
        $payload = [
            'host' => $data['host'] ?? null,
            'port' => (int) ($data['port'] ?? 587),
            'username' => $data['username'] ?? null,
            'password' => $data['password'] ?? null,
            'encryption' => $data['encryption'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'updated_at' => now(),
        ];

        $existing = $this->current();
        if ($existing) {
            DB::table('mail_settings')->where('id', $existing->id)->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table('mail_settings')->insert($payload);
        }

        return $this->current();
    }

    /**
     * Push the stored SMTP settings into Laravel's runtime mail config.
     * Returns true if a complete config was applied, false otherwise.
     */
    public function applyRuntimeConfig(): bool
    {
        $s = $this->current();
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
