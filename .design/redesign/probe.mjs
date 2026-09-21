/**
 * Диагностика стенда: печатает консоль страницы, ошибки и размер разметки.
 * Запуск: node .design/redesign/probe.mjs [сценарий]
 */
import puppeteer from 'puppeteer';

const SCENARIOS = { home: '/', news: '/news/1' };
const route = SCENARIOS[process.argv[2] || 'home'] || '/';
const CHROME = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const browser = await puppeteer.launch({ headless: true, executablePath: CHROME, args: ['--no-sandbox'] });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 900 });
page.on('console', (m) => console.log(`[${m.type()}]`, m.text()));
page.on('pageerror', (e) => console.log('[pageerror]', String(e)));
page.on('requestfailed', (r) => console.log('[requestfailed]', r.url(), r.failure()?.errorText));

await page.goto(`http://localhost:5199/.design/redesign/index.html?theme=light&route=${encodeURIComponent(route)}`, { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 800));

const info = await page.evaluate(() => ({
  appHtml: document.querySelector('#app')?.innerHTML.length ?? -1,
  text: document.body.innerText.slice(0, 400),
  tags: [...document.querySelectorAll('#app *')].slice(0, 12).map((e) => e.tagName + (e.className ? '.' + String(e.className).split(' ')[0] : '')),
}));
console.log('длина разметки #app:', info.appHtml);
console.log('теги:', info.tags.join(', '));
console.log('текст:', JSON.stringify(info.text));

await browser.close();
