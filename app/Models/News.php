<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Новость кабинета.
 *
 * Таблица до 21.09.2026 жила без модели и без миграции — её создавал на лету
 * WorkspaceController. Модель заведена вместе с миграцией
 * 2026_09_21_000100_extend_news_for_cabinet.
 *
 * `meta` хранит то, что у каждой новости своё и меняется редактором, а не
 * кодом: параметры акции, подписи сгенерированной обложки и внешнюю ссылку.
 */
class News extends Model
{
    protected $table = 'news';

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'pinned' => 'boolean',
        'meta' => 'array',
        'published_at' => 'datetime',
    ];

    /** Тег новости: промо или обновление. */
    public function isPromo(): bool
    {
        return $this->kind === 'promo';
    }

    /** Дата публикации; у старых записей её нет — берём дату создания. */
    public function publishedAt(): ?\Illuminate\Support\Carbon
    {
        return $this->published_at ?? $this->created_at;
    }
}
