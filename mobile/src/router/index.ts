import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

// /app/* — партнёрский кабинет (MobileShell)
// /manage/* — админ-кабинет (MobileShellAdmin), доступен только staff-ролям
// Login сам определяет, куда редиректить — по role у пользователя.
const routes: RouteRecordRaw[] = [
  {
    path: '/',
    redirect: () => {
      const auth = useAuthStore();
      if (!auth.isAuthenticated) return '/login';
      return auth.isStaff ? '/manage/dashboard' : '/app/home';
    },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/Login.vue'),
    meta: { guestOnly: true },
  },
  {
    path: '/app',
    component: () => import('@/layouts/MobileShell.vue'),
    meta: { requiresAuth: true },
    redirect: '/app/home',
    children: [
      { path: 'home', name: 'home', component: () => import('@/views/Home.vue') },
      { path: 'transactions', name: 'transactions', component: () => import('@/views/Transactions.vue') },
      { path: 'contracts', name: 'contracts', component: () => import('@/views/Contracts.vue') },
      { path: 'clients', name: 'clients', component: () => import('@/views/Clients.vue') },
      { path: 'structure', name: 'structure', component: () => import('@/views/Structure.vue') },
      { path: 'qualifications', name: 'qualifications', component: () => import('@/views/Qualifications.vue') },
      { path: 'finance', name: 'finance', component: () => import('@/views/Finance.vue') },
      { path: 'education', name: 'education', component: () => import('@/views/Education.vue') },
      { path: 'chat', name: 'chat', component: () => import('@/views/Chat.vue') },
      { path: 'chat/:id', name: 'chat-thread', component: () => import('@/views/ChatThread.vue') },
      { path: 'notifications', name: 'notifications', component: () => import('@/views/Notifications.vue') },
      { path: 'profile', name: 'profile', component: () => import('@/views/Profile.vue') },
      { path: 'requisites', name: 'requisites', component: () => import('@/views/Requisites.vue') },
      { path: 'documents', name: 'documents', component: () => import('@/views/Documents.vue') },
      { path: 'settings', name: 'settings', component: () => import('@/views/Settings.vue') },
    ],
  },
  {
    path: '/manage',
    component: () => import('@/layouts/MobileShellAdmin.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
    redirect: '/manage/dashboard',
    children: [
      { path: 'dashboard', name: 'm-dashboard', component: () => import('@/views/manage/ManageDashboard.vue') },
      { path: 'partners', name: 'm-partners', component: () => import('@/views/manage/ManagePartners.vue') },
      { path: 'clients', name: 'm-clients', component: () => import('@/views/manage/ManageClients.vue') },
      { path: 'transactions', name: 'm-transactions', component: () => import('@/views/manage/ManageTransactions.vue') },
      { path: 'contracts', name: 'm-contracts', component: () => import('@/views/manage/ManageContracts.vue') },
      { path: 'requisites', name: 'm-requisites', component: () => import('@/views/manage/ManageRequisites.vue') },
      { path: 'charges', name: 'm-charges', component: () => import('@/views/manage/ManageCharges.vue') },
      { path: 'payments', name: 'm-payments', component: () => import('@/views/manage/ManagePayments.vue') },
      { path: 'qualifications', name: 'm-qualifications', component: () => import('@/views/manage/ManageQualifications.vue') },
      { path: 'pool', name: 'm-pool', component: () => import('@/views/manage/ManagePool.vue') },
      { path: 'reports', name: 'm-reports', component: () => import('@/views/manage/ManageReports.vue') },
      { path: 'education', name: 'm-education', component: () => import('@/views/manage/ManageEducation.vue') },
      { path: 'chat', name: 'm-chat', component: () => import('@/views/manage/ManageChat.vue') },
      { path: 'support', name: 'm-support', component: () => import('@/views/manage/ManageSupportDesk.vue') },
      { path: 'contests', name: 'm-contests', component: () => import('@/views/manage/ManageContests.vue') },
      { path: 'menu', name: 'm-menu', component: () => import('@/views/manage/ManageMenu.vue') },
      { path: 'profile', name: 'm-profile', component: () => import('@/views/manage/ManageProfile.vue') },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to) => {
  const auth = useAuthStore();
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } };
  }
  if (to.meta.requiresStaff && !auth.isStaff) {
    return '/app/home';
  }
  if (to.meta.guestOnly && auth.isAuthenticated) {
    return auth.isStaff ? '/manage/dashboard' : '/app/home';
  }
});

export default router;
