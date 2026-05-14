# DS Partner — мобильное приложение

Capacitor 6 + Vue 3 + Vuetify 3. Отдельная папка `mobile/` рядом с веб-проектом, чтобы не смешивать сборки.

## Стек

| Слой | Версия | Назначение |
|---|---|---|
| Vue 3 | 3.5.x | Composition API + `<script setup>` |
| Vuetify 3 | 3.7.x | UI-компоненты, тема (primary `#2E7D32`) |
| Pinia | 2.3.x | Стейт (auth, и далее) |
| Vue Router | 4.5.x | Роутинг (HTML5 history) |
| Vite 6 | dev/build, порт **5174** |
| TypeScript | 5.7 | Типизация Vue + конфигов |
| Capacitor 6 | 6.2.x | Native shell (Android / iOS) |
| `@capacitor/preferences` | 6.0.x | Хранение токена (Keychain/Keystore) |
| `@capacitor/status-bar` | 6.0.x | Цвет/стиль status-bar |
| `@capacitor/app` | 6.0.x | Lifecycle + deep links |

## Структура

```
mobile/
├── src/
│   ├── api/index.ts         # axios + Sanctum Bearer
│   ├── plugins/vuetify.ts   # тема DS (light + dark)
│   ├── router/index.ts      # роутинг + auth-guard
│   ├── stores/auth.ts       # Pinia store (token + user)
│   ├── styles/global.css    # mobile reset + safe-area
│   ├── views/
│   │   ├── Login.vue        # экран входа (заглушка-логин)
│   │   └── Dashboard.vue    # главный экран (KPI + tabs)
│   ├── App.vue
│   └── main.ts
├── capacitor.config.ts      # appId ru.dsconsult.partner
├── vite.config.ts           # порт 5174, alias '@' → src/
├── tsconfig.json
├── package.json
└── index.html
```

## Запуск (real-time веб-просмотр)

### Через VS Code (один клик)
1. Открыть workspace `c:\Users\ENCODE\Desktop\ds` в VS Code.
2. `Terminal → Run Task… → Mobile: dev server (Vite, port 5174)`.
3. Открыть http://localhost:5174 в браузере. Hot-reload работает.

### Через CLI
```bash
cd mobile
npm install   # один раз
npm run dev   # запускает Vite на 5174
```

### Дебаг с DevTools в окне ~iPhone
В VS Code: `Run and Debug → Mobile: dev + Chrome` (или Edge). Запустит Chromium в окне 400×820 с открытыми DevTools и pre-launch-task'ом dev-сервера.

## Команды

| Команда | Что делает |
|---|---|
| `npm run dev` | Vite dev-сервер на :5174 с HMR |
| `npm run build` | type-check + production-билд в `dist/` |
| `npm run type-check` | только `vue-tsc --noEmit` |
| `npm run preview` | предпросмотр production-сборки |
| `npm run cap:sync` | build + копирование `dist/` в native-проекты |
| `npm run cap:android` | sync + открыть Android Studio |
| `npm run cap:ios` | sync + открыть Xcode (нужен Mac) |

## Подключение native-платформ (потом)

Сейчас в репозитории только web-слой и капациторовский конфиг. Native-проекты добавляются командами:

```bash
# Android (можно на Windows)
npx cap add android
npm run cap:android   # откроет Android Studio, можно запустить эмулятор

# iOS (нужен Mac)
npx cap add ios
npm run cap:ios
```

После `cap add` создадутся папки `mobile/android/` и `mobile/ios/`. Их коммитим в репо (там лежат settings подписи, permissions, иконки).

## Live-reload на физическом девайсе

В `capacitor.config.ts` временно (НЕ коммитить!) добавить:

```ts
server: {
  url: 'http://192.168.1.109:5174',  // IP вашего компа
  cleartext: true,
},
```

Запустить `npm run dev`, потом `npm run cap:android` (или sync без открытия). При запуске на девайсе он будет тянуть UI с вашего dev-сервера через локальную сеть.

## Backend

API-base по умолчанию: `https://dev.dsconsult.ru/api/v1`. Переопределяется через `.env` файл:

```
VITE_API_BASE=https://prod.dsconsult.ru/api/v1
```

Авторизация — Sanctum Bearer-токен, формат тот же, что у веб-SPA (общий бэкенд).

## Что дальше

- [ ] Реальный `/auth/login` (сейчас заглушка в `Login.vue::onSubmit`)
- [ ] Перенос Pinia auth-store из веб-проекта (учесть `Preferences` вместо `localStorage`)
- [ ] Чат-экран с Socket.IO (тот же сервер, что у веб-SPA)
- [ ] Push-уведомления (`@capacitor/push-notifications` + FCM/APNs)
- [ ] Биометрия (`capacitor-native-biometric`)
- [ ] Загрузка вложений в чат (`@capacitor/camera`, `@capacitor/filesystem`)
- [ ] Deep-links (open ticket по URL)
- [ ] CI/CD: GitHub Actions с `macos-latest` для iOS-сборки
- [ ] App Store / Google Play assets (иконка 1024×1024, скриншоты, политика)

## App ID и название

- iOS / Android Bundle ID: **`ru.dsconsult.partner`**
- Display name: **DS Partner**

Меняется в `capacitor.config.ts`. После изменения нужно пересоздать native-проекты (`npx cap add android/ios`).
