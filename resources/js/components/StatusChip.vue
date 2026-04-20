<template>
  <v-chip
    :color="resolvedColor"
    :size="size"
    :variant="variant"
    :label="label"
    :prepend-icon="icon"
  >
    <slot>{{ resolvedText }}</slot>
  </v-chip>
</template>

<script setup>
import { computed } from 'vue';
import {
  statusColors,
  statusLabels,
  priorityColors,
  priorityLabels,
  categoryLabels,
  activityLabels,
  getActivityColor,
  getActivityColorByName,
  getContractStatusColor,
  getContestStatusColor,
  getPaymentStatusColor,
  getImportStatusColor,
} from '@/composables/useDesign';

const props = defineProps({
  // Value to resolve. Can be a status code ('new', 'closed'),
  // a numeric id (1..5 for activity, 1..3 for contest), a label, etc.
  value: { type: [String, Number], default: null },

  // Category of resolver. Determines which color/label map is consulted.
  // 'status'  → statusColors + statusLabels (ticket/payment/contract labels)
  // 'priority'→ priorityColors + priorityLabels
  // 'activity'→ numeric id via getActivityColor + activityLabels
  // 'activityName' → russian label via getActivityColorByName
  // 'contract' → substring match via getContractStatusColor
  // 'contest' → numeric id via getContestStatusColor
  // 'payment' → numeric id via getPaymentStatusColor
  // 'import'  → import run states via getImportStatusColor
  // 'category'→ ticket category → statusColors + categoryLabels
  kind: {
    type: String,
    default: 'status',
    validator: (v) => ['status', 'priority', 'activity', 'activityName',
      'contract', 'contest', 'payment', 'import', 'category'].includes(v),
  },

  // Explicit color override — bypasses kind resolver.
  color: { type: String, default: null },
  // Explicit text override — bypasses kind resolver.
  text: { type: String, default: null },

  size: { type: String, default: 'small' },
  variant: { type: String, default: 'tonal' },
  label: { type: Boolean, default: false },
  icon: { type: String, default: null },
});

const resolvedColor = computed(() => {
  if (props.color) return props.color;
  const v = props.value;
  if (v === null || v === undefined || v === '') return 'grey';
  switch (props.kind) {
    case 'priority': return priorityColors[v] || 'grey';
    case 'activity': return getActivityColor(v);
    case 'activityName': return getActivityColorByName(v);
    case 'contract': return getContractStatusColor(v);
    case 'contest': return getContestStatusColor(v);
    case 'payment': return getPaymentStatusColor(v);
    case 'import': return getImportStatusColor(v);
    case 'category': return statusColors[v] || 'grey';
    case 'status':
    default: return statusColors[v] || 'grey';
  }
});

const resolvedText = computed(() => {
  if (props.text !== null) return props.text;
  const v = props.value;
  if (v === null || v === undefined || v === '') return '—';
  switch (props.kind) {
    case 'priority': return priorityLabels[v] || String(v);
    case 'activity': return activityLabels[v] || String(v);
    case 'category': return categoryLabels[v] || String(v);
    case 'status':
    default: return statusLabels[v] || String(v);
  }
});
</script>
