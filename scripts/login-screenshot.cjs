/**
 * Скриншот страницы логина (staging vs prod) для визуального сравнения редизайна.
 *   node scripts/login-screenshot.cjs
 */
const path = require('path');
const fs = require('fs');
const puppeteer = require('puppeteer-core');
// Use system Chrome since puppeteer-core ships without it
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const TARGETS = [
  { url: 'https://dev.dsconsult.ru:8443/login', out: 'login-staging.png' },
  { url: 'https://dev.dsconsult.ru/login',      out: 'login-prod.png' },
];

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--ignore-certificate-errors'],
  });
  for (const t of TARGETS) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
    try {
      await page.goto(t.url, { waitUntil: 'networkidle0', timeout: 30000 });
      await new Promise(r => setTimeout(r, 1500));
      const outPath = path.join(__dirname, '..', 'storage', t.out);
      await page.screenshot({ path: outPath, fullPage: false });
      const stat = fs.statSync(outPath);
      console.log(`OK ${t.url} → ${outPath} (${(stat.size / 1024).toFixed(1)} KB)`);
    } catch (e) {
      console.error(`FAIL ${t.url}: ${e.message}`);
    } finally {
      await page.close();
    }
  }
  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
