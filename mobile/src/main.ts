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
// После mount фоном тянем свежие данные пользователя (без блокировки UI).
const auth = useAuthStore();
auth.restore().finally(async () => {
  app.use(router);
  app.mount('#app');
  if (auth.isAuthenticated) {
    auth.refreshMe();
    // Регистрация push — только на native, на вебе no-op.
    import('./api/push').then((m) => m.setupPushNotifications()).catch(() => {});
    // Счётчик непрочитанных в шапке: первый запрос + polling.
    const { useNotificationsStore } = await import('./stores/notifications');
    const notif = useNotificationsStore();
    notif.refresh();
    notif.startPolling();
  }
});

// Native-инициализация: только когда реально запущены в native shell.
if (Capacitor.isNativePlatform()) {
  StatusBar.setStyle({ style: Style.Dark }).catch(() => {});
  StatusBar.setBackgroundColor({ color: '#2E7D32' }).catch(() => {});
}
