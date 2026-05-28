/**
 * Client-side XLSX export через ExcelJS.
 * Lazy-loads библиотеку только при вызове (она ~400 KB gzip).
 *
 * Раньше использовался SheetJS (`xlsx`), но он ушёл с npm-реестра и не
 * получает security-фиксы (CVE-2023-30533 prototype pollution и
 * CVE-2024-22363 ReDoS — оба «No fix available» в npm-версии). Эти
 * уязвимости в `read`/`parse`-сценариях нашего проекта не эксплуатимы,
 * но `npm audit` всё равно горел — поэтому мигрировали на exceljs.
 */

/**
 * Запись workbook в файл — ExcelJS в браузере отдаёт ArrayBuffer,
 * сам download делаем через временный <a href=blob>. File-saver не
 * подключаем — лишняя 4КБ зависимость ради двух вызовов.
 */
async function downloadWorkbook(workbook, filename) {
  const buffer = await workbook.xlsx.writeBuffer();
  const blob = new Blob([buffer], {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  // Освобождаем blob в следующем тике — Safari иногда отменяет
  // скачивание, если URL revoked сразу после click().
  setTimeout(() => URL.revokeObjectURL(url), 100);
}

/** Авто-ширина колонок: max(длина заголовка, max длина значения)+2, cap 50. */
function autoSizeColumns(worksheet, headers, rows) {
  worksheet.columns = headers.map((h, i) => {
    const maxLen = Math.max(
      String(h).length,
      ...rows.map(r => String(r[i] ?? '').length),
    );
    return { width: Math.min(maxLen + 2, 50) };
  });
}

export async function exportToXlsx(data, columns, filename = 'export') {
  const ExcelJS = (await import('exceljs')).default;
  const workbook = new ExcelJS.Workbook();
  const ws = workbook.addWorksheet('Data');

  const headers = columns.map(c => c.title);
  const keys = columns.map(c => c.key);

  const rows = data.map(row =>
    keys.map(key => {
      const val = row[key];
      if (val === null || val === undefined) return '';
      if (typeof val === 'boolean') return val ? 'Да' : 'Нет';
      return val;
    }),
  );

  ws.addRow(headers);
  rows.forEach(r => ws.addRow(r));
  autoSizeColumns(ws, headers, rows);

  await downloadWorkbook(
    workbook,
    `${filename}_${new Date().toISOString().slice(0, 10)}.xlsx`,
  );
}

/**
 * Export finance report to XLSX (per spec ✅Описание EXCEL-файла отчета.md).
 * Структура: 5 листов — Сводная, Личные продажи, Групповые продажи,
 * Прочие начисления и выплаты, Выплаты.
 */
export async function exportFinanceReport(reportData, month) {
  const ExcelJS = (await import('exceljs')).default;
  const workbook = new ExcelJS.Workbook();

  const s = reportData.summary || {};
  const t = reportData.tables || {};

  // Sheet 1: Сводная
  const summaryRows = [
    ['Период отчёта', month],
    ['Квалификация (тек.)', s.qualificationCurrent?.title || '—'],
    ['Квалификация (пред.)', s.qualificationPrev?.title || '—'],
    [],
    ['Объёмы в баллах'],
    ['ЛП', s.volumes?.lp ?? 0],
    ['ГП', s.volumes?.gp ?? 0],
    ['НГП', s.volumes?.ngp ?? 0],
    ['Отрыв', s.breakaway?.gapValue ?? 0],
    [],
    ['Показатель / Личные продажи'],
    ['Баллы', s.personalSales?.points ?? 0],
    ['Бонус', s.personalSales?.bonus ?? 0],
    ['Бонус, ₽', s.personalSales?.bonusRub ?? 0],
    ['Сумма оплат, ₽', s.personalSales?.clientPaymentsRub ?? 0],
    [],
    ['Групповые продажи'],
    ['Баллы', s.groupSales?.points ?? 0],
    ['Бонус', s.groupSales?.bonus ?? 0],
    ['Бонус, ₽', s.groupSales?.bonusRub ?? 0],
    ['Сумма оплат, ₽', s.groupSales?.clientPaymentsRub ?? 0],
    [],
    ['Итог по продажам'],
    ['Бонус', s.totalSales?.bonus ?? 0],
    ['Бонус, ₽', s.totalSales?.bonusRub ?? 0],
    ['Пул, ₽', s.totalSales?.poolRub ?? 0],
    ['Итого, ₽', s.totalSales?.totalRub ?? 0],
    [],
    ['Итоги к выплате за месяц'],
    ['Прочие начисления, баллы', s.monthEnd?.otherAccrualsPoints ?? 0],
    ['Прочие начисления, ₽', s.monthEnd?.otherAccruals ?? 0],
    ['Итого начислено, ₽', s.monthEnd?.totalAccrued ?? 0],
    ['Остаток на начало месяца, ₽', s.monthEnd?.balanceStart ?? 0],
    ['Итого к выплате, ₽', s.monthEnd?.totalPayable ?? 0],
  ];
  const wsSummary = workbook.addWorksheet('Сводная');
  summaryRows.forEach(r => wsSummary.addRow(r));
  wsSummary.columns = [{ width: 36 }, { width: 22 }];

  // Sheet 2: Личные продажи.
  // «Параметр» исторически был одной колонкой — теперь у разных продуктов
  // могут быть свои поля (свойство / срок / год КВ). В Excel выводим все
  // три, даже если конкретный продукт его не имеет (там будет '—'),
  // потому что одна выгрузка может содержать сделки по разным продуктам.
  const personalHeaders = [
    'Дата', 'Контракт', 'Клиент', 'Продукт', 'Программа',
    'Сумма оплаты', 'Сумма без НДС', 'Свойство', 'Срок, лет', 'Год КВ',
    'ЛП', 'Бонус', 'Бонус, ₽', 'Комментарий',
  ];
  const dash = (v) => v === null || v === undefined || v === '' ? '—' : v;
  const personalRows = (t.personalSales || []).map(r => [
    r.date, r.contractNumber, r.clientName, r.productName, r.programName,
    r.paymentAmount ?? 0, r.amountNoVat ?? 0,
    dash(r.propertyTitle), dash(r.contractTerm), dash(r.yearKV),
    r.personalVolume ?? 0, r.bonus ?? 0, r.bonusRub ?? 0, r.comment ?? '',
  ]);
  const wsPersonal = workbook.addWorksheet('Личные продажи');
  wsPersonal.addRow(personalHeaders);
  personalRows.forEach(r => wsPersonal.addRow(r));
  autoSizeColumns(wsPersonal, personalHeaders, personalRows);

  // Sheet 3: Групповые продажи
  const groupHeaders = [
    'Дата', 'Контракт', 'Клиент', 'Партнёр сделки', 'Продукт', 'Программа',
    'Сумма оплаты', 'Сумма без НДС', 'Свойство', 'Срок, лет', 'Год КВ',
    'ГП', 'Бонус', 'Бонус, ₽', 'Комментарий',
  ];
  const groupRows = (t.groupSales || []).map(r => [
    r.date, r.contractNumber, r.clientName, r.partnerName, r.productName, r.programName,
    r.paymentAmount ?? 0, r.amountNoVat ?? 0,
    dash(r.propertyTitle), dash(r.contractTerm), dash(r.yearKV),
    r.personalVolume ?? 0, r.bonus ?? 0, r.bonusRub ?? 0, r.comment ?? '',
  ]);
  const wsGroup = workbook.addWorksheet('Групповые продажи');
  wsGroup.addRow(groupHeaders);
  groupRows.forEach(r => wsGroup.addRow(r));
  autoSizeColumns(wsGroup, groupHeaders, groupRows);

  // Sheet 4: Прочие начисления и выплаты
  const otherHeaders = ['Дата', 'Сумма оплаты', 'Баллы', 'Комментарий'];
  const otherRows = (t.otherAccruals || []).map(r => [
    r.date, r.amountRUB ?? 0, r.amount ?? 0, r.comment ?? '',
  ]);
  const wsOther = workbook.addWorksheet('Прочие начисления');
  wsOther.addRow(otherHeaders);
  otherRows.forEach(r => wsOther.addRow(r));
  wsOther.columns = [{ width: 14 }, { width: 16 }, { width: 14 }, { width: 60 }];

  // Sheet 5: Выплаты
  const paymentHeaders = ['Дата', 'Сумма оплаты', 'Комментарий'];
  const paymentRows = (t.payments || []).map(r => [
    r.date, r.amount ?? 0, r.comment ?? '',
  ]);
  const wsPay = workbook.addWorksheet('Выплаты');
  wsPay.addRow(paymentHeaders);
  paymentRows.forEach(r => wsPay.addRow(r));
  wsPay.columns = [{ width: 14 }, { width: 16 }, { width: 60 }];

  await downloadWorkbook(workbook, `report_${month}.xlsx`);
}
