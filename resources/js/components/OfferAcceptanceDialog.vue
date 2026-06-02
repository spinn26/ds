<template>
  <v-dialog :model-value="open" max-width="600" persistent scrollable>
    <v-card>
      <v-card-title class="d-flex align-center ga-2">
        <v-icon color="primary">mdi-file-sign</v-icon>
        <span>Акцепт документов</span>
      </v-card-title>
      <v-card-text style="max-height: 70vh">
        <p class="text-body-2 mb-3">
          Для начала работы на платформе ознакомьтесь и примите перечисленные
          документы. Нажимая «Принять», вы подтверждаете заключение договора в
          электронной форме и соглашаетесь на использование простой электронной
          подписи (ПЭП).
        </p>

        <v-list density="compact" class="mb-2 py-0">
          <v-list-item v-for="d in documents" :key="d.id" class="px-0">
            <template #prepend>
              <v-icon size="small" color="primary" class="me-2">mdi-file-document-outline</v-icon>
            </template>
            <v-list-item-title class="text-body-2" style="white-space: normal;">
              <a v-if="d.link" :href="d.link" target="_blank" rel="noopener"
                class="text-primary text-decoration-underline">{{ d.name }}</a>
              <span v-else>{{ d.name }}</span>
            </v-list-item-title>
          </v-list-item>
        </v-list>

        <v-checkbox v-model="accepted" density="compact" hide-details="auto"
          label="Я ознакомлен(а) и принимаю все перечисленные документы" />

        <v-alert v-if="error" type="error" density="compact" class="mt-2">
          {{ error }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="pa-3">
        <v-spacer />
        <v-btn color="primary" :disabled="!accepted || !documents.length" :loading="submitting"
          @click="submit" prepend-icon="mdi-check">
          Принять
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import api from '../api';

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['accepted']);

const documents = ref([]);
const accepted = ref(false);
const submitting = ref(false);
const error = ref('');

async function loadDocuments() {
  try {
    const { data } = await api.get('/profile/agreement-documents');
    documents.value = Array.isArray(data) ? data : [];
  } catch {
    documents.value = [];
  }
}

// Грузим список документов, когда окно открывается.
watch(() => props.open, (isOpen) => {
  if (isOpen && !documents.value.length) loadDocuments();
}, { immediate: true });

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
