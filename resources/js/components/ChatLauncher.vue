<template>
  <!-- Плавающая кнопка/виджет чатов снизу-справа. Скрывается на самой
       странице чата (внутри неё своя верстка). -->
  <div v-if="!hiddenOnRoute" class="chat-launcher" :class="{ open }">
    <!-- Панель -->
    <transition name="cl-pop">
      <v-card v-if="open" class="cl-panel" elevation="16">
        <div class="cl-header">
          <v-icon size="20" color="primary">mdi-chat-processing</v-icon>
          <div class="cl-title flex-grow-1">Мои чаты</div>
          <v-btn icon="mdi-arrow-expand" size="x-small" variant="text"
            title="Открыть полностью" @click="goFull" />
          <v-btn icon="mdi-close" size="x-small" variant="text" @click="open = false" />
        </div>

        <div class="cl-body">
          <div v-if="loading" class="cl-empty">
            <v-progress-circular indeterminate size="20" />
          </div>
          <div v-else-if="!chats.length" class="cl-empty">
            <v-icon size="36" color="grey-lighten-1">mdi-chat-remove</v-icon>
            <div class="text-body-2 mt-2">Чатов пока нет</div>
            <v-btn class="mt-3" size="small" variant="tonal" color="primary"
              prepend-icon="mdi-plus" @click="goNew">Новый тикет</v-btn>
          </div>
          <v-list v-else density="compact" class="pa-0">
            <v-list-item v-for="t in chats" :key="t.id"
              class="cl-row" @click="openTicket(t)">
              <template #prepend>
                <v-avatar size="32" :color="t.unread ? 'primary' : 'grey-lighten-2'" variant="tonal">
                  <v-icon size="18">{{ statusIcon(t.status) }}</v-icon>
                </v-avatar>
              </template>
              <v-list-item-title class="text-body-2 font-weight-medium text-truncate">
                {{ t.subject || 'Без темы' }}
              </v-list-item-title>
              <v-list-item-subtitle class="text-caption text-truncate">
                <span v-if="t.last_message_from_me" class="text-medium-emphasis">Вы:&nbsp;</span>
                {{ t.last_message_preview || statusLabel(t.status) }}
              </v-list-item-subtitle>
              <template #append>
                <div class="text-caption text-medium-emphasis" style="font-size:0.6rem">
                  {{ timeAgo(t.last_message_at || t.updated_at) }}
                </div>
                <v-badge v-if="t.unread > 0" :content="t.unread" color="error" inline class="ms-2" />
              </template>
            </v-list-item>
          </v-list>
        </div>

        <div class="cl-footer">
          <v-btn variant="text" size="small" prepend-icon="mdi-open-in-new" @click="goFull">
            Все чаты
          </v-btn>
          <v-spacer />
          <v-btn variant="tonal" size="small" color="primary" prepend-icon="mdi-plus" @click="goNew">
            Новый
          </v-btn>
        </div>
      </v-card>
    </transition>

    <!-- FAB-кнопка -->
    <v-btn class="cl-fab" :color="totalUnread > 0 ? 'error' : 'primary'"
      size="large" icon elevation="8" @click="toggle">
      <v-badge v-if="totalUnread > 0" :content="totalUnread" color="white" floating>
        <v-icon size="22">mdi-chat-processing</v-icon>
      </v-badge>
      <v-icon v-else size="22">{{ open ? 'mdi-close' : 'mdi-chat-processing' }}</v-icon>
    </v-btn>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const open = ref(false);
const loading = ref(false);
const chats = ref([]);
let timer = null;

const totalUnread = computed(() => chats.value.reduce((s, t) => s + (t.unread || 0), 0));

// Не показываем виджет на самой странице чата — там полноценный UI.
const hiddenOnRoute = computed(() => {
  const p = route.path || '';
  return p.startsWith('/manage/chat') || p.startsWith('/chat');
});

async function load() {
  if (!auth.token) return;
  loading.value = true;
  try {
    const { data } = await api.get('/chat/tickets', { params: { page: 1 } });
    chats.value = (data.data || []).slice(0, 8);
  } catch {
    chats.value = [];
  }
  loading.value = false;
}

function toggle() {
  open.value = !open.value;
  if (open.value) load();
}

function openTicket(t) {
  open.value = false;
  const path = auth.user?.role && /admin|backoffice|support|head|finance|calculations|corrections|education/.test(auth.user.role)
    ? '/manage/chat' : '/chat';
  router.push(`${path}?open=${t.id}`);
}
function goFull() {
  open.value = false;
  const path = auth.user?.role && /admin|backoffice|support|head|finance|calculations|corrections|education/.test(auth.user.role)
    ? '/manage/chat' : '/chat';
  router.push(path);
}
function goNew() {
  open.value = false;
  // «Тех. проблема» (support) убрана у консультантов — техвопросы идут в
  // Telegram @DS_Helpdesk (решение 2026-05-26). Дефолтный новый тикет —
  // «Поддержка по продукту» (backoffice).
  router.push('/chat?new=backoffice');
}

const STATUS_LABELS = { new: 'Новый', open: 'Открыт', in_progress: 'В работе',
  pending: 'Ожидает', resolved: 'Решён', closed: 'Закрыт' };
function statusLabel(s) { return STATUS_LABELS[s] || s; }
function statusIcon(s) {
  return { new: 'mdi-circle', open: 'mdi-message-text', in_progress: 'mdi-progress-clock',
    pending: 'mdi-clock-outline', resolved: 'mdi-check', closed: 'mdi-lock' }[s] || 'mdi-message';
}
function timeAgo(iso) {
  if (!iso) return '';
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return 'сейчас';
  if (diff < 3600) return Math.floor(diff / 60) + ' мин';
  if (diff < 86400) return Math.floor(diff / 3600) + ' ч';
  if (diff < 604800) return Math.floor(diff / 86400) + ' дн';
  return new Date(iso).toLocaleDateString();
}

// Polling каждые 30 сек (только когда виджет НЕ открыт — иначе load
// при toggle подтянет свежее).
onMounted(() => {
  load();
  timer = setInterval(() => { if (!open.value) load(); }, 30_000);
});
onUnmounted(() => { if (timer) clearInterval(timer); });

// Закрыть на смену роута.
watch(() => route.path, () => { open.value = false; });
</script>

<style scoped>
.chat-launcher {
  position: fixed;
  right: 20px;
  bottom: 20px;
  z-index: 1100;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 12px;
  pointer-events: none;
}
.chat-launcher > * { pointer-events: auto; }

.cl-fab {
  width: 56px;
  height: 56px;
}

.cl-panel {
  width: 360px;
  max-width: calc(100vw - 40px);
  max-height: 70vh;
  display: flex;
  flex-direction: column;
  border-radius: 14px;
  overflow: hidden;
  background: rgb(var(--v-theme-surface));
}
.cl-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.1);
}
.cl-title { font-weight: 600; font-size: 14px; }
.cl-body {
  flex: 1 1 auto;
  overflow-y: auto;
  min-height: 120px;
  max-height: 50vh;
}
.cl-empty {
  text-align: center;
  padding: 32px 16px;
  color: rgba(var(--v-theme-on-surface), 0.6);
}
.cl-row {
  cursor: pointer;
}
.cl-row:hover {
  background: rgba(var(--v-theme-primary), 0.06);
}
.cl-footer {
  display: flex;
  align-items: center;
  padding: 8px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  background: rgba(var(--v-theme-surface-variant), 0.3);
}

/* Анимация раскрытия */
.cl-pop-enter-active, .cl-pop-leave-active { transition: all 0.2s ease; transform-origin: bottom right; }
.cl-pop-enter-from, .cl-pop-leave-to { opacity: 0; transform: scale(0.9) translateY(20px); }
</style>
