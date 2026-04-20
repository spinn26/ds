import { ref, reactive, computed, watch } from 'vue';
import { useDebounce } from './useDebounce';
import { useSnackbar } from './useSnackbar';
import api from '../api';

/**
 * List + CRUD state for a REST-ish admin resource.
 *
 * Produces a single reactive bundle that most admin list pages need:
 *   - items / total / loading / page / perPage
 *   - filters (reactive; edits auto-reload after debounce)
 *   - load() / refresh() / create() / update() / save() / remove()
 *   - editDialog / editForm / editErrors — ready for a <DialogShell>
 *   - deleteDialog / deleteTarget — ready for a second <DialogShell>
 *
 * Assumptions (override if the endpoint differs):
 *   - GET    /{resource}?page=&perPage=&{...filters}          → { items, total } or { data, total }
 *   - POST   /{resource}        (create)
 *   - PUT    /{resource}/{id}   (update)
 *   - DELETE /{resource}/{id}   (delete)
 *
 * Usage:
 *   const crud = useCrud('admin/news', { defaults: { active: true } });
 *   onMounted(() => crud.load());
 *   // template:  <DialogShell v-model="crud.editDialog" :loading="crud.saving"
 *   //              @confirm="crud.save">  ...  </DialogShell>
 */
export function useCrud(resource, options = {}) {
  const {
    // Initial filter shape. Every key becomes a query-string param.
    filters: initialFilters = {},
    // Defaults used for "create" — spread into editForm when openCreate() is called.
    defaults = {},
    // Normalise the server response. Default expects { items, total } OR { data, total }.
    normalise = (data) => ({
      items: data.items ?? data.data ?? [],
      total: data.total ?? data.meta?.total ?? (data.items ?? data.data ?? []).length,
    }),
    // Transform the form payload before POST/PUT (strip UI-only keys etc.)
    beforeSave = (form) => form,
    // Override if filter changes should NOT reload (rare).
    autoReloadOnFilterChange = true,
    // Debounce for search-like filters.
    debounceMs = 350,
    // Show snackbars on success / error.
    notify = true,
    // Override labels in notifications.
    labels = { created: 'Создано', updated: 'Сохранено', deleted: 'Удалено', error: 'Ошибка' },
  } = options;

  const { showSuccess, showError } = useSnackbar();

  // --- list state ---
  const items = ref([]);
  const total = ref(0);
  const loading = ref(false);
  const page = ref(1);
  const perPage = ref(25);
  const sortBy = ref([]);
  const filters = reactive({ ...initialFilters });

  // --- form state ---
  const editDialog = ref(false);
  const editForm = ref({ ...defaults });
  const editErrors = ref({});
  const editMessage = ref('');
  const saving = ref(false);

  // --- delete state ---
  const deleteDialog = ref(false);
  const deleteTarget = ref(null);

  async function load() {
    loading.value = true;
    try {
      const params = {
        page: page.value,
        perPage: perPage.value,
        ...buildFilterParams(),
      };
      if (sortBy.value.length) {
        params.sortBy = sortBy.value[0]?.key;
        params.sortDir = sortBy.value[0]?.order;
      }
      const { data } = await api.get(`/${resource}`, { params });
      const r = normalise(data);
      items.value = r.items;
      total.value = r.total;
    } catch (e) {
      if (notify) showError(e.response?.data?.message || labels.error);
      throw e;
    } finally {
      loading.value = false;
    }
  }

  const { debounced: debouncedLoad } = useDebounce(load, debounceMs);

  if (autoReloadOnFilterChange) {
    watch(filters, () => { page.value = 1; debouncedLoad(); }, { deep: true });
  }

  // Keys with empty string / null are dropped from params.
  function buildFilterParams() {
    const out = {};
    for (const k of Object.keys(filters)) {
      const v = filters[k];
      if (v === '' || v === null || v === undefined) continue;
      out[k] = v;
    }
    return out;
  }

  function openCreate() {
    editForm.value = { ...defaults };
    editErrors.value = {};
    editMessage.value = '';
    editDialog.value = true;
  }

  function openEdit(item) {
    editForm.value = { ...item };
    editErrors.value = {};
    editMessage.value = '';
    editDialog.value = true;
  }

  async function save() {
    saving.value = true;
    editErrors.value = {};
    editMessage.value = '';
    try {
      const payload = beforeSave({ ...editForm.value });
      const id = payload.id ?? editForm.value.id;
      const { data } = id
        ? await api.put(`/${resource}/${id}`, payload)
        : await api.post(`/${resource}`, payload);
      if (notify) showSuccess(id ? labels.updated : labels.created);
      editDialog.value = false;
      await load();
      return data;
    } catch (e) {
      const r = e.response?.data;
      editErrors.value = r?.errors || {};
      editMessage.value = r?.message || labels.error;
      if (notify) showError(editMessage.value);
      throw e;
    } finally {
      saving.value = false;
    }
  }

  function confirmDelete(item) {
    deleteTarget.value = item;
    deleteDialog.value = true;
  }

  async function remove() {
    if (!deleteTarget.value?.id) return;
    saving.value = true;
    try {
      await api.delete(`/${resource}/${deleteTarget.value.id}`);
      if (notify) showSuccess(labels.deleted);
      deleteDialog.value = false;
      deleteTarget.value = null;
      await load();
    } catch (e) {
      if (notify) showError(e.response?.data?.message || labels.error);
      throw e;
    } finally {
      saving.value = false;
    }
  }

  // v-data-table-server hook
  function onOptions({ page: p, itemsPerPage, sortBy: s }) {
    if (p != null) page.value = p;
    if (itemsPerPage != null) perPage.value = itemsPerPage;
    if (s != null) sortBy.value = s;
    load();
  }

  // Derived: how many filters are non-empty.
  const activeFilterCount = computed(() =>
    Object.keys(filters).reduce((n, k) => {
      const v = filters[k];
      return n + (v !== '' && v != null ? 1 : 0);
    }, 0)
  );

  function resetFilters() {
    for (const k of Object.keys(filters)) filters[k] = initialFilters[k] ?? null;
    page.value = 1;
    load();
  }

  return {
    // list
    items, total, loading, page, perPage, sortBy, filters,
    activeFilterCount,
    load, refresh: load, debouncedLoad, onOptions, resetFilters,

    // create/update
    editDialog, editForm, editErrors, editMessage, saving,
    openCreate, openEdit, save,

    // delete
    deleteDialog, deleteTarget, confirmDelete, remove,
  };
}
