import { defineStore } from 'pinia';
import api from '../api';

export const useAuthStore = defineStore('auth', {
    persist: {
        pick: ['token', 'user'],
    },
    state: () => ({
        user: null,
        token: null,
        initialized: false,
    }),
    getters: {
        isAdmin: (state) => {
            const roles = (state.user?.role || '').split(',').map(r => r.trim());
            return roles.includes('admin');
        },
        isStaff: (state) => {
            const roles = (state.user?.role || '').split(',').map(r => r.trim());
            return roles.some(r => ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections', 'education'].includes(r));
        },
        // Чистый education-куратор: спрятать write-кнопки на чужих доменах.
        // Если у пользователя есть admin или другая staff-write-роль — гард не активен.
        isEducationOnly: (state) => {
            const roles = (state.user?.role || '').split(',').map(r => r.trim());
            const overrides = ['admin', 'backoffice', 'finance', 'head', 'calculations', 'corrections'];
            return roles.includes('education') && !roles.some(r => overrides.includes(r));
        },
        // «Руководитель по расчётам» (Богданова) — видит контракты, но не
        // редактирует. Если у пользователя есть admin/backoffice/head — гард
        // не активен (write разрешён). По запросу 2026-05-06 (отдельно для
        // calculations: Менеджер контрактов только для просмотра).
        isCalculationsOnly: (state) => {
            const roles = (state.user?.role || '').split(',').map(r => r.trim());
            const writeOverrides = ['admin', 'backoffice', 'head'];
            return roles.includes('calculations') && !roles.some(r => writeOverrides.includes(r));
        },
        isConsultant: (state) => state.user?.role?.includes('consultant'),
        isRegistered: (state) => state.user?.role === 'registered',
        isTerminated: (state) => state.user?.activityStatus === 3,
        isExcluded: (state) => state.user?.activityStatus === 5,
        userId: (state) => state.user?.id,
        userName: (state) => state.user ? `${state.user.lastName || ''} ${state.user.firstName || ''}`.trim() : '',
    },
    actions: {
        async fetchUser() {
            if (!this.token) {
                this.initialized = true;
                return;
            }
            try {
                const { data } = await api.get('/auth/me');
                this.user = data;
            } catch {
                this.token = null;
                this.user = null;
            }
            this.initialized = true;
        },
        async login(email, password) {
            const { data } = await api.post('/auth/login', { email, password });
            this.token = data.token;
            this.user = data.user;
            this.initialized = true;
        },
        async register(form) {
            const { data } = await api.post('/auth/register', form);
            this.token = data.token;
            this.user = data.user;
            this.initialized = true;
        },
        logout() {
            api.post('/auth/logout').catch(() => {});
            this.token = null;
            this.user = null;
            // Pinia-persist cleared via removing the 'auth' key. The other
            // three are leftovers from the pre-single-source migration;
            // clearing them here until every returning session has rotated out.
            localStorage.removeItem('auth');
            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user_id');
            localStorage.removeItem('auth_user_name');
            sessionStorage.removeItem('impersonator_token');

            // Чистим feature-specific state, которое могло утечь между
            // юзерами на одном браузере (drafts, kanban-фильтры, открытые
            // тикеты, скрытые секции). Префиксы должны совпадать с теми,
            // что используют Vue-страницы при записи.
            const PREFIXES = [
                'staff-chat-',     // StaffChat: drafts, view-mode, context toggle
                'partner-chat-',   // PartnerChat: drafts
                'kanban-',         // Kanban-state на чате
                'pool-',           // Pool: column visibility
                'admin-',          // Admin pages: column visibility, last filter
            ];
            try {
                const toRemove = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    if (k && PREFIXES.some(p => k.startsWith(p))) toRemove.push(k);
                }
                toRemove.forEach(k => localStorage.removeItem(k));
            } catch {}
        },
    },
});
