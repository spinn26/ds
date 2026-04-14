<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#43A047">
    <meta name="description" content="DS Consulting - Партнёрская платформа">

    <!-- PWA -->
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/icons/icon-512.png">

    <!-- iOS PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="DS Platform">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">

    <!-- MS Tile -->
    <meta name="msapplication-TileImage" content="/icons/icon-192.png">
    <meta name="msapplication-TileColor" content="#43A047">

    <title>DS Consulting Platform</title>
    @vite('resources/js/app.js')
</head>
<body>
    <div id="app"></div>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            });
        }
    </script>
</body>
</html>
