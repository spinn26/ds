<?php

/**
 * Env-фолбэки для ApiSettingsService.
 *
 * Сервис резолвит ключ интеграции как БД → env → default. Читать env()
 * прямо из сервиса нельзя: деплой выполняет `artisan config:cache`, после
 * чего Laravel не загружает .env вообще и env() в рантайме возвращает null.
 * Фолбэк молча превращался в default, а диагностика в админке показывала
 * «в env не задано» для реально заданных ключей.
 *
 * Здесь значения читаются на этапе сборки конфига и попадают в кэш.
 * Новый ключ с 'envFallback' в ApiSettingsService::CATALOG нужно добавить
 * и сюда — иначе фолбэк для него работать не будет.
 */

return [

    'env' => [
        'BUBBLE_API_TOKEN' => env('BUBBLE_API_TOKEN'),
        'CHECKO_API_KEY' => env('CHECKO_API_KEY'),
        'DADATA_API_KEY' => env('DADATA_API_KEY'),
        'DADATA_SECRET_KEY' => env('DADATA_SECRET_KEY'),
        'GETCOURSE_API_KEY' => env('GETCOURSE_API_KEY'),
        'GOOGLE_PLACES_API_KEY' => env('GOOGLE_PLACES_API_KEY'),
        'GOOGLE_SA_CREDENTIALS_PATH' => env('GOOGLE_SA_CREDENTIALS_PATH'),
        'GOOGLE_SHEETS_API_KEY' => env('GOOGLE_SHEETS_API_KEY'),
        'GOOGLE_SHEETS_EXPORT_ID' => env('GOOGLE_SHEETS_EXPORT_ID'),
        'INSMART_API_BASE_URL' => env('INSMART_API_BASE_URL'),
        'INSMART_API_KEY' => env('INSMART_API_KEY'),
        'INSMART_WEBHOOK_SECRET' => env('INSMART_WEBHOOK_SECRET'),
        'TELEGRAM_BOT_TOKEN' => env('TELEGRAM_BOT_TOKEN'),
        'TELEGRAM_STATUS_CHAT_ID' => env('TELEGRAM_STATUS_CHAT_ID'),
    ],

];
