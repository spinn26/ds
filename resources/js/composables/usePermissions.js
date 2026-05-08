/**
 * usePermissions() — хелпер для страниц.
 *
 * Источник прав — auth-store (state.permissions), который загружается
 * через GET /auth/me/permissions при логине / boot'е приложения.
 * Это обновляемый из БД набор: правки в админке /manage/permissions
 * подхватываются автоматически после следующего fetch'а.
 *
 * Если БД-данные не загружены (ещё не залогинились / временно упало)
 * — фоллбэк на статический config/cabinetPermissions.js по ролям.
 *
 *   const { canView, canEdit, canFull, isReadOnly, permission } = usePermissions();
 *   <v-btn v-if="canEdit('clients')">Добавить клиента</v-btn>
 *   <v-btn v-if="canFull('clients')" @click="del">Удалить</v-btn>
 *   <v-alert v-if="isReadOnly('clients')">Режим только для просмотра</v-alert>
 */
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';
import {
  getPermission as staticGetPermission,
} from '../config/cabinetPermissions';

const LEVEL_RANK = { view: 1, edit: 2, full: 3 };

export function usePermissions() {
  const auth = useAuthStore();

  const userRoles = computed(() => {
    const role = auth.user?.role || '';
    return String(role).split(',').map(r => r.trim().toLowerCase()).filter(Boolean);
  });

  // Грузил ли БД successfull (хоть одна запись — значит response пришёл,
  // даже если для роли прав нет)? Используем простой признак: объект
  // permissions заполнен. Если auth-store ещё не дёрнул API или вернул
  // пусто — фоллбэк на static.
  const dbLoaded = computed(() =>
    auth.permissions && Object.keys(auth.permissions).length > 0
  );

  function permission(section) {
    if (!section) return null;
    if (dbLoaded.value) return auth.permissions[section] || null;
    return staticGetPermission(userRoles.value, section);
  }

  function canView(section) {
    return permission(section) !== null;
  }
  function canEdit(section) {
    const p = permission(section);
    return p === 'edit' || p === 'full';
  }
  function canFull(section) {
    return permission(section) === 'full';
  }
  function isReadOnly(section) {
    return permission(section) === 'view';
  }

  return { userRoles, permission, canView, canEdit, canFull, isReadOnly };
}
