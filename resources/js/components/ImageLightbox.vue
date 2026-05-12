<template>
  <v-dialog v-model="open" :max-width="maxWidth" content-class="image-lightbox-dialog">
    <div class="image-lightbox">
      <img v-if="src" :src="src" :alt="alt" class="image-lightbox-img" />
      <div class="image-lightbox-actions">
        <v-btn icon="mdi-download" size="small" variant="elevated" color="white"
          :href="src" :download="alt || 'image'" target="_blank" rel="noopener" />
        <v-btn icon="mdi-close" size="small" variant="elevated" color="white"
          @click="open = false" />
      </div>
    </div>
  </v-dialog>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  src: { type: String, default: '' },
  alt: { type: String, default: 'Изображение' },
  maxWidth: { type: [String, Number], default: '92vw' },
});
const emit = defineEmits(['update:modelValue']);
const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
});
</script>

<style scoped>
.image-lightbox {
  position: relative;
  background: #0a0a0a;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 240px;
  border-radius: 8px;
  overflow: hidden;
}
.image-lightbox-img {
  max-width: 100%;
  max-height: 88vh;
  object-fit: contain;
  display: block;
}
.image-lightbox-actions {
  position: absolute;
  top: 8px;
  right: 8px;
  display: flex;
  gap: 6px;
}
</style>

<style>
.image-lightbox-dialog {
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
}
</style>
