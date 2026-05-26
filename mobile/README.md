# DS Partner — мобильное приложение

Capacitor 6 + Vue 3 + Vuetify 3 + TypeScript. Сделано на основе того же бэкенда (`https://dev.dsconsult.ru/api/v1`), что и веб-кабинет.

## Что внутри

- **2 кабинета:** партнёрский (`/app/*`) и админский (`/manage/*`). Тип выбирается по `user.role` после логина.
- **Полный логин с 2FA**: `POST /auth/login` → если `requires_2fa=true`, второй шаг с TOTP-кодом → `POST /2fa/verify`.
- **Хранение сессии** через `@capacitor/preferences` (Keychain/Keystore на native, localStorage на вебе). Auth-store восстанавливает токен до монтирования роутера → нет «миганий» на `/login` при F5.
- **Real-time чат** через Socket.IO (порт 3001) — события `ticket:join`, `chat:new-message`.
- **Push-уведомления** через `@capacitor/push-notifications` (FCM/APNs), регистрация устройства на `POST /auth/device/register` (no-op в браузере).
- **Тёмная тема** через Vuetify (`dsLight` / `dsDark`), переключается в Настройках, сохраняется в Preferences.
- **Биометрия** — placeholder switch (логику добавим, когда нужно).

## Структура

```
mobile/
├── src/
│   ├── api/
│   │   ├── index.ts          # axios + Bearer + 401-redirect
│   │   ├── socket.ts         # Socket.IO клиент
│   │   └── push.ts           # FCM/APNs регистрация (native only)
│   ├── layouts/
│   │   ├── MobileShell.vue   # партнёрский: header + tabbar
│   │   └── MobileShellAdmin.vue
│   ├── stores/auth.ts        # token + user + refreshMe + logout
│   ├── router/index.ts       # /app/* + /manage/*
│   ├── views/                # 14 партнёрских + 17 admin-страниц
│   │   ├── manage/...
│   │   └── ...
│   └── main.ts
├── android/                  # native-проект Android (создан `cap add`)
├── capacitor.config.ts       # appId: ru.dsconsult.partner
└── README.md
```

## Real-time веб-просмотр

```bash
cd mobile
npm install     # один раз
npm run dev     # → http://localhost:5174
```

Hot-reload работает. Открыть в обычном Chrome или, ещё лучше, через VS Code:
**Run and Debug → «Mobile: dev + Chrome»** — поднимет dev-сервер и откроет окно 400×820 с DevTools.

## Production-сборка

```bash
npm run build   # vue-tsc + vite build → dist/
```

Бандл: ~180 KB gzip (главный vendor) + 17 KB gzip (ChatThread) + по 1-3 KB на каждый экран. ✓

## Сборка под Android

Native-проект уже добавлен в `mobile/android/`. Для пересборки:

```bash
npm run cap:sync          # build + копирование dist в android/
npm run cap:android       # откроет Android Studio для запуска эмулятора / build APK
```

Требования: Android Studio + Android SDK 33+. На Windows работает локально.

## Сборка под iOS

Native-проект iOS создаётся отдельно на macOS:

```bash
npx cap add ios
npm run cap:ios           # откроет Xcode
```

CI-вариант — GitHub Actions с `runs-on: macos-latest`. Делать iOS-сборку на Windows нельзя — это требование Apple.

## ENV

`.env.production` (закоммичен с дефолтами под dev-сервер):
```
VITE_API_BASE=https://dev.dsconsult.ru/api/v1
VITE_SOCKET_URL=https://dev.dsconsult.ru
VITE_APP_NAME=DS Partner
```

Для локальных оверрайдов — создать `.env.local` (не коммитится).

## Подключённые эндпоинты (партнёр)

| Экран | Endpoint |
|---|---|
| Login | `POST /auth/login`, `POST /2fa/verify` |
| Home | `GET /dashboard`, `GET /notifications` |
| Notifications | `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| Transactions | (UI готов, ждёт собственный endpoint у бэка) |
| Contracts | `GET /contracts/my` / `/contracts/team` |
| Clients | `GET /clients` |
| Structure | `GET /structure` |
| Qualifications | `GET /dashboard` + `GET /structure/qualification-levels` |
| Finance | `GET /finance/report` |
| Education | `GET /education/courses` |
| Chat list | `GET /chat/tickets` |
| Chat thread | `GET /chat/tickets/{id}`, `POST /chat/tickets/{id}/messages`, Socket.IO `chat:new-message` |
| Profile | `GET /auth/me` (через `auth.refreshMe()`) |
| Requisites | `GET /profile` |
| Documents | `GET /documents` |
| Settings | `GET /2fa/status`, `@capacitor/preferences` для флагов |
| Logout | `POST /auth/logout` |

## Подключённые эндпоинты (стафф)

| Экран | Endpoint |
|---|---|
| ManageDashboard | `GET /admin/dashboard` |
| ManagePartners | `GET /admin/partners?search&per_page` |
| ManageClients | `GET /admin/clients` |
| ManageContracts | `GET /admin/contracts` |
| ManageTransactions | `GET /admin/transactions` |
| ManageRequisites | `GET /admin/requisites` |
| ManageCharges | `GET /admin/charges` |
| ManagePayments | `GET /admin/payments` |
| ManageQualifications | `GET /admin/qualifications` |
| ManagePool | `GET /admin/pool` |
| ManageReports | `GET /admin/reports`, `GET /admin/reports/archive` |
| ManageEducation | `GET /admin/education/courses` |
| ManageChat | `GET /chat/tickets` + smart-views |
| ManageSupportDesk | `GET /support/desk` |
| ManageContests | `GET /admin/contests` |

## Что ещё подкрутить перед стором

- **Иконка приложения** (1024×1024 PNG): положить в `mobile/resources/icon.png`, запустить `npx @capacitor/assets generate` — он сделает все размеры под Android/iOS.
- **Splash screen** (2732×2732 PNG): `mobile/resources/splash.png` + та же команда.
- **Привязка FCM/APNs**: добавить `google-services.json` (Android) и APNs key (iOS), включить плагин в `MainActivity.java` и `AppDelegate.swift`.
- **Глубокие ссылки**: настроить `intent-filters` в `AndroidManifest.xml` и `Associated Domains` в `Info.plist`.
- **Сертификаты**: keystore для Android (`signingConfigs` в `build.gradle`), Apple Distribution profile для iOS.
- **CI/CD**: GitHub Actions с `macos-latest` для iOS, Android можно собирать на любой ОС.

## Реалистичный путь до стора (~2 недели)

1. **Неделя 1** — добавить иконку/splash, проверить deep-links, прогнать на физических устройствах.
2. **Неделя 2** — TestFlight + Internal Testing track в Google Play, сбор обратной связи.
3. Дальше — публичный релиз.

## App ID

- Bundle ID: **`ru.dsconsult.partner`**
- Display name: **DS Partner**

Меняется в `capacitor.config.ts`. После — `cap sync`.
