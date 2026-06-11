// v2 — bump this string on every deploy to evict stale JS/CSS caches on iOS Safari.
// iOS Safari не убивает старый SW пока открыта хотя бы одна вкладка; новый CACHE_NAME
// гарантирует что activate-фаза удалит весь старый кэш при переходе на новую версию.
const CACHE_NAME = 'ds-platform-v2';

// Кэшируем только HTML-оболочку (для офлайн-fallback).
// JS/CSS-чанки имеют content-hash в имени — браузерный HTTP-кэш обрабатывает их сам.
// SW их не трогает: это исключает ситуацию «старый JS-файл из кэша SW +
// новый HTML из сети» = SyntaxError / белый экран.
const STATIC_ASSETS = ['/'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
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

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // API — не трогаем
  if (url.pathname.startsWith('/api/')) return;

  // Vite build-ассеты (content-hash имена) — только сеть, SW не кэширует.
  // Если сеть недоступна — браузер сам отдаёт из HTTP-кэша.
  if (url.pathname.startsWith('/build/')) return;

  // HTML-навигация: сеть → кэш-оболочка (офлайн fallback)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => caches.match('/'))
    );
    return;
  }

  // Остальное (manifest.json, иконки) — network first, кэш как fallback
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        if (response.ok && event.request.method === 'GET') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        }
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
