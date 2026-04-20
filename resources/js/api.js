import axios from 'axios';
import { useSnackbar } from './composables/useSnackbar';

const api = axios.create({
    baseURL: '/api/v1',
    headers: { 'Accept': 'application/json' },
    timeout: 30000,
});

/**
 * Read token from pinia-persist storage OR legacy localStorage.
 */
function getToken() {
    // Pinia-persist stores under key 'auth' as JSON
    try {
        const stored = localStorage.getItem('auth');
        if (stored) {
            const parsed = JSON.parse(stored);
            if (parsed.token) return parsed.token;
        }
    } catch {}
    // Legacy fallback
    return localStorage.getItem('auth_token');
}

api.interceptors.request.use((config) => {
    const token = getToken();
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
});

api.interceptors.response.use(
    (r) => r,
    (error) => {
        // Request was cancelled (component unmounted / navigation). Not an
        // error the user needs to see — don't pop snackbars, just propagate.
        if (axios.isCancel(error)) {
            return Promise.reject(error);
        }

        const status = error.response?.status;
        const url = error.config?.url || '';
        const { showError } = useSnackbar();

        if (status === 401) {
            if (!url.includes('/auth/login') && !url.includes('/auth/register') && !url.includes('/auth/me')) {
                localStorage.removeItem('auth');
                localStorage.removeItem('auth_token');
                window.location.href = '/login';
            }
        } else if (status === 403) {
            showError('Недостаточно прав для этого действия');
        } else if (status === 422) {
            // Validation — component handles
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
