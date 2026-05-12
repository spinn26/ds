<template>
  <v-dialog v-model="open" :max-width="maxWidth" content-class="image-lightbox-dialog">
    <div class="image-lightbox">
      <!-- Картинка -->
      <img v-if="src && isImage" :src="src" :alt="alt" class="image-lightbox-img" />
      <!-- Прочие файлы (PDF и т.п.) — iframe, браузер сам решит как рендерить -->
      <iframe v-else-if="src" :src="src" class="image-lightbox-iframe" />

      <div class="image-lightbox-bar">
        <span class="image-lightbox-name" :title="alt">{{ alt }}</span>
        <div class="image-lightbox-actions">
          <v-btn icon="mdi-download" size="small" variant="elevated" color="white"
            :href="src" :download="alt || 'file'" target="_blank" rel="noopener" />
          <v-btn icon="mdi-close" size="small" variant="elevated" color="white"
            @click="open = false" />
        </div>
      </div>
    </div>
  </v-dialog>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  src: { type: String, default: '' },
  alt: { type: String, default: 'Файл' },
  maxWidth: { type: [String, Number], default: '92vw' },
});
const emit = defineEmits(['update:modelValue']);

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
});

const IMAGE_EXT = /\.(jpe?g|png|gif|webp|bmp|svg)(\?|$)/i;
const isImage = computed(() => IMAGE_EXT.test(props.alt || '') || IMAGE_EXT.test(props.src || ''));
</script>

<style scoped>
.image-lightbox {
  position: relative;
  background: #0a0a0a;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 240px;
  border-radius: 8px;
  overflow: hidden;
}
.image-lightbox-img {
  max-width: 100%;
  max-height: 86vh;
  object-fit: contain;
  display: block;
}
.image-lightbox-iframe {
  width: 92vw;
  max-width: 100%;
  height: 86vh;
  border: 0;
  background: white;
}
.image-lightbox-bar {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(8px);
}
.image-lightbox-name {
  color: white;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1 1 auto;
}
.image-lightbox-actions {
  display: flex;
  gap: 6px;
  flex: 0 0 auto;
}
</style>

<style>
.image-lightbox-dialog {
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
}
</style>
