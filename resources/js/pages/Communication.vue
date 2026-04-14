<template>
  <div>
    <PageHeader title="Обратная связь" icon="mdi-message-text">
      <template #actions>
        <v-badge v-if="unreadCount > 0" :content="unreadCount" color="error" inline class="mr-2" />
        <v-btn color="primary" prepend-icon="mdi-pencil" @click="openSendDialog">Написать сообщение</v-btn>
      </template>
    </PageHeader>

    <v-card class="mb-3 pa-3">
      <div class="d-flex ga-2 flex-wrap align-center">
        <v-select v-model="categoryFilter" :items="categoryOptions" label="Категория"
          clearable hide-details style="max-width:260px" @update:model-value="loadMessages" />
        <v-chip v-if="categoryFilter" size="small" color="info" variant="tonal" class="ml-1">1 фильтр</v-chip>
        <v-btn v-if="categoryFilter" size="small" variant="text" color="secondary"
          prepend-icon="mdi-filter-remove" @click="categoryFilter = null; loadMessages()">Сбросить</v-btn>
      </div>
    </v-card>

    <v-card :loading="loading">
      <v-list lines="three">
        <template v-for="msg in messages" :key="msg.id">
          <v-list-item>
            <template #prepend>
              <v-chip size="small" :color="msg.direction === 'ds2p' ? 'blue' : 'green'" class="mr-2" variant="flat">
                {{ msg.direction === 'ds2p' ? 'От DS' : 'Вы' }}
              </v-chip>
            </template>
            <v-list-item-title class="d-flex align-center ga-2">
              <span class="font-weight-medium">{{ msg.category || 'Без категории' }}</span>
              <v-chip v-if="msg.direction === 'ds2p' && !msg.readAt" size="x-small" color="error">Новое</v-chip>
              <span class="text-caption text-medium-emphasis ml-auto">{{ msg.createdAt }}</span>
            </v-list-item-title>
            <v-list-item-subtitle class="mt-1" style="white-space: pre-line">{{ msg.message }}</v-list-item-subtitle>
            <template #append>
              <div class="d-flex flex-column ga-1">
                <v-btn v-if="msg.direction === 'ds2p' && !msg.readAt" size="x-small" variant="outlined" color="primary"
                  prepend-icon="mdi-check" @click="markRead(msg)">Прочитано</v-btn>
                <v-btn v-if="msg.direction === 'ds2p'" size="x-small" variant="outlined"
                  prepend-icon="mdi-reply" @click="openReply(msg)">Ответить</v-btn>
              </div>
            </template>
          </v-list-item>
          <v-divider />
        </template>
        <v-list-item v-if="!messages.length && !loading">
          <EmptyState icon="mdi-message-off-outline" />
        </v-list-item>
      </v-list>

      <div v-if="totalPages > 1" class="d-flex justify-center pa-3">
        <v-pagination v-model="page" :length="totalPages" density="compact" @update:model-value="loadMessages" />
      </div>
    </v-card>

    <!-- Send / Reply Dialog -->
    <v-dialog v-model="sendDialog" max-width="600" persistent>
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">{{ replyTo ? 'mdi-reply' : 'mdi-pencil' }}</v-icon>
          {{ replyTo ? 'Ответ на сообщение' : 'Новое сообщение' }}
        </v-card-title>
        <v-card-text>
          <v-select v-model="sendForm.category_id" :items="categoryOptions" label="Категория"
            class="mb-3" />
          <v-textarea v-model="sendForm.message" label="Сообщение" rows="5" auto-grow />
          <v-alert v-if="sendError" type="error" density="compact" class="mt-2">{{ sendError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="sendDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="sending" @click="sendMessage" prepend-icon="mdi-send">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../api';
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';

const loading = ref(false);
const messages = ref([]);
const page = ref(1);
const totalPages = ref(1);
const unreadCount = ref(0);
const categoryFilter = ref(null);
const categoryOptions = ref([]);
const sendDialog = ref(false);
const sending = ref(false);
const sendError = ref('');
const replyTo = ref(null);
const sendForm = ref({ category_id: null, message: '' });

async function loadMessages() {
  loading.value = true;
  try {
    const params = { page: page.value };
    if (categoryFilter.value) params.category = categoryFilter.value;
    const { data } = await api.get('/communication', { params });
    messages.value = data.data;
    totalPages.value = data.last_page || 1;
  } catch {}
  loading.value = false;
}

async function loadUnreadCount() {
  try {
    const { data } = await api.get('/communication/unread-count');
    unreadCount.value = data.count ?? data;
  } catch {}
}

async function loadCategories() {
  try {
    const { data } = await api.get('/communication/categories');
    categoryOptions.value = data.map(c => ({ title: c.name, value: c.id }));
  } catch {}
}

async function markRead(msg) {
  try {
    await api.post(`/communication/${msg.id}/read`);
    msg.readAt = new Date().toISOString();
    loadUnreadCount();
  } catch {}
}

function openSendDialog() {
  replyTo.value = null;
  sendForm.value = { category_id: null, message: '' };
  sendError.value = '';
  sendDialog.value = true;
}

function openReply(msg) {
  replyTo.value = msg;
  sendForm.value = { category_id: msg.category_id || null, message: '' };
  sendError.value = '';
  sendDialog.value = true;
}

async function sendMessage() {
  sending.value = true;
  sendError.value = '';
  try {
    await api.post('/communication', {
      category_id: sendForm.value.category_id,
      message: sendForm.value.message,
      reply_to: replyTo.value?.id || null,
    });
    sendDialog.value = false;
    loadMessages();
    loadUnreadCount();
  } catch (e) {
    sendError.value = e.response?.data?.message || 'Ошибка отправки';
  }
  sending.value = false;
}

onMounted(() => {
  loadMessages();
  loadUnreadCount();
  loadCategories();
});
</script>
