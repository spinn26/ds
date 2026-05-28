import { defineStore } from 'pinia';
import { useAuthStore } from './auth';

/**
 * Per-user column preferences: видимость + порядок.
 *
 * Раньше ColumnVisibilityMenu сохранял состояние в `cols:${storageKey}`
 * без namespace по пользователю — на общей машине настройки одного
 * партнёра подменяли настройки другого. Здесь добавлен per-user
 * namespace: `cols:${userId|guest}:${key}`.
 *
 * Формат payload: `{ visible: { key: bool }, order: [key1, key2, ...] }`.
 * Старый формат (просто `{ key: bool }`) распознаётся и нормализуется
 * при чтении — поле order=null, visible=стар. payload.
 *
 * Не реактивный store состояния — getter/setter поверх localStorage.
 * Реактивность даёт сам ColumnVisibilityMenu через v-model:visible /
 * v-model:order, здесь только persistence-слой.
 */
export const useColumnPrefsStore = defineStore('columnPrefs', {
    actions: {
        scopedKey(storageKey) {
            const auth = useAuthStore();
            const userId = auth.user?.id || 'guest';
            return `cols:${userId}:${storageKey}`;
        },

        /**
         * Возвращает { visible, order } или null если нет записи.
         * Если в storage сохранён старый формат (плоский {key: bool}) —
         * оборачиваем в новый, чтобы потребители не имели двух веток.
         */
        load(storageKey) {
            try {
                const raw = localStorage.getItem(this.scopedKey(storageKey));
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object'
                    && (Object.prototype.hasOwnProperty.call(parsed, 'visible')
                        || Object.prototype.hasOwnProperty.call(parsed, 'order'))) {
                    return {
                        visible: parsed.visible || {},
                        order: Array.isArray(parsed.order) ? parsed.order : null,
                    };
                }
                // legacy: плоский объект видимости.
                return { visible: parsed || {}, order: null };
            } catch {
                return null;
            }
        },

        save(storageKey, payload) {
            try {
                const normalized = {
                    visible: payload?.visible || {},
                    order: Array.isArray(payload?.order) ? payload.order : null,
                };
                localStorage.setItem(this.scopedKey(storageKey), JSON.stringify(normalized));
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
