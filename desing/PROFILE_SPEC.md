# Профиль — полная спецификация

Все варианты экрана `/profile` (компонент `resources/js/pages/Profile.vue`), что показывается каждой роли, какие поля, кнопки и состояния.

---

## 0. Общая структура

```
┌──────────────────────────────────────────────────────────────────────┐
│ PageHeader: Профиль                                                   │
│  subtitle: личные данные · документы · безопасность                   │
├──────────────────────────────────────────────────────────────────────┤
│ ┌──┐  ФИО (ds-headline-s)                          [📷 Сменить фото] │
│ │А │  email · ID партнёра (только partner)                            │
│ └──┘  [Чип статуса] [до 14.03.2027] [Активация до 30.05.2026]         │
├──────────┬───────────────────────────────────────────────────────────┤
│ NAV      │ CONTENT                                                    │
│ (12/3)   │ (12/9)                                                     │
│          │                                                            │
└──────────┴───────────────────────────────────────────────────────────┘
```

**Hero (общий для всех табов):**
- 80×80 круглый аватар. Фон `primary` (=#2E7D32 light / #6EE87A dark), внутри `text-h4 white` инициалы или загруженное фото. Glow 4px `primary-soft` вокруг.
- ФИО: `ds-headline-s` (20px/700).
- 2-я строка `ds-body-m ds-muted`:
  - **Partner:** `email · ID DS-04812`
  - **Staff:** `email · [роль каплейфтер]` (например «email · Администратор»)
- 3-я строка чипов (только partner):
  - `<v-chip size="small" color="success" variant="flat">Активен</v-chip>` или другой статус
  - `ds-body-s ds-muted` «до 14.03.2027» (год.период)
  - `ds-body-s ds-muted` «Активация до 30.05.2026» (если в активационном периоде)
- Кнопка «Сменить фото» — `variant="outlined" size="small" prepend-icon="mdi-camera"`. Click → `<input type="file" accept="image/*" hidden>` → `POST /profile/avatar` (FormData) → перезагрузка профиля.

---

## 1. Роли — кто что видит

### Partner (consultant) — 7 пунктов меню
1. Личные данные
2. Документы
3. Реквизиты
4. Безопасность
5. Уведомления
6. Telegram-bot
7. Реферальные ссылки *(если `canInvite`)*

### Staff (admin/backoffice/support/finance/head/calculations/corrections/education) — 4 пункта
1. Информация о сотруднике (~= Личные данные но с полем «Должность»)
2. Безопасность
3. Уведомления
4. Telegram-bot

### Terminated (заблокирован) — full read-only
- Поля все disabled
- Кнопки сохранения скрыты
- В hero: чип «Терминирован» (error)
- Видны только: Личные данные, Безопасность (для смены пароля), Уведомления

---

## 2. Tab «Личные данные» (`info`)

### Заголовок
`<div class="ds-title-l">Личные данные</div>`

### Партнёрские поля (grid 4 колонки, отзывчиво)
| # | Поле | Тип | Disabled? | Подсказка | Required |
|---|---|---|---|---|---|
| 1 | **Фамилия** | text | partner-всегда | «Изменение через техподдержку» | да |
| 2 | **Имя** | text | partner-всегда | «Изменение через техподдержку» | да |
| 3 | **Отчество** | text | partner-всегда | «Изменение через техподдержку» | нет |
| 4 | **Дата рождения** | date (`mdi-cake`) | partner-нет | — | да |
| 5 | **Пол** | v-select [мужской/женский] | partner-нет | — | нет |
| 6 | **Страна** | v-autocomplete (Россия/Казахстан/Беларусь/...) (`mdi-flag`) | нет | — | нет |
| 7 | **Город** | v-combobox (loaded from API) (`mdi-city`) | нет | — | нет |
| 8 | **Email** | email (`mdi-email`) | нет | — | да |
| 9 | **Телефон** | `<vue-tel-input>` | нет | — | да |
| 10 | **Telegram** | text (`mdi-send`), placeholder=«@username» | нет | — | нет |

### Staff-добавки (только если `isEmployee`)
| # | Поле | Тип |
|---|---|---|
| 11 | **Должность** | text (`mdi-briefcase`) |
| — | ФИО редактируется | НЕ disabled |

### Кнопка сохранения
- `<v-btn color="primary" prepend-icon="mdi-content-save" :loading="saving" @click="saveProfile">Сохранить</v-btn>`
- API: `POST /profile` (тело: ВСЕ поля выше)
- Состояния:
  - **success:** `<v-alert type="success">Сохранено</v-alert>`
  - **error:** `<v-alert type="error">{{ saveMsg }}</v-alert>`
  - **validation:** `<v-alert type="warning">Заполните обязательные поля</v-alert>`

### Дополнительные блоки (только partner)

**Подписанные документы** (mdi-file-document-multiple, ds-title-l):
- Список ссылок (`v-list-item target="_blank"`): «Договор подряда (от 14.03.2023)», «Дополнительное соглашение №1», и т.д.
- Если пусто: alert info «Подписанные документы появятся после акцепта»

---

## 3. Tab «Документы» (`documents`) — только partner

### Заголовок
`<div class="ds-title-l">Документы партнёра</div>`

### 3 slot-карточки (3 колонки, v-row):

#### Slot 1: Паспорт — фото первой страницы
- Иконка: `mdi-card-account-details`
- Лейбл: «Паспорт — фото»
- `<v-file-input accept="image/*,.pdf" density="compact">`
- Превью: миниатюра ImageLightbox (если уже загружено)
- Чек-иконка `mdi-check-circle success` если загружено
- Статус верификации (если есть): чип «На проверке» (warning) / «Одобрено» (success) / «Отклонено» (error + причина)

#### Slot 2: Паспорт — регистрация
- То же поведение, иконка `mdi-passport`, лейбл «Паспорт — регистрация»

#### Slot 3: Заявление на выплаты
- `mdi-file-sign`, лейбл «Заявление на выплаты»

### API
- `GET /documents` — список загруженных
- `POST /documents/upload` (FormData: type, file) — загрузка
- Каждый slot независимо загружается

### Состояния
- **Loading при загрузке:** progress-circular внутри slot'а
- **Error:** snackbar «Не удалось загрузить»
- **Pending verification:** info-alert «Документ отправлен на проверку…»

---

## 4. Tab «Реквизиты» (`requisites`) — только partner

### Подсекция А: ИП-реквизиты

#### Заголовок
```
[mdi-domain] Реквизиты ИП  [chip: verificationStatus]
```
- Чип: `На проверке` (warning) / `Одобрено` (success mdi-check) / `Отклонено` (error)
- Если `verified`: жёлтый alert «Изменение реквизитов сбросит статус верификации»

#### Поля (v-row dense)
| Поле | Тип | Span |
|---|---|---|
| **Наименование ИП** | text | 6 |
| **ИНН** | text (12 цифр) | 3 |
| **ОГРН/ОГРНИП** | text (13-15 цифр) | 3 |
| **Юридический адрес** | text | 12 |
| ☐ Адрес регистрации совпадает с фактическим | checkbox | 12 |
| **Фактический адрес** | text (если чекбокс снят) | 12 |
| **Email для документов** | email | 6 |
| **Телефон ИП** | tel | 6 |

#### Кнопка
- «Сохранить реквизиты» (`primary mdi-content-save :loading="savingReq"`)
- API: `POST /requisites`

### Подсекция Б: Банковские реквизиты

#### Заголовок
```
[mdi-bank] Банковские реквизиты  [chip: verificationStatus]
```
- Та же логика чипа + warning-alert.

#### Поля
| Поле | Тип | Span |
|---|---|---|
| **Наименование банка** | text | 6 |
| **БИК** | text (9 цифр) | 6 |
| **Расчётный счёт** | text (20 цифр) | 6 |
| **Корр. счёт** | text (20 цифр) | 6 |
| **Наименование получателя** | text | 12 |

#### Кнопка
- «Сохранить банковские реквизиты» (`primary mdi-content-save`)
- API: `POST /bank-requisites`

---

## 5. Tab «Безопасность» (`security`) — все роли

### Подсекция А: Двухфакторная аутентификация

#### Заголовок
`[mdi-shield-key] Двухфакторная аутентификация`

#### Если 2FA включён:
- Alert success: «2FA включён с {date}. При входе будем спрашивать код из приложения.»
- Поле «Текущий пароль для отключения» (type=password, mdi-lock)
- Кнопка **«Отключить 2FA»** (color=error, mdi-shield-off, :loading)
- API: `POST /2fa/disable` (body: {password})

#### Если 2FA выключен (setup-flow в 3 шага):
- Lead «Защитите аккаунт одноразовыми кодами…»
- Кнопка **«Включить 2FA»** (primary, mdi-shield-plus) → POST /2fa/setup → возвращает `{secret, qrCodeUrl}`
- После клика разворачивается setup:
  - **Step 1:** «Установите Google Authenticator / 1Password»
  - **Step 2:** QR-код 180×180 + поле «Секрет» (mdi-key, copy-to-clipboard кнопка)
  - **Step 3:** Поле TOTP-кода (6 цифр, autofocus) + кнопка **«Подтвердить и включить»** (primary, mdi-check)
  - API: `POST /2fa/confirm` (body: {code, secret})

### Подсекция Б: Смена пароля

#### Заголовок
`[mdi-key] Смена пароля`

#### Поля (3 колонки):
| Поле | Тип |
|---|---|
| **Текущий пароль** | password (mdi-lock-outline, show-toggle) |
| **Новый пароль** | password (mdi-lock-plus, show-toggle, rules: ≥8, ≥1 буква, ≥1 цифра) |
| **Подтверждение** | password (mdi-lock-check, rule: match) |

#### Кнопка
- **«Сменить пароль»** (`primary mdi-key :loading="savingPwd"`)
- API: `POST /password` (body: {current, new})
- Состояния:
  - Success → alert «Пароль изменён»
  - Mismatch → alert error «Пароли не совпадают»
  - Wrong current → alert error «Неверный текущий пароль»

---

## 6. Tab «Уведомления» (`notifications`) — все роли

### Заголовок
`[mdi-bell-outline] Уведомления`

### Планируемое содержание (сейчас stub):
- **Каналы уведомлений** (checkboxes):
  - ☑ В кабинете (всегда on, disabled)
  - ☑ Telegram-bot (если привязан — см. соседний таб)
  - ☑ Email (если настроен SMTP в админке)
- **Типы уведомлений** (matrix per type × channel):
  - Новое сообщение в чате — в кабинете / Telegram / email
  - Изменение статуса контракта — в кабинете / Telegram
  - Новая заявка от клиента — в кабинете
  - Закрытие отчётного периода — в кабинете / email
  - Системный сбой / инцидент — всё включено по умолчанию
- **Звук** в кабинете (v-switch):
  - «Воспроизводить звук при новом уведомлении»
- **Тихие часы** (опционально):
  - Поля «с / по» (time-picker)
  - Каналы не блокируются, но звуки и Telegram-пуши приостанавливаются

### Текущая реализация
Заглушка: info-alert «Раздел в разработке. Звук уведомлений сейчас управляется из иконки колокольчика в шапке».

### API (планируется)
- `GET /notifications/preferences` → текущие настройки
- `PUT /notifications/preferences` → сохранение

---

## 7. Tab «Telegram-bot» (`telegram`) — все роли

### Заголовок
`[mdi-send] Telegram-уведомления`

### Состояние 1: НЕ привязан (`!telegram.linked && !tgLink`)
- Lead text: «Привязка через бота `@{bot_username}`: жмёте кнопку → открывается Telegram с уже введённой командой `/start`. Просто подтвердите — аккаунт привяжется автоматически.»
- Кнопка **«Привязать через бота»** (`primary mdi-send :loading="telegramBusy"`)
- API: `POST /telegram/start-link` → `{token, deepLinkUrl}`

### Состояние 2: Идёт привязка (`tgLink && !telegram.linked`)
- Alert info с инструкцией:
  - «Откройте бота в Telegram и нажмите Start.»
  - Caption «Ссылка действует 15 минут. После старта вернитесь сюда — статус обновится сам.»
- Действия (flex row, gap 8):
  - **«Открыть в Telegram»** (`primary mdi-open-in-new`, href=`tgLink`, target=_blank)
  - **«Проверить статус»** (`text mdi-refresh :loading="telegramBusy"`)
  - **«Отменить»** (`text grey`) → reset tgLink/tgToken
- Если идёт polling:
  - Progress-circular 14px + caption «Ожидаем подтверждения в Telegram…»

### Состояние 3: Привязан (`telegram.linked`)
- Alert success: «Аккаунт привязан к Telegram. Сюда будут приходить критические уведомления.»
- Действия:
  - **«Отправить тест»** (`tonal mdi-send-check`) — отправляет тестовое сообщение
  - **«Отвязать»** (`tonal color=error mdi-link-off`) — отвязка
- API:
  - `POST /telegram/test` (отправка тест)
  - `POST /telegram/unlink` (отвязка)

### Состояние 4: Telegram-bot выключен в системе (`!telegram.enabled`)
- Карточка не рендерится
- Info-alert: «Интеграция с Telegram отключена администратором.»

### Backend
- `GET /telegram` → `{enabled, linked, bot_username}`
- `POST /telegram/start-link` → `{token, deepLinkUrl}`
- `GET /telegram/status?token=…` → polling endpoint (вернёт `{linked: true}` когда привяжется)
- `POST /telegram/test`
- `POST /telegram/unlink`

---

## 8. Tab «Реферальные ссылки» (`referral`) — только partner + canInvite

### Заголовок
`[mdi-link-variant] Ваша реферальная ссылка`

### Карточка (если `canInvite`)
- Поля (readonly):
  - **Реферальный код** (text, readonly, mdi-tag)
    - Append: `<v-btn icon mdi-content-copy @click="copy(referralCode)" />`
  - **Ссылка для приглашения** (text, readonly, full width)
    - Append: copy-кнопка
- При клике на copy:
  - Чип «Скопировано» 2 сек (success)
- **Welcome-бонус-баннер** (info-tonal):
  - Иконка `mdi-gift`
  - Текст: «Отправьте друзьям-консультантам — они получат 5 000 ₽ welcome-бонус при первой продаже»

### Если `!canInvite`:
- Info-alert: «Реферальные ссылки доступны только для партнёров со статусом «Активен». Активируйте кабинет — и начинайте приглашать.»

### Backend
- `GET /profile` → `referral: {canInvite, referralCode, referralLink}`

---

## 9. Состояния страницы

### Loading
- Top progress-linear (fixed top, 3px primary indeterminate)

### Error при загрузке
- Snackbar «Не удалось загрузить профиль» + кнопка «Повторить»

### Save success (любая форма)
- Snackbar success «Сохранено»
- Кнопка «Сохранить» возвращается в idle

### Save error
- Inline `<v-alert type="error" closable>` под формой
- Или snackbar (для коротких ошибок)

---

## 10. Адаптив

- **≥1100px:** layout 12/3 + 12/9 (sidebar + content)
- **960-1099px:** 12/4 + 12/8 (более широкий sidebar)
- **<960px:** sidebar сверху над content (12/12 + 12/12). Меню превращается в горизонтальный chip-row.
- **<600px:** поля по 12/12, кнопки full-width

---

## 11. Точки расхождения с текущей реализацией

Сейчас в `Profile.vue`:
- ✅ Hero сверху (avatar + name + email + status chips + Сменить фото)
- ✅ Vertical sidebar 12/3
- ✅ 6 пунктов меню (info / documents / requisites / security / notifications / telegram) + опционально referral
- ✅ Документы и реквизиты разнесены на разные табы
- ⚠️ Notifications пока stub
- ⚠️ Hero для **staff** не показывает роль/отдел (только email)
- ⚠️ Текущий dark-theme рендер: ИЗ-ЗА пустого hero (нет статус-чипов для staff) карточка кажется голой
- ⚠️ Поля выкладываются в 3 колонки — широковато для коротких полей (ФИО, дата). Лучше 4 колонки на десктопе.

## 12. Что предлагается доделать

Сразу понятно:
- Hero для staff: добавить чип роли (Администратор / Бэк-офис / ...) + дата регистрации сотрудника + last_login
- Поля в info-табе: grid 4-колонки на десктопе (ФИО 4+4+4, дата+пол+должность 4+4+4, страна+город+email 4+4+4, телефон+telegram 4+4)
- Notifications: заменить stub на реальный preferences-grid если backend готов
- Эстетика dark-темы: outline-variant у hero вместо чистого без обводки

Если есть макет именно для staff-профиля (или дополнения к partner-макету) — приложи, перевешу 1:1.
