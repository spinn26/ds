/**
 * Client-side XLSX export using SheetJS.
 * Lazy-loads the library only when needed.
 */
export async function exportToXlsx(data, columns, filename = 'export') {
  const XLSX = await import('xlsx');

  const headers = columns.map(c => c.title);
  const keys = columns.map(c => c.key);

  const rows = data.map(row =>
    keys.map(key => {
      const val = row[key];
      if (val === null || val === undefined) return '';
      if (typeof val === 'boolean') return val ? 'Да' : 'Нет';
      return val;
    })
  );

  const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);

  // Auto-size columns
  ws['!cols'] = headers.map((h, i) => {
    const maxLen = Math.max(
      h.length,
      ...rows.map(r => String(r[i] || '').length)
    );
    return { wch: Math.min(maxLen + 2, 50) };
  });

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Data');
  XLSX.writeFile(wb, `${filename}_${new Date().toISOString().slice(0, 10)}.xlsx`);
}

/**
 * Export finance report to XLSX (per spec ✅Описание EXCEL-файла отчета.md).
 * Структура: 5 листов — Сводная, Личные продажи, Групповые продажи,
 * Прочие начисления и выплаты, Выплаты.
 */
export async function exportFinanceReport(reportData, month) {
  const XLSX = await import('xlsx');
  const wb = XLSX.utils.book_new();

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
  const wsSummary = XLSX.utils.aoa_to_sheet(summaryRows);
  wsSummary['!cols'] = [{ wch: 36 }, { wch: 22 }];
  XLSX.utils.book_append_sheet(wb, wsSummary, 'Сводная');

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
  const wsPersonal = XLSX.utils.aoa_to_sheet([personalHeaders, ...personalRows]);
  wsPersonal['!cols'] = personalHeaders.map((h, i) => ({ wch: Math.min(40, Math.max(h.length + 2, ...personalRows.map(r => String(r[i] ?? '').length + 2), 12)) }));
  XLSX.utils.book_append_sheet(wb, wsPersonal, 'Личные продажи');

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
  const wsGroup = XLSX.utils.aoa_to_sheet([groupHeaders, ...groupRows]);
  wsGroup['!cols'] = groupHeaders.map((h, i) => ({ wch: Math.min(40, Math.max(h.length + 2, ...groupRows.map(r => String(r[i] ?? '').length + 2), 12)) }));
  XLSX.utils.book_append_sheet(wb, wsGroup, 'Групповые продажи');

  // Sheet 4: Прочие начисления и выплаты
  const otherHeaders = ['Дата', 'Сумма оплаты', 'Баллы', 'Комментарий'];
  const otherRows = (t.otherAccruals || []).map(r => [
    r.date, r.amountRUB ?? 0, r.amount ?? 0, r.comment ?? '',
  ]);
  const wsOther = XLSX.utils.aoa_to_sheet([otherHeaders, ...otherRows]);
  wsOther['!cols'] = [{ wch: 14 }, { wch: 16 }, { wch: 14 }, { wch: 60 }];
  XLSX.utils.book_append_sheet(wb, wsOther, 'Прочие начисления');

  // Sheet 5: Выплаты
  const paymentHeaders = ['Дата', 'Сумма оплаты', 'Комментарий'];
  const paymentRows = (t.payments || []).map(r => [
    r.date, r.amount ?? 0, r.comment ?? '',
  ]);
  const wsPay = XLSX.utils.aoa_to_sheet([paymentHeaders, ...paymentRows]);
  wsPay['!cols'] = [{ wch: 14 }, { wch: 16 }, { wch: 60 }];
  XLSX.utils.book_append_sheet(wb, wsPay, 'Выплаты');

  XLSX.writeFile(wb, `report_${month}.xlsx`);
}
