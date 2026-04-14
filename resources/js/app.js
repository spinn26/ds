import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import './styles/global.css';
import router from './router';
import App from './App.vue';

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
                    primary: '#66BB6A',
                    secondary: '#FFB74D',
                    background: '#0F1419',
                    surface: '#1A1F2E',
                    'surface-variant': '#232838',
                    info: '#42A5F5',
                    success: '#66BB6A',
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
        VFileInput: { variant: 'outlined', density: 'compact', rounded: 'lg' },
        VChip: { size: 'small', rounded: 'lg' },
        VDataTableServer: { density: 'comfortable', hover: true },
        VDialog: { rounded: 'xl' },
        VAlert: { rounded: 'lg', variant: 'tonal' },
        VNavigationDrawer: { rounded: 'e-xl' },
    },
});

const app = createApp(App);
app.use(createPinia());
app.use(vuetify);
app.use(router);
app.mount('#app');
