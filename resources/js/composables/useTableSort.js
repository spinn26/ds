/**
 * Унифицированная обработка сортировки v-data-table-server.
 *
 * Vuetify шлёт sortBy массивом объектов `[{ key, order }]`. Раньше каждая
 * страница парсила его руками — половина страниц это просто игнорировала,
 * из-за чего клик по заголовку колонки ничего не делал. Этот хелпер
 * убирает дублирование и гарантирует, что серверный sort_by/sort_dir
 * всегда отправляется в API в одном формате (см. backend trait
 * Concerns/AppliesSorting).
 *
 * Использование:
 *   const { sortBy, sortDir, applyOptions, applyParams } = useTableSort('date', 'desc');
 *
 *   function onOptions(opts) {
 *     page.value = opts.page;
 *     if (opts.itemsPerPage) perPage.value = opts.itemsPerPage;
 *     applyOptions(opts);
 *     loadData();
 *   }
 *
 *   async function loadData() {
 *     const params = { page: page.value, per_page: perPage.value };
 *     applyParams(params);
 *     // ...
 *   }
 */
import { ref } from 'vue';

export function useTableSort(defaultBy = '', defaultDir = 'desc') {
  const sortBy = ref(defaultBy);
  const sortDir = ref(defaultDir);

  function applyOptions(opts) {
    if (Array.isArray(opts?.sortBy) && opts.sortBy.length) {
      sortBy.value = opts.sortBy[0].key;
      sortDir.value = opts.sortBy[0].order || defaultDir;
    } else {
      sortBy.value = defaultBy;
      sortDir.value = defaultDir;
    }
  }

  function applyParams(params) {
    if (sortBy.value) {
      params.sort_by = sortBy.value;
      params.sort_dir = sortDir.value;
    }
    return params;
  }

  return { sortBy, sortDir, applyOptions, applyParams };
}
