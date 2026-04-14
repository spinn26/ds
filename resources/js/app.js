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
                    primary: '#4CAF50',
                    secondary: '#FF9800',
                    background: '#FAFAFA',
                    surface: '#FFFFFF',
                    info: '#2196F3',
                    success: '#4CAF50',
                    warning: '#FF9800',
                    error: '#F44336',
                },
            },
            dark: {
                dark: true,
                colors: {
                    primary: '#66BB6A',
                    secondary: '#FFB74D',
                    background: '#121212',
                    surface: '#1E1E1E',
                    info: '#42A5F5',
                    success: '#66BB6A',
                    warning: '#FFA726',
                    error: '#EF5350',
                },
            },
        },
    },
    defaults: {
        VCard: { elevation: 2, rounded: 'lg' },
        VBtn: { rounded: 'lg' },
        VTextField: { variant: 'outlined', density: 'compact' },
        VSelect: { variant: 'outlined', density: 'compact' },
        VTextarea: { variant: 'outlined', density: 'compact' },
        VAutocomplete: { variant: 'outlined', density: 'compact' },
        VChip: { size: 'small' },
        VDataTableServer: { density: 'compact', hover: true },
    },
});

const app = createApp(App);
app.use(createPinia());
app.use(vuetify);
app.use(router);
app.mount('#app');
