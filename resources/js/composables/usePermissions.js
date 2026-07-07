/**
 * usePermissions() — хелпер для страниц.
 *
 * Единственный источник прав — auth-store.permissions (карта section→level),
 * загружается из БД через GET /auth/me/permissions и кэшируется в
 * localStorage (см. stores/auth.js). Правится ТОЛЬКО на странице
 * «Группы и права» (/admin/permissions → таблица permission_groups).
 * Статического дубля (config/cabinetPermissions.js) больше нет.
 *
 *   const { canView, canEdit, canFull, isReadOnly, permission } = usePermissions();
 *   <v-btn v-if="canEdit('clients')">Добавить клиента</v-btn>
 *   <v-btn v-if="canFull('clients')" @click="del">Удалить</v-btn>
 *   <v-alert v-if="isReadOnly('clients')">Режим только для просмотра</v-alert>
 */
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';

export function usePermissions() {
  const auth = useAuthStore();

  const userRoles = computed(() => {
    const role = auth.user?.role || '';
    return String(role).split(',').map(r => r.trim().toLowerCase()).filter(Boolean);
  });

  function permission(section) {
    if (!section) return null;
    return auth.permissions?.[section] || null;
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

  // Все КНОПКИ РАСЧЁТОВ (финализация, пул, пересчёт комиссий, фиксация
  // транзакций, удаление с пересчётом) доступны только руководителю
  // расчётов (роль calculations) и админу. Бэкенд дублирует гард
  // (role:admin,calculations на соответствующих роутах).
  const canCalc = computed(() =>
    userRoles.value.includes('calculations') || userRoles.value.includes('admin'));

  return { userRoles, permission, canView, canEdit, canFull, isReadOnly, canCalc };
}
