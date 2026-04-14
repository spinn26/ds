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
        if (error.response?.status === 401) {
            const url = error.config?.url || '';
            // Don't redirect on login/register attempts — let the page show the error
            if (!url.includes('/auth/login') && !url.includes('/auth/register')) {
                localStorage.removeItem('auth_token');
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    }
);

export default api;
