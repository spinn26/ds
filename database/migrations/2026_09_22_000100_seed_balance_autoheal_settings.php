<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки автопочинки снимка начислений (finance:autoheal-balances).
 *
 * Выключатель и порог вынесены в админку намеренно: автомат пишет в
 * consultantBalance — таблицу, из которой реестр берёт деньги к выплате.
 * Если он начнёт вести себя не так, руководитель расчётов должен уметь
 * остановить его сам, без деплоя.
 *
 * Порог 100 000 ₽ — не «допустимая ошибка», а граница масштаба: рутинный
 * дрейф на несколько тысяч автомат чинит молча, а расхождение размером с
 * фантомный период (27.08.2026 — 4,4 млн ₽) останавливает и зовёт человека.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $now = now();
        $rows = [
            [
                'key' => 'finance.balance_autoheal',
                'value' => '1',
                'type' => 'bool',
                'label' => 'Автопочинка снимка начислений',
                'description' => 'Сторож раз в час сверяет реестр выплат с комиссиями и пересобирает снимок сам. Выключите, если пересборку нужно делать только кнопкой.',
                'sort_order' => 10,
            ],
            [
                'key' => 'finance.balance_autoheal_limit',
                'value' => '100000',
                'type' => 'float',
                'label' => 'Порог автопочинки, ₽',
                'description' => 'Расхождение больше этой суммы автомат не чинит — только сообщает в Telegram и ждёт человека.',
                'sort_order' => 20,
            ],
        ];

        foreach ($rows as $r) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $r['key']],
                [
                    'value' => $r['value'],
                    'type' => $r['type'],
                    'category' => 'finance',
                    'label' => $r['label'],
                    'description' => $r['description'],
                    'sort_order' => $r['sort_order'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        Cache::forget('system_settings:map');
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->whereIn('key', ['finance.balance_autoheal', 'finance.balance_autoheal_limit'])
                ->delete();
            Cache::forget('system_settings:map');
        }
    }
};
