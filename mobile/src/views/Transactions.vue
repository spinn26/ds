<template>
  <div>
    <PageHeader title="Транзакции">
      <template #actions>
        <v-btn icon="mdi-filter-variant" size="small" variant="text" />
      </template>
    </PageHeader>

    <div class="search-bar">
      <v-text-field v-model="search" placeholder="ФИО клиента, № контракта"
        density="compact" variant="outlined" hide-details rounded clearable
        prepend-inner-icon="mdi-magnify" />
    </div>

    <div class="chip-row">
      <v-chip v-for="p in periods" :key="p.value"
        :color="period === p.value ? 'primary' : undefined"
        :variant="period === p.value ? 'flat' : 'tonal'"
        size="small" label @click="period = p.value">
        {{ p.label }}
      </v-chip>
    </div>

    <div v-if="filtered.length" class="list">
      <div v-for="tx in filtered" :key="tx.id" class="list-card" @click="open(tx)">
        <div class="list-card-avatar">
          <v-icon color="primary" size="22">mdi-swap-horizontal</v-icon>
        </div>
        <div class="list-card-body">
          <div class="list-card-title">{{ tx.clientName }}</div>
          <div class="list-card-sub">{{ tx.contract }} · {{ tx.date }}</div>
        </div>
        <div class="list-card-aside">
          <div class="list-card-amount">{{ tx.amount }}</div>
          <div class="list-card-meta">{{ tx.points }} баллов</div>
        </div>
      </div>
    </div>

    <div v-else class="empty-state">
      <v-icon size="48">mdi-database-search-outline</v-icon>
      <div class="empty-state-text">Транзакций не найдено</div>
    </div>

    <div class="summary-pill" v-if="filtered.length">
      <span class="text-caption text-medium-emphasis">Итого за период</span>
      <span class="summary-amount">{{ totalAmount }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

const search = ref('');
const period = ref('month');
const periods = [
  { value: 'week', label: 'Неделя' },
  { value: 'month', label: 'Месяц' },
  { value: 'quarter', label: 'Квартал' },
  { value: 'year', label: 'Год' },
  { value: 'all', label: 'Всё время' },
];

const all = [
  { id: 1, clientName: 'Левченко Олег Валериевич', contract: 'T20W015368', date: '13.05.2026', amount: '100 000 $', points: '1 341' },
  { id: 2, clientName: 'Иванов Сергей Петрович', contract: 'T20W015412', date: '11.05.2026', amount: '50 000 $', points: '670' },
  { id: 3, clientName: 'Петров Андрей', contract: 'M21B009921', date: '08.05.2026', amount: '7 822 460 ₽', points: '78 224' },
  { id: 4, clientName: 'Кузнецова Мария', contract: 'T20W015101', date: '02.05.2026', amount: '25 000 $', points: '335' },
  { id: 5, clientName: 'Сидоров Виктор', contract: 'M21B009854', date: '28.04.2026', amount: '3 200 000 ₽', points: '32 000' },
];

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return all;
  return all.filter((t) =>
    t.clientName.toLowerCase().includes(q) || t.contract.toLowerCase().includes(q),
  );
});

const totalAmount = computed(() => '14 022 460 ₽');

function open(tx: { id: number }) {
  // eslint-disable-next-line no-console
  console.log('open tx', tx.id);
}
</script>

<style scoped>
.summary-pill {
  position: sticky;
  bottom: calc(70px + env(safe-area-inset-bottom));
  margin: 16px 0 0;
  padding: 12px 16px;
  background: rgb(var(--v-theme-primary));
  color: #fff;
  border-radius: 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0 4px 16px rgba(46, 125, 50, 0.25);
}
.summary-pill .text-caption { color: rgba(255, 255, 255, 0.8) !important; }
.summary-amount {
  font-size: 16px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
</style>
