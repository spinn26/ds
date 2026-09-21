/**
 * Проверка редактора новостей: открывает форму правки промо-новости,
 * переключает вкладки и снимает их. Ловит ошибки, которые видны только
 * при взаимодействии, а не на первом рендере.
 */
import puppeteer from 'puppeteer';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, 'shots');
fs.mkdirSync(out, { recursive: true });
const CHROME = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const browser = await puppeteer.launch({ headless: true, executablePath: CHROME, args: ['--no-sandbox'] });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 1000 });
const errors = [];
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
page.on('pageerror', (e) => errors.push(String(e)));

await page.goto('http://localhost:5199/.design/redesign/index.html?theme=light&route=%2Fmanage%2Fnews', { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 800));

// Кнопка «правка» первой строки — у неё иконка карандаша.
const opened = await page.evaluate(() => {
  const btn = [...document.querySelectorAll('button')].find((b) => b.querySelector('.mdi-pencil, .mdi-pencil-outline'));
  if (! btn) return false;
  btn.click();
  return true;
});
console.log('форма открыта:', opened);
await new Promise((r) => setTimeout(r, 700));
await page.screenshot({ path: path.join(out, 'editor-main.png') });

for (const label of ['Обложка и ссылка', 'Акция']) {
  const ok = await page.evaluate((text) => {
    const tab = [...document.querySelectorAll('.v-tab')].find((t) => t.textContent.trim() === text);
    if (! tab) return false;
    tab.click();
    return true;
  }, label);
  await new Promise((r) => setTimeout(r, 600));
  const file = path.join(out, `editor-${label === 'Акция' ? 'promo' : 'cover'}.png`);
  await page.screenshot({ path: file });
  console.log(`вкладка «${label}»:`, ok ? 'снята' : 'НЕ НАЙДЕНА');
}

console.log(errors.length ? 'ОШИБКИ:\n' + errors.slice(0, 5).join('\n') : 'ошибок в консоли нет');
await browser.close();
