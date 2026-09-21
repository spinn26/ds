<?php

namespace App\Services;

use App\Models\News;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Новости кабинета: лента, одна новость, отметки прочтения и действующая
 * акция.
 *
 * Акция не зашита в код: её параметры лежат в meta промо-новости, поэтому
 * следующую заводит редактор, а не разработчик (per ds-redesign/CLAUDE_TASK.md).
 */
class NewsService
{
    /** Скорость чтения для подписи «Читать · 2 мин». */
    private const WORDS_PER_MINUTE = 180;

    /**
     * Лента новостей для кабинета.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, unread: int}
     */
    public function feed(?int $userId, ?string $kind = null, int $limit = 10): array
    {
        $query = News::query()->where('active', true);

        if ($kind && in_array($kind, ['promo', 'update'], true)) {
            $query->where('kind', $kind);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('pinned')
            ->orderByDesc(DB::raw('COALESCE(published_at, created_at)'))
            ->limit($limit)
            ->get();

        $readIds = $this->readIds($userId, $rows->pluck('id')->all());

        return [
            'items' => $rows->map(fn (News $n) => $this->card($n, $readIds))->all(),
            'total' => $total,
            'unread' => $this->unreadCount($userId),
        ];
    }

    /**
     * Одна новость со всем, что нужно странице: текст, акция, CTA, соседи.
     *
     * @return array<string, mixed>|null
     */
    public function article(int $id, ?int $userId, ?float $promoCurrent = null): ?array
    {
        /** @var News|null $news */
        $news = News::query()->where('active', true)->find($id);
        if (! $news) {
            return null;
        }

        $readIds = $this->readIds($userId, [$news->id]);

        $others = News::query()
            ->where('active', true)
            ->where('id', '!=', $news->id)
            ->orderByDesc(DB::raw('COALESCE(published_at, created_at)'))
            ->limit(3)
            ->get();

        $otherReadIds = $this->readIds($userId, $others->pluck('id')->all());

        return $this->card($news, $readIds) + [
            'content' => $news->content,
            'cta' => $this->metaSection($news, 'cta') ?: null,
            // promo — посчитанная панель прогресса, promoMeta — то, что задал
            // редактор: правило, пример, оговорка, советы. Страница рисует по
            // ним блоки вместо того, чтобы верстать их руками в тексте.
            'promoMeta' => $news->isPromo() ? ($this->metaSection($news, 'promo') ?: null) : null,
            'promo' => $news->isPromo() ? $this->promoFrom($news, $promoCurrent) : null,
            'others' => $others->map(fn (News $n) => $this->card($n, $otherReadIds))->all(),
        ];
    }

    /** Отметка «прочитано». Повторный вызов ничего не ломает. */
    public function markRead(int $newsId, int $userId): void
    {
        DB::table('news_reads')->upsert(
            [['news_id' => $newsId, 'user_id' => $userId, 'read_at' => now()]],
            ['news_id', 'user_id'],
            ['read_at']
        );
    }

    /** Сколько активных новостей пользователь ещё не открывал. */
    public function unreadCount(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        return News::query()
            ->where('active', true)
            ->whereNotExists(function ($sub) use ($userId) {
                $sub->selectRaw('1')
                    ->from('news_reads')
                    ->whereColumn('news_reads.news_id', 'news.id')
                    ->where('news_reads.user_id', $userId);
            })
            ->count();
    }

    /**
     * Действующая акция — из промо-новости, у которой в meta заданы даты.
     *
     * @param float|null $current текущий результат партнёра по метрике акции
     */
    public function activePromo(?float $current = null, ?Carbon $now = null): ?array
    {
        $now ??= now();

        $news = News::query()
            ->where('active', true)
            ->where('kind', 'promo')
            ->orderByDesc(DB::raw('COALESCE(published_at, created_at)'))
            ->get()
            ->first(fn (News $n) => $this->promoIsRunning($n, $now));

        return $news ? $this->promoFrom($news, $current, $now) : null;
    }

    /**
     * Параметры из meta: там лежит всё, что задал редактор, и строгого типа у
     * содержимого нет. Достаём только массивы — иначе кривая запись в jsonb
     * уронит страницу обращением к строке как к массиву.
     *
     * @return array<string, mixed>
     */
    private function metaSection(News $news, string $key): array
    {
        $meta = $news->meta;
        $section = is_array($meta) ? ($meta[$key] ?? null) : null;

        return is_array($section) ? $section : [];
    }

    /** Идёт ли акция этой новости прямо сейчас. */
    private function promoIsRunning(News $news, Carbon $now): bool
    {
        $promo = $this->metaSection($news, 'promo');
        if ($promo === [] || empty($promo['from']) || empty($promo['to'])) {
            return false;
        }

        return $now->betweenIncluded(
            Carbon::parse((string) $promo['from'])->startOfDay(),
            Carbon::parse((string) $promo['to'])->endOfDay()
        );
    }

    /**
     * Панель акции: цель, текущий результат, месяцы и сколько дней осталось.
     * Считается от дат в meta, а не от захардкоженного «сен–дек 2026».
     */
    private function promoFrom(News $news, ?float $current, ?Carbon $now = null): ?array
    {
        $promo = $this->metaSection($news, 'promo');
        if ($promo === []) {
            return null;
        }

        $now ??= now();
        $from = ! empty($promo['from']) ? Carbon::parse((string) $promo['from']) : null;
        $to = ! empty($promo['to']) ? Carbon::parse((string) $promo['to']) : null;

        return [
            'active' => $this->promoIsRunning($news, $now),
            'newsId' => $news->id,
            'title' => $promo['title'] ?? $news->title,
            'target' => (float) ($promo['target'] ?? 0),
            'current' => round((float) ($current ?? 0), 2),
            'unit' => $promo['unit'] ?? 'ЛП',
            'bonusLabel' => $promo['bonusLabel'] ?? '',
            'monthLabel' => $this->monthName($now->month),
            // Сколько полных дней осталось до конца месяца; в последний день — 0.
            'daysLeft' => (int) $now->copy()->startOfDay()
                ->diffInDays($now->copy()->endOfMonth()->startOfDay(), true),
            'months' => $from && $to ? $this->months($from, $to, $now) : [],
        ];
    }

    /** Плитки месяцев акции: прошедший, текущий, будущий. */
    private function months(Carbon $from, Carbon $to, Carbon $now): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();

        // Ограничение на 24 месяца — защита от кривых дат в meta: без него
        // опечатка в годе повесила бы запрос на бесконечном цикле.
        while ($cursor->lessThanOrEqualTo($last) && count($months) < 24) {
            $isNow = $cursor->isSameMonth($now);
            $months[] = [
                'label' => $this->monthName($cursor->month),
                'short' => mb_substr($this->monthName($cursor->month), 0, 3),
                'state' => $isNow ? 'now' : ($cursor->lessThan($now->copy()->startOfMonth()) ? 'past' : 'next'),
            ];
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Карточка новости для ленты: без полного текста, он — на странице.
     *
     * @param  array<int, int>  $readIds
     * @return array<string, mixed>
     */
    private function card(News $news, array $readIds): array
    {
        $cover = $this->metaSection($news, 'cover');
        $published = $news->publishedAt();

        return [
            'id' => $news->id,
            'kind' => $news->kind ?: 'update',
            'tag' => $news->isPromo() ? 'Промо' : 'Обновление',
            'title' => $news->title,
            'excerpt' => $news->excerpt ?: $this->excerptFrom($news->content),
            'coverUrl' => $news->cover_url,
            'coverEyebrow' => $cover['eyebrow'] ?? '',
            'coverNumeral' => $cover['numeral'] ?? '',
            'coverCaption' => $cover['caption'] ?? '',
            'publishedAt' => $published?->toIso8601String(),
            'readingMinutes' => $this->readingMinutes($news->content),
            'isNew' => ! in_array($news->id, $readIds, true),
            'pinned' => (bool) $news->pinned,
        ];
    }

    /**
     * Анонс из текста, если редактор его не заполнил: первые два предложения
     * без разметки. Раньше в ленту вываливался весь HTML новости целиком.
     */
    private function excerptFrom(?string $content): string
    {
        // Приведение обязательно: preg_replace отдаёт null при ошибке разбора,
        // а trim(null) в PHP 8.2 — deprecation.
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $content)));
        if ($text === '') {
            return '';
        }

        return mb_strimwidth($text, 0, 220, '…');
    }

    private function readingMinutes(?string $content): int
    {
        // Считаем регуляркой по юникоду: str_word_count работает побайтово и
        // на кириллице в UTF-8 даёт мусор.
        // preg_match_all при ошибке отдаёт false — приводим, иначе деление
        // на скорость чтения работает с false.
        $words = (int) preg_match_all('/\p{L}+/u', strip_tags((string) $content));

        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    /** @return array<int, int> id новостей, которые пользователь уже открывал */
    private function readIds(?int $userId, array $newsIds): array
    {
        if (! $userId || $newsIds === []) {
            return [];
        }

        return DB::table('news_reads')
            ->where('user_id', $userId)
            ->whereIn('news_id', $newsIds)
            ->pluck('news_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
            'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
        ][$month] ?? '';
    }
}
