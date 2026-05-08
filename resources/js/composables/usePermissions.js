/**
 * usePermissions() — хелпер для страниц.
 *
 * Берёт роли из auth-store и возвращает реактивные геттеры:
 *   permission(section)   — 'view' / 'edit' / 'full' / null
 *   canView(section)      — bool (есть хоть какой-то доступ)
 *   canEdit(section)      — bool (можно добавлять/редактировать)
 *   canFull(section)      — bool (можно удалять / системные действия)
 *   isReadOnly(section)   — bool (доступ есть, но ТОЛЬКО на чтение)
 *
 * Пример:
 *   const { canEdit, canFull, isReadOnly } = usePermissions();
 *   <v-btn v-if="canEdit('clients')">Добавить клиента</v-btn>
 *   <v-btn v-if="canFull('clients')" @click="del">Удалить</v-btn>
 *   <v-alert v-if="isReadOnly('clients')">Режим только для просмотра</v-alert>
 */
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';
import {
  getPermission,
  canView as canViewFn,
  canEdit as canEditFn,
  canFull as canFullFn,
} from '../config/cabinetPermissions';

export function usePermissions() {
  const auth = useAuthStore();

  // Roles меняются при логине/обновлении профиля — оборачиваем в computed,
  // чтобы реактивные шаблоны переиспользовали значение без лишних геттеров.
  const userRoles = computed(() => auth.user?.roles || []);

  const permission = (section) => getPermission(userRoles.value, section);
  const canView = (section) => canViewFn(userRoles.value, section);
  const canEdit = (section) => canEditFn(userRoles.value, section);
  const canFull = (section) => canFullFn(userRoles.value, section);
  const isReadOnly = (section) => permission(section) === 'view';

  return { userRoles, permission, canView, canEdit, canFull, isReadOnly };
}
