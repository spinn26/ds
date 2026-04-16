import { createApp } from 'vue';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import { VueQueryPlugin } from '@tanstack/vue-query';
import { createVuetify } from 'vuetify';
import { createI18n } from 'vue-i18n';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
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

const savedTheme = localStorage.getItem('theme') || 'light';

const vuetify = createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: savedTheme,
        themes: {
            light: {
                colors: {
                    primary: '#43A047',
                    secondary: '#FB8C00',
                    background: '#EEF1F6',
                    surface: '#F8F9FC',
                    'surface-variant': '#E8ECF2',
                    'on-surface': '#2D3748',
                    'on-background': '#1A202C',
                    info: '#5C9CE6',
                    success: '#5CB85C',
                    warning: '#F0AD4E',
                    error: '#E25D5D',
                },
            },
            dark: {
                dark: true,
                colors: {
                    primary: '#2E7D32',
                    secondary: '#FFB74D',
                    background: '#0F1419',
                    surface: '#1A1F2E',
                    'surface-variant': '#232838',
                    info: '#42A5F5',
                    success: '#2E7D32',
                    warning: '#FFA726',
                    error: '#EF5350',
                },
            },
        },
    },
    defaults: {
        VCard: { elevation: 0, rounded: 'xl', border: true },
        VBtn: { rounded: 'xl' },
        VTextField: { variant: 'outlined', density: 'compact', rounded: 'lg' },
        VSelect: { variant: 'outlined', density: 'compact', rounded: 'lg' },
        VTextarea: { variant: 'outlined', density: 'compact', rounded: 'lg' },
        VAutocomplete: { variant: 'outlined', density: 'compact', rounded: 'lg' },
        VCombobox: { variant: 'outlined', density: 'compact', rounded: 'lg' },
        VFileInput: { variant: 'outlined', density: 'compact', rounded: 'lg' },
        VChip: { size: 'small', rounded: 'lg' },
        VDataTableServer: { density: 'comfortable', hover: true },
        VDialog: { rounded: 'xl' },
        VAlert: { rounded: 'lg', variant: 'tonal' },
        VNavigationDrawer: { rounded: 'e-xl' },
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
    locale: localStorage.getItem('locale') || 'ru',
    fallbackLocale: 'ru',
    messages: { ru, en },
});

const app = createApp(App);
app.use(pinia);
app.use(vuetify);
app.use(router);
app.use(VueQueryPlugin, vueQueryOptions);
app.use(i18n);
app.mount('#app');
