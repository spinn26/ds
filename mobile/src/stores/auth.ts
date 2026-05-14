import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { Preferences } from '@capacitor/preferences';

// Auth state хранится в Capacitor Preferences (Keychain на iOS,
// SharedPreferences на Android). На вебе плагин фолбэчится
// на localStorage автоматически — для dev-режима в браузере
// ничего дополнительно не нужно.
const TOKEN_KEY = 'ds.auth.token';
const USER_KEY = 'ds.auth.user';

export interface User {
  id: number;
  firstName?: string;
  lastName?: string;
  email?: string;
  role?: string;
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(null);
  const user = ref<User | null>(null);
  const ready = ref(false);

  const isAuthenticated = computed(() => !!token.value);
  // Staff-роли (per web-веб cabinetPermissions.js): admin/support/head/business/
  // finance/calculations/corrections/backoffice/accounting/legal/owner.
  const STAFF_ROLES = ['admin', 'support', 'head', 'business', 'finance', 'calculations', 'corrections', 'backoffice', 'accounting', 'legal', 'owner', 'staff'];
  const isStaff = computed(() => STAFF_ROLES.includes(user.value?.role || ''));

  async function restore() {
    if (ready.value) return;
    const [t, u] = await Promise.all([
      Preferences.get({ key: TOKEN_KEY }),
      Preferences.get({ key: USER_KEY }),
    ]);
    token.value = t.value;
    user.value = u.value ? JSON.parse(u.value) : null;
    ready.value = true;
  }

  async function setSession(newToken: string, newUser: User) {
    token.value = newToken;
    user.value = newUser;
    await Promise.all([
      Preferences.set({ key: TOKEN_KEY, value: newToken }),
      Preferences.set({ key: USER_KEY, value: JSON.stringify(newUser) }),
    ]);
  }

  async function logout() {
    token.value = null;
    user.value = null;
    await Promise.all([
      Preferences.remove({ key: TOKEN_KEY }),
      Preferences.remove({ key: USER_KEY }),
    ]);
  }

  return { token, user, ready, isAuthenticated, isStaff, restore, setSession, logout };
});
