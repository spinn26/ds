<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anti-double-click + fuzzy-match warnings для импорта транзакций
 * (правки 2026-05-22):
 *  - file_hash: SHA256 содержимого источника. Блокируем повторный диспатч
 *    того же файла/листа за 5-минутное окно, пока предыдущий job в
 *    processing/pending.
 *  - warnings: JSON-массив информационных предупреждений (fuzzy ilike
 *    match, period-freeze hit и т.п.). НЕ ошибки — импорт пройдёт,
 *    но оператор увидит список «строка X: контракт найден по частичному
 *    совпадению, проверьте».
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transaction_import_log', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_import_log', 'file_hash')) {
                $table->string('file_hash', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('transaction_import_log', 'warnings')) {
                $table->text('warnings')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_import_log', function (Blueprint $table) {
            if (Schema::hasColumn('transaction_import_log', 'file_hash')) {
                $table->dropIndex(['file_hash']);
                $table->dropColumn('file_hash');
            }
            if (Schema::hasColumn('transaction_import_log', 'warnings')) {
                $table->dropColumn('warnings');
            }
        });
    }
};
