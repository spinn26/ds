import { defineStore } from 'pinia';
import api from '../api';

export const useAuthStore = defineStore('auth', {
    persist: {
        pick: ['token'],
    },
    state: () => ({
        user: null,
        token: localStorage.getItem('auth_token'),
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
        isTerminated: (state) => state.user?.activityStatus === 3, // Терминирован
        isExcluded: (state) => state.user?.activityStatus === 5, // Исключён
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
                localStorage.setItem('auth_user_id', data.id);
                localStorage.setItem('auth_user_name', `${data.lastName || ''} ${data.firstName || ''}`.trim());
            } catch {
                this.token = null;
                localStorage.removeItem('auth_token');
            }
            this.initialized = true;
        },
        async login(email, password) {
            const { data } = await api.post('/auth/login', { email, password });
            this.token = data.token;
            this.user = data.user;
            localStorage.setItem('auth_token', data.token);
            localStorage.setItem('auth_user_id', data.user.id);
            localStorage.setItem('auth_user_name', `${data.user.lastName || ''} ${data.user.firstName || ''}`.trim());
        },
        async register(form) {
            const { data } = await api.post('/auth/register', form);
            this.token = data.token;
            this.user = data.user;
            localStorage.setItem('auth_token', data.token);
        },
        logout() {
            api.post('/auth/logout').catch(() => {});
            this.token = null;
            this.user = null;
            localStorage.removeItem('auth_token');
        },
    },
});
