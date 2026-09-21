/**
 * Снимки стенда редизайна — обе темы и ключевые ширины.
 *
 * Сначала поднять стенд:
 *   npx vite --config .design/redesign/vite.config.mjs
 * Потом:
 *   node .design/redesign/shot.mjs [сценарий] [ширины] [темы]
 *
 * Сценарий — имя, а не адрес: Git Bash подменяет «/» на путь к своей папке.
 *   node .design/redesign/shot.mjs home  1440,1280,1024,768,390
 *   node .design/redesign/shot.mjs news  1440  light
 *
 * Браузер берём системный (Chrome): скачивать второй ради снимков незачем.
 */
import puppeteer from 'puppeteer';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, 'shots');
fs.mkdirSync(out, { recursive: true });

// Сценарии вместо адресов: см. комментарий выше.
const SCENARIOS = { home: '/', news: '/news/1' };
const scenario = process.argv[2] || 'home';
const route = SCENARIOS[scenario] || '/';
const widths = (process.argv[3] || '1440').split(',').map((w) => parseInt(w, 10));
const themes = (process.argv[4] || 'light,dark').split(',');

const CHROME = process.env.CHROME_PATH
  || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const STAND = 'http://localhost:5199/.design/redesign/index.html';

const browser = await puppeteer.launch({
  headless: true,
  executablePath: CHROME,
  args: ['--no-sandbox', '--font-render-hinting=none'],
});

const slug = scenario;
const problems = [];

for (const theme of themes) {
  for (const width of widths) {
    const page = await browser.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));

    await page.setViewport({ width, height: Math.max(900, Math.round(width * 0.72)) });
    const url = `${STAND}?theme=${theme}&route=${encodeURIComponent(route)}`;
    await page.goto(url, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 600));

    const file = path.join(out, `${slug}-${theme}-${width}.png`);
    await page.screenshot({ path: file, fullPage: true });
    console.log('снимок:', path.relative(process.cwd(), file));
    if (errors.length) {
      problems.push(`${theme}/${width}: ${errors.slice(0, 3).join(' | ')}`);
    }
    await page.close();
  }
}

await browser.close();

if (problems.length) {
  console.error('\nОшибки в консоли страницы:');
  problems.forEach((p) => console.error(' -', p));
  process.exit(1);
}
