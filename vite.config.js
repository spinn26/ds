import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        vue(),
    ],
    build: {
        // ES2020 = Chrome 87 / Safari 14 / Firefox 78 — минимум для Vue 3 + Vuetify 3.
        // Явный target запрещает Vite 7 эмитировать ES2022-синтаксис
        // (private class fields #f, ||= /&&=, at()), которые ломают Safari 14.0
        // и Android WebView ≤ Chrome 86 — эти браузеры падают с SyntaxError
        // ещё до монтирования Vue, что выглядит как «белый экран».
        target: 'es2020',
        rollupOptions: {
            output: {
                manualChunks: {
                    'vuetify': ['vuetify', 'vuetify/components', 'vuetify/directives'],
                    'vendor': ['vue', 'vue-router', 'pinia', '@tanstack/vue-query', 'vue-i18n'],
                },
            },
        },
    },
});
