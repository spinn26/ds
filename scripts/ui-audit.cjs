/**
 * Visual design audit — снимает скриншоты на разных viewport-ах
 * для оценки плотности/размеров и адаптивности.
 *
 * Output: storage/ui-audit/<viewport>/<page>.png
 */

const path = require('path');
const fs = require('fs');
const puppeteer = require('C:/Users/ENCODE/AppData/Local/Temp/smoke/node_modules/puppeteer');

const BASE = 'http://127.0.0.1:8000';
const EMAIL = 'as@webkrafter.ru';
const PASSWORD = 'audit12345';
const OUT_ROOT = path.join(__dirname, '..', 'storage', 'ui-audit');

const VIEWPORTS = [
  { w: 1366, h: 768, name: 'mac-air' },     // Mac Air baseline (per CLAUDE.md)
];

// Самые «нагруженные» страницы — фильтры, таблицы, диалоги.
const PAGES = [
  // Partner cabinet
  { path: '/dashboard', name: 'partner-dashboard' },
  { path: '/clients', name: 'partner-clients' },
  { path: '/contracts', name: 'partner-contracts' },
  { path: '/structure', name: 'partner-structure' },
  { path: '/products', name: 'partner-products' },
  { path: '/finance/report', name: 'partner-finance-report' },
  { path: '/profile', name: 'partner-profile' },

  // Admin
  { path: '/admin/dashboard', name: 'admin-dashboard' },
  { path: '/admin/partners', name: 'admin-partners' },
  { path: '/admin/clients', name: 'admin-clients' },
  { path: '/admin/contracts', name: 'admin-contracts' },
  { path: '/admin/transactions', name: 'admin-transactions' },
  { path: '/admin/commissions', name: 'admin-commissions' },
  { path: '/admin/qualifications', name: 'admin-qualifications' },
  { path: '/admin/charges', name: 'admin-charges' },
  { path: '/admin/payments', name: 'admin-payments' },
  { path: '/admin/reports', name: 'admin-reports' },
  { path: '/admin/acceptance', name: 'admin-acceptance' },
  { path: '/admin/transfers', name: 'admin-transfers' },
  { path: '/admin/products', name: 'admin-products' },
  { path: '/admin/currencies', name: 'admin-currencies' },
  { path: '/admin/education', name: 'admin-education' },
  { path: '/admin/users', name: 'admin-users' },
  { path: '/admin/mail', name: 'admin-mail' },
];

(async () => {
  console.log('Launching chromium...');
  const browser = await puppeteer.launch({
    headless: true,
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  for (const vp of VIEWPORTS) {
    const outDir = path.join(OUT_ROOT, vp.name);
    fs.mkdirSync(outDir, { recursive: true });
    console.log(`\n=== Viewport ${vp.name} (${vp.w}x${vp.h}) ===`);

    const page = await browser.newPage();
    await page.setViewport({ width: vp.w, height: vp.h });

    // Login
    await page.goto(BASE + '/login', { waitUntil: 'networkidle2', timeout: 30000 });
    await page.waitForSelector('input[type="email"], input[type="text"]', { timeout: 10000 });
    await page.type('input[type="email"], input[type="text"]', EMAIL, { delay: 20 });
    await page.type('input[type="password"]', PASSWORD, { delay: 20 });
    await Promise.all([
      page.click('button[type="submit"]'),
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(() => {}),
    ]);
    await new Promise((r) => setTimeout(r, 1500));
    if (page.url().includes('/login')) {
      console.error(`  ❌ login failed at ${vp.name}`);
      await page.screenshot({ path: path.join(outDir, '!LOGIN_FAILED.png'), fullPage: true });
      await page.close();
      continue;
    }

    for (const spec of PAGES) {
      try {
        await page.goto(BASE + spec.path, { waitUntil: 'networkidle2', timeout: 20000 });
      } catch (e) {
        console.log(`  ✗ goto ${spec.path}: ${e.message.slice(0,80)}`);
      }
      await new Promise((r) => setTimeout(r, 800));
      const ssPath = path.join(outDir, `${spec.name}.png`);
      try {
        await page.screenshot({ path: ssPath, fullPage: false });
        console.log(`  ✓ ${spec.name}`);
      } catch (e) {
        console.log(`  ✗ snap ${spec.name}: ${e.message.slice(0,80)}`);
      }
    }

    await page.close();
  }

  await browser.close();
  console.log('\nDone. Files:', OUT_ROOT);
})().catch((err) => { console.error('FATAL:', err); process.exit(1); });
