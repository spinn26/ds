// Конвертер SVG → PNG 1024×1024 для @capacitor/assets.
// Sharp умеет растеризовать SVG напрямую через librsvg.
import sharp from 'sharp';
import { readFileSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');

async function convert(srcSvg, destPng, size = 1024, opts = {}) {
  const svg = readFileSync(join(root, srcSvg));
  const pipeline = sharp(svg, { density: 384 })
    .resize(size, size, { fit: 'contain', background: opts.background || { r: 0, g: 0, b: 0, alpha: 0 } });
  await pipeline.png().toFile(join(root, destPng));
  // eslint-disable-next-line no-console
  console.log(`  ${srcSvg} → ${destPng} (${size}×${size})`);
}

console.log('Generating PNGs from SVG sources…');

// Главная иконка (PNG с тёмным фоном) — Capacitor сделает из неё все размеры
await convert('resources/icon.svg', 'resources/icon.png', 1024);

// Только foreground (прозрачный фон) — для adaptive Android icons
await convert('resources/icon-foreground.svg', 'resources/icon-foreground.png', 1024);

// Splash 2732×2732 — Capacitor сожмёт под все ориентации/плотности
await convert('resources/icon.svg', 'resources/splash.png', 2732, {
  background: { r: 46, g: 125, b: 50, alpha: 1 },
});

// Тёмная версия splash — на dark theme устройствах
await convert('resources/icon.svg', 'resources/splash-dark.png', 2732, {
  background: { r: 10, g: 43, b: 16, alpha: 1 },
});

console.log('Done.');
