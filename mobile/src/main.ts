import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import vuetify from './plugins/vuetify';
import { useAuthStore } from './stores/auth';
import './styles/global.css';

// Capacitor StatusBar — настраиваем тему статус-бара (тёмный текст
// на зелёном фоне). На вебе плагин просто no-op'ит, ошибки не будет.
import { StatusBar, Style } from '@capacitor/status-bar';
import { Capacitor } from '@capacitor/core';

const app = createApp(App);

const pinia = createPinia();
app.use(pinia);
app.use(vuetify);

// Восстанавливаем сохранённую сессию до router.beforeEach, чтобы при
// перезагрузке (F5) сразу попадать на нужный кабинет, а не на /login.
useAuthStore()
  .restore()
  .finally(() => {
    app.use(router);
    app.mount('#app');
  });

// Native-инициализация: только когда реально запущены в native shell.
if (Capacitor.isNativePlatform()) {
  StatusBar.setStyle({ style: Style.Dark }).catch(() => {});
  StatusBar.setBackgroundColor({ color: '#2E7D32' }).catch(() => {});
}
