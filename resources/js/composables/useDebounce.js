import { ref, onUnmounted } from 'vue';

/**
 * Reusable debounce composable.
 * Usage: const { debounced } = useDebounce(loadData, 400);
 * Then: @input="debounced"
 */
export function useDebounce(fn, delay = 400) {
  let timer = null;

  function debounced(...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  }

  function cancel() {
    clearTimeout(timer);
  }

  onUnmounted(cancel);

  return { debounced, cancel };
}

/**
 * Debounced ref — value updates after delay.
 * Usage: const { value, debouncedValue } = useDebouncedRef('', 400);
 */
export function useDebouncedRef(initial = '', delay = 400) {
  const value = ref(initial);
  const debouncedValue = ref(initial);
  let timer = null;

  function update(newVal) {
    value.value = newVal;
    clearTimeout(timer);
    timer = setTimeout(() => { debouncedValue.value = newVal; }, delay);
  }

  return { value, debouncedValue, update };
}
