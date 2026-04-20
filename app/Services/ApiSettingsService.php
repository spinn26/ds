<?php

namespace App\Services;

use App\Models\ApiSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Read/write для ключей интеграций.
 *
 * Порядок резолвинга get(): сначала БД → потом env() → потом default.
 * Это даёт возможность переопределить env через UI без перезапуска, но
 * сохраняет совместимость со старым кодом, который читал env().
 */
class ApiSettingsService
{
    private const CACHE_KEY = 'api_settings:map';
    private const CACHE_TTL = 300; // 5 минут

    /** Каталог всех известных ключей — редактируется в UI. */
    public const CATALOG = [
        // Google Sheets
        'google.sheets.api_key'        => ['group' => 'google', 'label' => 'Google Sheets API Key', 'hint' => 'API Key из GCP Console для чтения публичных таблиц', 'secret' => true, 'envFallback' => 'GOOGLE_SHEETS_API_KEY'],
        'google.sheets.products_id'    => ['group' => 'google', 'label' => 'ID таблицы «Продукты»', 'hint' => 'ID из URL таблицы: .../spreadsheets/d/{ID}/edit', 'secret' => false, 'envFallback' => null],
        'google.sheets.reference_id'   => ['group' => 'google', 'label' => 'ID таблицы «Справочники»', 'hint' => 'Необязательно — для общих справочников', 'secret' => false, 'envFallback' => null],

        // Telegram
        'telegram.bot.token'           => ['group' => 'telegram', 'label' => 'Telegram Bot Token', 'hint' => 'Выдаётся @BotFather после создания бота', 'secret' => true, 'envFallback' => 'TELEGRAM_BOT_TOKEN'],
        'telegram.status.chat_id'      => ['group' => 'telegram', 'label' => 'Chat ID для статуса платформы', 'hint' => 'ID чата/группы, куда слать health-алерты. Числовой, может быть отрицательным (группа)', 'secret' => false, 'envFallback' => 'TELEGRAM_STATUS_CHAT_ID'],
        'telegram.staff.chat_id'       => ['group' => 'telegram', 'label' => 'Chat ID для staff-уведомлений', 'hint' => 'Дублирование админ-нотификаций в Telegram (необязательно)', 'secret' => false, 'envFallback' => null],

        // Другие интеграции — под резерв
        'bubble.api_token'             => ['group' => 'bubble', 'label' => 'Bubble API Token', 'hint' => 'Legacy интеграция, только для миграции', 'secret' => true, 'envFallback' => 'BUBBLE_API_TOKEN'],
        'getcourse.api_key'            => ['group' => 'getcourse', 'label' => 'GetCourse API Key', 'hint' => '', 'secret' => true, 'envFallback' => 'GETCOURSE_API_KEY'],
    ];

    /** Ленивая-синхронизация каталога с БД. Создаёт недостающие строки. */
    public function syncCatalog(): void
    {
        foreach (self::CATALOG as $key => $meta) {
            ApiSetting::firstOrCreate(
                ['key' => $key],
                [
                    'group' => $meta['group'],
                    'label' => $meta['label'],
                    'hint' => $meta['hint'] ?? null,
                    'secret' => (bool) ($meta['secret'] ?? true),
                ]
            );
        }
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Значение ключа. Резолв: БД → env(envFallback) → default.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $map = $this->all();
        $fromDb = $map[$key] ?? null;
        if ($fromDb !== null && $fromDb !== '') return $fromDb;

        $envKey = self::CATALOG[$key]['envFallback'] ?? null;
        if ($envKey) {
            $envVal = env($envKey);
            if ($envVal) return (string) $envVal;
        }

        return $default;
    }

    /** Записать значение (null = очистить). */
    public function set(string $key, ?string $value, ?int $userId = null): void
    {
        $meta = self::CATALOG[$key] ?? null;
        $setting = ApiSetting::firstOrNew(['key' => $key]);
        if ($meta) {
            $setting->group  = $meta['group'];
            $setting->label  = $meta['label'];
            $setting->hint   = $meta['hint'] ?? null;
            $setting->secret = (bool) ($meta['secret'] ?? true);
        }
        $setting->value = $value;
        $setting->updated_by = $userId;
        $setting->save();

        Cache::forget(self::CACHE_KEY);
    }

    /** Все значения (decrypted) — из памяти, 5-минутный кэш. */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $out = [];
            foreach (ApiSetting::all() as $s) {
                $out[$s->key] = $s->value;
            }
            return $out;
        });
    }

    /**
     * Список для админ-UI: ключ + метаданные + есть ли значение (без самого
     * значения для secret=true, чтобы не светить ключи при загрузке списка).
     */
    public function listForUi(): array
    {
        $this->syncCatalog();
        $out = [];
        foreach (ApiSetting::orderBy('group')->orderBy('key')->get() as $s) {
            $meta = self::CATALOG[$s->key] ?? ['envFallback' => null];
            $hasValue = ! empty($s->value);
            $envFallback = $meta['envFallback'] ?? null;
            $envPresent = $envFallback && env($envFallback);

            $out[] = [
                'key' => $s->key,
                'group' => $s->group,
                'label' => $s->label,
                'hint' => $s->hint,
                'secret' => (bool) $s->secret,
                'hasValue' => $hasValue,
                // Показываем значение полностью для не-секретных, маскируем иначе.
                'value' => $s->secret
                    ? ($hasValue ? '••••••••' : '')
                    : (string) ($s->value ?? ''),
                'envFallback' => $envFallback,
                'envPresent' => (bool) $envPresent,
                'updatedAt' => $s->updated_at?->toDateTimeString(),
            ];
        }
        return $out;
    }
}
