<template>
  <div>
    <PageHeader title="Инструкции" icon="mdi-help-circle-outline" />
    <v-card variant="flat" class="ds-empty-card">
      <EmptyState
        icon="mdi-tools"
        message="Раздел в разработке"
        hint="Скоро здесь появятся подробные инструкции и FAQ"
      />
    </v-card>
  </div>
</template>

<script setup>
import PageHeader from '../components/PageHeader.vue';
import EmptyState from '../components/EmptyState.vue';
</script>

<style scoped>
.ds-empty-card {
  border-radius: var(--ds-radius-xl, 16px);
  border: 1px solid var(--ds-outline-variant, rgba(0, 0, 0, 0.06));
  background: rgb(var(--v-theme-surface));
}
</style>
