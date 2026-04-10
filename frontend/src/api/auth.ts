import api from './client';

export interface LoginData {
  email: string;
  password: string;
}

export interface RegisterData {
  firstName: string;
  lastName: string;
  patronymic?: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
}

export interface AuthUser {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  patronymic?: string;
  phone?: string;
  role: string;
}

export const authApi = {
  login: (data: LoginData) => api.post<{ token: string; user: AuthUser }>('/auth/login', data),
  register: (data: RegisterData) => api.post<{ token: string; user: AuthUser }>('/auth/register', data),
  me: () => api.get<AuthUser>('/auth/me'),
  logout: () => api.post('/auth/logout'),
};
