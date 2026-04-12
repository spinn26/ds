import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    // === Guest ===
    { path: '/login', component: () => import('../pages/Auth/Login.vue'), meta: { guest: true } },
    { path: '/register', component: () => import('../pages/Auth/Register.vue'), meta: { guest: true } },

    // === Partner SPA ===
    {
        path: '/',
        component: () => import('../layouts/MainLayout.vue'),
        meta: { auth: true },
        children: [
            { path: '', component: () => import('../pages/Dashboard.vue') },
            { path: 'education', component: () => import('../pages/Education.vue') },
            { path: 'referrals', component: () => import('../pages/Referrals.vue') },
            { path: 'finance/report', component: () => import('../pages/Finance/Report.vue') },
            { path: 'finance/calculator', component: () => import('../pages/Finance/Calculator.vue') },
            { path: 'clients', component: () => import('../pages/Clients/ClientList.vue') },
            { path: 'contracts', component: () => import('../pages/Contracts/MyContracts.vue') },
            { path: 'contracts/team', component: () => import('../pages/Contracts/TeamContracts.vue') },
            { path: 'structure', component: () => import('../pages/Structure.vue') },
            { path: 'products', component: () => import('../pages/Products.vue') },
            { path: 'contests', component: () => import('../pages/Contests.vue') },
            { path: 'communication', component: () => import('../pages/Communication.vue') },
            { path: 'help', component: () => import('../pages/Help.vue') },
            { path: 'profile', component: () => import('../pages/Profile.vue') },
        ],
    },

    // === Admin (отдельный layout) ===
    {
        path: '/admin',
        component: () => import('../layouts/AdminLayout.vue'),
        meta: { auth: true, admin: true },
        children: [
            { path: '', redirect: '/admin/partners' },
            { path: 'contracts', component: () => import('../pages/Admin/ContractManager.vue') },
            { path: 'partners', component: () => import('../pages/Admin/Partners.vue') },
            { path: 'partners/statuses', component: () => import('../pages/Admin/PartnerStatuses.vue') },
            { path: 'clients', component: () => import('../pages/Admin/Clients.vue') },
            { path: 'acceptance', component: () => import('../pages/Admin/Acceptance.vue') },
            { path: 'requisites', component: () => import('../pages/Admin/Requisites.vue') },
            { path: 'transfers', component: () => import('../pages/Admin/Transfers.vue') },
            { path: 'transactions/import', component: () => import('../pages/Admin/TransactionImport.vue') },
            { path: 'transactions', component: () => import('../pages/Admin/Transactions.vue') },
            { path: 'commissions', component: () => import('../pages/Admin/Commissions.vue') },
            { path: 'pool', component: () => import('../pages/Admin/Pool.vue') },
            { path: 'qualifications', component: () => import('../pages/Admin/Qualifications.vue') },
            { path: 'charges', component: () => import('../pages/Admin/Charges.vue') },
            { path: 'payments', component: () => import('../pages/Admin/Payments.vue') },
            { path: 'reports', component: () => import('../pages/Admin/Reports.vue') },
            { path: 'currencies', component: () => import('../pages/Admin/Currencies.vue') },
        ],
    },

    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.initialized) await auth.fetchUser();

    if (to.meta.auth && !auth.user) return '/login';
    if (to.meta.guest && auth.user) return '/';
    if (to.meta.admin && !auth.isAdmin) return '/';

    // Registered-only → education
    if (auth.user?.role === 'registered' && !['education', 'profile', 'help'].some(p => to.path.includes(p))) {
        return '/education';
    }
});

export default router;
