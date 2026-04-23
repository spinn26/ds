<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publish-workflow для продуктов (spec §3 «Контент и публикация»).
 *
 * - publish_status: 'draft' | 'published'. Default 'published' для
 *   backward compat — существующие записи сразу на витрине.
 * - hero_image: URL большой картинки продукта для карточки (раньше был
 *   только imageUrl — использовался как логотип/иконка; hero_image —
 *   это баннер сверху карточки).
 * - published_at/published_by: audit-пара для отслеживания публикации.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product', function (Blueprint $t) {
            if (! Schema::hasColumn('product', 'publish_status'))  $t->string('publish_status', 20)->default('published');
            if (! Schema::hasColumn('product', 'hero_image'))      $t->string('hero_image', 500)->nullable();
            if (! Schema::hasColumn('product', 'published_at'))    $t->timestamp('published_at')->nullable();
            if (! Schema::hasColumn('product', 'published_by'))    $t->unsignedBigInteger('published_by')->nullable();
        });

        // Existing records — считаем published, но без published_at (не было события).
        DB::table('product')->whereNull('publish_status')->update(['publish_status' => 'published']);
    }

    public function down(): void
    {
        Schema::table('product', function (Blueprint $t) {
            foreach (['publish_status', 'hero_image', 'published_at', 'published_by'] as $c) {
                if (Schema::hasColumn('product', $c)) $t->dropColumn($c);
            }
        });
    }
};
