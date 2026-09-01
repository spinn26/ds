<?php

/**
 * v1 — публичные signed-роуты (middleware 'signed', без auth:sanctum).
 *
 * Скачивание файлов по временной подписи: браузер при переходе по ссылке
 * не передаёт Authorization-заголовок, поэтому Bearer-авторизация тут
 * не подходит. Подпись выдаёт бэкенд уже после проверки прав.
 *
 * Файл подключается через require из routes/api.php внутри
 * Route::prefix('v1')->group(...) — префикс v1 наследуется автоматически.
 */

use Illuminate\Support\Facades\Route;



// Документы партнёра (паспорта/заявления) — публичный signed-роут.
// Подпись (URL::temporarySignedRoute) выдаётся бэком в /documents и
// /documents/upload только владельцу. Файлы лежат в private storage
// (local), браузер ходит сюда без Bearer-токена.
Route::get('/documents/{consultantId}/{type}', [\App\Http\Controllers\Api\DocumentController::class, 'download'])
    ->whereNumber('consultantId')
    ->name('documents.download')
    ->middleware('signed');

// Скачивание вложений чата — публичный signed-роут.
// Подпись (URL::temporarySignedRoute) выдаётся бэком уже после
// авторизации в getMessages, имеет короткий expiry. Браузер при
// клике по ссылке не передаёт Authorization Bearer — поэтому
// обычный auth:sanctum middleware тут не подходит.
Route::get('/chat/messages/{messageId}/attachment', [\App\Http\Controllers\Api\ChatController::class, 'downloadAttachment'])
    ->whereNumber('messageId')
    ->name('chat.attachment')
    ->middleware('signed');
