<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Новости кабинета: тег, анонс, обложка, закрепление и отметки прочтения.
 *
 * Таблица `news` до сих пор жила без миграции — её создавал на лету
 * WorkspaceController::ensureNewsTable() сырым SQL. Поэтому здесь два шага:
 * создать таблицу, если её нет (чистая установка и тестовая БД), и дописать
 * новые колонки, если она уже есть (прод).
 *
 * Зачем колонки (per ds-redesign/design/components/NewsCard.md):
 *   kind          — тег новости: promo | update. Прежний `type`
 *                   (info/warning/success) остаётся: он красит плашку в
 *                   старом виджете и в админке, но тегом не является.
 *   excerpt       — лид в 1–2 предложения для карточки. Раньше в ленту
 *                   вываливался весь HTML новости целиком.
 *   cover_url     — загруженная обложка; без неё рисуется обложка из токенов.
 *   pinned        — закреплённая новость идёт первой в ленте.
 *   published_at  — дата публикации отдельно от created_at: новость можно
 *                   завести заранее и опубликовать задним числом.
 *   meta          — параметры акции, подписи обложки и внешняя ссылка (CTA).
 *                   Живут в самой новости, чтобы следующая акция заводилась
 *                   редактором, а не правкой кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('news')) {
            Schema::create('news', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->text('title');
                $table->text('content')->nullable();
                $table->string('type')->default('info');
                $table->boolean('active')->default(true);
                $table->integer('created_by')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'kind')) {
                $table->string('kind', 16)->default('update');
            }
            if (! Schema::hasColumn('news', 'excerpt')) {
                $table->text('excerpt')->nullable();
            }
            if (! Schema::hasColumn('news', 'cover_url')) {
                $table->string('cover_url', 512)->nullable();
            }
            if (! Schema::hasColumn('news', 'pinned')) {
                $table->boolean('pinned')->default(false);
            }
            if (! Schema::hasColumn('news', 'published_at')) {
                $table->timestamp('published_at')->nullable();
            }
            if (! Schema::hasColumn('news', 'meta')) {
                $table->jsonb('meta')->nullable();
            }
        });

        // Дата публикации у прежних новостей — дата создания: иначе лента
        // отсортируется пустотой и старые записи уедут в конец.
        DB::statement('UPDATE news SET published_at = created_at WHERE published_at IS NULL');

        // Лента сортируется по закреплению и дате — индекс под неё.
        if (! $this->indexExists('news_pinned_published_idx')) {
            DB::statement('CREATE INDEX news_pinned_published_idx ON news (pinned DESC, published_at DESC)');
        }

        // Отметки прочтения: метка «Новое» у непрочитанной новости и счётчик
        // на вкладке «Все». Храним факт, а не счётчик — пересчитывается сам.
        if (! Schema::hasTable('news_reads')) {
            Schema::create('news_reads', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('news_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('read_at')->useCurrent();

                $table->unique(['news_id', 'user_id']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('news_reads');

        Schema::table('news', function (Blueprint $table) {
            foreach (['kind', 'excerpt', 'cover_url', 'pinned', 'published_at', 'meta'] as $column) {
                if (Schema::hasColumn('news', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        DB::statement('DROP INDEX IF EXISTS news_pinned_published_idx');
    }

    /** Индексы в Postgres живут в своём каталоге, Schema их не показывает. */
    private function indexExists(string $name): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('indexname', $name)
            ->exists();
    }
};
