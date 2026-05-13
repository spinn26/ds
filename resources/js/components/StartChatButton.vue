<template>
  <v-tooltip :text="tooltipText" location="left">
    <template #activator="{ props: ttProps }">
      <v-btn v-bind="ttProps" size="small" variant="text" color="primary"
        :icon="icon" :loading="starting" @click="startChat" />
    </template>
  </v-tooltip>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const props = defineProps({
  partnerId: { type: [Number, String], required: true },
  partnerName: { type: String, default: '' },
  contextType: { type: String, default: '' },  // e.g. "contract", "requisites", "acceptance"
  contextId: { type: [Number, String], default: '' },
  contextLabel: { type: String, default: '' },  // e.g. "Контракт #123"
  // silent=true → не отправлять авто-приветствие. Тикет создастся с
  // системной строкой «Чат создан администратором X». Используется
  // в разделе «Партнёры» для общего чата без преамбулы.
  silent: { type: Boolean, default: false },
  // Переопределить subject, иначе генерится по контексту.
  customSubject: { type: String, default: '' },
  icon: { type: String, default: 'mdi-chat-plus' },
  tooltip: { type: String, default: '' },
});

const router = useRouter();
const starting = ref(false);

const tooltipText = computed(() => {
  if (props.tooltip) return props.tooltip;
  return props.silent ? 'Открыть общий чат с партнёром' : 'Написать партнёру';
});

async function startChat() {
  starting.value = true;
  try {
    let subject;
    if (props.customSubject) {
      subject = props.customSubject;
    } else if (props.silent) {
      subject = `Чат по общим вопросам ${props.partnerName || ''}`.trim();
    } else if (props.contextLabel) {
      subject = `${props.contextType}: ${props.contextLabel}`;
    } else {
      subject = `Обращение к ${props.partnerName}`;
    }

    const payload = {
      subject,
      department: 'general',
      context_type: props.contextType,
      context_id: String(props.contextId),
      // partnerId — это consultant.id из listing. Бэк сам резолвит
      // в WebUser.id (разные id-namespace per CLAUDE.md).
      consultant_id: props.partnerId,
    };
    if (props.silent) {
      payload.silent = 1;
    } else {
      payload.message = `Чат создан из раздела: ${props.contextType}${props.contextLabel ? ' — ' + props.contextLabel : ''}`;
    }

    const { data } = await api.post('/chat/tickets', payload);
    router.push(`/manage/chat?open=${data.ticket?.id || ''}`);
  } catch {}
  starting.value = false;
}
</script>
