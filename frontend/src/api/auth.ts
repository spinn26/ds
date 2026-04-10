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
  telegram?: string;
  birthDate?: string;
  city?: string;
  password: string;
  password_confirmation: string;
  refCode?: string;
  consentPersonalData: boolean;
  consentTerms: boolean;
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

export interface DuplicateCheckResult {
  duplicate: boolean;
  type?: 'email' | 'phone' | 'client_mismatch';
  message?: string;
}

export interface ReferralCheckResult {
  valid: boolean;
  message?: string;
  mentor?: {
    id: number;
    name: string;
    code: string;
  };
}

export const authApi = {
  login: (data: LoginData) => api.post<{ token: string; user: AuthUser }>('/auth/login', data),
  register: (data: RegisterData) => api.post<{ token: string; user: AuthUser }>('/auth/register', data),
  me: () => api.get<AuthUser>('/auth/me'),
  logout: () => api.post('/auth/logout'),
  checkDuplicates: (data: { email: string; phone?: string; refCode?: string }) =>
    api.post<DuplicateCheckResult>('/auth/check-duplicates', data),
  checkReferral: (code: string) =>
    api.post<ReferralCheckResult>('/auth/check-referral', { code }),
};
