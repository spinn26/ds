<template>
  <div class="d-flex align-center ga-2">
    <v-avatar v-if="showAvatar" :size="avatarSize" :color="avatarColor">
      <v-img v-if="avatar" :src="avatar" :alt="name" />
      <span v-else class="text-caption font-weight-bold">{{ initials }}</span>
    </v-avatar>
    <div class="d-flex flex-column" style="min-width: 0">
      <span class="text-body-2 font-weight-medium text-truncate">{{ name || '—' }}</span>
      <span v-if="subtitle" class="text-caption text-medium-emphasis text-truncate">{{ subtitle }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getInitials } from '../composables/useDesign';

const props = defineProps({
  name: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  avatar: { type: String, default: null },
  showAvatar: { type: Boolean, default: true },
  avatarSize: { type: [String, Number], default: 32 },
  avatarColor: { type: String, default: 'primary' },
});

const initials = computed(() => getInitials(props.name));
</script>
