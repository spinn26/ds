<template>
  <v-tooltip text="Написать в бэк-офис по контракту" location="left">
    <template #activator="{ props: tip }">
      <v-btn v-bind="tip" size="x-small" variant="text" color="primary"
        icon="mdi-headset" @click.stop="go" />
    </template>
  </v-tooltip>
</template>

<script setup>
// Кнопка на строке контракта (партнёрские страницы «Контракты моих
// клиентов» / «моей команды»): открывает партнёрский чат с пред-
// заполненной формой обращения в бэк-офис, привязанной к этому контракту.
// Партнёр дописывает свой вопрос и отправляет — на бэке первое сообщение
// автоматически обогащается деталями контракта (ChatController::
// buildContextSummary, context_type='Контракт').
import { useRouter } from 'vue-router';

const props = defineProps({
  contractId: { type: [Number, String], required: true },
  contractNumber: { type: [Number, String], default: '' },
});

const router = useRouter();

function go() {
  router.push({
    path: '/chat',
    query: {
      new: 'backoffice',
      ctx_type: 'Контракт',
      ctx_id: String(props.contractId),
      ctx_label: props.contractNumber ? `#${props.contractNumber}` : '',
    },
  });
}
</script>
