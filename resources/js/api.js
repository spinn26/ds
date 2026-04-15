import axios from 'axios';

const api = axios.create({
    baseURL: '/api/v1',
    headers: { 'Accept': 'application/json' },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('auth_token');
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
});

api.interceptors.response.use(
    (r) => r,
    (error) => {
        const status = error.response?.status;
        const url = error.config?.url || '';

        if (status === 401) {
            // Don't redirect on login/register attempts
            if (!url.includes('/auth/login') && !url.includes('/auth/register')) {
                localStorage.removeItem('auth_token');
                window.location.href = '/login';
            }
        }

        if (status === 403) {
            // Show notification or redirect — user lacks permission
            console.warn('[API] Forbidden:', url);
        }

        return Promise.reject(error);
    }
);

export default api;
