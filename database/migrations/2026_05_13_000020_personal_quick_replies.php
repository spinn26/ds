<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личные шаблоны быстрых ответов: chat_quick_replies.created_by → WebUser.id.
 *  - null: глобальный шаблон, видят все сотрудники (как и раньше);
 *  - integer: личный шаблон, видит только автор.
 *
 * Существующие сидовые шаблоны остаются с created_by=null — никаких
 * миграционных переносов; UI редактирования личных шаблонов работает
 * сразу после миграции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_quick_replies', function (Blueprint $table) {
            $table->integer('created_by')->nullable()->after('shortcut');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('chat_quick_replies', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
