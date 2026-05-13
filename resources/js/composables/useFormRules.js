/**
 * Общие правила валидации форм клиента / партнёра / регистрации.
 *
 * ФИО, отчество, город — только кириллица (без транслита и латиницы) по
 * требованию заказчика «строго зафиксированный формат внесения данных»
 * (2026-05-13). Email — только латиница с @.
 *
 * Использование:
 *   import { cyrillicRequiredRules, emailRules } from '../../composables/useFormRules';
 *   <v-text-field :rules="cyrillicRequiredRules" ... />
 */
export const CYRILLIC_RE = /^[А-Яа-яЁё][А-Яа-яЁё\s\-]*$/;
export const EMAIL_RE = /^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/;
export const TELEGRAM_RE = /^@?[A-Za-z0-9_]{3,32}$/;

export const cyrillicRequiredRules = [
  v => !!v || 'Обязательное поле',
  v => CYRILLIC_RE.test(v || '') || 'Только русские буквы',
];

export const cyrillicOptionalRules = [
  v => !v || CYRILLIC_RE.test(v) || 'Только русские буквы',
];

export const emailRules = [
  v => !v || EMAIL_RE.test(v) || 'Неверный email (только латиница)',
];

export const emailRequiredRules = [
  v => !!v || 'Обязательное поле',
  v => EMAIL_RE.test(v || '') || 'Неверный email (только латиница)',
];

export const telegramRequiredRules = [
  v => !!v || 'Обязательное поле',
  v => TELEGRAM_RE.test(v || '') || 'Формат @username (латиница, цифры, _)',
];
