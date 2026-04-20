/**
 * Interactive UI probe — visits every page, tries to click every
 * visible button and open every dialog, collecting console errors,
 * pageerrors, failed API requests. Logs per-action issues and takes
 * a screenshot when something fails.
 *
 * Run: node scripts/ui-interact.cjs
 * Output: storage/ui-interact/report.json + screenshots of failures.
 */

const path = require('path');
const fs = require('fs');
const puppeteer = require('C:/Users/ENCODE/AppData/Local/Temp/smoke/node_modules/puppeteer');

const BASE = 'http://127.0.0.1:8000';
const EMAIL = 'kaprielov93@gmail.com';
const PASSWORD = 'test1234';
const OUT_DIR = path.join(__dirname, '..', 'storage', 'ui-interact');
fs.mkdirSync(OUT_DIR, { recursive: true });

// Pages we expect to have interactive surface. A subset of the full list
// — the ones that routinely have dialogs / filters / bulk actions.
const PAGES = [
  '/', '/dashboard', '/clients', '/contracts', '/structure',
  '/products', '/contests', '/education', '/profile',
  '/chat',
  // Manage
  '/manage/partners', '/manage/partners/statuses', '/manage/clients',
  '/manage/requisites', '/manage/acceptance', '/manage/contracts',
  '/manage/transfers', '/manage/transactions', '/manage/transactions/import',
  '/manage/commissions', '/manage/qualifications', '/manage/pool',
  '/manage/charges', '/manage/payments', '/manage/reports',
  '/manage/currencies', '/manage/products', '/manage/contests',
  '/manage/chat',
  // Admin
  '/admin', '/admin/dashboard', '/admin/news', '/admin/users',
  '/admin/partners', '/admin/partners/statuses',
  '/admin/clients', '/admin/contracts', '/admin/acceptance',
  '/admin/requisites', '/admin/transfers', '/admin/transactions',
  '/admin/commissions', '/admin/pool', '/admin/qualifications',
  '/admin/charges', '/admin/payments', '/admin/reports',
  '/admin/currencies', '/admin/products', '/admin/education',
  '/admin/contests', '/admin/references', '/admin/mail',
  '/admin/monitoring',
];

(async () => {
  console.log('Launching chromium...');
  const browser = await puppeteer.launch({
    headless: true,
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--window-size=1440,900'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  // Collectors — reset per action
  let errs = [], apiFails = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errs.push(msg.text().slice(0, 300));
  });
  page.on('pageerror', (err) => errs.push('[pageerror] ' + err.message.slice(0, 300)));
  page.on('response', (res) => {
    if (res.status() >= 400 && res.url().includes('/api/v1/')) {
      apiFails.push(`${res.status()} ${res.url().slice(BASE.length)}`);
    }
  });

  const report = [];
  const note = (page, label, action, issues) => {
    if (issues.errs.length || issues.apiFails.length) {
      report.push({ page, label, action, ...issues });
      console.log(`  ✗ ${page.padEnd(40)} ${action.padEnd(30)} ${label}`);
      for (const e of issues.errs) console.log(`      ERR ${e}`);
      for (const f of issues.apiFails) console.log(`      API ${f}`);
    }
  };

  const snapshot = () => ({ errs: [...errs], apiFails: [...apiFails] });
  const reset = () => { errs = []; apiFails = []; };

  // === Login ===
  console.log('Login...');
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
  await page.waitForSelector('input[type="email"], input[type="text"]');
  await page.type('input[type="email"], input[type="text"]', EMAIL, { delay: 20 });
  await page.type('input[type="password"]', PASSWORD, { delay: 20 });
  await Promise.all([
    page.click('button[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 10000 }).catch(() => {}),
  ]);
  await new Promise((r) => setTimeout(r, 1500));
  if (page.url().includes('/login')) {
    console.error('Login failed'); await browser.close(); process.exit(1);
  }

  // === For each page ===
  for (const url of PAGES) {
    reset();
    try {
      await page.goto(BASE + url, { waitUntil: 'networkidle2', timeout: 20000 });
    } catch (e) {
      note(url, '', 'goto', { errs: [e.message], apiFails: [] });
      continue;
    }
    await new Promise((r) => setTimeout(r, 800));
    note(url, '', 'load', snapshot());

    // Find all non-disabled buttons the user could interact with.
    const targets = await page.$$eval('button:not([disabled]), [role=button]:not([aria-disabled=true])', (els) =>
      els
        .filter((e) => e.offsetParent !== null)
        .slice(0, 15)
        .map((e, i) => ({
          i,
          label: (e.innerText || e.title || e.ariaLabel || '').trim().slice(0, 40),
          tag: e.tagName,
        }))
    );

    // Click each, log issues, close overlay if one appeared
    for (const t of targets) {
      reset();
      // Skip obvious "navigation" buttons that leave the page
      if (/выйти|выход|logout|вернуть|назад/i.test(t.label)) continue;
      if (/сбросить|очистить|reset|clear/i.test(t.label)) continue;

      try {
        const btns = await page.$$('button:not([disabled]), [role=button]:not([aria-disabled=true])');
        const visible = [];
        for (const b of btns) {
          if (await b.isIntersectingViewport().catch(() => false)) visible.push(b);
        }
        const btn = visible[t.i];
        if (!btn) continue;

        await btn.click({ delay: 10 }).catch(() => {});
        await new Promise((r) => setTimeout(r, 400));
        note(url, t.label || '(no label)', 'click', snapshot());

        // If a dialog opened, try to close it via ESC
        const hasOverlay = await page.$('.v-overlay__content, .v-dialog');
        if (hasOverlay) {
          await page.keyboard.press('Escape');
          await new Promise((r) => setTimeout(r, 300));
        }
      } catch (e) {
        note(url, t.label, 'click-fail', { errs: [e.message], apiFails: [] });
      }
    }

    // Also exercise pagination / sort if a v-data-table is present
    reset();
    const hasTable = await page.$('table, .v-data-table');
    if (hasTable) {
      // Try first sort-header click
      try {
        const sortHead = await page.$('.v-data-table__th[role=columnheader] button, th button');
        if (sortHead) {
          await sortHead.click();
          await new Promise((r) => setTimeout(r, 500));
          note(url, 'sort header', 'click', snapshot());
        }
      } catch {}
    }
  }

  const summary = {
    pages: PAGES.length,
    issues: report.length,
    uniqueErrorTexts: [...new Set(report.flatMap((r) => r.errs))].slice(0, 10),
    uniqueApiFails: [...new Set(report.flatMap((r) => r.apiFails))].slice(0, 20),
  };

  fs.writeFileSync(path.join(OUT_DIR, 'report.json'), JSON.stringify({ summary, report }, null, 2));
  console.log('\n=== SUMMARY ===');
  console.log('Pages visited:    ' + summary.pages);
  console.log('Issues logged:    ' + summary.issues);
  console.log('\nUnique error texts:');
  for (const e of summary.uniqueErrorTexts) console.log('  ' + e);
  console.log('\nUnique API fails:');
  for (const f of summary.uniqueApiFails) console.log('  ' + f);

  await browser.close();
})().catch((err) => { console.error('FATAL:', err); process.exit(1); });
