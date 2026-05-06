import { defineStore } from 'pinia';
import { useAuthStore } from './auth';

/**
 * Per-user column visibility preferences.
 *
 * Раньше ColumnVisibilityMenu сохранял состояние в `cols:${storageKey}`
 * без namespace по пользователю — на общей машине настройки одного
 * партнёра подменяли настройки другого. Здесь добавлен per-user
 * namespace и единая точка чтения/записи: `cols:${userId|guest}:${key}`.
 *
 * Не реактивный store состояния — просто getter/setter, который пишет
 * в localStorage. Реактивность даёт сам ColumnVisibilityMenu через
 * v-model:visible, а здесь только persistence-слой.
 */
export const useColumnPrefsStore = defineStore('columnPrefs', {
    actions: {
        /**
         * Возвращает namespace-ключ. Без авторизации — guest, чтобы не
         * терять настройки до логина.
         */
        scopedKey(storageKey) {
            const auth = useAuthStore();
            const userId = auth.user?.id || 'guest';
            return `cols:${userId}:${storageKey}`;
        },

        load(storageKey) {
            try {
                const raw = localStorage.getItem(this.scopedKey(storageKey));
                return raw ? JSON.parse(raw) : null;
            } catch {
                return null;
            }
        },

        save(storageKey, state) {
            try {
                localStorage.setItem(this.scopedKey(storageKey), JSON.stringify(state));
            } catch {}
        },

        /**
         * Очищает все column-prefs текущего пользователя. Вызывать при
         * logout, чтобы следующий пользователь на той же машине начал
         * с чистого листа.
         */
        clearForCurrentUser() {
            const auth = useAuthStore();
            const userId = auth.user?.id || 'guest';
            const prefix = `cols:${userId}:`;
            try {
                const keys = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    if (k && k.startsWith(prefix)) keys.push(k);
                }
                keys.forEach(k => localStorage.removeItem(k));
            } catch {}
        },
    },
});
