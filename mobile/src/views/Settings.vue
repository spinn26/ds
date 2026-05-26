<template>
  <div>
    <PageHeader title="Настройки" back />

    <div class="menu-group">
      <div class="menu-cell">
        <v-icon>mdi-bell-outline</v-icon>
        <div class="menu-cell-title">
          Push-уведомления
          <div v-if="pushStatus === 'denied'" class="text-caption text-error">
            Разрешения отключены в настройках устройства
          </div>
          <div v-else-if="pushStatus === 'unsupported'" class="text-caption text-medium-emphasis">
            Доступно только в мобильном приложении
          </div>
          <div v-else-if="pushStatus === 'granted'" class="text-caption text-success">
            Разрешено
          </div>
        </div>
        <v-switch v-model="push" :disabled="pushStatus === 'denied' || pushStatus === 'unsupported'"
          hide-details density="compact" color="primary" inset
          @update:model-value="onTogglePush" />
      </div>
      <div class="menu-cell">
        <v-icon>mdi-email-outline</v-icon>
        <div class="menu-cell-title">E-mail рассылка</div>
        <v-switch v-model="emailNotify" hide-details density="compact" color="primary" inset
          @update:model-value="saveFlag('emailNotify', emailNotify)" />
      </div>
      <div class="menu-cell">
        <v-icon>mdi-theme-light-dark</v-icon>
        <div class="menu-cell-title">Тёмная тема</div>
        <v-switch v-model="darkMode" hide-details density="compact" color="primary" inset
          @update:model-value="onToggleTheme" />
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell">
        <v-icon>mdi-fingerprint</v-icon>
        <div class="menu-cell-title">Вход по биометрии</div>
        <v-switch v-model="biometric" hide-details density="compact" color="primary" inset
          @update:model-value="saveFlag('biometric', biometric)" />
      </div>
      <div class="menu-cell">
        <v-icon>mdi-key-variant</v-icon>
        <div class="menu-cell-title">Сменить пароль</div>
        <v-icon class="menu-cell-arrow">mdi-chevron-right</v-icon>
      </div>
      <div class="menu-cell">
        <v-icon>mdi-shield-check-outline</v-icon>
        <div class="menu-cell-title">Двухфакторная аутентификация</div>
        <v-chip v-if="twoFAEnabled === true" size="x-small" color="success" variant="tonal">включена</v-chip>
        <v-chip v-else-if="twoFAEnabled === false" size="x-small" color="grey" variant="tonal">выключена</v-chip>
        <v-icon v-else class="menu-cell-arrow">mdi-loading mdi-spin</v-icon>
      </div>
    </div>

    <div class="menu-group">
      <div class="menu-cell">
        <v-icon>mdi-translate</v-icon>
        <div class="menu-cell-title">Язык</div>
        <span class="text-caption text-medium-emphasis">Русский</span>
      </div>
      <div class="menu-cell">
        <v-icon>mdi-cellphone-cog</v-icon>
        <div class="menu-cell-title">Версия приложения</div>
        <span class="text-caption text-medium-emphasis">{{ appVersion }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useTheme } from 'vuetify';
import { Preferences } from '@capacitor/preferences';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';
import { requestPushPermissions, getPushPermissions, type PushPermResult } from '@/api/push';

const pushStatus = ref<PushPermResult>('unsupported');

async function onTogglePush(val: boolean | null) {
  const on = !!val;
  // Сохраняем флаг пользователя сразу — даже если разрешение в системе
  // ещё не выдано. Это его «хочу» — а получится ли его выполнить,
  // зависит от системных разрешений.
  await saveFlag('push', on);
  if (!on) return;
  // Запрос системного разрешения. На web вернёт 'unsupported' — switch
  // и так заблокирован, сюда не дойдёт.
  const res = await requestPushPermissions();
  pushStatus.value = res;
  if (res === 'denied') {
    // Откатываем UI — пользователь хотел включить, но разрешения нет.
    push.value = false;
    await saveFlag('push', false);
  }
}

const theme = useTheme();
const push = ref(true);
const emailNotify = ref(false);
const darkMode = ref(theme.global.current.value.dark);
const biometric = ref(false);
const twoFAEnabled = ref<boolean | null>(null);
const appVersion = '0.1.0';

async function saveFlag(key: string, val: boolean) {
  await Preferences.set({ key: `settings.${key}`, value: String(val) });
}
async function loadFlag(key: string, fallback: boolean) {
  const r = await Preferences.get({ key: `settings.${key}` });
  return r.value == null ? fallback : r.value === 'true';
}

async function onToggleTheme(val: boolean | null) {
  const on = !!val;
  theme.global.name.value = on ? 'dsDark' : 'dsLight';
  await saveFlag('darkMode', on);
}

onMounted(async () => {
  push.value = await loadFlag('push', true);
  emailNotify.value = await loadFlag('emailNotify', false);
  biometric.value = await loadFlag('biometric', false);
  // Узнаём текущее системное разрешение на push (granted/denied/prompt/unsupported)
  pushStatus.value = await getPushPermissions();
  // Если пользователь хотел push, но разрешение в системе отозвано — синхронизируем
  if (push.value && pushStatus.value === 'denied') push.value = false;
  // Тема: применяем сохранённую при загрузке.
  const savedTheme = await loadFlag('darkMode', false);
  darkMode.value = savedTheme;
  theme.global.name.value = savedTheme ? 'dsDark' : 'dsLight';

  // Тянем актуальный 2FA-статус с бэка (если эндпоинт доступен).
  try {
    const { data } = await api.get('/2fa/status');
    twoFAEnabled.value = !!(data?.enabled ?? data?.two_factor_enabled);
  } catch {
    twoFAEnabled.value = null;
  }
});
</script>
