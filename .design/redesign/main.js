/**
 * Стенд редизайна: монтирует НАСТОЯЩИЙ каркас кабинета (MainLayout) с
 * настоящими страницами и заглушкой api.
 *
 * Запуск:  npx vite --config .design/redesign/vite.config.mjs
 * Снимок:  node .design/redesign/shot.mjs
 *
 * Параметры адреса: ?theme=light|dark&route=/ | /news/1
 */
import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createMemoryHistory, RouterView } from 'vue-router';
import { VApp } from 'vuetify/components';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import '../../resources/js/styles/ds-tokens.css';
import '../../resources/js/styles/tokens-v2.css';
import '../../resources/js/styles/global.css';
import { createAppVuetify } from '../../resources/js/plugins/vuetify';
import MainLayout from '../../resources/js/layouts/MainLayout.vue';
import Workspace from '../../resources/js/pages/Workspace.vue';
import NewsDetail from '../../resources/js/pages/News/NewsDetail.vue';
import NewsList from '../../resources/js/pages/News/NewsList.vue';
import MyPayments from '../../resources/js/pages/MyPayments.vue';
import Dashboard from '../../resources/js/pages/Dashboard.vue';
import EducationKb from '../../resources/js/pages/EducationKb.vue';
import SystemStatus from '../../resources/js/pages/SystemStatus.vue';
import AdminNews from '../../resources/js/pages/Admin/News.vue';
import { useAuthStore } from '../../resources/js/stores/auth';

const params = new URLSearchParams(location.search);
const theme = params.get('theme') === 'dark' ? 'dark' : 'light';
const startRoute = params.get('route') || '/';

// Токены v2 читают data-theme — в приложении его ставит app.js, здесь стенд.
document.documentElement.setAttribute('data-theme', theme);

const vuetify = createAppVuetify(theme);

const blank = { render: () => h('div') };
const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    {
      path: '/',
      component: MainLayout,
      children: [
        { path: '', component: Workspace },
        { path: 'news', component: NewsList },
        { path: 'news/:id', component: NewsDetail },
        { path: 'my-payments', component: MyPayments },
        { path: 'dashboard', component: Dashboard },
        { path: 'education/kb', component: EducationKb },
        { path: 'status', component: SystemStatus },
        { path: 'manage/news', component: AdminNews },
        // Заглушки для ссылок каркаса: без них router-link ругается.
        { path: ':pathMatch(.*)*', component: blank },
      ],
    },
  ],
});

// Обязательно внутри <v-app>: классы темы Vuetify вешает именно на него,
// без обёртки страница рендерится пустой.
const app = createApp({
  // RouterView импортируем явно: строка 'router-view' в render-функции
  // резолвится как нативный тег и рисует пустоту.
  render: () => h(VApp, null, { default: () => h(RouterView) }),
});
const pinia = createPinia();
app.use(pinia).use(vuetify).use(router);

const auth = useAuthStore();
auth.user = {
  id: 101, firstName: 'Любава', lastName: 'Громова', patronymic: 'Сергеевна',
  email: 'lubava@example.com', role: 'consultant', avatarUrl: null, hasConsultant: true,
};
// Права нужны, чтобы на стенде были видны кнопки правки в админских списках.
auth.permissions = { news: 'full' };

router.push(startRoute).catch(() => {});
router.isReady().then(() => app.mount('#app'));
