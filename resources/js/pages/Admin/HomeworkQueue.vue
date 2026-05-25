<template>
  <div class="pa-6">
    <PageHeader title="Домашние задания" icon="mdi-clipboard-edit-outline">
      <template #actions>
        <v-btn-toggle v-model="status" mandatory density="comfortable" color="primary" variant="outlined">
          <v-btn value="pending" size="small">На проверке</v-btn>
          <v-btn value="approved" size="small">Принятые</v-btn>
          <v-btn value="rejected" size="small">Отклонённые</v-btn>
          <v-btn value="all" size="small">Все</v-btn>
        </v-btn-toggle>
        <v-btn variant="text" prepend-icon="mdi-refresh" @click="load">Обновить</v-btn>
      </template>
    </PageHeader>

    <div v-if="loading" class="d-flex justify-center pa-6">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <EmptyState
      v-else-if="!items.length"
      icon="mdi-clipboard-check-outline"
      title="Нет ответов"
      description="В этой ленте пусто"
    />

    <v-row v-else dense>
      <v-col v-for="hw in items" :key="hw.id" cols="12" md="6">
        <v-card class="hw-card pa-4" elevation="0">
          <div class="d-flex align-center mb-2">
            <v-chip size="small" :color="statusColor(hw.status)" variant="tonal" class="me-2">
              {{ statusLabel(hw.status) }}
            </v-chip>
            <span class="text-caption text-medium-emphasis">
              {{ fmt(hw.createdAt) }}
            </span>
            <v-spacer />
            <span class="text-caption font-weight-medium">{{ hw.userName }}</span>
          </div>
          <div class="text-subtitle-2 font-weight-bold">{{ hw.courseTitle }} → {{ hw.lessonTitle }}</div>
          <div class="hw-answer mt-3">{{ hw.answerText || '—' }}</div>
          <div v-if="hw.attachments?.length" class="mt-2 d-flex flex-wrap ga-1">
            <v-chip
              v-for="(a, i) in hw.attachments"
              :key="i"
              size="x-small" variant="tonal" color="primary"
              :href="a.url" target="_blank"
              prepend-icon="mdi-paperclip"
            >
              {{ a.name || 'файл' }}
            </v-chip>
          </div>
          <div v-if="hw.reviewerComment" class="reviewer-comment mt-2">
            <v-icon size="14" class="me-1">mdi-comment-outline</v-icon>
            <span class="text-caption">{{ hw.reviewerComment }}</span>
          </div>
          <div v-if="hw.status === 'pending'" class="mt-3">
            <v-text-field
              v-model="commentDraft[hw.id]"
              label="Комментарий (опционально)"
              variant="outlined" density="compact" hide-details
            />
            <div class="d-flex ga-2 mt-2">
              <v-btn color="success" size="small" :loading="reviewing === hw.id"
                @click="review(hw, 'approved')">
                <v-icon start>mdi-check</v-icon>
                Принять
              </v-btn>
              <v-btn color="error" size="small" :loading="reviewing === hw.id"
                variant="tonal"
                @click="review(hw, 'rejected')">
                <v-icon start>mdi-close</v-icon>
                Отклонить
              </v-btn>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();
const status = ref('pending');
const items = ref([]);
const loading = ref(true);
const reviewing = ref(null);
const commentDraft = ref({});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/admin/kb/homework', { params: { status: status.value } });
    items.value = data.items || [];
  } catch (e) {
    showError(e.response?.data?.message || 'Ошибка загрузки');
  }
  loading.value = false;
}

async function review(hw, decision) {
  reviewing.value = hw.id;
  try {
    await api.post(`/admin/kb/homework/${hw.id}/review`, {
      status: decision,
      comment: commentDraft.value[hw.id] || null,
    });
    showSuccess(decision === 'approved' ? 'Принято' : 'Отклонено');
    await load();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  reviewing.value = null;
}

function statusColor(s) {
  return { pending: 'warning', approved: 'success', rejected: 'error' }[s] || 'grey';
}
function statusLabel(s) {
  return { pending: 'На проверке', approved: 'Принято', rejected: 'Отклонено' }[s] || s;
}
function fmt(d) {
  if (!d) return '—';
  try {
    const dt = new Date(d);
    return dt.toLocaleDateString('ru-RU') + ' '
      + dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  } catch { return d; }
}

watch(status, load);
onMounted(load);
</script>

<style scoped>
.hw-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 12px;
  height: 100%;
}
.hw-answer {
  font-size: 13.5px;
  line-height: 1.55;
  background: rgba(var(--v-theme-on-surface), 0.04);
  padding: 10px 12px;
  border-radius: 8px;
  white-space: pre-wrap;
  word-wrap: break-word;
  max-height: 200px;
  overflow-y: auto;
}
.reviewer-comment {
  padding: 8px 10px;
  background: rgba(46, 125, 50, 0.06);
  border-left: 3px solid rgb(var(--v-theme-primary));
  border-radius: 6px;
  color: rgba(var(--v-theme-on-surface), 0.75);
}
</style>
