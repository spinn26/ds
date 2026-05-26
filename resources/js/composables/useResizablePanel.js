import { ref, onBeforeUnmount } from 'vue';

/**
 * Resizable side-panel (drag-to-resize).
 *
 * Возвращает ширину панели (px) и handler `startResize(event)` для
 * `<div class="resize-handle" @mousedown="startResize">`. Ширина
 * клемпится в [min, max] и сохраняется в localStorage по storageKey,
 * так что у каждого пользователя свой расклад между визитами.
 *
 * Использование:
 *   const { width, startResize, isResizing } = useResizablePanel({
 *     storageKey: 'chat:sidebar-w', defaultWidth: 320, min: 240, max: 560,
 *   });
 *   <aside :style="{ width: width + 'px' }">…</aside>
 *   <div class="resize-handle" :class="{ active: isResizing }" @mousedown="startResize" />
 *
 * Touch-устройства: на mobile панель раздвигать смысла нет (там она
 * full-width), поэтому handler работает только для mouse-событий.
 */
export function useResizablePanel({ storageKey, defaultWidth, min = 200, max = 640 } = {}) {
  const stored = storageKey ? Number(localStorage.getItem(storageKey)) : 0;
  const width = ref(clamp(stored > 0 ? stored : defaultWidth, min, max));
  const isResizing = ref(false);

  let startX = 0;
  let startW = 0;

  function clamp(v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
  }

  function onMouseMove(e) {
    if (!isResizing.value) return;
    const dx = e.clientX - startX;
    width.value = clamp(startW + dx, min, max);
  }

  function onMouseUp() {
    if (!isResizing.value) return;
    isResizing.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
    if (storageKey) {
      try { localStorage.setItem(storageKey, String(width.value)); } catch (_) { /* quota — ignore */ }
    }
  }

  function startResize(e) {
    if (e.button !== 0) return;            // только ЛКМ
    isResizing.value = true;
    startX = e.clientX;
    startW = width.value;
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';
    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup', onMouseUp);
    e.preventDefault();
  }

  function reset() {
    width.value = defaultWidth;
    if (storageKey) {
      try { localStorage.removeItem(storageKey); } catch (_) { /* ignore */ }
    }
  }

  onBeforeUnmount(() => {
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
  });

  return { width, isResizing, startResize, reset };
}
