/**
 * Снимки статической вёрстки из ds-static — эталон, с которым сверяем продукт.
 * Запуск: node .design/redesign/shot-static.mjs [страница] [тема]
 */
import puppeteer from 'puppeteer';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, 'shots');
fs.mkdirSync(out, { recursive: true });

const SRC = process.env.DS_STATIC || 'C:/Users/kapri/OneDrive/Desktop/ds-static';
const page_ = process.argv[2] || 'partner';
const theme = process.argv[3] || 'light';
const CHROME = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const browser = await puppeteer.launch({ headless: true, executablePath: CHROME, args: ['--no-sandbox'] });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 1000 });
await page.goto(pathToFileURL(path.join(SRC, `${page_}.html`)).href, { waitUntil: 'networkidle0' });
await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
await new Promise((r) => setTimeout(r, 700));

const file = path.join(out, `static-${page_}-${theme}.png`);
await page.screenshot({ path: file, fullPage: true });
console.log('снимок:', path.relative(process.cwd(), file));

await browser.close();
