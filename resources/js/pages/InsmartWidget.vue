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
      <!-- Контейнер для виджета. Загрузчик InSmart внутри своей логики
           делает t.parentNode.insertBefore(iframe, t) — то есть iframe
           появляется РЯДОМ с тегом <script>. Поэтому скрипт надо
           аппендить именно в этот div, а не в <head>. -->
      <div ref="mountRef" class="insmart-mount"></div>

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
const mountRef = ref(null);

// Канальные креды от InSmart — идентифицируют DS Consulting как B2B-партнёра.
// INSMART_TOKEN = «ID приложения». INSMART_SECRET = «Закрытый ключ» — loader
// требует его в data-secret и шлёт как query-param iframe'у для channel-auth.
// Тот же ключ дублируется в .env на бэке (INSMART_SECRET) для HS256 JWT-подписи
// user-данных в InsmartController::widgetToken.
const INSMART_TOKEN = '382f5151-a7e5-5ad6-b1fd-2841052a4aac';
const INSMART_SECRET = '15fd9275-80c5-5dfc-a98d-dd0b9ed658c9';
const INSMART_LOADER_SRC = 'https://widgets.inssmart.ru/widgets/b2c-frame.loader.js';
const INSMART_ORIGIN = 'https://widgets.inssmart.ru';

let loaderScript = null;

onMounted(() => {
  loaderScript = document.createElement('script');
  loaderScript.type = 'text/javascript';
  loaderScript.src = INSMART_LOADER_SRC;
  loaderScript.setAttribute('data-id', 'inssmart-b2c');
  loaderScript.setAttribute('data-origin', INSMART_ORIGIN);
  loaderScript.setAttribute('data-product', '/');
  loaderScript.setAttribute('data-token', INSMART_TOKEN);
  loaderScript.setAttribute('data-secret', INSMART_SECRET);
  loaderScript.setAttribute('data-auth', 'true');
  loaderScript.onload = () => {
    // Регистрируем callback ПОСЛЕ загрузки loader'а: до onload у него
    // нет window.InssmartEventListener. Loader.auth(cb) сохраняет cb
    // во внутреннюю переменную `n` и дёргает её перед каждой загрузкой
    // iframe'а — мы отдаём подписанный HS256 JWT с user-info партнёра.
    if (window.InssmartEventListener
        && typeof window.InssmartEventListener.auth === 'function') {
      window.InssmartEventListener.auth(async () => {
        try {
          const { data } = await api.get('/insmart/widget-token');
          // Loader делает postMessage("onload", { token: t }) где t —
          // ровно то, что вернул наш callback. Виджет внутри iframe ждёт
          // `token` как JWT-СТРОКУ. Поэтому отдаём data.token, а не весь
          // объект {token, consultant_id} — иначе во вложенности.
          return data?.token ?? null;
        } catch (e) {
          console.error('[InSmart] auth callback failed:', e);
          throw e;
        }
      });
    } else {
      console.warn('[InSmart] InssmartEventListener.auth недоступен после onload');
    }
    loading.value = false;
  };
  loaderScript.onerror = () => {
    loading.value = false;
    loadError.value = 'Не удалось загрузить скрипт InSmart.';
  };

  // Loader ВСТАВЛЯЕТ iframe рядом со своим тегом <script> через
  // t.parentNode.insertBefore(iframe, t). Поэтому скрипт нужно
  // воткнуть именно внутрь видимого контейнера, а не в <head>.
  if (mountRef.value) {
    mountRef.value.appendChild(loaderScript);
  } else {
    document.body.appendChild(loaderScript);
  }
});

onUnmounted(() => {
  // Чистим как скрипт, так и созданный им iframe — он живёт рядом со
  // скриптом, в том же контейнере, и не уйдёт автоматом при размонтировании
  // Vue-компонента (Vue знает только о своих узлах).
  if (loaderScript && loaderScript.parentNode) {
    loaderScript.parentNode.removeChild(loaderScript);
  }
  loaderScript = null;
  const iframe = document.getElementById('inssmart-b2c-frame');
  if (iframe && iframe.parentNode) {
    iframe.parentNode.removeChild(iframe);
  }
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
/* iframe InSmart'а лежит в .insmart-mount (loader его туда вставляет) */
.insmart-mount :deep(iframe) {
  width: 100%;
  display: block;
}
</style>
