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
            return roles.some(r => ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections'].includes(r));
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
        },
    },
});
