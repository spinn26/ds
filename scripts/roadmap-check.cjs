/**
 * Проверка публичного /roadmap на console-ошибки и failed requests.
 *   node scripts/roadmap-check.cjs [url]
 */
const puppeteer = require('C:/Users/ENCODE/AppData/Local/Temp/smoke/node_modules/puppeteer');

(async () => {
  const URL = process.argv[2] || 'https://dev.dsconsult.ru/roadmap';
  const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();

  const logs = [];
  page.on('console', (m) => logs.push(`[${m.type()}] ${m.text()}`));
  page.on('pageerror', (e) => logs.push(`[pageerror] ${e.message}`));
  page.on('requestfailed', (r) => logs.push(`[reqfail] ${r.url()} ${r.failure()?.errorText}`));
  page.on('response', async (r) => {
    if (r.status() >= 400) logs.push(`[http${r.status()}] ${r.url()}`);
  });

  await page.goto(URL, { waitUntil: 'networkidle0', timeout: 30000 });
  await new Promise((r) => setTimeout(r, 1500));
  console.log(logs.join('\n') || 'no errors');
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
