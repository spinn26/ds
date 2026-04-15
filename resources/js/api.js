import axios from 'axios';
import { useSnackbar } from './composables/useSnackbar';

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
        const { showError } = useSnackbar();

        if (status === 401) {
            if (!url.includes('/auth/login') && !url.includes('/auth/register')) {
                localStorage.removeItem('auth_token');
                window.location.href = '/login';
            }
        } else if (status === 403) {
            showError('Недостаточно прав для этого действия');
        } else if (status === 422) {
            // Validation errors — let the component handle it
        } else if (status === 429) {
            showError('Слишком много запросов. Подождите немного.');
        } else if (status >= 500) {
            showError('Ошибка сервера. Попробуйте позже.');
        } else if (!error.response) {
            showError('Нет связи с сервером');
        }

        return Promise.reject(error);
    }
);

export default api;
