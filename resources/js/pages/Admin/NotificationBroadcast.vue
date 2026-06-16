<template>
  <div>
    <PageHeader title="Рассылка уведомлений" icon="mdi-bell-ring" />

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-3">
      Отправка in-app уведомления (колокольчик в шапке). Доставляется всем
      выбранным пользователям; они увидят его при обновлении страницы.
    </v-alert>

    <v-card class="pa-4" max-width="720">
      <v-text-field v-model="form.title" label="Заголовок *" density="comfortable"
        :error-messages="errs.title" class="mb-2" />
      <v-textarea v-model="form.message" label="Текст" rows="3" auto-grow density="comfortable" class="mb-2" />
      <v-text-field v-model="form.link" label="Ссылка (необязательно)" density="comfortable"
        placeholder="/profile" prepend-inner-icon="mdi-link" class="mb-3" />

      <v-radio-group v-model="form.target" inline density="compact" class="mb-2">
        <v-radio label="Всем пользователям" value="all" />
        <v-radio label="По ролям" value="roles" />
      </v-radio-group>
      <v-select v-if="form.target === 'roles'" v-model="form.roles" :items="roleOptions"
        item-title="title" item-value="value" label="Роли" multiple chips closable-chips
        density="comfortable" class="mb-2" />

      <div class="d-flex justify-end mt-2">
        <v-btn color="primary" :loading="sending" prepend-icon="mdi-send" @click="send">Отправить</v-btn>
      </div>
      <v-alert v-if="result" type="success" variant="tonal" density="compact" class="mt-3">{{ result }}</v-alert>
    </v-card>

    <v-snackbar v-model="snack.open" :color="snack.color" timeout="3500">{{ snack.text }}</v-snackbar>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const roleOptions = [
  { title: 'Администратор', value: 'admin' }, { title: 'Бэкофис', value: 'backoffice' },
  { title: 'Техподдержка', value: 'support' }, { title: 'Руководитель', value: 'head' },
  { title: 'Фин. менеджер', value: 'finance' }, { title: 'Расчёты', value: 'calculations' },
  { title: 'Правки', value: 'corrections' }, { title: 'Отдел обучения', value: 'education' },
  { title: 'Консультант', value: 'consultant' }, { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];

const form = reactive({ title: '', message: '', link: '', target: 'all', roles: [] });
const errs = reactive({});
const sending = ref(false);
const result = ref('');
const snack = ref({ open: false, color: 'success', text: '' });
function notify(text, color = 'success') { snack.value = { open: true, color, text }; }

async function send() {
  Object.keys(errs).forEach(k => delete errs[k]);
  result.value = '';
  if (form.target === 'roles' && !form.roles.length) { notify('Выберите роли', 'error'); return; }
  if (!confirm('Отправить уведомление выбранным пользователям?')) return;
  sending.value = true;
  try {
    const { data } = await api.post('/admin/notifications/broadcast', { ...form });
    result.value = data.message || 'Отправлено';
    form.title = ''; form.message = ''; form.link = '';
  } catch (e) {
    if (e.response?.status === 422) {
      const ve = e.response.data.errors || {};
      for (const [k, v] of Object.entries(ve)) errs[k] = v[0];
      notify(e.response.data.message || 'Проверьте поля', 'error');
    } else notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  sending.value = false;
}
</script>
