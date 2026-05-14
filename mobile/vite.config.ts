import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import vuetify from 'vite-plugin-vuetify';
import { fileURLToPath, URL } from 'node:url';

// Mobile-приложение использует порт 5174, чтобы не конфликтовать
// с веб-проектом на корневом 5173. Capacitor при синке копирует
// результат `npm run build` (dist/) в native-проекты.
export default defineConfig({
  plugins: [
    vue(),
    vuetify({ autoImport: true }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5174,
    host: true, // слушаем 0.0.0.0 — пригодится для live-reload на физическом девайсе
    strictPort: true,
  },
  preview: {
    port: 5174,
    strictPort: true,
  },
  build: {
    target: 'es2020',
    sourcemap: true,
  },
});
