import { ref } from 'vue';

const snackbar = ref(false);
const snackbarText = ref('');
const snackbarColor = ref('error');
const snackbarTimeout = ref(4000);

export function useSnackbar() {
  function showError(msg = 'Произошла ошибка') {
    snackbarText.value = msg;
    snackbarColor.value = 'error';
    snackbarTimeout.value = 5000;
    snackbar.value = true;
  }

  function showSuccess(msg = 'Готово') {
    snackbarText.value = msg;
    snackbarColor.value = 'success';
    snackbarTimeout.value = 3000;
    snackbar.value = true;
  }

  function showInfo(msg) {
    snackbarText.value = msg;
    snackbarColor.value = 'info';
    snackbarTimeout.value = 3000;
    snackbar.value = true;
  }

  /**
   * Wrap an async API call with error handling.
   * Usage: const data = await apiCall(() => api.get('/url'));
   */
  async function apiCall(fn, errorMsg) {
    try {
      const result = await fn();
      return result;
    } catch (e) {
      const msg = e.response?.data?.message || errorMsg || 'Ошибка запроса';
      showError(msg);
      throw e;
    }
  }

  return { snackbar, snackbarText, snackbarColor, snackbarTimeout, showError, showSuccess, showInfo, apiCall };
}
