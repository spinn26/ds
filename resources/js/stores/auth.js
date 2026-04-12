import { defineStore } from 'pinia';
import api from '../api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: localStorage.getItem('auth_token'),
        initialized: false,
    }),
    getters: {
        isAdmin: (state) => state.user?.role?.includes('admin') || state.user?.role?.includes('backoffice'),
        isConsultant: (state) => state.user?.role?.includes('consultant'),
        isRegistered: (state) => state.user?.role === 'registered',
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
                localStorage.removeItem('auth_token');
            }
            this.initialized = true;
        },
        async login(email, password) {
            const { data } = await api.post('/auth/login', { email, password });
            this.token = data.token;
            this.user = data.user;
            localStorage.setItem('auth_token', data.token);
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
