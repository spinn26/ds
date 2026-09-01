# DS Consulting — партнёрская платформа

Кабинет партнёра и бэкофис для сети финансовых консультантов: клиенты,
контракты, транзакции, каскадный расчёт вознаграждений по структуре
наставничества, квалификации, пул, реестр выплат и отчётность.

Laravel 12 (API) + Vue 3 SPA + Socket.IO + мобильное приложение на Capacitor.

## Быстрый старт

Нужны PHP 8.2+, Composer, Node 20+, PostgreSQL, Redis.

```bash
composer setup      # зависимости, .env, ключ, миграции, сборка фронта
composer dev        # сервер + очередь + логи + vite одной командой
```

Realtime-чат поднимается отдельно:

```bash
cd socket-server && npm install && npm start
```

Мобильное приложение — см. [mobile/README.md](mobile/README.md) и
[mobile/BUILD.md](mobile/BUILD.md).

## Проверки

```bash
composer test                # PHPUnit
vendor/bin/phpstan analyse   # level 5 — гейт перед деплоем
vendor/bin/pint              # стиль (в CI не входит)
```

## Документация

| Файл | О чём |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Архитектура, инварианты расчётов, подводные камни. Читать первым |
| [.claude/specs/](.claude/specs/) | Бизнес-спеки: комиссии, пул, квалификации, статусы, интеграции |
| [docs/db-tables-reference.md](docs/db-tables-reference.md) | Справочник по таблицам БД: живые, legacy, служебные |
| [docs/partner-cabinet-guide.md](docs/partner-cabinet-guide.md) | Инструкция по кабинету для партнёра |
| [DEPLOY.md](DEPLOY.md) | Накат, откат, бэкапы |

## Деплой

Push в `main` автоматически катит код на прод
([.github/workflows/deploy.yml](.github/workflows/deploy.yml)): статический
анализ и тесты как гейт, затем зависимости, миграции, сборка фронта и
перезапуск сервисов. Staging и ручного подтверждения нет — ветка `main`
всегда должна быть в состоянии релиза.
