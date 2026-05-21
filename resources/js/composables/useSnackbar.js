import { ref } from 'vue';

const snackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('error');
const snackbarTimeout = ref(4000);
// Опциональный action: { label: string, to: RouteLocationRaw | string }
// Показывается как кнопка справа от текста; клик переходит и закрывает snackbar.
const snackbarAction = ref(null);

export function useSnackbar() {
  function show(msg, color, timeout, action = null) {
    snackbarText.value = msg;
    snackbarColor.value = color;
    snackbarTimeout.value = timeout;
    snackbarAction.value = action;
    snackbar.value = true;
  }

  function showError(msg = 'Произошла ошибка') {
    show(msg, 'error', 5000);
  }

  function showSuccess(msg = 'Готово', action = null) {
    show(msg, 'success', action ? 7000 : 3000, action);
  }

  function showInfo(msg, action = null) {
    show(msg, 'info', action ? 7000 : 3000, action);
  }

  /**
   * Для real-time-уведомлений (новое сообщение в чате и т.п.): высокий
   * приоритет, заметный цвет, есть кнопка «Открыть», но автодиссмис.
   * Чуть дольше — 8 секунд — чтобы успел заметить даже если отвернулся.
   */
  function showNotification(msg, action = null) {
    show(msg, 'primary', 8000, action);
  }

  /**
   * Wrap an async API call with error handling.
   */
  async function apiCall(fn, errorMsg) {
    try {
      return await fn();
    } catch (e) {
      const msg = e.response?.data?.message || errorMsg || 'Ошибка запроса';
      showError(msg);
      throw e;
    }
  }

  return {
    snackbar, snackbarText, snackbarColor, snackbarTimeout, snackbarAction,
    showError, showSuccess, showInfo, showNotification, apiCall,
  };
}
