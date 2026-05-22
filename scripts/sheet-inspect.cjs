const fs = require('fs');
const path = require('path');

const file = process.argv[2];
const txt = fs.readFileSync(file, 'utf8');
const obj = JSON.parse(txt);
const rows = obj.fileContent.split('\n');
console.log('total rows:', rows.length);
console.log('--- first 8 ---');
rows.slice(0, 8).forEach((r, i) => console.log(i, ':', r.slice(0, 250)));
console.log('\n--- row 50..60 ---');
rows.slice(50, 60).forEach((r, i) => console.log(50 + i, ':', r.slice(0, 250)));
console.log('\n--- row 200..210 ---');
rows.slice(200, 210).forEach((r, i) => console.log(200 + i, ':', r.slice(0, 250)));
console.log('\n--- last 10 ---');
rows.slice(-10).forEach((r, i) => console.log(rows.length - 10 + i, ':', r.slice(0, 250)));
