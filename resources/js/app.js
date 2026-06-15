import { createApp } from 'vue';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import { VueQueryPlugin } from '@tanstack/vue-query';
import { createVuetify } from 'vuetify';
// Vuetify-локаль для встроенных текстов (pagination, no-data, etc).
// Без неё пагинация рендерит «Items per page» — пользователю надо ru.
import { ru as vuetifyRu, en as vuetifyEn } from 'vuetify/locale';
import { createI18n } from 'vue-i18n';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import VueTelInput from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';
import './styles/ds-tokens.css';
import './styles/global.css';
import router from './router';
import App from './App.vue';
import { ru, en } from './i18n';

// Sentry — lazy-loaded in production
if (import.meta.env.PROD && import.meta.env.VITE_SENTRY_DSN) {
    import('@sentry/vue').then(Sentry => {
        Sentry.init({
            dsn: import.meta.env.VITE_SENTRY_DSN,
            integrations: [],
            tracesSampleRate: 0.1,
        });
    });
}

// Safari private mode throws SecurityError on localStorage — fall back to defaults.
let savedTheme = 'light';
try { savedTheme = localStorage.getItem('theme') || 'light'; } catch {}


const vuetify = createVuetify({
    components,
    directives,
    locale: {
        locale: 'ru',
        fallback: 'en',
        messages: { ru: vuetifyRu, en: vuetifyEn },
    },
    // Палитра — DS Consulting Design System (см. desing/README.md).
    // MD3 color roles. brand/brand-ink — legacy-алиасы для старого кода,
    // оставлены чтобы не ломать ссылки на rgb(var(--v-theme-brand)).
    theme: {
        defaultTheme: savedTheme,
        themes: {
            light: {
                colors: {
                    primary: '#2E7D32',
                    // secondary как ЧИТАЕМЫЙ UI-цвет (текст/иконки/tonal-чипы):
                    // tealный зелёный с контрастом ~4.7:1 на белом, отличим от
                    // forest-green primary. Прежний яркий мятный #6EE87A не
                    // читался как текст в светлой теме (кнопки «Сбросить», числа
                    // дашборда, чип «контрактов») — он сохранён в токене brand
                    // для декоративных поверхностей.
                    secondary: '#00796B',
                    tertiary: '#4361A8',
                    success: '#2E7D32',
                    warning: '#ED6C02',
                    error: '#C62828',
                    info: '#0277BD',
                    background: '#F8F9F8',
                    surface: '#FFFFFF',
                    'on-surface': '#1A1F1B',
                    'on-surface-variant': '#4A524C',
                    'surface-variant': '#E9EBE9',
                    outline: '#BDC4BE',
                    'outline-variant': '#DDE2DE',
                    // legacy-алиасы
                    brand: '#6EE87A',
                    'brand-ink': '#0A2B10',
                },
            },
            dark: {
                dark: true,
                colors: {
                    primary: '#6EE87A',
                    secondary: '#A4E0AC',
                    tertiary: '#B3C5FF',
                    success: '#A4E0AC',
                    warning: '#FFB77A',
                    error: '#FFB4AB',
                    info: '#93CCFF',
                    background: '#0F1311',
                    surface: '#161A17',
                    'on-surface': '#E2E4E2',
                    'on-surface-variant': '#C2C8C3',
                    'surface-variant': '#24292A',
                    outline: '#3D4540',
                    'outline-variant': '#2A312C',
                    // legacy-алиасы
                    brand: '#6EE87A',
                    'brand-ink': '#0A2B10',
                },
            },
        },
    },
    // Defaults — округления md/lg (12px вместо 24px у текущего xl).
    // Density: comfortable (40px) вместо compact (32px) для всех инпутов —
    // соответствует --ds-h-control в дизайн-системе. На v-data-table остаётся
    // compact, т.к. таблицы плотные по природе.
    defaults: {
        VCard: { elevation: 0, rounded: 'lg', border: true },
        VBtn: { rounded: 'md', class: 'text-none' },
        VTextField: { variant: 'outlined', density: 'comfortable', rounded: 'md' },
        VSelect: { variant: 'outlined', density: 'comfortable', rounded: 'md' },
        VTextarea: { variant: 'outlined', density: 'comfortable', rounded: 'md' },
        VAutocomplete: { variant: 'outlined', density: 'comfortable', rounded: 'md' },
        VCombobox: { variant: 'outlined', density: 'comfortable', rounded: 'md' },
        VFileInput: { variant: 'outlined', density: 'comfortable', rounded: 'md' },
        VChip: { size: 'small', rounded: 'pill' },
        VProgressLinear: { rounded: true, color: 'primary' },
        // Vuetify локаль 'ru' уже подключена выше, но v-data-table footer
        // в 3.12 берёт тексты из своих собственных пропов, минуя $vuetify.
        // Поэтому дублируем явно — иначе видно «Items per page: 25 of 1858».
        VDataTableServer: {
            density: 'compact',
            hover: true,
            itemsPerPageText: 'Записей на странице:',
            pageText: '{0}-{1} из {2}',
            noDataText: 'Нет данных',
            loadingText: 'Загрузка…',
        },
        VDataTable: {
            density: 'compact',
            hover: true,
            itemsPerPageText: 'Записей на странице:',
            pageText: '{0}-{1} из {2}',
            noDataText: 'Нет данных',
            loadingText: 'Загрузка…',
        },
        VDialog: { rounded: 'lg' },
        VAlert: { rounded: 'md', variant: 'tonal' },
        VNavigationDrawer: { rounded: 0 },
    },
});

// Pinia with persistence
const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);

// Vue Query config
const vueQueryOptions = {
    queryClientConfig: {
        defaultOptions: {
            queries: {
                staleTime: 60_000,        // data fresh for 1 min
                gcTime: 5 * 60_000,       // keep in cache 5 min
                retry: 1,
                refetchOnWindowFocus: false,
            },
        },
    },
};

// i18n
const i18n = createI18n({
    legacy: false,
    locale: (() => { try { return localStorage.getItem('locale') || 'ru'; } catch { return 'ru'; } })(),
    fallbackLocale: 'ru',
    messages: { ru, en },
});

const app = createApp(App);
app.use(pinia);
app.use(vuetify);
app.use(router);
app.use(VueQueryPlugin, vueQueryOptions);
app.use(i18n);
// Глобальные дефолты vue-tel-input. mode=international + showDialCode=false:
// код страны "+7" виден только в country-selector слева (с флагом), а в
// самом инпуте остаются только цифры в международной маске libphonenumber:
// для RU это "911 835-08-92" (с тире). Так нет дублирования "+7"/"8" между
// чипом и инпутом. v-model по-прежнему хранит "+79118350892" — формат
// отображения не влияет на сохраняемое в БД значение.
app.use(VueTelInput, {
    mode: 'international',
    defaultCountry: 'RU',
    preferredCountries: ['RU', 'BY', 'KZ', 'UA', 'UZ', 'AM', 'AZ', 'KG', 'TJ', 'MD'],
    inputOptions: { showDialCode: false, placeholder: 'Номер телефона' },
    dropdownOptions: { showSearchBox: true, showFlags: true, showDialCodeInSelection: true },
    validCharactersOnly: true,
    autoFormat: true,
    dynamicPlaceholder: true,
});
app.mount('#app');
