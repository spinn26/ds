<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->id();
                $table->string('subject');
                $table->string('category'); // support, backoffice, legal, accounting, accruals
                $table->integer('created_by'); // WebUser ID
                $table->integer('consultant_id')->nullable(); // consultant who created
                $table->string('status')->default('open'); // open, in_progress, resolved, closed
                $table->string('priority')->default('normal'); // low, normal, high
                $table->integer('assigned_to')->nullable(); // WebUser ID of staff
                $table->string('context_type')->nullable(); // dashboard, clients, contracts, products, payments
                $table->string('context_id')->nullable(); // specific record ID
                $table->text('context_info')->nullable(); // JSON with page context
                $table->timestamp('closed_at')->nullable();
                $table->integer('closed_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_messages')) {
            Schema::create('ticket_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
                $table->integer('user_id'); // WebUser ID
                $table->text('message')->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
                $table->boolean('is_system')->default(false); // system messages (redirect, close, etc)
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_participants')) {
            Schema::create('ticket_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
                $table->integer('user_id'); // WebUser ID
                $table->string('role')->default('participant'); // creator, assigned, participant
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_participants');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
