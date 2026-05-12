<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Initial seed для permission_groups. Соответствует
 * resources/js/config/cabinetPermissions.js (yonote-спеки кабинетов).
 *
 * Идемпотентен: если группа уже есть — обновляет permissions, иначе
 * создаёт. Это позволяет безопасно перезапустить seeder при правках
 * yonote-спек.
 */
class PermissionGroupsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $groups = [
            [
                'key' => 'admin',
                'name' => 'Администратор',
                'description' => 'Полный доступ ко всем разделам и системным действиям.',
                'is_system' => true,
                'permissions' => '__ALL_FULL__',  // спец. токен — admin не описывается явно
            ],
            [
                'key' => 'backoffice',
                'name' => 'Кабинет БЭК-офиса',
                'description' => 'Сотрудники бэк-офиса: ведение клиентов, контрактов, обработка тикетов.',
                'is_system' => true,
                'permissions' => [
                    'calculator' => 'full',
                    'structure' => 'view',
                    'contracts' => 'edit',          // спека: ручное добавление + редактирование, без удаления
                    'upload' => 'full',
                    'clients' => 'edit',            // спека: двухшаговое добавление + редактирование, без удаления
                    'partners' => 'edit',
                    'statuses' => 'view',
                    'transfers' => 'view',
                    'acceptance' => 'view',
                    'commissions' => 'view',
                    'reports' => 'edit',            // спека: формирование + выгрузка, без публикации
                    'products' => 'view',
                    'communication' => 'edit',      // спека: обработка тикетов своей ветки, без закрытия
                    'chat-analytics' => 'view',
                    'pool' => 'view',
                    'partner-questionnaires' => 'view',
                    'requisites' => 'view',
                    'instructions' => 'view',
                ],
            ],
            [
                'key' => 'support',
                'name' => 'Техподдержка',
                'description' => 'Helpdesk: обработка тикетов от партнёров, маршрутизация технических проблем.',
                'is_system' => true,
                'permissions' => [
                    'partners' => 'view',
                    'structure' => 'view',
                    'statuses' => 'view',
                    'acceptance' => 'view',
                    'products' => 'edit',
                    'clients' => 'view',
                    'contracts' => 'view',
                    'communication' => 'edit',      // спека: ведение/закрытие своих тикетов
                    'support-desk' => 'full',
                    'calculator' => 'view',
                    'partner-questionnaires' => 'view',
                    'instructions' => 'edit',       // спека: добавление/редактирование разделов БЗ
                ],
            ],
            [
                'key' => 'head',
                'name' => 'Кабинет Руководителя',
                'description' => 'Read-Only обзор всей платформы + полный доступ к управленческой аналитике.',
                'is_system' => true,
                'permissions' => [
                    'calculator' => 'view',
                    'structure' => 'view',
                    'contracts' => 'view',
                    'clients' => 'view',
                    'partners' => 'view',
                    'statuses' => 'view',
                    'acceptance' => 'view',
                    'transfers' => 'view',
                    'products' => 'view',
                    'reports' => 'full',
                    'communication' => 'view',
                    'support-desk' => 'view',
                    'chat-analytics' => 'view',
                    'pool' => 'view',
                    'partner-questionnaires' => 'view',
                    'owner-dashboard' => 'full',
                    'reconciliation' => 'full',
                    'anomalies' => 'full',
                    'funnel' => 'full',
                    'cohorts' => 'full',
                    'instructions' => 'view',
                ],
            ],
            [
                'key' => 'finance',
                'name' => 'Кабинет фин. менеджера',
                'description' => 'Реестр выплат, начисления, отчёты для бухгалтерии.',
                'is_system' => true,
                'permissions' => [
                    'calculator' => 'edit',         // спека: расчёты, без системных действий
                    'payments' => 'full',
                    'charges' => 'full',
                    'reports' => 'edit',            // спека: формирование + выгрузка, без публикации
                    'requisites' => 'view',
                    'pool' => 'view',
                    'communication' => 'edit',
                ],
            ],
            [
                'key' => 'calculations',
                'name' => 'Кабинет руководителя по расчётам (Богданова)',
                'description' => 'Полный доступ к расчётам комиссий, пулу, валютам, доступности отчётов.',
                'is_system' => true,
                'permissions' => [
                    'calculator' => 'full',
                    'structure' => 'view',
                    'import' => 'full',
                    'transactions' => 'full',
                    'commissions' => 'edit',        // спека: настройка интерфейса/скрытие колонок
                    'charges' => 'full',
                    'pool' => 'full',
                    'qualifications' => 'view',
                    'reports' => 'edit',            // формирование/выгрузка — edit, публикация — отдельная секция reports-access
                    'reports-access' => 'full',     // публикация, закрытие периода, принудительный пересчёт
                    'partners' => 'edit',
                    'requisites' => 'edit',
                    'statuses' => 'full',
                    'acceptance' => 'view',
                    'transfers' => 'view',
                    'currencies' => 'full',
                    'payments' => 'full',
                    'products' => 'full',
                    'contracts' => 'view',
                    'clients' => 'view',
                    'communication' => 'edit',      // спека: переписка по реквизитам/финвопросам
                ],
            ],
            [
                'key' => 'corrections',
                'name' => 'Правки',
                'description' => 'Узкая роль для read-only доступа к данным партнёров и контрактов (Жарков, Минакова).',
                'is_system' => true,
                'permissions' => [
                    'calculator' => 'view',
                    'clients' => 'view',
                    'contracts' => 'view',
                    'partners' => 'view',
                ],
            ],
            [
                'key' => 'education',
                'name' => 'Куратор обучения',
                'description' => 'Конструктор LMS, статистика обучения, анкеты партнёров.',
                'is_system' => true,
                'permissions' => [
                    'education' => 'full',
                    'education-analytics' => 'full',
                    'partner-questionnaires' => 'full',
                    'partners' => 'view',
                    'products' => 'view',
                    'communication' => 'edit',
                    'instructions' => 'view',
                ],
            ],
        ];

        foreach ($groups as $g) {
            $permissionsJson = $g['permissions'] === '__ALL_FULL__'
                ? '{}'
                : json_encode($g['permissions'], JSON_UNESCAPED_UNICODE);
            DB::table('permission_groups')->updateOrInsert(
                ['key' => $g['key']],
                [
                    'name' => $g['name'],
                    'description' => $g['description'],
                    'is_system' => $g['is_system'],
                    'permissions' => $permissionsJson,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
