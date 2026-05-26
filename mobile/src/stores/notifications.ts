import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '@/api';

// Единый источник правды для бейджа «непрочитанные уведомления»
// в шапке (партнёр и админ). Подгружается при логине, обновляется
// после markRead / markAllRead, плюс лёгкий polling каждые 60 сек.
export const useNotificationsStore = defineStore('notifications', () => {
  const unread = ref(0);
  let timer: ReturnType<typeof setInterval> | null = null;

  async function refresh() {
    try {
      const { data } = await api.get('/notifications/unread-count');
      const n = typeof data === 'number' ? data : (data?.count ?? data?.unread ?? 0);
      unread.value = Number(n) || 0;
    } catch {
      // 401 уже обработан в interceptor'е; для прочих ошибок не сбрасываем
      // счётчик, чтобы не «мигало» при флапах сети.
    }
  }

  async function markRead(id: number | string) {
    if (unread.value > 0) unread.value -= 1;
    try { await api.post(`/notifications/${id}/read`); }
    catch { refresh(); }
  }

  async function markAllRead() {
    unread.value = 0;
    try { await api.post('/notifications/read-all'); }
    catch { refresh(); }
  }

  function startPolling() {
    stopPolling();
    timer = setInterval(refresh, 60_000);
  }
  function stopPolling() {
    if (timer) { clearInterval(timer); timer = null; }
  }
  function reset() {
    stopPolling();
    unread.value = 0;
  }

  return { unread, refresh, markRead, markAllRead, startPolling, stopPolling, reset };
});
