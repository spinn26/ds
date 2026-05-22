// Парсер markdown-таблицы из Google Drive read_file_content.
// Выводит статистику: уникальные продукты, программы, поставщики, формулы.
const fs = require('fs');
const file = process.argv[2];
const txt = fs.readFileSync(file, 'utf8');
const obj = JSON.parse(txt);
const lines = obj.fileContent.split('\n');

function stripCells(line) {
  // markdown row: | a | b | c |
  if (!line.startsWith('|')) return null;
  if (line.match(/^\|\s*:-+:\s*\|/)) return null; // separator row
  const parts = line.split('|');
  // first and last are empty
  return parts.slice(1, -1).map(s => s.trim()
    .replace(/\\\*/g, '*')
    .replace(/\\#/g, '#')
    .replace(/\\_/g, '_')
    .replace(/&#10;/g, ' '));
}

const rows = [];
for (const l of lines) {
  const cells = stripCells(l);
  if (cells) rows.push(cells);
}

console.log('Parsed table rows:', rows.length);
console.log('First row (header):', rows[0]);
console.log('Cell counts distribution:');
const cc = {};
rows.forEach(r => { cc[r.length] = (cc[r.length] || 0) + 1; });
console.log(cc);

// Identify "data rows" — те, где есть и продукт, и программа (минимум).
// Header columns (по первой строке):
// 0: коммент, 1: ТИП, 2: ПРОДУКТ, 3: ПРОГРАММА, 4: Стоимость, 5: ВАЛЮТА,
// 6: ПОСТАВЩИК, 7: % DS, 8: Свойство, 9: Срок, 10: Год КВ, 11: Баллы,
// 12: МЕТОДИКА, 13: Комментарии, 14: Категория
const HCOLS = ['note','type','product','program','fixedCost','currency','provider','dsPercent','property','term','yearKV','points','formula','comment','category'];
const data = rows
  .filter(r => r.length >= 7 && r[2] && r[3]) // есть product и program
  .map(r => {
    const o = {};
    HCOLS.forEach((k, i) => o[k] = r[i] || '');
    return o;
  })
  .filter(r => r.product !== 'ПРОДУКТ' && r.product !== 'ТИП');

console.log('\nData rows (product+program filled):', data.length);

// Уникальные продукты
const products = new Set(data.map(d => d.product));
console.log('Unique products:', products.size);
console.log([...products].sort().join('\n  ').slice(0, 4000));

// Уникальные (product, program)
const productPrograms = new Set(data.map(d => `${d.product} || ${d.program}`));
console.log('\nUnique (product, program):', productPrograms.size);

// Уникальные (product, program, term, yearKV) — финальный key
const finalKey = new Set(data.map(d => `${d.product} || ${d.program} || ${d.term} || ${d.yearKV}`));
console.log('Unique (product, program, term, yearKV):', finalKey.size);

// Уникальные поставщики
const providers = new Set(data.map(d => d.provider).filter(Boolean));
console.log('\nUnique providers:', [...providers].sort().join(', '));

// Уникальные валюты
const currencies = new Set(data.map(d => d.currency).filter(Boolean));
console.log('Unique currencies:', [...currencies].sort().join(', '));

// Уникальные категории (15-я колонка)
const cats = new Set(data.map(d => d.category).filter(Boolean));
console.log('Unique categories:', [...cats].sort().join(', '));

// Шаблоны формул — выделяем
const formulaShapes = {};
for (const d of data) {
  if (!d.formula) continue;
  // Normalize: replace numeric multiplier with X
  const norm = d.formula
    .replace(/[\d]+[,.][\d]+/g, 'N')   // 0,775 → N
    .replace(/[\d]+/g, 'N');             // any remaining integers
  formulaShapes[norm] = (formulaShapes[norm] || 0) + 1;
}
console.log('\nFormula shapes (top 15):');
Object.entries(formulaShapes).sort((a,b) => b[1]-a[1]).slice(0, 15).forEach(([k,v]) => {
  console.log(`  ${v}× ${k}`);
});

// Образец 10 разных продуктов
console.log('\n--- Sample 10 distinct (product, program, term, yearKV) ---');
const seen = new Set();
let count = 0;
for (const d of data) {
  const k = `${d.product}|${d.program}`;
  if (seen.has(k)) continue;
  seen.add(k);
  console.log(`product="${d.product}" program="${d.program}" term="${d.term}" yearKV="${d.yearKV}" currency=${d.currency} provider=${d.provider} dsPercent=${d.dsPercent}`);
  if (++count >= 15) break;
}
