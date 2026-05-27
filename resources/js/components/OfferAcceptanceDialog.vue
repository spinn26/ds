<template>
  <v-dialog :model-value="open" max-width="560" persistent>
    <v-card>
      <v-card-title class="d-flex align-center ga-2">
        <v-icon color="primary">mdi-file-sign</v-icon>
        <span>Акцепт документов</span>
      </v-card-title>
      <v-card-text>
        <p class="text-body-2 mb-3">
          Ваши реквизиты ИП верифицированы. Для начала работы примите условия
          Оферты и приложений к ней.
        </p>
        <v-checkbox v-model="accepted" density="compact" hide-details="auto">
          <template #label>
            <span class="text-body-2">
              Принимаю условия
              <a :href="OFFER_LINK" target="_blank" rel="noopener"
                class="text-primary text-decoration-underline" @click.stop>Оферты и всех приложений к ней</a>
              <span class="text-error">*</span>
            </span>
          </template>
        </v-checkbox>
        <p class="text-caption text-medium-emphasis mt-3">
          Нажимая кнопку «Продолжить» я подтверждаю заключение договора в
          электронной форме и соглашаюсь на использование простой электронной
          подписи.
        </p>
        <v-alert v-if="error" type="error" density="compact" class="mt-2">
          {{ error }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="pa-3">
        <v-spacer />
        <v-btn color="primary" :disabled="!accepted" :loading="submitting"
          @click="submit" prepend-icon="mdi-arrow-right">
          Продолжить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref } from 'vue';
import api from '../api';

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['accepted']);

// Ссылка на Оферту. При смене также обновить в backend (миграция
// 2026_05_27_000060_refresh_legal_document_links + PartnerAcceptanceService).
const OFFER_LINK = 'https://docs.google.com/document/d/13xayyrQ9xiQmjlj3mdWyEXS3eTFVFWBd/edit?usp=sharing';

const accepted = ref(false);
const submitting = ref(false);
const error = ref('');

async function submit() {
  error.value = '';
  submitting.value = true;
  try {
    await api.post('/profile/accept-offer');
    emit('accepted');
  } catch (e) {
    error.value = e.response?.data?.message || 'Не удалось зафиксировать акцепт. Попробуйте позже.';
  } finally {
    submitting.value = false;
  }
}
</script>
