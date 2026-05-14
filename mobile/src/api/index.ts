import axios, { type AxiosInstance } from 'axios';
import { useAuthStore } from '@/stores/auth';

// Base URL: для dev указываем dev.dsconsult.ru (тот же backend, что
// и веб). На прод-сборке можно переопределить через VITE_API_BASE.
const baseURL = import.meta.env.VITE_API_BASE || 'https://dev.dsconsult.ru/api/v1';

const api: AxiosInstance = axios.create({
  baseURL,
  timeout: 15000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

// Bearer-токен подкладываем из Pinia-store. Store должен быть
// уже restored (вызов auth.restore() в main или на splash-экране).
api.interceptors.request.use((config) => {
  const auth = useAuthStore();
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`;
  }
  return config;
});

// 401 → выкидываем на логин (token протух / отозван бэком).
// Login-эндпоинт (где 401 = неверный пароль) исключаем — иначе
// при первой неудачной попытке логина юзер сразу выкидывался бы.
api.interceptors.response.use(
  (r) => r,
  async (error) => {
    const url: string = error?.config?.url || '';
    const isLoginCall = url.includes('/auth/login');
    if (error?.response?.status === 401 && !isLoginCall) {
      const auth = useAuthStore();
      if (auth.isAuthenticated) {
        await auth.logout();
        // HTML5 history → используем pathname, а не hash.
        window.location.pathname = '/login';
      }
    }
    return Promise.reject(error);
  },
);

export default api;
