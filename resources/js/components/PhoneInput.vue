<template>
  <!-- Кастомный инпут российского телефона.
       Слева статичный префикс «🇷🇺 +7» (страна не выбирается — ~99%
       партнёров в РФ), в самом инпуте маска (XXX) XXX-XX-XX без
       дублирующих «+7»/«8». В v-model отдаём полный международный
       номер «+79991234567», в БД пишется именно он.

       Заменяет vue-tel-input в формах партнёра, потому что vue-tel-input
       не умеет одновременно держать «+7» в чипе и не показывать его
       (или «8») в поле — формат жёстко задан libphonenumber-js. -->
  <div class="phone-input-wrap">
    <div class="phone-input-prefix" :class="{ 'is-focused': focused }">
      <span class="flag">🇷🇺</span>
      <span class="dial">+7</span>
    </div>
    <v-text-field
      v-model="display"
      :label="label"
      :placeholder="placeholder"
      :density="density"
      :variant="variant"
      :error-messages="errorMessages"
      :hide-details="hideDetails"
      :disabled="disabled"
      :readonly="readonly"
      class="phone-input-field"
      type="tel"
      inputmode="tel"
      autocomplete="tel"
      @input="onInput"
      @focus="focused = true"
      @blur="focused = false; touched = true"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { isValidPhoneNumber } from 'libphonenumber-js';

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: '' },
  placeholder: { type: String, default: '(999) 123-45-67' },
  density: { type: String, default: 'compact' },
  variant: { type: String, default: 'outlined' },
  errorMessages: { type: [String, Array], default: () => [] },
  hideDetails: { type: [Boolean, String], default: false },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'validate']);

const display = ref('');
const focused = ref(false);
const touched = ref(false);

// «+79991234567» / «79991234567» / «89991234567» / «9991234567» → 10 цифр
function toTenDigits(raw) {
  const digits = String(raw || '').replace(/\D/g, '');
  if (digits.length === 11 && (digits.startsWith('7') || digits.startsWith('8'))) {
    return digits.slice(1);
  }
  return digits.slice(-10);
}

// 10 цифр → «(999) 123-45-67» (с прогрессивной маской при наборе).
function formatNational(d) {
  d = (d || '').slice(0, 10);
  if (!d) return '';
  let out = '(' + d.slice(0, 3);
  if (d.length >= 3) out += ')';
  if (d.length > 3) out += ' ' + d.slice(3, 6);
  if (d.length > 6) out += '-' + d.slice(6, 8);
  if (d.length > 8) out += '-' + d.slice(8, 10);
  return out;
}

function emitValidate(tenDigits) {
  const e164 = tenDigits.length === 10 ? `+7${tenDigits}` : '';
  const valid = e164 ? isValidPhoneNumber(e164, 'RU') : false;
  emit('validate', {
    valid,
    possible: tenDigits.length > 0 && tenDigits.length <= 10,
    country: 'RU',
    countryCode: 'RU',
    nationalNumber: tenDigits,
    formatted: e164,
  });
}

function onInput(e) {
  const ten = toTenDigits(e?.target?.value ?? display.value);
  display.value = formatNational(ten);
  emit('update:modelValue', ten.length ? `+7${ten}` : '');
  emitValidate(ten);
}

// Внешние изменения modelValue (загрузка профиля, reset формы).
watch(() => props.modelValue, (val) => {
  const ten = toTenDigits(val);
  const next = formatNational(ten);
  if (next !== display.value) {
    display.value = next;
    emitValidate(ten);
  }
}, { immediate: false });

onMounted(() => {
  const ten = toTenDigits(props.modelValue);
  display.value = formatNational(ten);
  // Первый @validate — чтобы родитель сразу знал статус валидности.
  emitValidate(ten);
});
</script>

<style scoped>
.phone-input-wrap {
  display: flex;
  align-items: stretch;
  gap: 0;
}
.phone-input-prefix {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.38);
  border-right: 0;
  border-radius: 4px 0 0 4px;
  font-size: 14px;
  background: transparent;
  user-select: none;
  /* Высота под density=compact (~40px), синхронно с v-text-field. */
  min-height: 40px;
  transition: border-color 120ms;
}
.phone-input-prefix.is-focused {
  border-color: rgb(var(--v-theme-primary));
}
.phone-input-prefix .flag {
  font-size: 16px;
  line-height: 1;
}
.phone-input-prefix .dial {
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
}
.phone-input-field {
  flex: 1 1 auto;
}
/* Срезаем левый радиус у v-text-field, чтобы префикс и инпут читались
   как одно поле; в density=compact высоты совпадают. */
.phone-input-field :deep(.v-field) {
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
}
.phone-input-field :deep(.v-field__outline__start) {
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
}
</style>
