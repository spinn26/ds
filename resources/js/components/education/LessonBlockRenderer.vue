<template>
  <div class="block-renderer">
    <component
      v-for="(b, i) in normalizedBlocks"
      :key="i"
      :is="renderBlock(b)"
    />
  </div>
</template>

<script setup>
import { computed, h } from 'vue';

const props = defineProps({
  blocks: { type: [Array, String, null], default: () => [] },
});

const normalizedBlocks = computed(() => {
  const v = props.blocks;
  if (!v) return [];
  if (Array.isArray(v)) return v;
  try {
    const p = JSON.parse(v);
    return Array.isArray(p) ? p : [];
  } catch { return []; }
});

function toEmbed(url) {
  if (!url) return null;
  try {
    const u = new URL(url);
    const host = u.hostname.replace(/^www\./, '');
    if (host === 'rutube.ru') {
      if (u.pathname.startsWith('/play/embed/')) return url;
      const m = u.pathname.match(/\/video\/(?:private\/)?([a-f0-9]+)/i);
      if (m) {
        const p = u.searchParams.get('p');
        return `https://rutube.ru/play/embed/${m[1]}` + (p ? `?p=${encodeURIComponent(p)}` : '');
      }
    }
    if (host === 'youtube.com' || host === 'm.youtube.com') {
      const v = u.searchParams.get('v');
      if (v) return `https://www.youtube.com/embed/${v}`;
      if (u.pathname.startsWith('/embed/')) return url;
    }
    if (host === 'youtu.be') {
      const id = u.pathname.slice(1).split('/')[0];
      if (id) return `https://www.youtube.com/embed/${id}`;
    }
    if (host === 'vimeo.com') {
      const id = u.pathname.slice(1).split('/')[0];
      if (/^\d+$/.test(id)) return `https://player.vimeo.com/video/${id}`;
    }
    if (host === 'vk.com' || host === 'vkvideo.ru') {
      const m = u.pathname.match(/\/video(-?\d+)_(\d+)/);
      if (m) return `https://vk.com/video_ext.php?oid=${m[1]}&id=${m[2]}&hd=2`;
    }
  } catch {}
  return null;
}

function fileIcon(url) {
  const u = (url || '').toLowerCase();
  if (u.endsWith('.pdf')) return 'mdi-file-pdf-box';
  if (u.match(/\.(docx?|odt)(\?|$)/)) return 'mdi-file-word-box';
  if (u.match(/\.(xlsx?|csv|ods)(\?|$)/)) return 'mdi-file-excel-box';
  if (u.match(/\.(pptx?|key)(\?|$)/)) return 'mdi-file-powerpoint-box';
  if (u.match(/\.(zip|rar|7z|tar|gz)(\?|$)/)) return 'mdi-folder-zip';
  if (u.match(/\.(png|jpg|jpeg|gif|webp|svg)(\?|$)/)) return 'mdi-image';
  return 'mdi-file-document-outline';
}

function isInternalLink(value) {
  if (!value) return false;
  // Внутренняя ссылка: либо чисто число (id урока), либо начинается с /education/
  return /^\d+$/.test(String(value).trim()) || String(value).startsWith('/');
}

function innerLinkHref(value) {
  const v = String(value).trim();
  if (/^\d+$/.test(v)) return `/education/lessons/${v}`;
  return v;
}

function renderBlock(b) {
  const type = b?.type;
  const value = b?.value || '';
  const label = b?.label || '';

  if (type === 'text') {
    return () => h('div', { class: 'block block-text' }, value);
  }

  if (type === 'video') {
    const embed = toEmbed(value);
    return () => h('div', { class: 'block block-video' }, [
      label ? h('div', { class: 'block-caption' }, label) : null,
      embed
        ? h('div', { class: 'video-frame' }, [
            h('iframe', {
              src: embed,
              frameborder: '0',
              allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
              allowfullscreen: true,
            }),
          ])
        : h('a', {
            href: value, target: '_blank', rel: 'noopener',
            class: 'block-link block-link-fallback',
          }, [
            h('span', { class: 'mdi mdi-play-circle' }),
            h('span', null, 'Открыть видео'),
          ]),
    ]);
  }

  if (type === 'audio') {
    return () => h('div', { class: 'block block-audio' }, [
      label ? h('div', { class: 'block-caption' }, label) : null,
      h('audio', { controls: true, src: value, class: 'audio-player' }),
    ]);
  }

  if (type === 'image') {
    return () => h('figure', { class: 'block block-image-wrap' }, [
      h('img', { src: value, class: 'block-image', alt: label }),
      label ? h('figcaption', { class: 'block-caption' }, label) : null,
    ]);
  }

  if (type === 'file') {
    return () => h('a', {
      href: value, target: '_blank', rel: 'noopener',
      class: 'block-file',
      download: true,
    }, [
      h('span', { class: `mdi ${fileIcon(value)} block-file-icon` }),
      h('div', { class: 'block-file-text' }, [
        h('div', { class: 'block-file-title' }, label || 'Скачать файл'),
        h('div', { class: 'block-file-sub' }, value.split('/').pop() || value),
      ]),
      h('span', { class: 'mdi mdi-download block-file-action' }),
    ]);
  }

  if (type === 'link') {
    return () => h('a', {
      href: value, target: '_blank', rel: 'noopener',
      class: 'block-link block-link-button',
    }, [
      h('span', { class: 'mdi mdi-link-variant' }),
      h('span', null, label || value),
    ]);
  }

  if (type === 'inner_link') {
    return () => h('a', {
      href: innerLinkHref(value),
      class: 'block-link block-link-inner',
    }, [
      h('span', { class: 'mdi mdi-bookmark-outline' }),
      h('span', null, label || `Урок #${value}`),
    ]);
  }

  // Fallback — неизвестный тип
  return () => h('div', {
    class: 'block block-unknown',
    style: 'opacity:0.5; font-style:italic;',
  }, `[Блок типа ${type} не поддерживается]`);
}
</script>

<style scoped>
.block-renderer {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.block { width: 100%; }
.block-text {
  font-size: 14.5px;
  line-height: 1.65;
  color: rgb(var(--v-theme-on-surface));
  white-space: pre-wrap;
  word-wrap: break-word;
}

.block-caption {
  font-size: 12.5px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.65);
  margin-bottom: 8px;
}

.video-frame {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: 10px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.video-frame :deep(iframe) {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.audio-player {
  width: 100%;
  height: 44px;
  border-radius: 8px;
}

.block-image-wrap {
  margin: 0;
}
.block-image {
  width: 100%;
  border-radius: 10px;
  display: block;
}
.block-image-wrap .block-caption {
  margin-top: 8px;
  font-style: italic;
  font-weight: 400;
}

.block-file {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  text-decoration: none;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.block-file:hover {
  background: rgba(46, 125, 50, 0.04);
  border-color: rgba(46, 125, 50, 0.3);
}
.block-file-icon {
  font-size: 28px;
  color: rgb(var(--v-theme-primary));
  flex-shrink: 0;
}
.block-file-text { flex: 1; min-width: 0; }
.block-file-title {
  font-weight: 600;
  font-size: 14px;
}
.block-file-sub {
  font-size: 11.5px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.block-file-action {
  font-size: 20px;
  color: rgba(var(--v-theme-on-surface), 0.45);
}

.block-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-radius: 10px;
  text-decoration: none;
  font-weight: 500;
  transition: all 0.15s ease;
}
.block-link-button {
  background: rgba(46, 125, 50, 0.08);
  color: rgb(var(--v-theme-primary));
  border: 1px solid rgba(46, 125, 50, 0.25);
}
.block-link-button:hover { background: rgba(46, 125, 50, 0.15); }

.block-link-inner {
  background: rgba(110, 232, 122, 0.08);
  color: rgb(var(--v-theme-primary));
  border: 1px dashed rgba(46, 125, 50, 0.35);
}
.block-link-inner:hover {
  background: rgba(110, 232, 122, 0.15);
  border-style: solid;
}

.block-link-fallback {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  color: rgb(var(--v-theme-primary));
}
</style>
