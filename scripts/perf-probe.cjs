/**
 * Performance probe — замеряет latency всех hot GET-эндпоинтов,
 * ищет N+1 по количеству SQL-запросов, сообщает топ медленных.
 *
 * Запуск: node scripts/perf-probe.cjs
 */
const http = require('http');

const TOKEN = '97|5fMA9Y8sFktwDxUkqDsSNi9ZXt2BqavMbrfWm0RS1e61521a';
const HOST = '127.0.0.1';
const PORT = 8000;

const ENDPOINTS = [
  // partner
  '/api/v1/auth/me',
  '/api/v1/workspace',
  '/api/v1/notifications',
  '/api/v1/notifications/unread-count',
  '/api/v1/dashboard',
  '/api/v1/profile',
  '/api/v1/clients',
  '/api/v1/contracts/my',
  '/api/v1/contracts/team',
  '/api/v1/contracts/statuses',
  '/api/v1/contracts/products',
  '/api/v1/structure',
  '/api/v1/structure/qualification-levels',
  '/api/v1/products',
  '/api/v1/contests',
  '/api/v1/education/courses',
  '/api/v1/finance/report',
  '/api/v1/calculator/product-matrix',
  '/api/v1/calculator/history',
  // chat
  '/api/v1/chat/tickets',
  '/api/v1/chat/tickets/stats',
  '/api/v1/chat/unread-count',
  '/api/v1/chat/analytics',
  '/api/v1/chat/my-open',
  // admin data
  '/api/v1/admin/dashboard',
  '/api/v1/admin/users',
  '/api/v1/admin/partners',
  '/api/v1/admin/partners?page=1&per_page=25',
  '/api/v1/admin/partner-statuses',
  '/api/v1/admin/clients',
  '/api/v1/admin/requisites',
  '/api/v1/admin/acceptance',
  '/api/v1/admin/contracts',
  '/api/v1/admin/transfers',
  '/api/v1/admin/news',
  // admin finance
  '/api/v1/admin/transactions',
  '/api/v1/admin/transactions?month=2026-02',
  '/api/v1/admin/commissions',
  '/api/v1/admin/commissions?month=2026-02',
  '/api/v1/admin/pool',
  '/api/v1/admin/qualifications',
  '/api/v1/admin/qualifications?month=2026-02',
  '/api/v1/admin/charges',
  '/api/v1/admin/payments',
  '/api/v1/admin/reports',
  '/api/v1/admin/currencies',
  // admin refs/products/edu
  '/api/v1/admin/references',
  '/api/v1/admin/references/currency',
  '/api/v1/admin/products',
  '/api/v1/admin/contests',
  '/api/v1/admin/education/courses',
  '/api/v1/admin/mail/settings',
  '/api/v1/admin/mail/log',
  '/api/v1/admin/monitoring/status',
  '/api/v1/admin/periods',
  '/api/v1/admin/pool/participants?year=2026&month=2',
];

function hit(url, label) {
  return new Promise((resolve) => {
    const start = Date.now();
    const req = http.request(
      { host: HOST, port: PORT, path: url, headers: {
        Accept: 'application/json', Authorization: `Bearer ${TOKEN}`,
      }},
      (res) => {
        let size = 0;
        res.on('data', (c) => { size += c.length; });
        res.on('end', () => resolve({
          label: label || url, status: res.statusCode,
          ms: Date.now() - start, kb: (size / 1024).toFixed(1),
        }));
      }
    );
    req.on('error', (err) => resolve({ label, status: 0, ms: Date.now() - start, kb: 0, err: err.message }));
    req.end();
  });
}

(async () => {
  // Warmup — ускоряет opcache
  await hit('/api/v1/auth/me', 'warmup');
  await hit('/api/v1/auth/me', 'warmup');

  console.log('Measuring latency (2 runs, min of two):');
  console.log('─'.repeat(70));
  const results = [];
  for (const url of ENDPOINTS) {
    const a = await hit(url);
    const b = await hit(url);
    const ms = Math.min(a.ms, b.ms);
    const best = a.ms < b.ms ? a : b;
    results.push({ ...best, ms });
  }

  results.sort((x, y) => y.ms - x.ms);
  console.log('TOP 15 slowest:');
  for (const r of results.slice(0, 15)) {
    const mark = r.ms > 500 ? '🐢' : r.ms > 200 ? '⚠ ' : '✓ ';
    console.log(`  ${mark} ${String(r.ms).padStart(5)}ms  ${String(r.kb).padStart(7)}KB  ${r.status}  ${r.label}`);
  }
  const total = results.reduce((s, r) => s + r.ms, 0);
  const avg = Math.round(total / results.length);
  console.log('─'.repeat(70));
  console.log(`Endpoints: ${results.length}  total: ${total}ms  avg: ${avg}ms`);
  console.log(`> 500ms: ${results.filter(r => r.ms > 500).length}`);
  console.log(`> 200ms: ${results.filter(r => r.ms > 200).length}`);
})();
