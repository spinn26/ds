import ru from './ru';

// Current language (can be extended to support multiple languages)
const translations = ru;

/**
 * Get translation by dot-notation key: t('auth.login') → 'Вход в систему'
 */
export function t(key: string): string {
  const keys = key.split('.');
  let result: any = translations;
  for (const k of keys) {
    result = result?.[k];
    if (result === undefined) return key;
  }
  return typeof result === 'string' ? result : key;
}

export default translations;
