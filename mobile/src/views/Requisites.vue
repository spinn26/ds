<template>
  <div>
    <PageHeader title="Реквизиты" back />

    <v-card class="detail-card" elevation="0">
      <div class="text-overline text-medium-emphasis mb-2">Банковская карта</div>
      <div class="bank-card">
        <div class="bc-bank">Сбербанк</div>
        <div class="bc-num">•••• •••• •••• 4732</div>
        <div class="bc-row">
          <span>{{ holder }}</span>
          <span>09/28</span>
        </div>
      </div>
      <v-btn variant="text" color="primary" size="small" prepend-icon="mdi-pencil" class="mt-2">
        Изменить
      </v-btn>
    </v-card>

    <v-card class="detail-card mt-3" elevation="0">
      <div class="text-overline text-medium-emphasis mb-2">Паспортные данные</div>
      <div class="detail-row">
        <span class="detail-row-key">ФИО</span>
        <span class="detail-row-val">{{ passport.fio }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-row-key">Серия / номер</span>
        <span class="detail-row-val">{{ passport.series }} {{ passport.number }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-row-key">Выдан</span>
        <span class="detail-row-val">{{ passport.issued }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-row-key">Код подразделения</span>
        <span class="detail-row-val">{{ passport.code }}</span>
      </div>
    </v-card>

    <v-card class="detail-card mt-3" elevation="0">
      <div class="text-overline text-medium-emphasis mb-2">Налоговые</div>
      <div class="detail-row">
        <span class="detail-row-key">ИНН</span>
        <span class="detail-row-val">{{ tax.inn }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-row-key">Статус</span>
        <span class="detail-row-val">
          <v-chip size="x-small" color="success" variant="tonal">Самозанятый</v-chip>
        </span>
      </div>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';

const holder = 'KAPRIELOV E.';
const passport = {
  fio: 'Каприелов Эрик',
  series: '4517',
  number: '••••12',
  issued: 'УФМС по г. Москве, 12.04.2017',
  code: '770-021',
};
const tax = {
  inn: '7709••••12',
};
</script>

<style scoped>
.bank-card {
  background: linear-gradient(135deg, #0A2B10 0%, #2E7D32 100%);
  color: #fff;
  border-radius: 14px;
  padding: 16px;
  box-shadow: 0 4px 16px rgba(46, 125, 50, 0.15);
}
.bc-bank { font-size: 11px; opacity: 0.7; letter-spacing: 0.5px; text-transform: uppercase; }
.bc-num { font-size: 17px; letter-spacing: 2px; margin: 10px 0 14px; font-variant-numeric: tabular-nums; }
.bc-row { display: flex; justify-content: space-between; font-size: 11px; opacity: 0.85; }
</style>
