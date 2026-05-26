/**
 * Скриншот публичной страницы роадмапа.
 * Снимает full-page PNG в storage/roadmap-preview.png.
 *   node scripts/roadmap-screenshot.cjs
 */
const path = require('path');
const fs = require('fs');
const puppeteer = require('C:/Users/ENCODE/AppData/Local/Temp/smoke/node_modules/puppeteer');

(async () => {
  const URL = process.argv[2] || 'https://dev.dsconsult.ru/roadmap';
  const OUT = path.join(__dirname, '..', 'storage', 'roadmap-preview.png');
  fs.mkdirSync(path.dirname(OUT), { recursive: true });

  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 2 });
  await page.goto(URL, { waitUntil: 'networkidle0', timeout: 30000 });
  // IntersectionObserver на странице делает fade-in только для карточек,
  // попавших в viewport. Прокатываем всю страницу до низа и обратно
  // вверх, чтобы все .entry перешли в .visible.
  await page.evaluate(async () => {
    const total = document.documentElement.scrollHeight;
    for (let y = 0; y < total; y += 400) {
      window.scrollTo(0, y);
      await new Promise(r => setTimeout(r, 80));
    }
    window.scrollTo(0, 0);
    await new Promise(r => setTimeout(r, 600));
  });
  await page.screenshot({ path: OUT, fullPage: true });
  await browser.close();

  const stat = fs.statSync(OUT);
  console.log(`OK ${OUT} (${(stat.size / 1024).toFixed(1)} KB)`);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
