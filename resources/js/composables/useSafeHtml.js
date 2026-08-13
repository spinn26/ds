/**
 * Санитайзер HTML для контента, введённого через RichTextEditor
 * (новости, объявления и т.п.).
 *
 * Зачем: редактор хранит innerHTML, поэтому вывод обязан быть v-html —
 * иначе партнёр видит сырые теги (<div><br></div>). Но v-html без чистки —
 * это XSS (см. SEC-4 в /admin/code-quality), поэтому прогоняем через
 * whitelist: разбираем строку в отдельный документ (DOMParser — скрипты
 * там не исполняются) и вырезаем всё, чего нет в списке.
 *
 * Дополнительно линкуем голые http(s)/www URL в текстовых узлах — авторы
 * часто вставляют ссылку простым текстом (paste в редакторе принудительно
 * plain-text), и кликабельной она не становится.
 *
 * Использование:
 *   <div class="rich-body" v-html="safeHtml(news.content)"></div>
 */

const ALLOWED_TAGS = new Set([
  'a', 'b', 'strong', 'i', 'em', 'u', 's', 'br', 'p', 'div', 'span',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'ul', 'ol', 'li', 'blockquote', 'hr', 'code', 'pre',
  'table', 'thead', 'tbody', 'tr', 'th', 'td',
]);

// style пропускаем только у блоков — execCommand('justify*') кладёт
// выравнивание именно туда; опасные значения отсекаем отдельно.
const ALLOWED_ATTRS = {
  a: ['href', 'title'],
  '*': ['style'],
};

const SAFE_STYLE_RE = /^(text-align|font-weight|font-style|text-decoration)\s*:\s*[\w-]+$/i;
const URL_RE = /\b((?:https?:\/\/|www\.)[^\s<>"']+)/gi;

function isSafeHref(value) {
  // Пробелы и управляющие символы внутри схемы — классический обход (java-перенос-script:).
  const v = String(value).split('').filter((c) => c.charCodeAt(0) > 32).join('').toLowerCase();
  return v.startsWith('http://') || v.startsWith('https://')
    || v.startsWith('mailto:') || v.startsWith('tel:') || v.startsWith('/');
}

function cleanStyle(value) {
  return String(value)
    .split(';')
    .map((d) => d.trim())
    .filter((d) => d && SAFE_STYLE_RE.test(d))
    .join('; ');
}

function linkifyTextNodes(root, doc) {
  const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  const targets = [];
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    if (n.parentElement?.closest('a')) continue; // уже внутри ссылки
    if (URL_RE.test(n.nodeValue)) targets.push(n);
    URL_RE.lastIndex = 0;
  }

  for (const node of targets) {
    const frag = doc.createDocumentFragment();
    let last = 0;
    const text = node.nodeValue;
    URL_RE.lastIndex = 0;
    for (let m = URL_RE.exec(text); m; m = URL_RE.exec(text)) {
      let url = m[0];
      let trail = '';
      // Trailing-пунктуация — почти всегда конец предложения, не часть URL.
      const trailMatch = url.match(/[.,!?;:)\]}]+$/);
      if (trailMatch) {
        trail = trailMatch[0];
        url = url.slice(0, -trail.length);
      }
      if (m.index > last) frag.appendChild(doc.createTextNode(text.slice(last, m.index)));
      const a = doc.createElement('a');
      a.setAttribute('href', url.startsWith('http') ? url : `https://${url}`);
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener noreferrer');
      a.textContent = url;
      frag.appendChild(a);
      if (trail) frag.appendChild(doc.createTextNode(trail));
      last = m.index + m[0].length;
    }
    if (last < text.length) frag.appendChild(doc.createTextNode(text.slice(last)));
    node.parentNode.replaceChild(frag, node);
  }
}

export function safeHtml(html) {
  if (html == null || html === '') return '';
  const doc = new DOMParser().parseFromString(`<div id="root">${html}</div>`, 'text/html');
  const root = doc.getElementById('root');
  if (!root) return '';

  // Снизу вверх: unwrap запрещённого тега не должен пропустить его детей.
  for (const el of Array.from(root.querySelectorAll('*')).reverse()) {
    const tag = el.tagName.toLowerCase();
    if (!ALLOWED_TAGS.has(tag)) {
      // script/style/iframe уносим вместе с содержимым, остальное — разворачиваем.
      if (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'].includes(tag)) {
        el.remove();
      } else {
        el.replaceWith(...el.childNodes);
      }
      continue;
    }

    const allowed = [...(ALLOWED_ATTRS[tag] || []), ...ALLOWED_ATTRS['*']];
    for (const attr of Array.from(el.attributes)) {
      const name = attr.name.toLowerCase();
      if (!allowed.includes(name)) { el.removeAttribute(attr.name); continue; }
      if (name === 'href' && !isSafeHref(attr.value)) { el.removeAttribute(attr.name); continue; }
      if (name === 'style') {
        const cleaned = cleanStyle(attr.value);
        if (cleaned) el.setAttribute('style', cleaned);
        else el.removeAttribute('style');
      }
    }

    if (tag === 'a' && el.hasAttribute('href')) {
      el.setAttribute('target', '_blank');
      el.setAttribute('rel', 'noopener noreferrer');
    }
  }

  linkifyTextNodes(root, doc);
  return root.innerHTML;
}

/** Тот же контент, но без разметки — для превью в таблицах. */
export function htmlToText(html) {
  if (html == null || html === '') return '';
  const doc = new DOMParser().parseFromString(String(html), 'text/html');
  return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
}

export function useSafeHtml() {
  return { safeHtml, htmlToText };
}
