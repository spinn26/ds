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
      <!-- Контейнер для виджета InSmart. data-id ниже у <script> должен
           точно совпадать с id этого элемента — лоадер ищет узел по id
           и монтирует frame внутрь него. -->
      <div id="inssmart-b2c" class="insmart-mount"></div>

      <v-overlay v-if="loading" contained persistent
        class="d-flex align-center justify-center">
        <div class="d-flex flex-column align-center">
          <v-progress-circular indeterminate color="primary" size="48" />
          <div class="mt-3 text-body-2 text-medium-emphasis">Загружаем виджет InSmart…</div>
        </div>
      </v-overlay>

      <v-alert v-if="loadError" type="warning" variant="tonal" class="ma-4">
        <div class="font-weight-medium mb-1">Виджет временно недоступен</div>
        <div class="text-body-2">{{ loadError }}</div>
      </v-alert>
    </v-card>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';

const loading = ref(true);
const loadError = ref(null);

// Канальные креды от InSmart — идентифицируют B2B-партнёра (DS Consulting)
// в их b2c-frame. INSMART_TOKEN — «ID приложения» в b2b-ЛК InSmart,
// INSMART_SECRET — ключ к этому приложению. Не пользовательский токен;
// идентификация конкретного партнёра — через callback ниже.
const INSMART_TOKEN = '382f5151-a7e5-5ad6-b1fd-2841052a4aac';
const INSMART_SECRET = '15fd9275-80c5-5dfc-a98d-dd0b9ed658c9';
const INSMART_LOADER_SRC = 'https://widgets.inssmart.ru/widgets/b2c-frame.loader.js';
const INSMART_ORIGIN = 'https://widgets.inssmart.ru';

let loaderScript = null;

onMounted(() => {
  // Подгружаем loader. data-attributes:
  //   data-id        — id контейнера для монтирования frame'а
  //   data-origin    — origin InSmart, используется в postMessage
  //   data-product   — стартовый раздел внутри виджета («/» = home)
  //   data-token     — канальный токен платформы
  //   data-secret    — канальный секрет (валидируется только сервером InSmart)
  //   data-auth=true — включает вызов InssmartEventListener.auth(cb)
  loaderScript = document.createElement('script');
  loaderScript.type = 'text/javascript';
  loaderScript.src = INSMART_LOADER_SRC;
  loaderScript.setAttribute('data-id', 'inssmart-b2c');
  loaderScript.setAttribute('data-origin', INSMART_ORIGIN);
  loaderScript.setAttribute('data-product', '/');
  loaderScript.setAttribute('data-token', INSMART_TOKEN);
  loaderScript.setAttribute('data-secret', INSMART_SECRET);
  // Temporary: data-auth=false — guest-режим без user-JWT callback'а.
  // Channel auth по data-token + data-secret прошёл (config от InSmart
  // приходит), но при data-auth=true виджет ожидал валидный user-JWT,
  // а наш /insmart/widget-token возвращает null (нет INSMART_API_KEY).
  // В guest-режиме партнёр заполняет ФИО/телефон сам внутри виджета.
  loaderScript.setAttribute('data-auth', 'false');
  loaderScript.onload = () => {
    loading.value = false;
  };
  loaderScript.onerror = () => {
    loading.value = false;
    loadError.value = 'Не удалось загрузить скрипт InSmart. Проверьте интернет-соединение или обратитесь в поддержку.';
  };
  document.head.appendChild(loaderScript);
});

onUnmounted(() => {
  // Снимаем тег <script> при уходе со страницы. window.InssmartEventListener
  // не трогаем — это объект самого лоадера, на других страницах он не мешает,
  // а удалять его свойства может сломать внутренние подписки лоадера.
  if (loaderScript && loaderScript.parentNode) {
    loaderScript.parentNode.removeChild(loaderScript);
  }
  loaderScript = null;
});
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
.insmart-mount {
  width: 100%;
  min-height: 70vh;
}
</style>
