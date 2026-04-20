/**
 * Admin UI core. Import named components from one place:
 *   import { DataTableWrapper, StatusChip, MoneyCell } from '@/components';
 *
 * Kept intentionally flat — every admin page should find what it needs here
 * without digging through sub-folders.
 */

// Layout
export { default as PageHeader } from './PageHeader.vue';
export { default as Breadcrumbs } from './Breadcrumbs.vue';

// Data display
export { default as DataTableWrapper } from './DataTableWrapper.vue';
export { default as EmptyState } from './EmptyState.vue';
export { default as BrandWaves } from './BrandWaves.vue';

// Filters / search
export { default as FilterBar } from './FilterBar.vue';
export { default as DateRangePicker } from './DateRangePicker.vue';
export { default as MonthPicker } from './MonthPicker.vue';

// Cells
export { default as StatusChip } from './StatusChip.vue';
export { default as ActionsCell } from './ActionsCell.vue';
export { default as BooleanCell } from './BooleanCell.vue';
export { default as PersonCell } from './PersonCell.vue';
export { default as MoneyCell } from './MoneyCell.vue';

// Dialogs / interactions
export { default as DialogShell } from './DialogShell.vue';
export { default as ConfirmDialog } from './ConfirmDialog.vue';
export { default as BulkActionBar } from './BulkActionBar.vue';

// Forms
export { default as FormErrors } from './FormErrors.vue';

// Specialty
export { default as ExportButton } from './ExportButton.vue';
export { default as RichTextEditor } from './RichTextEditor.vue';
export { default as StartChatButton } from './StartChatButton.vue';
