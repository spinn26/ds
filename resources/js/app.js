import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
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
                    background: '#f5f5f5',
                },
            },
            dark: {
                dark: true,
                colors: {
                    primary: '#66BB6A',
                    secondary: '#FFB74D',
                    background: '#121212',
                    surface: '#1E1E1E',
                },
            },
        },
    },
});

const app = createApp(App);
app.use(createPinia());
app.use(vuetify);
app.use(router);
app.mount('#app');
