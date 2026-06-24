<?php

/**
 * Конфигурация прав доступа — single source of truth на бэкенде.
 *
 * sections — список всех известных «разделов меню», на которые может
 * распространяться permission. Ключ должен совпадать с adminSection
 * в menuItems (resources/js/layouts/MainLayout.vue) и с ключами в
 * cabinetPermissions.js (фронтовый fallback).
 *
 * При добавлении нового раздела в меню нужно:
 *  1) добавить запись в этот config,
 *  2) (опционально) обновить cabinetPermissions.js, чтобы фронт-fallback
 *     знал про секцию;
 *  3) для существующих групп — выставить уровень через
 *     /manage/permissions (или прямо в seeder'е).
 */
return [
    'levels' => ['view', 'edit', 'full'],

    'sections' => [
        ['key' => 'calculator',              'label' => 'Калькулятор объёмов'],
        ['key' => 'structure',               'label' => 'Структура'],
        ['key' => 'partners',                'label' => 'Партнёры'],
        ['key' => 'statuses',                'label' => 'Статусы партнёров'],
        ['key' => 'clients',                 'label' => 'Клиенты'],
        ['key' => 'contracts',               'label' => 'Менеджер контрактов'],
        ['key' => 'upload',                  'label' => 'Загрузка контрактов'],
        ['key' => 'acceptance',              'label' => 'Акцепт документов'],
        ['key' => 'requisites',              'label' => 'Реквизиты'],
        ['key' => 'bank-changes',            'label' => 'Смена реквизитов'],
        ['key' => 'transfers',               'label' => 'Перестановки'],
        ['key' => 'import',                  'label' => 'Импорт транзакций'],
        ['key' => 'transactions',            'label' => 'Транзакции (Manual)'],
        ['key' => 'commissions',             'label' => 'Комиссии'],
        ['key' => 'pool',                    'label' => 'Пул'],
        ['key' => 'qualifications',          'label' => 'Квалификации'],
        ['key' => 'charges',                 'label' => 'Прочие начисления'],
        ['key' => 'payments',                'label' => 'Реестр выплат'],
        ['key' => 'currencies',              'label' => 'Валюты и НДС'],
        ['key' => 'products',                'label' => 'Продукты / Инструкции'],
        ['key' => 'education',               'label' => 'Конструктор курсов'],
        ['key' => 'education-categories',    'label' => 'Категории курсов'],
        ['key' => 'education-analytics',     'label' => 'Статистика обучения'],
        ['key' => 'homework',                'label' => 'Проверка домашек'],
        ['key' => 'kb',                      'label' => 'База знаний'],
        ['key' => 'workspace',               'label' => 'Рабочий стол staff'],
        ['key' => 'tasks',                   'label' => 'Задачи и проекты'],
        ['key' => 'org-structure',           'label' => 'Структура компании'],
        ['key' => 'partner-questionnaires',  'label' => 'Анкеты партнёров'],
        ['key' => 'communication',           'label' => 'Чат / Тикеты'],
        ['key' => 'support-desk',            'label' => 'Тех. поддержка (desk)'],
        ['key' => 'chat-analytics',          'label' => 'Аналитика чата'],
        ['key' => 'reports',                 'label' => 'Отчёты'],
        ['key' => 'reports-access',          'label' => 'Доступность отчётов'],
        ['key' => 'instructions',            'label' => 'Инструкции / База знаний'],
        ['key' => 'contests',                'label' => 'Конкурсы и события'],
        ['key' => 'permissions',             'label' => 'Группы и права'],
        ['key' => 'owner-dashboard',         'label' => 'Дашборд руководителя'],
        ['key' => 'sales-matrix',            'label' => 'Матрица продаж'],
        ['key' => 'management-currencies',   'label' => 'Курсы для отчётов'],
        ['key' => 'reconciliation',          'label' => 'Реконсиляция'],
        ['key' => 'anomalies',               'label' => 'Аномалии'],
        ['key' => 'funnel',                  'label' => 'Воронка партнёров'],
        ['key' => 'cohorts',                 'label' => 'Когорты'],
    ],
];
