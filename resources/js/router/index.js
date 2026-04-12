import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/login',
        component: () => import('../pages/Auth/Login.vue'),
        meta: { guest: true },
    },
    {
        path: '/register',
        component: () => import('../pages/Auth/Register.vue'),
        meta: { guest: true },
    },
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
            // Admin
            { path: 'admin/contracts', component: () => import('../pages/Admin/ContractManager.vue') },
            { path: 'admin/partners', component: () => import('../pages/Admin/Partners.vue') },
            { path: 'admin/clients', component: () => import('../pages/Admin/Clients.vue') },
            { path: 'admin/partners/statuses', component: () => import('../pages/Admin/PartnerStatuses.vue') },
            { path: 'admin/acceptance', component: () => import('../pages/Admin/Acceptance.vue') },
            { path: 'admin/requisites', component: () => import('../pages/Admin/Requisites.vue') },
            { path: 'admin/transfers', component: () => import('../pages/Admin/Transfers.vue') },
            { path: 'admin/transactions/import', component: () => import('../pages/Admin/TransactionImport.vue') },
            { path: 'admin/transactions', component: () => import('../pages/Admin/Transactions.vue') },
            { path: 'admin/commissions', component: () => import('../pages/Admin/Commissions.vue') },
            { path: 'admin/pool', component: () => import('../pages/Admin/Pool.vue') },
            { path: 'admin/qualifications', component: () => import('../pages/Admin/Qualifications.vue') },
            { path: 'admin/charges', component: () => import('../pages/Admin/Charges.vue') },
            { path: 'admin/payments', component: () => import('../pages/Admin/Payments.vue') },
            { path: 'admin/reports', component: () => import('../pages/Admin/Reports.vue') },
            { path: 'admin/currencies', component: () => import('../pages/Admin/Currencies.vue') },
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

    if (!auth.initialized) {
        await auth.fetchUser();
    }

    if (to.meta.auth && !auth.user) return '/login';
    if (to.meta.guest && auth.user) return '/';

    // Registered users can only access education
    if (auth.user?.role === 'registered' && to.path !== '/education' && to.path !== '/profile' && to.path !== '/help') {
        return '/education';
    }
});

export default router;
