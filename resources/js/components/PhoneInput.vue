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
    <div v-if="!foreign" class="phone-input-prefix" :class="{ 'is-focused': focused }">
      <span class="flag">🇷🇺</span>
      <span class="dial">+7</span>
    </div>
    <v-text-field
      v-model="display"
      :label="label"
      :placeholder="foreign ? '+971 50 123 4567' : placeholder"
      :density="density"
      :variant="variant"
      :error-messages="errorMessages"
      :hide-details="hideDetails"
      :disabled="disabled"
      :readonly="readonly"
      class="phone-input-field"
      :class="{ 'no-prefix': foreign }"
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
// Иностранный номер (код страны ≠ 7) держим как есть: маска «+7 (XXX)…» ему
// не подходит. Раньше её применяли ко всему подряд — реальный «+971 50 832
// 2870» (ОАЭ) показывался как «🇷🇺 +7 (150) 832-28-70» с ошибкой «Неверный
// номер», а Сохранить записало бы в базу именно этот испорченный «+7150…».
const foreign = ref(false);

function looksForeign(raw) {
  const s = String(raw || '').trim();
  return s.startsWith('+') && s[1] !== '7';
}

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

// Иностранный номер: ни маски, ни обрезки — валидируем целиком.
function emitValidateForeign(e164) {
  emit('validate', {
    valid: e164.length > 3 ? isValidPhoneNumber(e164) : false,
    possible: e164.length > 1,
    country: null,
    countryCode: null,
    nationalNumber: e164.replace(/\D/g, ''),
    formatted: e164,
  });
}

// Приводит любое входящее значение к состоянию компонента.
function applyValue(raw) {
  if (looksForeign(raw)) {
    foreign.value = true;
    const e164 = '+' + String(raw).replace(/\D/g, '');
    display.value = String(raw).trim();
    emitValidateForeign(e164);
    return;
  }
  foreign.value = false;
  const ten = toTenDigits(raw);
  display.value = formatNational(ten);
  emitValidate(ten);
}

function onInput(e) {
  const raw = e?.target?.value ?? display.value;
  if (looksForeign(raw)) {
    foreign.value = true;
    const e164 = '+' + String(raw).replace(/\D/g, '');
    display.value = raw;               // не мешаем набирать
    emit('update:modelValue', e164.length > 1 ? e164 : '');
    emitValidateForeign(e164);
    return;
  }
  foreign.value = false;
  const ten = toTenDigits(raw);
  display.value = formatNational(ten);
  emit('update:modelValue', ten.length ? `+7${ten}` : '');
  emitValidate(ten);
}

// Внешние изменения modelValue (загрузка профиля, reset формы).
watch(() => props.modelValue, (val) => {
  // Сравниваем по цифрам: в foreign-режиме display хранит номер как набран,
  // и посимвольное сравнение с modelValue давало бы лишние перерисовки.
  const same = looksForeign(val) === foreign.value && (
    foreign.value
      ? String(val || '').replace(/\D/g, '') === display.value.replace(/\D/g, '')
      : toTenDigits(val) === toTenDigits(display.value)
  );
  if (! same) {
    applyValue(val);
  }
}, { immediate: false });

onMounted(() => {
  // Первый @validate — чтобы родитель сразу знал статус валидности.
  applyValue(props.modelValue);
});
</script>

<style scoped>
.phone-input-wrap {
  display: flex;
  /* flex-start, а не stretch: у v-text-field под полем зарезервирована
     строка сообщений (details). При stretch префикс тянулся на всю высоту
     вместе с ней и выглядел как отдельная высокая рамка. Выравниваем по
     верху и фиксируем высоту префикса под высоту самого поля. */
  align-items: flex-start;
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
  /* Высота поля при density=compact (~40px), синхронно с v-text-field. */
  height: 40px;
  flex: 0 0 auto;
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
/* Иностранный номер: префикса «🇷🇺 +7» нет — возвращаем полю свой левый угол. */
.phone-input-field.no-prefix :deep(.v-field),
.phone-input-field.no-prefix :deep(.v-field__outline__start) {
  border-top-left-radius: 4px;
  border-bottom-left-radius: 4px;
}
</style>
