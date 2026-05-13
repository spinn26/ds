<?php

namespace App\Console\Commands;

use App\Support\Telegram;
use Illuminate\Console\Command;

class TelegramSetupWebhook extends Command
{
    protected $signature = 'telegram:setup-webhook
                            {--url= : Полный URL вебхука; по умолчанию APP_URL + /api/v1/webhooks/telegram}
                            {--info : Только показать getWebhookInfo, ничего не менять}';

    protected $description = 'Регистрирует webhook бота в Telegram (один раз после смены домена/секрета)';

    public function handle(): int
    {
        if ($this->option('info')) {
            $info = Telegram::getWebhookInfo();
            $this->line(json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $url = $this->option('url') ?: rtrim(config('app.url'), '/') . '/api/v1/webhooks/telegram';
        $secret = (string) config('services.telegram.webhook_secret', '');

        if (! config('services.telegram.bot_token')) {
            $this->error('TELEGRAM_BOT_TOKEN не задан в .env');
            return self::FAILURE;
        }
        if ($secret === '') {
            $this->warn('TELEGRAM_WEBHOOK_SECRET пустой — рекомендую сгенерировать openssl rand -hex 32');
        }

        $this->line("Регистрируем webhook: {$url}");
        $res = Telegram::setWebhook($url, $secret ?: null);
        $this->line(json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
