/**
 * Генерирует красивый PNG-баннер «Роадмап обновлён» через puppeteer.
 *   node scripts/roadmap-banner.cjs
 * Размер 1200×630 (OG / Telegram-превью / соцсети).
 */
const path = require('path');
const fs = require('fs');
const puppeteer = require('C:/Users/ENCODE/AppData/Local/Temp/smoke/node_modules/puppeteer');

const HTML = `<!doctype html><html><head><meta charset="utf-8">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:Inter,system-ui,sans-serif}
body{width:1200px;height:630px;background:#06090b;color:#e8f0ec;overflow:hidden;position:relative}

/* Декор: два плавающих гриновых пятна */
.blob1{position:absolute;width:700px;height:700px;border-radius:50%;
  background:radial-gradient(circle,#6EE87A 0%,transparent 70%);
  filter:blur(110px);opacity:.55;top:-200px;left:-180px}
.blob2{position:absolute;width:600px;height:600px;border-radius:50%;
  background:radial-gradient(circle,#2E7D32 0%,transparent 70%);
  filter:blur(110px);opacity:.5;bottom:-220px;right:-150px}

/* Сетка */
.grid{position:absolute;inset:0;
  background-image:
    linear-gradient(rgba(110,232,122,.05) 1px,transparent 1px),
    linear-gradient(90deg,rgba(110,232,122,.05) 1px,transparent 1px);
  background-size:60px 60px;
  mask-image:radial-gradient(ellipse at center,black 0%,transparent 75%);
  -webkit-mask-image:radial-gradient(ellipse at center,black 0%,transparent 75%);
  pointer-events:none}

.wrap{position:relative;z-index:2;padding:60px 70px;height:100%;
  display:flex;flex-direction:column;justify-content:space-between}

/* Верх */
.top{display:flex;justify-content:space-between;align-items:center}
.brand{display:flex;align-items:center;gap:14px}
.brand-mark{width:42px;height:42px;border-radius:12px;
  background:linear-gradient(135deg,#6EE87A 0%,#2E7D32 100%);
  display:flex;align-items:center;justify-content:center;
  font-weight:900;color:#0A2B10;font-size:18px;
  box-shadow:0 8px 24px rgba(110,232,122,.35)}
.brand-text{display:flex;flex-direction:column;line-height:1}
.brand-name{font-weight:800;font-size:16px;letter-spacing:.02em}
.brand-sub{font-size:11px;letter-spacing:.18em;color:rgba(232,240,236,.4);
  text-transform:uppercase;margin-top:4px}

.tag{display:inline-flex;align-items:center;gap:9px;
  padding:8px 16px;border:1px solid rgba(110,232,122,.35);
  background:rgba(110,232,122,.08);backdrop-filter:blur(10px);
  border-radius:999px;font-size:13px;color:#6EE87A;font-weight:500}
.tag .dot{width:8px;height:8px;border-radius:50%;
  background:#6EE87A;box-shadow:0 0 14px #6EE87A}

/* Центр */
.mid{flex:1;display:flex;flex-direction:column;justify-content:center;padding:30px 0}
h1{font-size:80px;font-weight:800;line-height:1;letter-spacing:-.035em;
  margin-bottom:22px;
  background:linear-gradient(110deg,#ffffff 0%,#6EE87A 65%,#4ec461 100%);
  -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.lead{font-size:22px;color:rgba(232,240,236,.7);max-width:780px;line-height:1.45}
.lead b{color:#fff;font-weight:600}

/* Низ: стат-плашки + URL */
.bottom{display:flex;justify-content:space-between;align-items:flex-end;gap:24px}
.stats{display:flex;gap:14px}
.stat{padding:18px 24px;background:rgba(255,255,255,.04);
  border:1px solid rgba(110,232,122,.18);border-radius:16px;
  backdrop-filter:blur(20px);min-width:140px}
.stat .n{font-size:36px;font-weight:800;line-height:1;margin-bottom:6px}
.stat .l{font-size:12px;letter-spacing:.12em;color:rgba(232,240,236,.45);
  text-transform:uppercase}
.s1 .n{color:#6EE87A}
.s2 .n{color:#FFD166}
.s3 .n{color:rgba(232,240,236,.85)}

.url-block{text-align:right}
.url-label{font-size:11px;letter-spacing:.18em;color:rgba(232,240,236,.4);
  text-transform:uppercase;margin-bottom:8px}
.url{font-size:24px;font-weight:700;color:#fff;
  display:inline-flex;align-items:center;gap:10px}
.url-arrow{width:32px;height:32px;border-radius:50%;
  background:#6EE87A;color:#0A2B10;display:flex;align-items:center;
  justify-content:center;font-size:20px;font-weight:900}
</style></head>
<body>
  <div class="blob1"></div><div class="blob2"></div><div class="grid"></div>
  <div class="wrap">

    <div class="top">
      <div class="brand">
        <div class="brand-mark">DS</div>
        <div class="brand-text">
          <span class="brand-name">DS Consulting</span>
          <span class="brand-sub">Партнёрская платформа</span>
        </div>
      </div>
      <div class="tag"><span class="dot"></span> Только что обновили</div>
    </div>

    <div class="mid">
      <h1>Роадмап обновлён</h1>
      <p class="lead">Посмотри <b>что мы уже выпустили</b>, <b>что в работе</b>
        и <b>куда идём дальше</b>. Учли все ваши пожелания и комментарии.</p>
    </div>

    <div class="bottom">
      <div class="stats">
        <div class="stat s1"><div class="n">15</div><div class="l">Выпущено</div></div>
        <div class="stat s2"><div class="n">4</div><div class="l">В работе</div></div>
        <div class="stat s3"><div class="n">31</div><div class="l">В планах</div></div>
      </div>
      <div class="url-block">
        <div class="url-label">Открыть</div>
        <div class="url">dev.dsconsult.ru/roadmap <span class="url-arrow">→</span></div>
      </div>
    </div>

  </div>
</body></html>`;

(async () => {
  const OUT = path.join(__dirname, '..', 'storage', 'roadmap-banner.png');
  fs.mkdirSync(path.dirname(OUT), { recursive: true });

  const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1200, height: 630, deviceScaleFactor: 2 });
  await page.setContent(HTML, { waitUntil: 'networkidle0' });
  // Дать шрифту дорисоваться.
  await new Promise(r => setTimeout(r, 800));
  await page.screenshot({ path: OUT, omitBackground: false });
  await browser.close();
  const stat = fs.statSync(OUT);
  console.log(`OK ${OUT} (${(stat.size / 1024).toFixed(1)} KB)`);
})().catch((e) => { console.error(e); process.exit(1); });
