import { onUnmounted } from 'vue';

/**
 * Component-scoped AbortController. The signal is passed to axios
 * (or fetch) via the `signal` option; when the component unmounts
 * every in-flight request tied to it is cancelled.
 *
 * Usage:
 *   const { signal } = useAbortController();
 *   const { data } = await api.get('/foo', { signal });
 *
 * Cancelled errors are already swallowed by the axios interceptor,
 * so the `catch` branch in calling code won't see them — but you
 * should still avoid setting state after `await` if the component
 * is gone.
 */
export function useAbortController() {
  const controller = new AbortController();

  onUnmounted(() => {
    controller.abort();
  });

  return {
    signal: controller.signal,
    abort: () => controller.abort(),
  };
}
