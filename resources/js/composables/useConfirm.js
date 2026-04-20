import { ref } from 'vue';

/**
 * Programmatic confirm dialog.
 *
 * Usage — mount <ConfirmDialog ref="confirmRef" /> once in the app layout
 * (or in any component), then use useConfirm() to access `.ask(...)` from
 * anywhere down the tree. Promise resolves to true/false.
 *
 *   // In layout:
 *   <ConfirmDialog ref="confirmRef" />
 *   import { provideConfirm } from '@/composables/useConfirm';
 *   const confirmRef = ref(null);
 *   provideConfirm(confirmRef);
 *
 *   // In any child:
 *   const confirm = useConfirm();
 *   if (await confirm.ask({ title: 'Удалить?', confirmColor: 'error' })) { ... }
 */

import { inject, provide } from 'vue';

const KEY = Symbol('confirm');

export function provideConfirm(refToConfirmDialog) {
  provide(KEY, refToConfirmDialog);
}

export function useConfirm() {
  const confirmRef = inject(KEY, null);
  return {
    async ask(opts) {
      if (!confirmRef?.value?.ask) {
        if (typeof window !== 'undefined') {
          return window.confirm(opts?.message || opts?.title || 'Вы уверены?');
        }
        return false;
      }
      return await confirmRef.value.ask(opts);
    },
  };
}

// Fallback standalone version — mounts its own dialog component lazily,
// useful for one-off uses outside a provided layout.
const standaloneRef = ref(null);
export function useConfirmStandalone() {
  return useConfirm();
}
