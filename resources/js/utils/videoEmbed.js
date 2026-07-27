/**
 * Единый резолвер видео-ссылок для всей платформы.
 *
 * Логика раньше жила копиями в CourseRunner.vue, LessonBlockRenderer.vue и
 * Instructions.vue — из-за этого новый хостинг приходилось добавлять в трёх
 * местах, и они разъезжались (в инструкциях не было ни Rutube, ни VK).
 *
 * Поддержка: Kinescope, Rutube, YouTube, Vimeo, VK Video + прямые файлы.
 */

/**
 * Разрешения для iframe с видео. `encrypted-media` обязателен — без него
 * не проигрываются защищённые (DRM) ролики Kinescope; остальное нужно для
 * автоплея, полного экрана и корректной работы на мобильных.
 */
export const VIDEO_IFRAME_ALLOW =
  'accelerometer; autoplay; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; screen-wake-lock';

/** Прямой видеофайл — его играет <video>, а не iframe. */
export function isFileVideo(url) {
  return /\.(mp4|webm|mov|m4v)(\?|#|$)/i.test(String(url || ''));
}

/**
 * Достаёт src из вставленного embed-кода.
 *
 * Хостинги (в том числе Kinescope) дают «код для вставки» целым блоком
 * <div><iframe src="..."></iframe></div>. Оператору естественно вставить его
 * целиком в поле «ссылка на видео», поэтому вытаскиваем адрес сами, вместо
 * того чтобы требовать вручную выковыривать URL.
 */
export function extractIframeSrc(input) {
  const s = String(input || '');
  if (!s.includes('<iframe')) return null;
  const m = s.match(/<iframe[^>]*\ssrc\s*=\s*["']([^"']+)["']/i);
  return m ? m[1].trim() : null;
}

/**
 * Приводит ссылку (или вставленный embed-код) к URL для iframe.
 * Возвращает null, если это не опознанный хостинг — вызывающий код
 * показывает прямую ссылку или плеер для файла.
 */
export function toEmbedUrl(input) {
  if (!input) return null;

  const raw = extractIframeSrc(input) || String(input).trim();
  if (isFileVideo(raw)) return null; // файл играет <video>

  try {
    const u = new URL(raw);
    const host = u.hostname.replace(/^www\./, '');

    // Kinescope. Форматы:
    //   https://kinescope.io/embed/<id>  — уже embed
    //   https://kinescope.io/<id>        — страница просмотра
    if (host === 'kinescope.io' || host.endsWith('.kinescope.io')) {
      if (u.pathname.startsWith('/embed/')) return raw;
      const id = u.pathname.replace(/^\//, '').split('/')[0];
      // Параметры (autoplay, seek, quality, muted, texttrack…) сохраняем —
      // иначе тайм-код или отключённый звук из ссылки потеряются.
      if (id) return `https://kinescope.io/embed/${id}${u.search}`;
    }

    // Rutube:
    //   https://rutube.ru/video/<hash>/
    //   https://rutube.ru/play/embed/<hash>
    //   https://rutube.ru/video/private/<hash>?p=<key>
    if (host === 'rutube.ru') {
      if (u.pathname.startsWith('/play/embed/')) return raw;
      // id может быть любым alphanumeric, не только hex: старый регекс
      // [a-f0-9]+ ловил длинные hash, но новые короткие id с буквами
      // проваливались в fallback «Открыть видео» (починено в уроках, а в
      // проигрывателе курса копия оставалась старой).
      const m = u.pathname.match(/\/video\/(?:private\/)?([a-zA-Z0-9]+)/);
      if (m) {
        const p = u.searchParams.get('p');
        return `https://rutube.ru/play/embed/${m[1]}` + (p ? `?p=${encodeURIComponent(p)}` : '');
      }
    }

    // YouTube
    if (host === 'youtube.com' || host === 'm.youtube.com') {
      const v = u.searchParams.get('v');
      if (v) return `https://www.youtube.com/embed/${v}`;
      if (/^\/embed\/[\w-]+/.test(u.pathname)) return raw;
    }
    if (host === 'youtu.be') {
      const id = u.pathname.replace(/^\//, '').split('/')[0];
      if (id) return `https://www.youtube.com/embed/${id}`;
    }

    // Vimeo
    if (host === 'vimeo.com') {
      const id = u.pathname.replace(/^\//, '').split('/')[0];
      if (/^\d+$/.test(id)) return `https://player.vimeo.com/video/${id}`;
    }
    if (host === 'player.vimeo.com') return raw;

    // VK Video: https://vk.com/video123_456, https://vkvideo.ru/video123_456
    if (host === 'vk.com' || host === 'vkvideo.ru') {
      const m = u.pathname.match(/\/video(-?\d+)_(\d+)/);
      if (m) return `https://vk.com/video_ext.php?oid=${m[1]}&id=${m[2]}&hd=2`;
    }
  } catch { /* не URL — вернём null ниже */ }

  return null;
}

/**
 * Нормализует то, что ввёл оператор, для СОХРАНЕНИЯ в базу: из embed-кода
 * оставляем чистый адрес, чтобы в поле не лежал HTML.
 */
export function normalizeVideoInput(input) {
  const src = extractIframeSrc(input);
  return src || String(input || '').trim();
}
