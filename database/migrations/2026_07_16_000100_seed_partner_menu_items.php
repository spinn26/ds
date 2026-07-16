<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Заливка стандартного партнёрского меню в Конструктор меню (menu_items).
 *
 * После этого сида партнёрское меню полностью управляется из
 * /admin/menu-builder: MainLayout при наличии хотя бы одного пункта
 * area=partner строит меню кабинета из БД (статический список остаётся
 * фолбэком на случай пустой таблицы / ошибки загрузки).
 *
 * Спец-значение to='action:founder-message' — диалог «Написать
 * ген.директору» (не URL); маппится на openQuickMsg в MainLayout.
 */
return new class extends Migration
{
    /** @return list<array{group:?string,title:string,icon:string,to:string,external:bool}> */
    private function items(): array
    {
        return [
            ['group' => 'Обзор', 'title' => 'Дашборд', 'icon' => 'mdi-view-dashboard-outline', 'to' => '/dashboard', 'external' => false],
            ['group' => 'Обзор', 'title' => 'Отчёт начислений', 'icon' => 'mdi-bank-outline', 'to' => '/finance/report', 'external' => false],
            ['group' => 'Обзор', 'title' => 'Реестр выплат', 'icon' => 'mdi-cash-register', 'to' => '/my-payments', 'external' => false],

            ['group' => 'Работа', 'title' => 'Калькулятор объёмов', 'icon' => 'mdi-calculator', 'to' => '/finance/calculator', 'external' => false],
            ['group' => 'Работа', 'title' => 'Мои клиенты', 'icon' => 'mdi-account-group-outline', 'to' => '/clients', 'external' => false],
            ['group' => 'Работа', 'title' => 'Контракты клиентов', 'icon' => 'mdi-file-document-outline', 'to' => '/contracts', 'external' => false],
            ['group' => 'Работа', 'title' => 'Контракты команды', 'icon' => 'mdi-folder-account-outline', 'to' => '/contracts/team', 'external' => false],
            ['group' => 'Работа', 'title' => 'Структура', 'icon' => 'mdi-sitemap-outline', 'to' => '/structure', 'external' => false],

            ['group' => 'Развитие', 'title' => 'Обучение', 'icon' => 'mdi-school-outline', 'to' => '/education', 'external' => false],
            ['group' => 'Развитие', 'title' => 'База знаний', 'icon' => 'mdi-book-education-outline', 'to' => '/education/kb', 'external' => false],
            ['group' => 'Развитие', 'title' => 'Инструкции', 'icon' => 'mdi-book-open-variant', 'to' => '/instructions', 'external' => false],
            ['group' => 'Развитие', 'title' => 'Статус системы', 'icon' => 'mdi-monitor-dashboard', 'to' => '/status', 'external' => false],
            ['group' => 'Развитие', 'title' => 'Продукты', 'icon' => 'mdi-package-variant-closed', 'to' => '/products', 'external' => false],
            ['group' => 'Развитие', 'title' => 'ФинРывок', 'icon' => 'mdi-rocket-launch-outline', 'to' => 'https://ds.igron.games/auth/login', 'external' => true],

            ['group' => 'Связь', 'title' => 'Мои обращения', 'icon' => 'mdi-chat-outline', 'to' => '/chat', 'external' => false],
            ['group' => 'Связь', 'title' => 'Тех. поддержка', 'icon' => 'mdi-lifebuoy', 'to' => 'https://t.me/DS_Helpdesk', 'external' => true],
            ['group' => 'Связь', 'title' => 'Поддержка по продукту', 'icon' => 'mdi-package-variant', 'to' => '/chat?new=backoffice', 'external' => false],
            ['group' => 'Связь', 'title' => 'Верификация реквизитов', 'icon' => 'mdi-credit-card-check', 'to' => '/chat?new=accruals', 'external' => false],
            ['group' => 'Связь', 'title' => 'Написать ген.директору', 'icon' => 'mdi-email-edit-outline', 'to' => 'action:founder-message', 'external' => false],
            ['group' => 'Связь', 'title' => 'Оставить кейс', 'icon' => 'mdi-briefcase-plus-outline', 'to' => 'https://dsconsalting.academy/bankcases', 'external' => true],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        // Идемпотентность ПО-ПУНКТНО: пропускаем пункты, которые уже есть
        // (title+to). Гард «есть хоть один партнёрский → выйти» был ошибкой:
        // на проде уже лежал один ручной пункт («Банк Кейсов»), сид
        // пропустился целиком и партнёрское меню схлопнулось бы до него.
        $existing = DB::table('menu_items')
            ->where('area', 'partner')
            ->get(['title', 'to'])
            ->map(fn ($r) => $r->title.'|'.$r->to)
            ->flip();

        $now = now();
        $rows = [];
        $sort = 10;
        foreach ($this->items() as $i) {
            if (isset($existing[$i['title'].'|'.$i['to']])) {
                $sort += 10;
                continue;
            }
            $rows[] = [
                'area' => 'partner',
                'group_title' => $i['group'],
                'title' => $i['title'],
                'icon' => $i['icon'],
                'to' => $i['to'],
                'external' => $i['external'],
                'roles' => null,
                'sort_order' => $sort,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $sort += 10;
        }
        if ($rows) {
            DB::table('menu_items')->insert($rows);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }
        // Удаляем только засеянные пункты (по точному совпадению title+to),
        // не трогая созданные админом вручную.
        foreach ($this->items() as $i) {
            DB::table('menu_items')
                ->where('area', 'partner')
                ->where('title', $i['title'])
                ->where('to', $i['to'])
                ->delete();
        }
    }
};
