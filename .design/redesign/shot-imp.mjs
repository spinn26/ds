/**
 * Снимок главной в режиме «вход под пользователем» — чтобы видеть оранжевую
 * полосу над шапкой. Запуск: node .design/redesign/shot-imp.mjs [ширина] [тема]
 */
import puppeteer from 'puppeteer';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, 'shots');
fs.mkdirSync(out, { recursive: true });
const width = parseInt(process.argv[2] || '1920', 10);
const theme = process.argv[3] || 'light';
const CHROME = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const browser = await puppeteer.launch({ headless: true, executablePath: CHROME, args: ['--no-sandbox'] });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
await page.setViewport({ width, height: 1000 });
await page.goto(`http://localhost:5199/.design/redesign/index.html?theme=${theme}&impersonated=1&route=%2F`, { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 700));
const file = path.join(out, `imp-${theme}-${width}.png`);
await page.screenshot({ path: file, fullPage: true });
console.log('снимок:', path.relative(process.cwd(), file));
console.log(errors.length ? 'ОШИБКИ: ' + errors.slice(0, 3).join(' | ') : 'ошибок нет');
await browser.close();
