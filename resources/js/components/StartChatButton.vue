<template>
  <v-tooltip text="Написать партнёру" location="left">
    <template #activator="{ props: ttProps }">
      <v-btn v-bind="ttProps" size="small" variant="text" color="primary"
        icon="mdi-chat-plus" :loading="starting" @click="startChat" />
    </template>
  </v-tooltip>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const props = defineProps({
  partnerId: { type: [Number, String], required: true },
  partnerName: { type: String, default: '' },
  contextType: { type: String, default: '' },  // e.g. "contract", "requisites", "acceptance"
  contextId: { type: [Number, String], default: '' },
  contextLabel: { type: String, default: '' },  // e.g. "Контракт #123"
});

const router = useRouter();
const starting = ref(false);

async function startChat() {
  starting.value = true;
  try {
    const subject = props.contextLabel
      ? `${props.contextType}: ${props.contextLabel}`
      : `Обращение к ${props.partnerName}`;

    const { data } = await api.post('/chat/tickets', {
      subject,
      message: `Чат создан из раздела: ${props.contextType}${props.contextLabel ? ' — ' + props.contextLabel : ''}`,
      department: 'general',
      context_type: props.contextType,
      context_id: String(props.contextId),
      recipient_id: props.partnerId,
    });

    router.push(`/manage/chat?open=${data.ticket?.id || ''}`);
  } catch {}
  starting.value = false;
}
</script>
