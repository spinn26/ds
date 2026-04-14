<template>
  <div class="d-inline-block">
    <v-tooltip text="Начать чат" location="bottom">
      <template #activator="{ props }">
        <v-btn
          v-bind="props"
          icon
          size="x-small"
          variant="text"
          color="primary"
          @click.stop="dialogOpen = true"
        >
          <v-icon>mdi-chat-outline</v-icon>
        </v-btn>
      </template>
    </v-tooltip>

    <v-dialog v-model="dialogOpen" max-width="500" @click:outside="dialogOpen = false">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-chat-plus</v-icon>
          Новый тикет
        </v-card-title>
        <v-card-text>
          <v-text-field
            v-model="subject"
            label="Тема"
            density="compact"
            variant="outlined"
            class="mb-3"
            hide-details
          />
          <v-select
            v-model="category"
            :items="categoryOptions"
            label="Категория"
            density="compact"
            variant="outlined"
            class="mb-3"
            hide-details
          />
          <v-textarea
            v-model="message"
            label="Сообщение"
            rows="4"
            variant="outlined"
            hide-details
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialogOpen = false">Отмена</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="sending"
            :disabled="!message.trim()"
            @click="submit"
          >
            Отправить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import api from '../api';

const props = defineProps({
  consultantId: { type: Number, default: null },
  consultantName: { type: String, default: '' },
  contextType: { type: String, required: true },
  contextId: { type: [Number, String], required: true },
  contextLabel: { type: String, default: '' },
});

const emit = defineEmits(['created']);

const categoryMap = {
  clients: 'backoffice',
  contracts: 'backoffice',
  requisites: 'accounting',
  acceptance: 'legal',
  transactions: 'accruals',
  commissions: 'accruals',
};

const categoryOptions = [
  { title: 'Техподдержка', value: 'support' },
  { title: 'Бэк-офис', value: 'backoffice' },
  { title: 'Юридический', value: 'legal' },
  { title: 'Бухгалтерия', value: 'accounting' },
  { title: 'Начисления', value: 'accruals' },
];

const dialogOpen = ref(false);
const subject = ref('');
const category = ref('');
const message = ref('');
const sending = ref(false);

watch(dialogOpen, (val) => {
  if (val) {
    subject.value = `${props.contextType} — ${props.contextLabel}`;
    category.value = categoryMap[props.contextType] || 'support';
    message.value = '';
  }
});

async function submit() {
  if (!message.value.trim()) return;
  sending.value = true;
  try {
    const payload = {
      subject: subject.value,
      category: category.value,
      message: message.value,
      context_type: props.contextType,
      context_id: props.contextId,
      context_info: {
        consultantId: props.consultantId,
        consultantName: props.consultantName,
        label: props.contextLabel,
      },
    };
    const { data } = await api.post('/tickets', payload);
    dialogOpen.value = false;
    emit('created', data);
  } catch {}
  sending.value = false;
}
</script>
