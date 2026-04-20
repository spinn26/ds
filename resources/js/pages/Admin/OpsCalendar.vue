<template>
  <div>
    <PageHeader title="Календарь операций" icon="mdi-calendar-check" />

    <v-row dense>
      <v-col cols="12" md="6">
        <v-card>
          <v-card-title class="pa-3">Задачи на {{ data.month }}</v-card-title>
          <v-list>
            <v-list-item v-for="t in data.tasks || []" :key="t.day">
              <template #prepend>
                <v-avatar :color="t.overdue ? 'error' : (t.daysLeft <= 3 ? 'warning' : 'primary')" size="36">
                  <span class="text-body-2 text-white">{{ t.day }}</span>
                </v-avatar>
              </template>
              <v-list-item-title>{{ t.title }}</v-list-item-title>
              <v-list-item-subtitle>{{ t.hint }}</v-list-item-subtitle>
              <template #append>
                <v-chip v-if="t.overdue" color="error" size="x-small">просрочено</v-chip>
                <v-chip v-else-if="t.daysLeft <= 3" color="warning" size="x-small">через {{ t.daysLeft }} дн</v-chip>
                <v-chip v-else size="x-small">через {{ t.daysLeft }} дн</v-chip>
              </template>
            </v-list-item>
          </v-list>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card>
          <v-card-title class="pa-3">SLA-нарушения</v-card-title>
          <v-list v-if="(data.slaBreaches || []).length">
            <v-list-item v-for="(s, i) in data.slaBreaches" :key="i"
              @click="s.to && $router.push(s.to)">
              <template #prepend>
                <v-icon :color="s.severity">mdi-alert-circle</v-icon>
              </template>
              <v-list-item-title>{{ s.label }}</v-list-item-title>
              <template #append>
                <v-chip :color="s.severity" size="small">{{ s.count }}</v-chip>
              </template>
            </v-list-item>
          </v-list>
          <v-card-text v-else class="text-medium-emphasis">SLA в норме, нарушений нет.</v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import { PageHeader } from '../../components';

const data = ref({});

async function load() {
  try { const { data: d } = await api.get('/admin/ops/calendar'); data.value = d; } catch {}
}
onMounted(load);
</script>
