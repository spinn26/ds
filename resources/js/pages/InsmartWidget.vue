<template>
  <div>
    <PageHeader title="InSmart" icon="mdi-shield-car"
      :subtitle="'Подбор и оформление страховых продуктов'" />

    <v-card class="mb-3 pa-3 d-flex align-center ga-3 flex-wrap">
      <v-icon color="primary" size="20">mdi-information-outline</v-icon>
      <div class="text-body-2 flex-grow-1">
        Вся последующая обработка данных и начисления происходят автоматически.
        После оплаты страхового полиса контракт и транзакция создаются на платформе
        без вашего участия.
      </div>
      <v-btn variant="text" size="small" prepend-icon="mdi-arrow-left"
        @click="$router.push('/products')">К продуктам</v-btn>
    </v-card>

    <v-card class="insmart-frame" elevation="2">
      <div v-if="loading" class="insmart-loader">
        <v-progress-circular indeterminate color="primary" size="48" />
        <div class="mt-3 text-body-2 text-medium-emphasis">Загружаем виджет InSmart…</div>
      </div>

      <v-alert v-else-if="error" type="warning" variant="tonal" class="ma-4">
        <div class="font-weight-medium mb-1">Виджет временно недоступен</div>
        <div class="text-body-2">{{ error }}</div>
        <v-btn class="mt-3" variant="tonal" size="small" color="warning"
          prepend-icon="mdi-refresh" @click="loadToken">Повторить</v-btn>
      </v-alert>

      <iframe v-else-if="iframeUrl" :src="iframeUrl" class="insmart-iframe"
        allow="payment; clipboard-read; clipboard-write" />

      <v-alert v-else type="info" variant="tonal" class="ma-4">
        Виджет InSmart не настроен в данном окружении. Свяжитесь с поддержкой
        для подключения интеграции.
      </v-alert>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';

const loading = ref(true);
const error = ref(null);
const token = ref(null);
const widgetUrl = ref(null);
const consultantId = ref(null);

// Insmart отдаёт либо готовый widgetUrl, либо только token — тогда
// собираем URL по шаблону, указанному в их доке: ?token=...&client=...
const iframeUrl = computed(() => {
  if (widgetUrl.value) return widgetUrl.value;
  if (token.value && consultantId.value) {
    return `https://widget.inssmart.ru/?token=${encodeURIComponent(token.value)}`
      + `&clientId=${encodeURIComponent(consultantId.value)}`;
  }
  return null;
});

async function loadToken() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get('/insmart/widget-token');
    token.value = data.token || null;
    widgetUrl.value = data.widget_url || null;
    consultantId.value = data.consultant_id || null;
    if (!token.value && !widgetUrl.value && data.message) {
      error.value = data.message;
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Не удалось получить токен InSmart';
  }
  loading.value = false;
}

onMounted(loadToken);
</script>

<style scoped>
.insmart-frame {
  position: relative;
  min-height: 70vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: var(--ds-radius-lg, 12px);
}
.insmart-iframe {
  width: 100%;
  height: 80vh;
  border: 0;
  display: block;
}
.insmart-loader {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 64px 16px;
}
</style>
