/**
 * Превращает plain-text в безопасный HTML с кликабельными URL.
 *
 * Делаем в два шага:
 *   1) экранируем HTML, чтобы пользовательский текст никогда не мог стать
 *      разметкой (защита от XSS — особенно важно для чата);
 *   2) находим http(s)://... в экранированной строке и заворачиваем в <a>
 *      с target="_blank" + rel="noopener noreferrer".
 *
 * Перенос строк (\n) → <br> сохраняем — старая верстка использовала
 * white-space: pre-wrap на .msg-text, но после v-html этот эффект
 * пропадает; <br> компенсирует.
 *
 * Использование:
 *   <div class="msg-text" v-html="linkify(msg.content)"></div>
 */
export function linkify(text) {
  if (text == null) return '';
  const escaped = String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  // http(s) или www. — захватываем до пробела/кавычки/конца строки.
  // Trailing-пунктуация (., , ! ? ; : ) ] }) отрезаем — это почти всегда
  // признак предложения, а не часть URL.
  const urlRe = /\b((?:https?:\/\/|www\.)[^\s<>"]+)/gi;
  const withLinks = escaped.replace(urlRe, (match) => {
    let url = match;
    let trail = '';
    const trailMatch = url.match(/[.,!?;:)\]}]+$/);
    if (trailMatch) {
      trail = trailMatch[0];
      url = url.slice(0, -trail.length);
    }
    const href = url.startsWith('http') ? url : 'https://' + url;
    return `<a href="${href}" target="_blank" rel="noopener noreferrer">${url}</a>${trail}`;
  });

  return withLinks.replace(/\n/g, '<br>');
}
