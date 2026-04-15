<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Тикеты
        Schema::create('chat_tickets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('new'); // new, open, pending, resolved, closed
            $table->string('priority', 20)->default('medium'); // critical, high, medium, low
            $table->string('department', 30)->default('general'); // technical, billing, sales, general
            $table->integer('created_by'); // WebUser ID (partner = customer)
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->integer('assigned_to')->nullable(); // WebUser ID (staff)
            $table->string('assigned_name')->nullable();
            $table->json('tags')->nullable();
            $table->integer('messages_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('priority');
            $table->index('department');
            $table->index('created_by');
            $table->index('assigned_to');
        });

        // Сообщения
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ticket_id');
            $table->integer('sender_id'); // WebUser ID
            $table->string('sender_name');
            $table->text('content');
            $table->boolean('is_agent')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->timestamps();
            $table->foreign('ticket_id')->references('id')->on('chat_tickets')->onDelete('cascade');
            $table->index('ticket_id');
        });

        // Внутренние заметки (видны только сотрудникам)
        Schema::create('chat_internal_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ticket_id');
            $table->integer('author_id'); // WebUser ID
            $table->string('author_name');
            $table->text('content');
            $table->timestamps();
            $table->foreign('ticket_id')->references('id')->on('chat_tickets')->onDelete('cascade');
            $table->index('ticket_id');
        });

        // Быстрые ответы (шаблоны для сотрудников)
        Schema::create('chat_quick_replies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->text('content');
            $table->string('category', 50)->default('general');
            $table->string('shortcut', 20)->nullable(); // /hi, /solved, etc
            $table->timestamps();
        });

        // База знаний
        Schema::create('chat_knowledge_articles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->text('content');
            $table->string('category', 50);
            $table->json('tags')->nullable();
            $table->integer('views')->default(0);
            $table->integer('helpful')->default(0);
            $table->timestamps();
        });

        // Seed quick replies
        \DB::table('chat_quick_replies')->insert([
            ['title' => 'Приветствие', 'content' => 'Добрый день! Благодарим за обращение в службу поддержки. Меня зовут {agent_name}, и я помогу вам решить ваш вопрос.', 'category' => 'Общие', 'shortcut' => '/hi', 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Уточнение деталей', 'content' => "Для того чтобы мы могли помочь вам максимально быстро, пожалуйста, уточните:\n1. Когда возникла проблема?\n2. Какие действия вы выполняли?\n3. Есть ли сообщение об ошибке?", 'category' => 'Техподдержка', 'shortcut' => '/details', 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Проблема решена', 'content' => 'Рады сообщить, что ваша проблема успешно решена! Если у вас возникнут дополнительные вопросы, не стесняйтесь обращаться.', 'category' => 'Завершение', 'shortcut' => '/solved', 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Передача специалисту', 'content' => 'Ваш запрос требует дополнительной экспертизы, поэтому я передаю его нашему специалисту. Он свяжется с вами в ближайшее время.', 'category' => 'Эскалация', 'shortcut' => '/escalate', 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Запрос скриншота', 'content' => 'Для более точной диагностики, пожалуйста, приложите скриншот ошибки или видео.', 'category' => 'Техподдержка', 'shortcut' => '/screen', 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Информация о платеже', 'content' => "Для оформления счёта нам потребуются:\n- Полное наименование организации\n- ИНН\n- КПП\n- Юридический адрес\n- Банковские реквизиты", 'category' => 'Биллинг', 'shortcut' => '/invoice', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_knowledge_articles');
        Schema::dropIfExists('chat_quick_replies');
        Schema::dropIfExists('chat_internal_notes');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_tickets');
    }
};
