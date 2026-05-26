<template>
  <div class="thread">
    <PageHeader :title="ticket?.subject || 'Чат'" back>
      <template #actions>
        <v-btn v-if="ticket?.status !== 'closed'" icon="mdi-refresh" size="small" variant="text"
          :loading="loading" @click="reload" />
      </template>
    </PageHeader>

    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
      {{ error }}
    </v-alert>

    <div v-if="loading && !messages.length" class="text-center py-8">
      <v-progress-circular indeterminate color="primary" size="32" />
    </div>

    <div v-else class="messages" ref="msgsRef">
      <div v-for="m in messages" :key="m.id" class="msg-row" :class="{ own: isOwn(m), system: m.isSystem }">
        <div v-if="m.isSystem" class="msg-system">{{ m.content }}</div>
        <div v-else class="msg-bubble" :class="{ own: isOwn(m) }">
          <div v-if="!isOwn(m)" class="msg-author">{{ m.senderName }}</div>
          <div v-if="m.content" class="msg-text">{{ m.content }}</div>
          <a v-if="m.attachmentPath" :href="m.attachmentPath" target="_blank" class="msg-attach-link">
            <img v-if="isImage(m.attachmentName)" :src="m.attachmentPath" :alt="m.attachmentName" class="msg-image" />
            <span v-else class="msg-attach-row">
              <v-icon size="16">mdi-paperclip</v-icon>
              {{ m.attachmentName || 'Вложение' }}
            </span>
          </a>
          <div class="msg-time">{{ formatTime(m.createdAt) }}</div>
        </div>
      </div>
    </div>

    <div v-if="ticket?.status !== 'closed'" class="composer-wrap">
      <!-- Превью выбранных файлов прямо над композером -->
      <div v-if="files.length" class="files-preview">
        <div v-for="(item, idx) in files" :key="idx" class="file-item">
          <img v-if="item.previewUrl" :src="item.previewUrl" alt="preview" />
          <v-icon v-else size="22" color="primary">{{ fileIcon(item.file.name) }}</v-icon>
          <div class="file-info">
            <div class="file-name">{{ item.file.name }}</div>
            <div class="file-size">{{ fmtSize(item.file.size) }}</div>
          </div>
          <v-btn icon size="x-small" variant="text" title="Удалить" @click="removeFile(idx)">
            <v-icon size="16">mdi-close</v-icon>
          </v-btn>
        </div>
      </div>

      <div class="composer">
        <input ref="fileInput" type="file" multiple hidden
          accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx"
          @change="onFileInput" />
        <!-- Меню "+" для выбора источника медиа -->
        <v-menu location="top start">
          <template #activator="{ props }">
            <v-btn v-bind="props" icon variant="text" size="small" title="Прикрепить">
              <v-icon>mdi-plus-circle-outline</v-icon>
            </v-btn>
          </template>
          <v-list density="compact" min-width="180">
            <v-list-item prepend-icon="mdi-image-outline" title="Галерея" @click="pickFromGallery" />
            <v-list-item prepend-icon="mdi-camera-outline" title="Камера" @click="pickFromCamera" />
            <v-list-item prepend-icon="mdi-file-outline" title="Файл" @click="pickFile" />
          </v-list>
        </v-menu>

        <v-textarea v-model="text"
          placeholder="Сообщение…"
          density="compact" variant="outlined" hide-details
          rows="1" auto-grow max-rows="5"
          @paste="onPaste"
          @keydown.enter.exact.prevent="send" />

        <v-btn icon color="primary" size="small"
          :loading="sending"
          :disabled="!text.trim() && !files.length"
          @click="send">
          <v-icon>mdi-send</v-icon>
        </v-btn>
      </div>
    </div>
    <div v-else class="closed-note">
      <v-icon size="16">mdi-lock-outline</v-icon>
      Чат закрыт
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { Capacitor } from '@capacitor/core';
import PageHeader from '@/components/PageHeader.vue';
import { useAuthStore } from '@/stores/auth';
import api from '@/api';
import { connectSocket, disconnectSocket, type SocketHandle } from '@/api/socket';

interface Message {
  id: number | string;
  senderId?: number;
  senderName?: string;
  content: string;
  isSystem?: boolean;
  isAgent?: boolean;
  attachmentPath?: string;
  attachmentName?: string;
  createdAt?: string;
}
interface Ticket { id: number; subject?: string; status?: string }
interface FileItem { file: File; previewUrl: string | null }

const route = useRoute();
const auth = useAuthStore();
const ticketId = Number(route.params.id);

const ticket = ref<Ticket | null>(null);
const messages = ref<Message[]>([]);
const text = ref('');
const files = ref<FileItem[]>([]);
const loading = ref(true);
const sending = ref(false);
const error = ref<string | null>(null);
const msgsRef = ref<HTMLElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

let socket: SocketHandle | null = null;

const isOwn = (m: Message) => m.senderId === auth.user?.id;

const IMG_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
function isImage(name?: string) {
  if (!name) return false;
  const ext = name.split('.').pop()?.toLowerCase() || '';
  return IMG_EXT.includes(ext);
}
function fileIcon(name?: string) {
  const ext = name?.split('.').pop()?.toLowerCase() || '';
  return ({ pdf: 'mdi-file-pdf-box', xlsx: 'mdi-microsoft-excel', xls: 'mdi-microsoft-excel', docx: 'mdi-file-word-box', doc: 'mdi-file-word-box' } as Record<string, string>)[ext] || 'mdi-file-outline';
}
function fmtSize(n: number) {
  if (n < 1024) return n + ' B';
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
  return (n / 1024 / 1024).toFixed(1) + ' MB';
}

function addFiles(list: FileList | File[] | null | undefined) {
  if (!list) return;
  for (const f of Array.from(list)) {
    const previewUrl = f.type?.startsWith('image/') ? URL.createObjectURL(f) : null;
    files.value.push({ file: f, previewUrl });
  }
}
function removeFile(idx: number) {
  const it = files.value[idx];
  if (it?.previewUrl) URL.revokeObjectURL(it.previewUrl);
  files.value.splice(idx, 1);
}
function clearFiles() {
  for (const it of files.value) {
    if (it.previewUrl) URL.revokeObjectURL(it.previewUrl);
  }
  files.value = [];
}

function pickFile() {
  fileInput.value?.click();
}
function onFileInput(e: Event) {
  const t = e.target as HTMLInputElement;
  addFiles(t.files);
  t.value = ''; // позволяет выбрать тот же файл повторно
}
function onPaste(e: ClipboardEvent) {
  const items = e.clipboardData?.items || [];
  const pasted: File[] = [];
  for (const it of items) {
    if (it.kind === 'file') {
      const f = it.getAsFile();
      if (f) pasted.push(f);
    }
  }
  if (pasted.length) {
    addFiles(pasted);
    e.preventDefault();
  }
}

// Capacitor Camera (на native): даёт удобный нативный пикер
// галереи или камеры. На вебе — фолбэк на обычный <input type=file>.
async function pickFromGallery() {
  if (!Capacitor.isNativePlatform()) { pickFile(); return; }
  await pickWithCamera('PHOTOS');
}
async function pickFromCamera() {
  if (!Capacitor.isNativePlatform()) { pickFile(); return; }
  await pickWithCamera('CAMERA');
}
async function pickWithCamera(source: 'PHOTOS' | 'CAMERA') {
  try {
    const { Camera, CameraResultType, CameraSource } = await import('@capacitor/camera');
    const photo = await Camera.getPhoto({
      quality: 80,
      resultType: CameraResultType.Base64,
      source: source === 'CAMERA' ? CameraSource.Camera : CameraSource.Photos,
      allowEditing: false,
      width: 1600,
    });
    if (!photo.base64String) return;
    const mime = photo.format ? `image/${photo.format}` : 'image/jpeg';
    const blob = await (await fetch(`data:${mime};base64,${photo.base64String}`)).blob();
    const ext = (photo.format || 'jpg').replace(/^jpeg$/, 'jpg');
    const filename = `photo-${Date.now()}.${ext}`;
    const file = new File([blob], filename, { type: mime });
    addFiles([file]);
  } catch (e) {
    // eslint-disable-next-line no-console
    console.warn('[camera] cancelled or failed', e);
  }
}

async function reload() {
  loading.value = true;
  error.value = null;
  try {
    const { data } = await api.get(`/chat/tickets/${ticketId}`);
    ticket.value = data.ticket || data;
    messages.value = Array.isArray(data.messages) ? data.messages : [];
    await nextTick();
    scrollDown();
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить чат';
  } finally {
    loading.value = false;
  }
}

const newClientId = () => (globalThis.crypto?.randomUUID?.() ?? `cmid-${Date.now()}-${Math.random().toString(36).slice(2)}`);

async function send() {
  const body = text.value.trim();
  const fileItems = files.value.slice();
  if (!body && !fileItems.length) return;
  sending.value = true;
  error.value = null;
  try {
    if (!fileItems.length) {
      const fd = new FormData();
      fd.append('message', body);
      fd.append('client_message_id', newClientId());
      await api.post(`/chat/tickets/${ticketId}/messages`, fd);
    } else {
      // Несколько файлов = несколько сообщений: текст идёт с первым,
      // остальные — только файл. Последовательно, чтобы порядок в БД
      // совпал с порядком выбора.
      for (let i = 0; i < fileItems.length; i++) {
        const fd = new FormData();
        fd.append('message', i === 0 ? body : '');
        fd.append('client_message_id', newClientId());
        fd.append('attachment', fileItems[i].file);
        await api.post(`/chat/tickets/${ticketId}/messages`, fd);
      }
    }
    text.value = '';
    clearFiles();
    await reload();
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось отправить';
  } finally {
    sending.value = false;
  }
}

function scrollDown() {
  const el = msgsRef.value;
  if (el) el.scrollTop = el.scrollHeight;
}

function formatTime(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

onMounted(async () => {
  await reload();
  socket = connectSocket({ ticketId, userId: auth.user?.id, userName: auth.user?.firstName });
  socket?.on('chat:new-message', (msg: any) => {
    if (!msg || Number(msg.ticketId) !== ticketId) return;
    if (messages.value.some((m) => m.id === msg.id)) return;
    messages.value.push(msg);
    nextTick(scrollDown);
  });
});

onBeforeUnmount(() => {
  if (socket) disconnectSocket(socket);
  clearFiles();
});
</script>

<style scoped>
.thread { display: flex; flex-direction: column; min-height: 100%; }
.messages { flex: 1; display: flex; flex-direction: column; gap: 8px; padding-bottom: 120px; max-height: calc(100vh - 220px); overflow-y: auto; }
.msg-row { display: flex; }
.msg-row.own { justify-content: flex-end; }
.msg-row.system { justify-content: center; }
.msg-system { font-size: 11px; color: rgba(0,0,0,0.5); background: rgba(0,0,0,0.04); padding: 4px 10px; border-radius: 12px; }
.msg-bubble { max-width: 78%; background: #fff; border-radius: 14px; padding: 8px 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.msg-bubble.own { background: rgb(var(--v-theme-primary)); color: #fff; }
.msg-author { font-size: 11px; font-weight: 600; color: rgb(var(--v-theme-primary)); margin-bottom: 2px; }
.msg-text { font-size: 14px; line-height: 1.4; white-space: pre-wrap; word-break: break-word; }
.msg-attach-link { display: block; margin-top: 6px; }
.msg-attach-row { display: inline-flex; gap: 4px; align-items: center; font-size: 11px; opacity: 0.85; }
.msg-image { display: block; max-width: 240px; max-height: 240px; border-radius: 8px; object-fit: cover; }
.msg-time { font-size: 10px; opacity: 0.7; margin-top: 4px; text-align: right; }

.composer-wrap {
  position: fixed; left: 0; right: 0;
  bottom: calc(60px + env(safe-area-inset-bottom));
  background: rgba(255,255,255,0.96);
  backdrop-filter: blur(12px);
  border-top: 1px solid rgba(0,0,0,0.06);
}
.composer { display: flex; align-items: flex-end; gap: 6px; padding: 8px 8px max(8px, env(safe-area-inset-bottom)); }
.composer .v-textarea { flex: 1; }

.files-preview {
  display: flex; flex-direction: column; gap: 4px;
  padding: 6px 10px 0;
  max-height: 160px;
  overflow-y: auto;
}
.file-item {
  display: flex; align-items: center; gap: 8px;
  padding: 4px 6px;
  background: rgba(var(--v-theme-primary), 0.08);
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
  border-radius: 10px;
}
.file-item img {
  width: 36px; height: 36px;
  object-fit: cover; border-radius: 6px;
}
.file-info { flex: 1; min-width: 0; }
.file-name {
  font-size: 12px; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.file-size { font-size: 10px; color: rgba(0,0,0,0.55); }

.closed-note { position: fixed; left: 0; right: 0; bottom: calc(60px + env(safe-area-inset-bottom)); padding: 10px; text-align: center; background: rgba(0,0,0,0.04); color: rgba(0,0,0,0.6); font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 6px; }
</style>
