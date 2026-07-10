// v3 — bump this string on every deploy to evict stale JS/CSS caches on iOS Safari.
// iOS Safari не убивает старый SW пока открыта хотя бы одна вкладка; новый CACHE_NAME
// гарантирует что activate-фаза удалит весь старый кэш при переходе на новую версию.
const CACHE_NAME = 'ds-platform-v3';

// Кэшируем только HTML-оболочку (для офлайн-fallback).
// JS/CSS-чанки имеют content-hash в имени — браузерный HTTP-кэш обрабатывает их сам.
// SW их не трогает: это исключает ситуацию «старый JS-файл из кэша SW +
// новый HTML из сети» = SyntaxError / белый экран.
const STATIC_ASSETS = ['/'];

self.addEventListener('install', (event) => {
  // catch(): если оболочка «/» временно отдала не-200 (редирект на логин,
  // 5xx), install НЕ должен падать — иначе новый SW не активируется и старый
  // (возможно сломанный) продолжает жить. Пустой кэш безопаснее мёртвого SW.
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  // Удаляем старые кэши. clients.claim() намеренно убран:
  // на iOS он перехватывает управление страницей mid-load и прерывает
  // уже идущие запросы ресурсов — это вызывает бесконечный спиннер.
  // Новый SW возьмёт управление на следующей навигации без claim().
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
});

// respondWith НИКОГДА не должен получить undefined: браузер трактует это как
// network error и роняет запрос с "Failed to convert value to 'Response'".
// Поэтому каждая ветка гарантированно резолвится в валидный Response.
const OFFLINE_RESPONSE = () =>
  new Response('', { status: 504, statusText: 'Gateway Timeout (offline)' });

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Не-GET (POST/PUT/PATCH/DELETE) вообще не трогаем — пусть идут в сеть напрямую.
  // Cache API не хранит не-GET, поэтому любой fallback тут = undefined = краш.
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // API — не трогаем
  if (url.pathname.startsWith('/api/')) return;

  // Vite build-ассеты (content-hash имена) — только сеть, SW не кэширует.
  // Если сеть недоступна — браузер сам отдаёт из HTTP-кэша.
  if (url.pathname.startsWith('/build/')) return;

  // HTML-навигация: сеть → кэш-оболочка → офлайн-заглушка (никогда undefined)
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .catch(() => caches.match('/'))
        .then((res) => res || OFFLINE_RESPONSE())
    );
    return;
  }

  // Остальное (manifest.json, иконки) — network first, кэш как fallback
  event.respondWith(
    fetch(request)
      .then((response) => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      })
      .catch(() => caches.match(request))
      .then((res) => res || OFFLINE_RESPONSE())
  );
});
