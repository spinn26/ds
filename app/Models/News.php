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
 *
 * Поля перечислены аннотациями: анализатор не ходит в базу и без них считает
 * обращение к любой колонке обращением к несуществующему свойству.
 *
 * @property int $id
 * @property string $title
 * @property string|null $content
 * @property string|null $excerpt
 * @property string $type
 * @property string|null $kind
 * @property string|null $cover_url
 * @property bool $active
 * @property bool $pinned
 * @property array<string, mixed>|null $meta
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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

    /**
     * Дата публикации; у старых записей её нет — берём дату создания.
     *
     * Тип CarbonInterface, а не Illuminate\Support\Carbon: каст published_at
     * даёт один класс даты, штатный created_at — другой, и жёсткий тип
     * расходится с тем, что реально возвращается.
     */
    public function publishedAt(): ?\Carbon\CarbonInterface
    {
        return $this->published_at ?? $this->created_at;
    }
}
