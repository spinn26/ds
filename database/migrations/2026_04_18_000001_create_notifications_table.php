<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            // Table was bootstrapped by NotificationController::ensureTable().
            // Add missing indexes idempotently.
            Schema::table('notifications', function (Blueprint $table) {
                if (! $this->indexExists('notifications', 'notifications_user_id_read_index')) {
                    $table->index(['user_id', 'read'], 'notifications_user_id_read_index');
                }
                if (! $this->indexExists('notifications', 'notifications_user_id_created_at_index')) {
                    $table->index(['user_id', 'created_at'], 'notifications_user_id_created_at_index');
                }
            });
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 32)->default('system');
            $table->text('title');
            $table->text('message')->nullable();
            $table->string('icon', 64)->default('mdi-bell');
            $table->string('color', 32)->default('grey');
            $table->string('link')->nullable();
            $table->boolean('read')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read'], 'notifications_user_id_read_index');
            $table->index(['user_id', 'created_at'], 'notifications_user_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = \DB::select(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $index]
        );
        return ! empty($rows);
    }
};
