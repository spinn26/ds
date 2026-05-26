# DS Consulting Platform · полный визуальный дизайн

74 артборда в 11 секциях на одном канвасе. Покрывает весь приоритет §15:
дизайн-токены → каталог компонентов → состояния → layouts → авторизация →
партнёрский кабинет → менеджер → админ-панель.

## Что внутри

| файл | что |
|---|---|
| **`Дизайн-система.html`** | главный канвас. Открой в браузере — все 74 артборда |
| `design-canvas.jsx` | обёртка-канвас Figma-стиля (только для просмотра) |
| `ds-tokens.css` | **CSS variables** — мапятся в Vuetify theme config |
| `ds-primitives.jsx` | React-копии компонентов с именами семантически парными Vuetify |
| `ds-foundations.jsx` | артборды палитры, типографики, токенов, бренда, иконографии |
| `ds-components.jsx` | каталог компонентов в обеих темах |
| `ds-patterns.jsx` | состояния (loading/empty/error/success/permission) + nav |
| `ds-layouts.jsx` | layouts: MainLayout + AdminLayout + tablet + mobile |
| `ds-screens-auth-partner.jsx` | login + dashboard + clients + contracts + products |
| `ds-screens-manager-admin.jsx` | Manager Workspace + Admin Home redesign |
| `ds-extra-partner.jsx` | оставшиеся партнёрские экраны (8) |
| `ds-extra-manager.jsx` | весь менеджерский набор (17) |
| `ds-extra-admin.jsx` | полная админ-панель (18) |

## Маппинг на Vuetify 3

Названия React-компонентов в `ds-primitives.jsx` подобраны так, чтобы было
очевидно соответствие Vuetify. Минимум кастомного CSS — всё через токены.

| DS-компонент | Vuetify-эквивалент |
|---|---|
| `DSButton variant="filled\|tonal\|outlined\|text\|elevated"` | `v-btn variant="…"` |
| `DSField label hint error` | `v-text-field variant="outlined"` |
| `DSField textarea` | `v-textarea` |
| `DSChip onClick active` | `v-chip filter` |
| `DSChip variant="success\|warning\|error\|info\|brand"` | `v-chip color="…"` |
| `DSStatus variant` | `v-chip size="small"` с точкой (custom) |
| `DSBadge` / `DSBadge dot` | `v-badge` |
| `DSCard variant="default\|elevated\|filled\|brand"` | `v-card variant="…"` |
| `DSAlert variant title` | `v-alert type="…"` |
| `DSProgress value variant height` | `v-progress-linear rounded color="primary"` |
| `DSTabs items active` | `v-tabs` + `v-tab` |
| `DSAvatar initials size status` | `v-avatar` |
| `DSSwitch on label` | `v-switch` |
| `DSCheckbox checked label` | `v-checkbox` |
| `DSRadio checked label` | `v-radio` |
| `DSSlider value` | `v-slider` |
| `DSSkel` | `v-skeleton-loader` |
| `DSDivider` | `v-divider` |
| `FullShell` + `*Sidebar` + `AppBar` | `v-app` + `v-navigation-drawer` + `v-app-bar` |

## Vuetify theme config (готовый)

```ts
// vuetify.ts
export default createVuetify({
  theme: {
    defaultTheme: 'light',
    themes: {
      light: {
        dark: false,
        colors: {
          primary:    '#2E7D32',
          secondary:  '#6EE87A',
          tertiary:   '#4361A8',
          success:    '#2E7D32',
          warning:    '#ED6C02',
          error:      '#C62828',
          info:       '#0277BD',
          background: '#F8F9F8',
          surface:    '#FFFFFF',
          'on-surface': '#1A1F1B',
          'on-surface-variant': '#4A524C',
          outline:    '#BDC4BE',
          'outline-variant': '#DDE2DE',
        },
      },
      dark: {
        dark: true,
        colors: {
          primary:    '#6EE87A',
          secondary:  '#A4E0AC',
          tertiary:   '#B3C5FF',
          success:    '#A4E0AC',
          warning:    '#FFB77A',
          error:      '#FFB4AB',
          info:       '#93CCFF',
          background: '#0F1311',
          surface:    '#161A17',
          'on-surface': '#E2E4E2',
          'on-surface-variant': '#C2C8C3',
          outline:    '#3D4540',
          'outline-variant': '#2A312C',
        },
      },
    },
  },
  defaults: {
    VBtn:   { variant: 'flat', class: 'text-none', rounded: 'md' },
    VCard:  { rounded: 'lg' },
    VTextField:    { variant: 'outlined', density: 'comfortable' },
    VSelect:       { variant: 'outlined', density: 'comfortable' },
    VAutocomplete: { variant: 'outlined', density: 'comfortable' },
    VChip:  { rounded: 'pill' },
    VProgressLinear: { rounded: true, color: 'primary' },
  },
});
```

## Палитра

```
LIGHT
primary       #2E7D32   тёмно-зелёный, основной CTA
primary-deep  #1B5E20   hover, заголовки
primary-soft  #E8F1E9   фоны выделенных блоков
secondary     #6EE87A   brand mint, акценты, success 100%
background    #F8F9F8   фон страниц
surface       #FFFFFF   карточки
on-surface    #1A1F1B   текст
outline       #BDC4BE   разделители

DARK
primary       #6EE87A   (брендовый зелёный flipped)
primary-deep  #1B5E20
secondary     #A4E0AC
background    #0F1311
surface       #161A17
on-surface    #E2E4E2
outline       #3D4540
```

## Типографика

- **Inter** 400/500/600/700 (основной)
- **JetBrains Mono** 400/500/700 (числа, токены, таймкоды)
- 15 ступеней: Display L/M/S → Headline L/M/S → Title L/M/S → Body L/M/S → Label L/M/S

## Иконки

Только **MDI** (Material Design Icons). В дизайне использованы эмодзи как
плейсхолдеры — заменить при имплементации:

```
⌂  → mdi-home
▦  → mdi-view-dashboard
⚇  → mdi-account-group
▤  → mdi-file-document
¤  → mdi-cash-multiple
⏏  → mdi-school
❑  → mdi-chat
⚙  → mdi-cog
⚐  → mdi-bell
⌕  → mdi-magnify
⋮  → mdi-dots-vertical
⤓  → mdi-download
⤒  → mdi-upload
⎘  → mdi-content-copy
↻  → mdi-refresh
☷  → mdi-sitemap
★  → mdi-star
✓  → mdi-check-circle
○  → mdi-circle-outline
◐  → mdi-circle-slice-4
🔒 → mdi-lock
```

## Респонсив (из брифа)

- **Mobile ≤ 700**: сайдбар → drawer, sticky-CTA в нижний фикс-бар,
  таблицы → карточки, bottom-tabbar (5 пунктов)
- **Tablet ≤ 1100**: rail-сайдбар (72px, только иконки)
- **Desktop ≥ 1100**: основная разметка по дизайну
- **Mobile-app (Capacitor)**: учитывать safe-area-insets, нативный back

## Что покрывает каждая секция

```
①  Foundations · 7      палитра / типографика / spacing / тени / motion / бренд / иконки
②  Components · 8       buttons / inputs / feedback / cards / tables (light+dark)
③  Patterns · 3         loading / empty / error / success / permission + nav
④  Layouts · 6          partner home (light+dark) / admin / tablet / mobile (light+dark)
⑤  Auth · 1             split-screen login с BrandWaves
⑥  Partner flagship · 4 dashboard / clients / contracts / products
⑦  Manager flagship · 1 Workspace (Kanban + SLA)
⑧  Admin flagship · 1   Home redesign (замена скриншота)
⑨  Partner extras · 8   структура / финансы / калькулятор / конкурсы / чат / профиль / справка / рефералы
⑩  Manager full · 17    периоды (list+wizard) / контракты / upload / партнёры / приёмка / реквизиты / транзакции / импорт / комиссии / бассейн / квалификации / начисления / реестр / поддержка / анкеты
⑪  Admin full · 18      пользователи / сверка / аномалии / календарь / когорты / bulk-ops / триггеры / mail / интеграции / api-keys / настройки / справочники / новости / роадмап / продукты / конкурсы / мониторинг / статус
```

**Итого: 74 артборда.**

LMS-модуль (8 экранов: курсы, уроки, тесты, БЗ, конструктор, результат) — в
отдельном пакете «Обучение — дизайн.html» (предыдущая доставка).

## Доступность

- contrast primary `#2E7D32` на белом — WCAG AA OK
- secondary `#6EE87A` — **только декор**, не для текста на белом
- focus-visible: 3px primary 18%, offset 2px
- `prefers-reduced-motion`: выключает конфетти, scale-in, hover-translate

## Что НЕ в этом пакете (за рамками задачи)

- LMS-экраны (отдельный пакет)
- Mobile-варианты для каждого из 74 артбордов (есть только для главной партнёра)
- Микро-анимации (только спецификация в `ds-tokens.css` через motion-токены)
- Иллюстрации (есть только пример empty-state)
- A4-сертификат об окончании курса
- Прототипы взаимодействия (canvas — статика)

При необходимости — можно расширить.
