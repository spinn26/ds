<template>
  <div>
    <PageHeader title="Безопасность и 2FA" back />

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>
    <v-alert v-if="success" type="success" variant="tonal" density="compact" class="mb-3">
      {{ success }}
    </v-alert>

    <!-- Текущий статус 2FA -->
    <v-card class="detail-card" elevation="0">
      <div class="section-title-row">
        <v-icon size="18" color="primary">mdi-shield-check-outline</v-icon>
        <span class="section-title">Двухфакторная аутентификация</span>
      </div>
      <div class="d-flex align-center justify-space-between">
        <div>
          <div class="text-body-2 font-weight-bold">
            <span v-if="loading">Загрузка…</span>
            <span v-else-if="enabled" class="text-success">Включена</span>
            <span v-else class="text-medium-emphasis">Выключена</span>
          </div>
          <div class="text-caption text-medium-emphasis">
            При входе с нового устройства потребуется код из приложения-аутентификатора.
          </div>
        </div>
        <v-btn v-if="!loading && !enabled" color="primary" size="small" @click="beginSetup">Включить</v-btn>
        <v-btn v-else-if="!loading && enabled" color="error" variant="tonal" size="small" @click="showDisable = true">
          Отключить
        </v-btn>
      </div>
    </v-card>

    <!-- Биометрия — заглушка для нативного билда -->
    <v-card class="detail-card mt-3" elevation="0">
      <div class="section-title-row">
        <v-icon size="18" color="primary">mdi-fingerprint</v-icon>
        <span class="section-title">Вход по биометрии</span>
      </div>
      <div class="d-flex align-center justify-space-between">
        <div class="text-caption text-medium-emphasis">
          Touch ID / Face ID. Доступно только в нативном приложении.
        </div>
        <v-switch v-model="biometric" hide-details density="compact" color="primary" inset
          @update:model-value="saveBiometric" />
      </div>
    </v-card>

    <!-- Setup-диалог: QR + ввод первого кода для подтверждения -->
    <v-dialog v-model="setupOpen" max-width="480">
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="mr-2" color="primary">mdi-qrcode-scan</v-icon>
          Подключение 2FA
        </v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-3">
            Отсканируйте QR-код в приложении Google Authenticator / 1Password / Microsoft Authenticator
            и введите 6-значный код для подтверждения.
          </div>
          <div v-if="setupData?.qr" class="qr-wrap">
            <img :src="setupData.qr" alt="2FA QR" class="qr-img" />
          </div>
          <div v-if="setupData?.secret" class="secret-row">
            <span class="text-caption text-medium-emphasis">или вручную:</span>
            <code>{{ setupData.secret }}</code>
          </div>
          <v-text-field v-model="setupCode" label="Код из приложения" type="text"
            inputmode="numeric" maxlength="6" class="mt-2"
            :error-messages="setupError" />
          <v-btn block color="primary" :loading="confirming" :disabled="setupCode.length !== 6"
            @click="confirmSetup">
            Подтвердить
          </v-btn>
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Disable-диалог: подтверждение кодом -->
    <v-dialog v-model="showDisable" max-width="420">
      <v-card>
        <v-card-title>Отключить 2FA?</v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-3">
            Для отключения введите текущий 6-значный код или пароль.
          </div>
          <v-text-field v-model="disableCode" label="Код или пароль"
            :error-messages="disableError" />
          <div class="d-flex ga-2 mt-2">
            <v-btn variant="text" @click="showDisable = false">Отмена</v-btn>
            <v-spacer />
            <v-btn color="error" :loading="disabling" :disabled="!disableCode" @click="confirmDisable">
              Отключить
            </v-btn>
          </div>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Preferences } from '@capacitor/preferences';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/api';

interface SetupData { qr?: string; secret?: string }

const loading = ref(true);
const enabled = ref(false);
const biometric = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const setupOpen = ref(false);
const setupData = ref<SetupData | null>(null);
const setupCode = ref('');
const setupError = ref<string | null>(null);
const confirming = ref(false);

const showDisable = ref(false);
const disableCode = ref('');
const disableError = ref<string | null>(null);
const disabling = ref(false);

async function loadStatus() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/2fa/status');
    enabled.value = !!(data?.enabled ?? data?.two_factor_enabled);
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось получить статус 2FA';
  } finally {
    loading.value = false;
  }
}

async function beginSetup() {
  error.value = null;
  setupError.value = null;
  setupCode.value = '';
  try {
    const { data } = await api.post('/2fa/setup');
    // Бэк может вернуть { qr, secret } или { qrCode, secretKey } — поддержим оба.
    setupData.value = {
      qr: data?.qr || data?.qrCode || data?.qr_code || data?.qrUrl,
      secret: data?.secret || data?.secretKey || data?.secret_key,
    };
    setupOpen.value = true;
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось начать настройку';
  }
}

async function confirmSetup() {
  setupError.value = null;
  if (setupCode.value.length !== 6) return;
  confirming.value = true;
  try {
    await api.post('/2fa/confirm', { code: setupCode.value });
    setupOpen.value = false;
    enabled.value = true;
    success.value = '2FA включена';
    setTimeout(() => { success.value = null; }, 4000);
  } catch (e: any) {
    setupError.value = e?.response?.data?.message || 'Неверный код';
  } finally {
    confirming.value = false;
  }
}

async function confirmDisable() {
  disableError.value = null;
  disabling.value = true;
  try {
    await api.post('/2fa/disable', { code: disableCode.value, password: disableCode.value });
    showDisable.value = false;
    enabled.value = false;
    success.value = '2FA отключена';
    setTimeout(() => { success.value = null; }, 4000);
    disableCode.value = '';
  } catch (e: any) {
    disableError.value = e?.response?.data?.message || 'Не удалось отключить';
  } finally {
    disabling.value = false;
  }
}

async function saveBiometric(val: boolean | null) {
  await Preferences.set({ key: 'settings.biometric', value: String(!!val) });
}

onMounted(async () => {
  loadStatus();
  const r = await Preferences.get({ key: 'settings.biometric' });
  biometric.value = r.value === 'true';
});
</script>

<style scoped>
.detail-card { background: #fff; border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.section-title-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.section-title { font-size: 14px; font-weight: 600; }
.qr-wrap { display: flex; justify-content: center; margin: 12px 0; }
.qr-img { width: 180px; height: 180px; background: #fff; padding: 8px; border-radius: 8px; box-shadow: 0 0 0 1px rgba(0,0,0,0.06); }
.secret-row { font-size: 12px; text-align: center; margin: 8px 0; word-break: break-all; }
.secret-row code { background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; font-family: monospace; }
</style>
