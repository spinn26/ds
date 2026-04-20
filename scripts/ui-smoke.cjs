/**
 * Headless UI walker — проходит по всем страницам SPA, собирает:
 *   - HTTP статусы навигаций
 *   - console errors / warnings
 *   - uncaught JS errors
 *   - failed network requests (4xx/5xx/abort)
 *   - скриншот каждой страницы
 *
 * Запуск (из c:/Users/ENCODE/Desktop/ds):
 *   node scripts/ui-smoke.js
 *
 * Путь к puppeteer — из /tmp/smoke/node_modules (мы его туда поставили).
 */

const path = require('path');
const puppeteer = require('C:/Users/ENCODE/AppData/Local/Temp/smoke/node_modules/puppeteer');

(async () => {
  const pptr = puppeteer;

  const BASE = 'http://127.0.0.1:8000';
  const EMAIL = 'kaprielov93@gmail.com';
  const PASSWORD = 'test1234';
  const OUT_DIR = path.join(__dirname, '..', 'storage', 'ui-smoke');
  require('fs').mkdirSync(OUT_DIR, { recursive: true });

  const PAGES = [
    // Partner cabinet
    { path: '/', name: 'workspace' },
    { path: '/dashboard', name: 'dashboard' },
    { path: '/clients', name: 'clients' },
    { path: '/contracts', name: 'my-contracts' },
    { path: '/contracts/team', name: 'team-contracts' },
    { path: '/structure', name: 'structure' },
    { path: '/products', name: 'products' },
    { path: '/contests', name: 'contests' },
    { path: '/education', name: 'education' },
    { path: '/finance/report', name: 'finance-report' },
    { path: '/finance/calculator', name: 'finance-calculator' },
    { path: '/chat', name: 'partner-chat' },
    { path: '/profile', name: 'profile' },
    { path: '/help', name: 'help' },

    // Manage (staff)
    { path: '/manage/contracts', name: 'manage-contracts' },
    { path: '/manage/partners', name: 'manage-partners' },
    { path: '/manage/partners/statuses', name: 'manage-partners-statuses' },
    { path: '/manage/clients', name: 'manage-clients' },
    { path: '/manage/requisites', name: 'manage-requisites' },
    { path: '/manage/acceptance', name: 'manage-acceptance' },
    { path: '/manage/transfers', name: 'manage-transfers' },
    { path: '/manage/transactions', name: 'manage-transactions' },
    { path: '/manage/transactions/import', name: 'manage-transactions-import' },
    { path: '/manage/commissions', name: 'manage-commissions' },
    { path: '/manage/pool', name: 'manage-pool' },
    { path: '/manage/qualifications', name: 'manage-qualifications' },
    { path: '/manage/charges', name: 'manage-charges' },
    { path: '/manage/payments', name: 'manage-payments' },
    { path: '/manage/reports', name: 'manage-reports' },
    { path: '/manage/currencies', name: 'manage-currencies' },
    { path: '/manage/products', name: 'manage-products' },
    { path: '/manage/contests', name: 'manage-contests' },
    { path: '/manage/chat', name: 'staff-chat' },
    { path: '/manage/chat/analytics', name: 'chat-analytics' },

    // Admin
    { path: '/admin', name: 'admin-home' },
    { path: '/admin/dashboard', name: 'admin-dashboard' },
    { path: '/admin/news', name: 'admin-news' },
    { path: '/admin/users', name: 'admin-users' },
    { path: '/admin/partners', name: 'admin-partners' },
    { path: '/admin/partners/statuses', name: 'admin-partner-statuses' },
    { path: '/admin/clients', name: 'admin-clients' },
    { path: '/admin/contracts', name: 'admin-contracts' },
    { path: '/admin/acceptance', name: 'admin-acceptance' },
    { path: '/admin/requisites', name: 'admin-requisites' },
    { path: '/admin/transfers', name: 'admin-transfers' },
    { path: '/admin/transactions', name: 'admin-transactions' },
    { path: '/admin/commissions', name: 'admin-commissions' },
    { path: '/admin/pool', name: 'admin-pool' },
    { path: '/admin/qualifications', name: 'admin-qualifications' },
    { path: '/admin/charges', name: 'admin-charges' },
    { path: '/admin/payments', name: 'admin-payments' },
    { path: '/admin/reports', name: 'admin-reports' },
    { path: '/admin/currencies', name: 'admin-currencies' },
    { path: '/admin/products', name: 'admin-products' },
    { path: '/admin/education', name: 'admin-education' },
    { path: '/admin/contests', name: 'admin-contests' },
    { path: '/admin/references', name: 'admin-references' },
    { path: '/admin/mail', name: 'admin-mail' },
    { path: '/admin/monitoring', name: 'admin-monitoring' },

    // Error pages
    { path: '/no-such-page', name: '404' },
    { path: '/forbidden', name: '403' },
  ];

  console.log('Launching chromium...');
  const browser = await pptr.launch({
    headless: true,
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  // Collectors per-navigation
  let logs = [], netFails = [];
  page.on('console', (msg) => {
    const t = msg.type();
    if (t === 'error' || t === 'warning') {
      logs.push({ type: t, text: msg.text().slice(0, 300) });
    }
  });
  page.on('pageerror', (err) => logs.push({ type: 'pageerror', text: err.message.slice(0, 300) }));
  page.on('requestfailed', (req) => {
    netFails.push({ url: req.url(), err: req.failure()?.errorText });
  });
  page.on('response', (res) => {
    if (res.status() >= 400 && res.url().includes('/api/v1/')) {
      netFails.push({ url: res.url().slice(BASE.length), status: res.status() });
    }
  });

  console.log('Logging in...');
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2', timeout: 30000 });
  await page.waitForSelector('input[type="email"], input[type="text"]', { timeout: 10000 });
  await page.type('input[type="email"], input[type="text"]', EMAIL, { delay: 30 });
  await page.type('input[type="password"]', PASSWORD, { delay: 30 });
  const loginErrorsBefore = logs.length;
  await Promise.all([
    page.click('button[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(() => {}),
  ]);
  await new Promise((r) => setTimeout(r, 1000));

  const loginUrl = page.url();
  if (loginUrl.includes('/login')) {
    console.error('❌ LOGIN FAILED — still on /login');
    await page.screenshot({ path: path.join(OUT_DIR, '!LOGIN_FAILED.png'), fullPage: true });
    await browser.close();
    process.exit(1);
  }
  console.log('Logged in, now at:', loginUrl);

  const report = [];
  for (const spec of PAGES) {
    logs = [];
    netFails = [];
    const start = Date.now();
    let navOk = true;
    try {
      await page.goto(BASE + spec.path, { waitUntil: 'networkidle2', timeout: 20000 });
    } catch (e) {
      navOk = false;
      logs.push({ type: 'navigation', text: e.message.slice(0, 200) });
    }
    await new Promise((r) => setTimeout(r, 800)); // settle XHR

    const title = await page.title().catch(() => '');
    const screenshotPath = path.join(OUT_DIR, `${spec.name}.png`);
    try {
      await page.screenshot({ path: screenshotPath, fullPage: false });
    } catch {}

    report.push({
      page: spec.path,
      name: spec.name,
      navOk,
      title,
      url: page.url().slice(BASE.length),
      durationMs: Date.now() - start,
      errors: logs.filter((l) => l.type === 'error' || l.type === 'pageerror'),
      warnings: logs.filter((l) => l.type === 'warning'),
      apiFails: netFails,
    });

    const errs = report[report.length - 1].errors.length;
    const fails = report[report.length - 1].apiFails.length;
    const mark = errs || fails ? '✗' : '✓';
    console.log(`  ${mark}  ${spec.path.padEnd(40)}  errs=${errs}  apiFails=${fails}`);
  }

  require('fs').writeFileSync(
    path.join(OUT_DIR, 'report.json'),
    JSON.stringify(report, null, 2)
  );

  // Summary at the end
  const totalErrs = report.reduce((s, r) => s + r.errors.length, 0);
  const totalFails = report.reduce((s, r) => s + r.apiFails.length, 0);
  const breakdown = report.filter((r) => r.errors.length || r.apiFails.length);
  console.log(`\n=== SUMMARY ===`);
  console.log(`Pages visited:        ${report.length}`);
  console.log(`Pages with issues:    ${breakdown.length}`);
  console.log(`Total JS errors:      ${totalErrs}`);
  console.log(`Total API fails:      ${totalFails}`);
  console.log(`Screenshots:          ${OUT_DIR}\\*.png`);
  console.log(`Full report:          ${OUT_DIR}\\report.json`);

  await browser.close();
})().catch((err) => {
  console.error('FATAL:', err);
  process.exit(1);
});
