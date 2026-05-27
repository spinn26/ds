<template>
  <div class="pa-4">
    <PageHeader
      title="VPN для сотрудников"
      subtitle="Клиент Happ + ключ доступа и инструкция по подключению"
      icon="mdi-shield-lock-outline"
    />

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-4"
      icon="mdi-information-outline">
      Страница только для сотрудников. Ключ и инструкция предназначены для
      внутреннего использования — не передавайте их третьим лицам.
    </v-alert>

    <!-- ШАГ 1. Скачивание клиента -->
    <v-card class="ds-card mb-4 pa-4" elevation="0">
      <div class="text-h6 mb-1 d-flex align-center ga-2">
        <v-avatar size="28" color="primary" variant="tonal">
          <span class="font-weight-bold">1</span>
        </v-avatar>
        Скачайте приложение Happ
      </div>
      <div class="text-body-2 text-medium-emphasis mb-4">
        Кроссплатформенный клиент для работы с прокси-серверами (ядро Xray,
        поддержка VLESS). Установите версию под свою систему.
      </div>

      <v-row dense>
        <!-- Windows -->
        <v-col cols="12" md="6">
          <v-card variant="outlined" class="pa-4 h-100">
            <div class="d-flex align-center ga-3 mb-3">
              <v-icon size="32" color="primary">mdi-microsoft-windows</v-icon>
              <div>
                <div class="text-subtitle-1 font-weight-bold">Windows</div>
                <div class="text-caption text-medium-emphasis">64-bit · установщик .exe</div>
              </div>
            </div>
            <v-btn block color="primary" size="large" prepend-icon="mdi-download"
              :href="DOWNLOADS.windows" target="_blank" rel="noopener">
              Скачать для Windows
            </v-btn>
          </v-card>
        </v-col>

        <!-- macOS -->
        <v-col cols="12" md="6">
          <v-card variant="outlined" class="pa-4 h-100">
            <div class="d-flex align-center ga-3 mb-3">
              <v-icon size="32" color="primary">mdi-apple</v-icon>
              <div>
                <div class="text-subtitle-1 font-weight-bold">macOS (MacBook)</div>
                <div class="text-caption text-medium-emphasis">Universal · .dmg или App Store</div>
              </div>
            </div>
            <v-btn block color="primary" size="large" prepend-icon="mdi-download"
              :href="DOWNLOADS.macDmg" target="_blank" rel="noopener" class="mb-2">
              Скачать .dmg для macOS
            </v-btn>
            <div class="d-flex ga-2">
              <v-btn class="flex-grow-1" variant="tonal" size="small" prepend-icon="mdi-apple"
                :href="DOWNLOADS.macAppStore" target="_blank" rel="noopener">
                App Store
              </v-btn>
              <v-btn class="flex-grow-1" variant="tonal" size="small" prepend-icon="mdi-apple"
                :href="DOWNLOADS.macAppStoreRu" target="_blank" rel="noopener">
                App Store (РФ)
              </v-btn>
            </div>
          </v-card>
        </v-col>
      </v-row>

      <div class="text-caption text-medium-emphasis mt-3">
        Официальный сайт:
        <a href="https://www.happ.su/main/ru" target="_blank" rel="noopener" class="text-primary">happ.su</a>
      </div>
    </v-card>

    <!-- ШАГ 2. Ключ доступа -->
    <v-card class="ds-card mb-4 pa-4" elevation="0">
      <div class="text-h6 mb-1 d-flex align-center ga-2">
        <v-avatar size="28" color="primary" variant="tonal">
          <span class="font-weight-bold">2</span>
        </v-avatar>
        Скопируйте ключ доступа
      </div>
      <div class="text-body-2 text-medium-emphasis mb-3">
        Это ключ конфигурации VLESS. Скопируйте его целиком — он понадобится
        в приложении на следующем шаге.
      </div>

      <v-textarea
        :model-value="VLESS_KEY"
        readonly
        variant="outlined"
        rows="3"
        auto-grow
        hide-details
        class="vpn-key mb-3"
        @focus="selectAll"
      />
      <v-btn color="primary" prepend-icon="mdi-content-copy" @click="copyKey">
        Скопировать ключ
      </v-btn>
    </v-card>

    <!-- ШАГ 3. Инструкция -->
    <v-card class="ds-card pa-4" elevation="0">
      <div class="text-h6 mb-3 d-flex align-center ga-2">
        <v-avatar size="28" color="primary" variant="tonal">
          <span class="font-weight-bold">3</span>
        </v-avatar>
        Добавьте ключ и подключитесь
      </div>

      <v-list lines="two" density="comfortable" class="bg-transparent">
        <v-list-item v-for="(s, i) in steps" :key="i" class="px-0">
          <template #prepend>
            <v-avatar size="26" color="primary-soft" class="me-3">
              <span class="text-primary font-weight-bold text-caption">{{ i + 1 }}</span>
            </v-avatar>
          </template>
          <v-list-item-title class="text-wrap font-weight-medium" v-html="s.title" />
          <v-list-item-subtitle v-if="s.hint" class="text-wrap mt-1">{{ s.hint }}</v-list-item-subtitle>
        </v-list-item>
      </v-list>

      <v-alert type="success" variant="tonal" density="comfortable" class="mt-3"
        icon="mdi-check-circle-outline">
        Готово. После подключения статус в Happ станет «Connected», а трафик
        пойдёт через сервер <strong>MyServer</strong>.
      </v-alert>
    </v-card>
  </div>
</template>

<script setup>
import PageHeader from '../../components/PageHeader.vue';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();

// Ссылки на клиент Happ (источник: happ.su / GitHub Happ-proxy).
const DOWNLOADS = {
  windows: 'https://github.com/Happ-proxy/happ-desktop/releases/latest/download/setup-Happ.x64.exe',
  macDmg: 'https://github.com/Happ-proxy/happ-desktop/releases/latest/download/Happ.macOS.universal.dmg',
  macAppStore: 'https://apps.apple.com/us/app/happ-proxy-utility/id6504287215',
  macAppStoreRu: 'https://apps.apple.com/ru/app/happ-proxy-utility-plus/id6746188973',
};

const VLESS_KEY = 'vless://f318de58-69de-4fde-9a90-3092b7608dbd@212.67.15.55:443?encryption=none&flow=xtls-rprx-vision&security=reality&sni=www.google.com&fp=chrome&pbk=yudFyb8_Z-UNcBJx4wAxfCa5MiLt8wA6Iy5OP8zQ5hQ&type=tcp#MyServer';

const steps = [
  {
    title: 'Установите и запустите приложение <strong>Happ</strong>',
    hint: 'Windows: запустите скачанный setup-Happ.x64.exe. macOS: откройте .dmg и перетащите Happ в «Программы», либо установите из App Store.',
  },
  {
    title: 'Скопируйте ключ кнопкой «Скопировать ключ» (шаг 2)',
    hint: 'Ключ попадёт в буфер обмена — он начинается с vless://',
  },
  {
    title: 'В Happ нажмите «+» и выберите «Добавить из буфера обмена»',
    hint: 'Add from clipboard / Import from clipboard. Приложение само распознает ключ из буфера. Если предлагает добавить конфигурацию при запуске — согласитесь.',
  },
  {
    title: 'В списке появится сервер <strong>MyServer</strong> — выберите его',
    hint: 'Если серверов несколько, убедитесь, что активен именно MyServer.',
  },
  {
    title: 'Нажмите большую кнопку подключения (Connect)',
    hint: 'При первом запуске система может запросить разрешение на создание VPN-конфигурации — подтвердите.',
  },
];

async function copyKey() {
  try {
    await navigator.clipboard.writeText(VLESS_KEY);
    showSuccess('Ключ скопирован в буфер обмена');
  } catch {
    showError('Не удалось скопировать. Выделите ключ вручную и скопируйте.');
  }
}

function selectAll(e) {
  e.target?.select?.();
}
</script>

<style scoped>
.vpn-key :deep(textarea) {
  font-family: 'Roboto Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 13px;
  line-height: 1.5;
  word-break: break-all;
}
</style>
