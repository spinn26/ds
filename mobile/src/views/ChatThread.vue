<template>
  <div class="thread">
    <PageHeader :title="ticket.subject" back />

    <div class="messages">
      <div v-for="m in messages" :key="m.id" class="msg-row" :class="{ own: m.own, system: m.system }">
        <div v-if="m.system" class="msg-system">{{ m.text }}</div>
        <div v-else class="msg-bubble" :class="{ own: m.own }">
          <div class="msg-author" v-if="!m.own">{{ m.author }}</div>
          <div class="msg-text">{{ m.text }}</div>
          <div class="msg-time">{{ m.time }}</div>
        </div>
      </div>
    </div>

    <div class="composer">
      <v-btn icon variant="text" size="small" title="Прикрепить">
        <v-icon>mdi-paperclip</v-icon>
      </v-btn>
      <v-textarea v-model="text"
        placeholder="Введите сообщение…"
        density="compact" variant="outlined" hide-details
        rows="1" auto-grow max-rows="5" />
      <v-btn icon color="primary" size="small" :disabled="!text.trim()">
        <v-icon>mdi-send</v-icon>
      </v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

const text = ref('');

const ticket = {
  id: 1,
  subject: 'Не приходят push-уведомления',
};

const messages = [
  { id: 1, system: true, text: 'Чат создан · 12 мая, 09:30' },
  { id: 2, own: true, author: 'Вы', text: 'Здравствуйте! Перестали приходить уведомления о новых транзакциях.', time: '09:31' },
  { id: 3, own: false, author: 'Иван (оператор)', text: 'Добрый день! Проверьте, пожалуйста, разрешения уведомлений в настройках приложения.', time: '10:14' },
  { id: 4, own: true, author: 'Вы', text: 'Разрешения включены. Поможет ли переустановка?', time: '11:02' },
  { id: 5, own: false, author: 'Иван (оператор)', text: 'Попробуйте сначала перезагрузить устройство. Если не поможет — переустановите. Перед этим напишите ID партнёра, я проверю на нашей стороне.', time: '12:14' },
];
</script>

<style scoped>
.thread {
  display: flex;
  flex-direction: column;
  min-height: 100%;
}
.messages {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-bottom: 80px;
}
.msg-row {
  display: flex;
}
.msg-row.own { justify-content: flex-end; }
.msg-row.system { justify-content: center; }

.msg-system {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.5);
  background: rgba(0, 0, 0, 0.04);
  padding: 4px 10px;
  border-radius: 12px;
}

.msg-bubble {
  max-width: 78%;
  background: #fff;
  border-radius: 14px;
  padding: 8px 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
.msg-bubble.own {
  background: rgb(var(--v-theme-primary));
  color: #fff;
}
.msg-author {
  font-size: 11px;
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
  margin-bottom: 2px;
}
.msg-text {
  font-size: 14px;
  line-height: 1.4;
  white-space: pre-wrap;
  word-break: break-word;
}
.msg-time {
  font-size: 10px;
  opacity: 0.7;
  margin-top: 4px;
  text-align: right;
}

.composer {
  position: fixed;
  left: 0; right: 0;
  bottom: calc(60px + env(safe-area-inset-bottom));
  display: flex;
  align-items: flex-end;
  gap: 6px;
  padding: 8px 12px max(8px, env(safe-area-inset-bottom));
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(12px);
  border-top: 1px solid rgba(0, 0, 0, 0.06);
}
.composer .v-textarea {
  flex: 1;
}
</style>
