import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const realApi = path.resolve(root, 'resources/js/api.js');
const stubApi = path.resolve(here, 'stub-api.js');

/**
 * Стенд редизайна «Рабочего стола».
 *
 * Корень — корень проекта, чтобы относительные импорты внутри страниц
 * разрешались как обычно; подменяется только модуль api. Нужен, чтобы видеть
 * страницу глазами партнёра: платформа — SPA, по SSH виден пустой каркас, а
 * гонять правки вёрстки через прод нельзя.
 */
export default defineConfig({
  root,
  // Алиас «@» в проекте приходит от laravel-vite-plugin, которого здесь нет.
  resolve: { alias: { '@': path.resolve(root, 'resources/js') } },
  plugins: [
    vue(),
    {
      name: 'redesign-stub-api',
      enforce: 'pre',
      async resolveId(source, importer) {
        if (!importer || source.startsWith('\0')) return null;
        const resolved = await this.resolve(source, importer, { skipSelf: true });
        if (resolved && path.resolve(resolved.id.split('?')[0]) === realApi) return stubApi;
        return null;
      },
    },
  ],
  server: { port: 5199, strictPort: true },
});
