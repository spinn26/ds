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
 * Export finance report to XLSX with multiple sheets.
 */
export async function exportFinanceReport(reportData, month) {
  const XLSX = await import('xlsx');
  const wb = XLSX.utils.book_new();

  // Summary sheet
  const summaryRows = [
    ['Отчёт начислений и выплат', month],
    [],
    ['Квалификация', reportData.summary?.qualificationCurrent?.title || '—'],
    ['Уровень комиссии', `${reportData.summary?.commissionLevel?.percent || 0}%`],
    ['ЛП', reportData.summary?.volumes?.lp || 0],
    ['ГП', reportData.summary?.volumes?.gp || 0],
    ['НГП', reportData.summary?.volumes?.ngp || 0],
    [],
    ['Личные продажи'],
    ['Баллы', reportData.summary?.personalSales?.points || 0],
    ['Бонус', reportData.summary?.personalSales?.bonus || 0],
    ['Бонус (руб)', reportData.summary?.personalSales?.bonusRub || 0],
    [],
    ['Групповые продажи'],
    ['Баллы', reportData.summary?.groupSales?.points || 0],
    ['Бонус', reportData.summary?.groupSales?.bonus || 0],
    ['Бонус (руб)', reportData.summary?.groupSales?.bonusRub || 0],
  ];
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows), 'Итого');

  // Personal sales
  if (reportData.tables?.personalSales?.length) {
    const headers = ['Дата', 'Контракт', 'Клиент', 'Продукт', 'Программа', 'Баллы', 'Бонус', 'Бонус (руб)'];
    const rows = reportData.tables.personalSales.map(r => [
      r.date, r.contractNumber, r.clientName, r.productName, r.programName,
      r.personalVolume, r.bonus, r.bonusRub,
    ]);
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([headers, ...rows]), 'Личные продажи');
  }

  // Group sales
  if (reportData.tables?.groupSales?.length) {
    const headers = ['Дата', 'Контракт', 'Клиент', 'Партнёр', 'Продукт', 'Баллы', 'Бонус', 'Бонус (руб)'];
    const rows = reportData.tables.groupSales.map(r => [
      r.date, r.contractNumber, r.clientName, r.partnerName, r.productName,
      r.personalVolume, r.bonus, r.bonusRub,
    ]);
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([headers, ...rows]), 'Групповые продажи');
  }

  XLSX.writeFile(wb, `report_${month}.xlsx`);
}
