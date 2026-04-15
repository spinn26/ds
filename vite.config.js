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
