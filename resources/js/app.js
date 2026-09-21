import { createApp, watch } from 'vue';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import { VueQueryPlugin } from '@tanstack/vue-query';
import { createI18n } from 'vue-i18n';
import { createAppVuetify } from './plugins/vuetify';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import VueTelInput from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';
import './styles/ds-tokens.css';
import './styles/tokens-v2.css';
import './styles/global.css';
import router from './router';
import App from './App.vue';
import api from './api';
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
// Без сохранённого выбора идём за системной темой (per ds-redesign/design/BRAND.md),
// а не за light: раньше пользователь с тёмной ОС получал светлый кабинет и
// переключал тему руками при каждом заходе с нового устройства.
function systemTheme() {
    try {
        return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch { return 'light'; }
}
let savedTheme = systemTheme();
try { savedTheme = localStorage.getItem('theme') || savedTheme; } catch {}


const vuetify = createAppVuetify(savedTheme);

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

// Тема хранится в Vuetify (theme.global.name), а токены редизайна читают
// <html data-theme>. Синхронизация здесь одна на всё приложение: любой код,
// который переключает тему Vuetify (шапка кабинета, AdminLayout с его
// принудительным dark), автоматически перекрашивает и новые компоненты.
// Второго источника правды не заводим — разъедутся.
const applyThemeAttribute = (name) => {
    document.documentElement.setAttribute('data-theme', name === 'dark' ? 'dark' : 'light');
};
applyThemeAttribute(savedTheme);
watch(() => vuetify.theme.global.name.value, applyThemeAttribute);

// Пока пользователь не выбрал тему сам, следуем за системной и на лету.
try {
    if (!localStorage.getItem('theme')) {
        window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', (e) => {
            try { if (localStorage.getItem('theme')) return; } catch { return; }
            vuetify.theme.global.name.value = e.matches ? 'dark' : 'light';
        });
    }
} catch { /* приватный режим — остаёмся на стартовой теме */ }

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

// Активный дизайн (логотип/палитры/CSS) — применяем после монтирования.
// Дефолты в createVuetify совпадают с сид-шаблоном, поэтому вспышки нет.
import('./stores/design').then(({ useDesignStore }) => {
    useDesignStore(pinia).load(vuetify);
});

// i18n-переопределения строк интерфейса (из админки) — мёржим поверх бандла.
api.get('/i18n/overrides').then(({ data }) => {
    const overrides = data?.overrides || {};
    const setDeep = (obj, path, val) => {
        const parts = path.split('.');
        let o = obj;
        parts.forEach((p, idx) => {
            if (idx === parts.length - 1) o[p] = val;
            else { o[p] = (typeof o[p] === 'object' && o[p]) ? o[p] : {}; o = o[p]; }
        });
    };
    for (const [locale, map] of Object.entries(overrides)) {
        const nested = {};
        for (const [key, value] of Object.entries(map)) setDeep(nested, key, value);
        i18n.global.mergeLocaleMessage(locale, nested);
    }
}).catch(() => { /* нет оверрайдов / не залогинен — игнор */ });
