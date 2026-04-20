<template>
  <div>
    <PageHeader title="API-ключи и токены" icon="mdi-key-variant">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="load">Обновить</v-btn>
        <v-btn color="primary" prepend-icon="mdi-content-save" :loading="saving" @click="saveAll">Сохранить</v-btn>
      </template>
    </PageHeader>

    <v-alert type="info" variant="tonal" class="mb-3">
      Значения хранятся в БД зашифрованными (<code>APP_KEY</code> через Laravel encrypted cast).
      Если поле пустое — используется значение из <code>.env</code> (если задано). Placeholder
      <code>••••••••</code> означает, что значение уже сохранено — оставьте его, чтобы не менять.
    </v-alert>

    <!-- Group by group -->
    <div v-for="(items, group) in groupedItems" :key="group" class="mb-4">
      <v-card>
        <v-card-title class="d-flex align-center pa-3">
          <v-icon class="me-2" :color="groupColor(group)">{{ groupIcon(group) }}</v-icon>
          <span class="text-h6">{{ groupLabel(group) }}</span>
          <v-spacer />
          <v-btn v-if="group === 'telegram'" size="small" variant="tonal" color="info"
            prepend-icon="mdi-send" :loading="testing" @click="testTelegram">
            Тестовое сообщение
          </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text>
          <v-row dense>
            <v-col v-for="s in items" :key="s.key" cols="12" md="6">
              <div class="text-body-2 font-weight-medium">{{ s.label }}</div>
              <div class="text-caption text-medium-emphasis mb-1">
                {{ s.hint || '—' }}
                <template v-if="s.envFallback">
                  · fallback: <code>{{ s.envFallback }}</code>
                  <v-chip v-if="s.envPresent" size="x-small" color="success" class="ms-1">env задан</v-chip>
                </template>
              </div>
              <v-text-field
                v-model="values[s.key]"
                :type="s.secret && !revealed[s.key] ? 'password' : 'text'"
                :placeholder="s.hasValue ? 'Оставить без изменений' : 'Не задано'"
                variant="outlined"
                density="comfortable"
                hide-details
              >
                <template v-if="s.secret" #append-inner>
                  <v-icon :icon="revealed[s.key] ? 'mdi-eye-off' : 'mdi-eye'"
                    size="small" @click="revealed[s.key] = !revealed[s.key]" />
                </template>
              </v-text-field>
              <div class="text-caption text-medium-emphasis mt-1">
                <code class="me-1">{{ s.key }}</code>
                <span v-if="s.updatedAt">· обновлено {{ s.updatedAt }}</span>
              </div>
            </v-col>
          </v-row>
        </v-card-text>
      </v-card>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();

const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const items = ref([]);
const values = reactive({});
const revealed = reactive({});

const groupedItems = computed(() => {
  const out = {};
  for (const it of items.value) {
    (out[it.group] ??= []).push(it);
  }
  return out;
});

function groupLabel(g) {
  return {
    google: 'Google Sheets',
    telegram: 'Telegram бот',
    bubble: 'Bubble (legacy)',
    getcourse: 'GetCourse',
    general: 'Общие',
  }[g] || g;
}
function groupIcon(g) {
  return {
    google: 'mdi-google-spreadsheet',
    telegram: 'mdi-telegram',
    bubble: 'mdi-bubble',
    getcourse: 'mdi-school',
    general: 'mdi-cog',
  }[g] || 'mdi-key';
}
function groupColor(g) {
  return {
    google: 'success',
    telegram: 'info',
    bubble: 'grey',
    getcourse: 'warning',
  }[g] || 'primary';
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/api-settings');
    items.value = data.items || [];
    for (const it of items.value) {
      values[it.key] = it.value || '';
    }
  } catch (e) { showError(e.response?.data?.message || 'Не удалось загрузить настройки'); }
  loading.value = false;
}

async function saveAll() {
  saving.value = true;
  try {
    const payload = { settings: items.value.map(s => ({ key: s.key, value: values[s.key] ?? null })) };
    const { data } = await api.put('/admin/api-settings', payload);
    showSuccess(data.message || 'Сохранено');
    await load();
  } catch (e) { showError(e.response?.data?.message || 'Не удалось сохранить'); }
  saving.value = false;
}

async function testTelegram() {
  testing.value = true;
  try {
    const { data } = await api.post('/admin/api-settings/telegram-test', {});
    if (data.ok) showSuccess(data.message);
    else showError(data.message);
  } catch (e) { showError(e.response?.data?.message || 'Не удалось отправить'); }
  testing.value = false;
}

onMounted(load);
</script>
