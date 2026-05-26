// ds-extra-manager.jsx — менеджерские экраны
// Периоды (list + wizard), Контракты-менеджер, Загрузка контрактов,
// Партнёры, Статусы, Приёмка, Реквизиты, Транзакции, Импорт,
// Комиссии, Бассейн, Квалификации, Начисления, Реестр выплат,
// Чат стафф, Поддержка, Аналитика, Анкеты партнёров

// generic ListShell — оборачивает таблицу под FullShell+ManagerSidebar
function MgrShell({ activeId, title, subtitle, actions, children }) {
  return (
    <FullShell theme="light" sidebar={<ManagerSidebar activeId={activeId} />} content={
      <React.Fragment>
        <AppBar title={title} subtitle={subtitle}>{actions}</AppBar>
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 14 }}>
          {children}
        </div>
      </React.Fragment>
    } />
  );
}

// Финансовая мини-таблица — переиспользуется
function FinTable({ cols, rows, footer }) {
  return (
    <DSCard variant="elevated">
      <table className="ds-table">
        <thead><tr>{cols.map((c, i) => <th key={i} style={c.right ? { textAlign: 'right' } : undefined}>{c.label}</th>)}</tr></thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>
              {r.map((cell, j) => (
                <td key={j} className={cols[j].mono ? 'ds-mono' : ''} style={cols[j].right ? { textAlign: 'right' } : undefined}>{cell}</td>
              ))}
            </tr>
          ))}
          {footer && <tr style={{ background: 'var(--ds-surface-container-low)' }}>{footer.map((c, j) => <td key={j} className={'ds-mono ds-title-m'} style={cols[j].right ? { textAlign: 'right' } : undefined}>{c}</td>)}</tr>}
        </tbody>
      </table>
    </DSCard>
  );
}

// ─────────── ПЕРИОДЫ ───────────
function MgrPeriods() {
  return (
    <MgrShell activeId="per" title="Периоды" subtitle="закрытие финансовых месяцев"
      actions={<DSButton variant="filled" size="sm">＋ Новый период</DSButton>}>
      <DSCard variant="elevated">
        <table className="ds-table">
          <thead><tr><th>период</th><th>контрактов</th><th style={{ textAlign: 'right' }}>оборот</th><th style={{ textAlign: 'right' }}>к выплате</th><th>статус</th><th style={{ width: 32 }}></th></tr></thead>
          <tbody>
            {[
              ['май 2026', 482, '128.4 М ₽', '12.8 М ₽', 'wait', 'в процессе'],
              ['апрель 2026', 396, '105.2 М ₽', '10.4 М ₽', 'active', 'закрыт'],
              ['март 2026', 412, '108.8 М ₽', '10.9 М ₽', 'active', 'закрыт'],
              ['февраль 2026', 358, '92.4 М ₽', '9.2 М ₽', 'active', 'закрыт'],
              ['январь 2026', 280, '74.0 М ₽', '7.4 М ₽', 'active', 'закрыт'],
            ].map((r, i) => (
              <tr key={i}>
                <td><span className="ds-title-s">{r[0]}</span></td>
                <td>{r[1]}</td>
                <td className="ds-mono" style={{ textAlign: 'right' }}>{r[2]}</td>
                <td className="ds-mono" style={{ textAlign: 'right', fontWeight: 600 }}>{r[3]}</td>
                <td><DSStatus variant={r[4]}>{r[5]}</DSStatus></td>
                <td><DSButton variant="text" icon size="sm">⋮</DSButton></td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── ПЕРИОДЫ · WIZARD ───────────
function MgrPeriodWizard() {
  const steps = [
    { id: 1, label: 'Импорт транзакций', status: 'done' },
    { id: 2, label: 'Расчёт комиссий', status: 'done' },
    { id: 3, label: 'Начисления', status: 'current' },
    { id: 4, label: 'Реестр выплат', status: 'wait' },
    { id: 5, label: 'Закрытие периода', status: 'wait' },
  ];
  return (
    <MgrShell activeId="per" title="Закрытие · май 2026" subtitle="5 шагов · сейчас на шаге 3"
      actions={<React.Fragment><DSButton variant="text">отменить</DSButton><DSButton variant="filled">Продолжить →</DSButton></React.Fragment>}>
      {/* steps strip */}
      <DSCard variant="elevated">
        <div style={{ padding: 22, display: 'flex', alignItems: 'center', gap: 12 }}>
          {steps.map((s, i) => (
            <React.Fragment key={s.id}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div style={{
                  width: 32, height: 32, borderRadius: 50,
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontWeight: 700, fontSize: 13,
                  background: s.status === 'done' ? 'var(--ds-success)' : s.status === 'current' ? 'var(--ds-primary)' : 'var(--ds-surface-container-high)',
                  color: s.status === 'wait' ? 'var(--ds-on-surface-muted)' : 'var(--ds-on-primary)',
                  border: s.status === 'current' ? '3px solid var(--ds-primary-soft)' : 'none',
                }}>{s.status === 'done' ? '✓' : s.id}</div>
                <div>
                  <div className="ds-label-m" style={{ color: s.status === 'current' ? 'var(--ds-primary)' : 'var(--ds-on-surface-muted)' }}>шаг {s.id}</div>
                  <div className="ds-title-s">{s.label}</div>
                </div>
              </div>
              {i < steps.length - 1 && (
                <div style={{ flex: 1, height: 2, background: s.status === 'done' ? 'var(--ds-success)' : 'var(--ds-outline-variant)' }}></div>
              )}
            </React.Fragment>
          ))}
        </div>
      </DSCard>

      {/* step content */}
      <DSCard variant="elevated">
        <div style={{ padding: 22 }}>
          <div className="ds-headline-s" style={{ marginBottom: 6 }}>Шаг 3 · Начисления</div>
          <div className="ds-body-m ds-muted" style={{ marginBottom: 18 }}>Распределение комиссий по партнёрам. Проверьте суммы перед формированием реестра.</div>

          <FinTable cols={[
            { label: 'категория' }, { label: 'партнёров' }, { label: 'к начислению', right: true, mono: true }, { label: '' },
          ]} rows={[
            ['ЛП — личные продажи', 142, '6 840 000 ₽', <DSStatus variant="active">готово</DSStatus>],
            ['ГП — групповые продажи', 89, '3 240 000 ₽', <DSStatus variant="active">готово</DSStatus>],
            ['НГП — новые группы', 42, '1 480 000 ₽', <DSStatus variant="active">готово</DSStatus>],
            ['Leader pool', 12, '820 000 ₽', <DSStatus variant="active">готово</DSStatus>],
            ['Штрафы / удержания', 3, '−180 000 ₽', <DSStatus variant="warn">проверка</DSStatus>],
          ]} footer={['Итого', '148 партнёров', '12 200 000 ₽', '']} />
        </div>
      </DSCard>

      <DSAlert variant="warning" title="Проверьте удержания">
        3 партнёра имеют отрицательные начисления — обычно это возвраты по контрактам. Откройте каждый случай отдельно.
      </DSAlert>
    </MgrShell>
  );
}

// ─────────── КОНТРАКТЫ-МЕНЕДЖЕР ───────────
function MgrContracts() {
  return (
    <MgrShell activeId="con" title="Менеджер контрактов" subtitle="все контракты · 482 за май"
      actions={<React.Fragment><DSButton variant="outlined">⤓ экспорт</DSButton><DSButton variant="filled">⤒ загрузить файл</DSButton></React.Fragment>}>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 280, maxWidth: 380 }}><DSField placeholder="номер · клиент · партнёр" prefix="⌕" /></div>
        <DSChip onClick={()=>{}} active>все · 482</DSChip>
        <DSChip onClick={()=>{}}>активные · 396</DSChip>
        <DSChip onClick={()=>{}}>на проверке · 64</DSChip>
        <DSChip onClick={()=>{}}>отклонённые · 22</DSChip>
        <DSButton variant="outlined" size="sm">⏷ продукт</DSButton>
        <DSButton variant="outlined" size="sm">⏷ поставщик</DSButton>
        <DSButton variant="outlined" size="sm">⏷ партнёр</DSButton>
      </div>
      <FinTable cols={[
        {label:'номер', mono:true},{label:'клиент'},{label:'продукт'},{label:'партнёр'},{label:'сумма',right:true,mono:true},{label:'дата'},{label:'статус'},
      ]} rows={[
        ['C-2024-0381','Иванов И.','Эволюция','Иванов И.','2 400 000 ₽','12.05',<DSStatus variant="active">действует</DSStatus>],
        ['C-2024-0380','Петрова А.','СОЗ','Сидорова А.','1 850 000 ₽','14.05',<DSStatus variant="warn">проверка</DSStatus>],
        ['C-2024-0378','Сидоров Д.','PRE-IPO','Иванов И.','3 600 000 ₽','15.05',<DSStatus variant="active">действует</DSStatus>],
        ['C-2024-0376','Кузнецова М.','Эволюция','Карпов М.','900 000 ₽','18.05',<DSStatus variant="draft">черновик</DSStatus>],
        ['C-2024-0375','Лебедев Н.','Эволюция','Иванов И.','1 200 000 ₽','20.05',<DSStatus variant="active">действует</DSStatus>],
        ['C-2024-0372','Морозова Е.','СОЗ','Сидорова А.','900 000 ₽','21.05',<DSStatus variant="err">отклонён</DSStatus>],
        ['C-2024-0370','Соколов А.','PRE-IPO','Карпов М.','4 800 000 ₽','22.05',<DSStatus variant="warn">проверка</DSStatus>],
      ]} />
    </MgrShell>
  );
}

// ─────────── ЗАГРУЗКА КОНТРАКТОВ ───────────
function MgrContractUpload() {
  return (
    <MgrShell activeId="up" title="Загрузка контрактов" subtitle="bulk-upload CSV / Excel">
      <DSCard variant="elevated">
        <div style={{ padding: 28 }}>
          {/* dropzone */}
          <div style={{
            border: '2px dashed var(--ds-outline)', borderRadius: 12,
            padding: '40px 24px', textAlign: 'center', background: 'var(--ds-primary-soft)',
          }}>
            <div style={{ fontSize: 36, color: 'var(--ds-primary)' }}>⤒</div>
            <div className="ds-headline-s" style={{ marginTop: 12 }}>Перетащите файл или нажмите для выбора</div>
            <div className="ds-body-m ds-muted" style={{ marginTop: 6 }}>CSV или Excel · до 50 МБ · до 10 000 строк</div>
            <DSButton variant="filled" size="lg" style={{ marginTop: 18 }}>Выбрать файл</DSButton>
            <div className="ds-body-s ds-muted" style={{ marginTop: 12 }}>
              нужен шаблон? <a style={{ color: 'var(--ds-primary)' }}>скачать contracts-template.xlsx</a>
            </div>
          </div>
        </div>
      </DSCard>

      {/* recent uploads */}
      <DSCard variant="elevated">
        <div style={{ padding: '16px 18px 10px' }}>
          <div className="ds-title-l">Недавние загрузки</div>
        </div>
        <table className="ds-table">
          <thead><tr><th>файл</th><th>загружен</th><th>строк</th><th>результат</th><th>статус</th><th style={{ width: 32 }}></th></tr></thead>
          <tbody>
            {[
              ['contracts-may-batch-3.xlsx', '25.05 в 14:32', 142, <span className="ds-mono ds-body-s">+128 ✓ · 8 ⚠ · 6 ✗</span>, <DSStatus variant="active">обработан</DSStatus>],
              ['contracts-may-batch-2.xlsx', '22.05 в 11:18', 96, <span className="ds-mono ds-body-s">+94 ✓ · 2 ⚠</span>, <DSStatus variant="active">обработан</DSStatus>],
              ['contracts-may-batch-1.xlsx', '18.05 в 09:42', 244, <span className="ds-mono ds-body-s">+240 ✓ · 4 ⚠</span>, <DSStatus variant="active">обработан</DSStatus>],
              ['contracts-may-test.xlsx', '15.05 в 16:08', 12, <span className="ds-mono ds-body-s">валидация не пройдена</span>, <DSStatus variant="err">ошибка</DSStatus>],
            ].map((r, i) => (
              <tr key={i}>
                <td><span className="ds-mono ds-title-s">{r[0]}</span></td>
                <td className="ds-muted">{r[1]}</td>
                <td className="ds-mono">{r[2]}</td>
                <td>{r[3]}</td>
                <td>{r[4]}</td>
                <td><DSButton variant="text" icon size="sm">⋮</DSButton></td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── ПАРТНЁРЫ ───────────
function MgrPartners() {
  return (
    <MgrShell activeId="pr" title="Партнёры" subtitle="вся партнёрская сеть · 142 активных"
      actions={<DSButton variant="filled">＋ Создать партнёра</DSButton>}>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 280, maxWidth: 380 }}><DSField placeholder="ФИО, ID или Telegram" prefix="⌕" /></div>
        <DSChip onClick={()=>{}} active>все · 142</DSChip>
        <DSChip onClick={()=>{}}>стажёры · 24</DSChip>
        <DSChip onClick={()=>{}}>консультанты · 68</DSChip>
        <DSChip onClick={()=>{}}>senior · 34</DSChip>
        <DSChip onClick={()=>{}}>тим-лиды · 12</DSChip>
        <DSChip onClick={()=>{}}>директоры · 4</DSChip>
        <div style={{ width: 1, height: 24, background: 'var(--ds-outline-variant)' }}></div>
        <DSChip variant="warning" onClick={()=>{}}>в зоне риска · 8</DSChip>
        <DSChip variant="error" onClick={()=>{}}>заморожены · 3</DSChip>
      </div>
      <FinTable cols={[
        {label:'партнёр'},{label:'ID', mono:true},{label:'квалификация'},{label:'команда'},{label:'ЛП май', right:true, mono:true},{label:'активность'},{label:'статус'},
      ]} rows={[
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="ИП"/><span>Иванов И.И.</span></div>,'DS-04812','★ Senior','12','2.48 М ₽','активен',<DSStatus variant="active">активен</DSStatus>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="СА"/><span>Сидорова А.Н.</span></div>,'DS-04781','★ Senior','5','3.48 М ₽','активен',<DSStatus variant="active">активен</DSStatus>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="КМ"/><span>Карпов М.</span></div>,'DS-04802','Консультант','3','2.12 М ₽','активен',<DSStatus variant="active">активен</DSStatus>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="ЛН"/><span>Лебедева Н.</span></div>,'DS-04822','Стажёр','0','680 К ₽','60 дней',<DSStatus variant="warn">риск</DSStatus>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="ПП"/><span>Петров П.</span></div>,'DS-04733','Тим-лид','22','5.20 М ₽','активен',<DSStatus variant="active">активен</DSStatus>],
      ]} />
    </MgrShell>
  );
}

// ─────────── СТАТУСЫ ПАРТНЁРОВ ───────────
function MgrStatuses() {
  return (
    <MgrShell activeId="pr" title="Статусы партнёров" subtitle="ручное управление статусом и квалификацией">
      <DSAlert variant="warning" title="Осторожно — деструктивные действия">
        Заморозка партнёра блокирует ему доступ ко всему кабинету. Используйте только при подтверждённых нарушениях регламента.
      </DSAlert>
      <FinTable cols={[
        {label:'партнёр'},{label:'текущая квалификация'},{label:'дата изменения'},{label:'изменил'},{label:'действия'},
      ]} rows={[
        ['Иванов И.', <DSChip variant="brand">★ Senior</DSChip>, '01.04.2026', 'auto', <DSButton variant="outlined" size="sm">сменить</DSButton>],
        ['Лебедева Н.', <DSChip variant="warning">Стажёр (риск)</DSChip>, '12.05.2026', 'М. Карпов', <React.Fragment><DSButton variant="outlined" size="sm">сменить</DSButton> <DSButton variant="outlined" size="sm" danger>заморозить</DSButton></React.Fragment>],
        ['Иванов А.', <DSChip variant="error">заморожен</DSChip>, '03.05.2026', 'А. Карпова', <DSButton variant="tonal" size="sm">разморозить</DSButton>],
      ]} />
    </MgrShell>
  );
}

// ─────────── ПРИЁМКА КОНТРАКТОВ ───────────
function MgrAcceptance() {
  return (
    <MgrShell activeId="acc" title="Приёмка контрактов" subtitle="очередь модерации · 7 ждут">
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
        {[
          { id: 'C-0381', client: 'Иванов И.И.', product: 'Эволюция', sum: '2.4 М ₽', partner: 'Иванов И.', age: '2 ч', issues: 0 },
          { id: 'C-0380', client: 'Петрова А.', product: 'СОЗ', sum: '1.85 М ₽', partner: 'Сидорова А.', age: '5 ч', issues: 1 },
          { id: 'C-0379', client: 'Сидоров Д.', product: 'PRE-IPO', sum: '3.6 М ₽', partner: 'Иванов И.', age: '8 ч', issues: 0 },
          { id: 'C-0378', client: 'Кузнецова М.', product: 'Эволюция', sum: '0.9 М ₽', partner: 'Карпов М.', age: '1 д', issues: 2 },
          { id: 'C-0377', client: 'Лебедев Н.', product: 'Эволюция', sum: '1.2 М ₽', partner: 'Иванов И.', age: '1 д', issues: 0 },
          { id: 'C-0376', client: 'Морозова Е.', product: 'СОЗ', sum: '0.9 М ₽', partner: 'Сидорова А.', age: '2 д', issues: 3 },
        ].map((c, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                <span className="ds-mono ds-title-s">{c.id}</span>
                {c.issues > 0 ? <DSStatus variant="warn">{c.issues} замечан.</DSStatus> : <DSStatus variant="active">чисто</DSStatus>}
              </div>
              <div className="ds-headline-s" style={{ marginTop: 8 }}>{c.client}</div>
              <div className="ds-body-s ds-muted">{c.product} · {c.partner}</div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginTop: 14 }}>
                <span className="ds-headline-s ds-mono" style={{ color: 'var(--ds-primary)' }}>{c.sum}</span>
                <span className="ds-body-s ds-muted">ждёт {c.age}</span>
              </div>
              <div style={{ display: 'flex', gap: 6, marginTop: 14 }}>
                <DSButton variant="filled" size="sm" style={{ flex: 1 }}>Принять</DSButton>
                <DSButton variant="outlined" size="sm">Запросить</DSButton>
                <DSButton variant="text" size="sm" danger>×</DSButton>
              </div>
            </div>
          </DSCard>
        ))}
      </div>
    </MgrShell>
  );
}

// ─────────── ТРАНЗАКЦИИ ───────────
function MgrTransactions() {
  return (
    <MgrShell activeId="trx" title="Транзакции" subtitle="все движения · 8 412 за май"
      actions={<React.Fragment><DSButton variant="outlined">⤓ экспорт</DSButton><DSButton variant="filled">⤒ импорт</DSButton></React.Fragment>}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
        {[
          { lbl: 'Поступления', v: '128.4 М ₽', sub: '4 280 операций' },
          { lbl: 'Выплаты', v: '12.4 М ₽', sub: '148 операций' },
          { lbl: 'Возвраты', v: '−2.8 М ₽', sub: '12 операций' },
          { lbl: 'Расхождения', v: '0', sub: 'live ≈ snapshot', c: 'var(--ds-success)' },
        ].map((k, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 14 }}>
              <div className="ds-label-m ds-muted">{k.lbl}</div>
              <div className="ds-headline-s ds-mono" style={{ marginTop: 4, color: k.c }}>{k.v}</div>
              <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{k.sub}</div>
            </div>
          </DSCard>
        ))}
      </div>
      <FinTable cols={[
        {label:'дата', mono:true},{label:'тип'},{label:'контракт', mono:true},{label:'клиент'},{label:'партнёр'},{label:'сумма', right:true, mono:true},{label:''},
      ]} rows={[
        ['25.05 14:32',<DSChip variant="success">приход</DSChip>,'C-2024-0381','Иванов И.','Иванов И.','+ 240 000 ₽',<DSStatus variant="active">сверено</DSStatus>],
        ['25.05 11:08',<DSChip variant="success">приход</DSChip>,'C-2024-0378','Сидоров Д.','Иванов И.','+ 360 000 ₽',<DSStatus variant="active">сверено</DSStatus>],
        ['24.05 18:42',<DSChip>комиссия</DSChip>,'C-2024-0381','—','Иванов И.','+ 168 000 ₽',<DSStatus variant="warn">проверка</DSStatus>],
        ['23.05 09:18',<DSChip variant="error">возврат</DSChip>,'C-2024-0188','Васильев К.','Сидорова А.','− 80 000 ₽',<DSStatus variant="active">сверено</DSStatus>],
        ['22.05 16:08',<DSChip variant="info">выплата</DSChip>,'—','—','реестр апр-26','− 10 400 000 ₽',<DSStatus variant="active">сверено</DSStatus>],
      ]} />
    </MgrShell>
  );
}

// ─────────── ИМПОРТ ───────────
function MgrImport() {
  return (
    <MgrShell activeId="imp" title="Импорт транзакций" subtitle="загрузка выписок от поставщиков">
      <DSCard variant="elevated">
        <div style={{ padding: 22 }}>
          <div className="ds-title-l" style={{ marginBottom: 14 }}>Шаг 1 · Выбор поставщика и файла</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
            <DSField label="Поставщик" defaultValue="Альфа-Капитал" suffix="⌄" />
            <DSField label="Период выписки" defaultValue="май 2026" suffix="⌄" />
          </div>
          <div style={{ marginTop: 18, padding: '32px 24px', border: '2px dashed var(--ds-outline)', borderRadius: 12, textAlign: 'center', background: 'var(--ds-surface-container-low)' }}>
            <div style={{ fontSize: 32, color: 'var(--ds-primary)' }}>⤒</div>
            <div className="ds-title-l" style={{ marginTop: 10 }}>alpha-may-2026.csv</div>
            <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>4 280 строк · 12 МБ · валидация пройдена ✓</div>
            <DSButton variant="text" style={{ marginTop: 8 }}>заменить файл</DSButton>
          </div>
        </div>
      </DSCard>

      <DSCard variant="elevated">
        <div style={{ padding: 22 }}>
          <div className="ds-title-l" style={{ marginBottom: 14 }}>Шаг 2 · Preview и коллизии</div>
          <div style={{ display: 'flex', gap: 10, marginBottom: 14 }}>
            <DSChip variant="success">новых · 4 142</DSChip>
            <DSChip variant="info">обновлено · 128</DSChip>
            <DSChip variant="warning">конфликты · 10</DSChip>
          </div>
          <FinTable cols={[
            {label:'строка', mono:true},{label:'контракт', mono:true},{label:'дата', mono:true},{label:'сумма', right:true, mono:true},{label:'статус'},
          ]} rows={[
            ['#1','C-2024-0381','01.05','120 000 ₽',<DSStatus variant="active">новая</DSStatus>],
            ['#2','C-2024-0188','03.05','−40 000 ₽',<DSStatus variant="warn">сумма расходится: +40k → −40k</DSStatus>],
            ['#3','C-2024-0322','05.05','85 000 ₽',<DSStatus variant="active">новая</DSStatus>],
            ['#4','C-???-????','08.05','12 000 ₽',<DSStatus variant="err">не найден контракт</DSStatus>],
          ]} />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 18 }}>
            <DSButton variant="text">отменить</DSButton>
            <DSButton variant="filled">Запустить обработку →</DSButton>
          </div>
        </div>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── КОМИССИИ / БАССЕЙН / КВАЛ / НАЧИСЛЕНИЯ ───────────
function MgrCommissions() {
  return (
    <MgrShell activeId="com" title="Комиссии" subtitle="расчёт по контрактам · май 2026">
      <FinTable cols={[
        {label:'партнёр'},{label:'квал'},{label:'ЛП', right:true, mono:true},{label:'ГП', right:true, mono:true},{label:'leader pool', right:true, mono:true},{label:'итого', right:true, mono:true},
      ]} rows={[
        ['Иванов И.', 'Senior', '248 400', '142 000', '38 000', '428 400'],
        ['Сидорова А.', 'Senior', '348 000', '180 000', '52 000', '580 000'],
        ['Карпов М.', 'Консультант', '212 000', '64 000', '—', '276 000'],
        ['Петров П.', 'Тим-лид', '184 000', '380 000', '128 000', '692 000'],
        ['Лебедева Н.', 'Стажёр', '68 000', '—', '—', '68 000'],
      ]} footer={['', '', '1 060 400', '766 000', '218 000', '2 044 400 ₽']} />
    </MgrShell>
  );
}

function MgrPool() {
  return (
    <MgrShell activeId="pool" title="Бассейн" subtitle="leader pool · доли по квалификациям">
      <DSCard variant="elevated">
        <div style={{ padding: 22 }}>
          <div className="ds-title-l" style={{ marginBottom: 14 }}>Распределение пула · май</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {[
              { q: 'Senior · 34 партнёра', p: 45, sum: '982 800 ₽' },
              { q: 'Тим-лид · 12 партнёров', p: 30, sum: '655 200 ₽' },
              { q: 'Директор · 4 партнёра', p: 25, sum: '546 000 ₽' },
            ].map((r, i) => (
              <div key={i}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                  <span className="ds-title-s">{r.q}</span>
                  <span className="ds-body-m ds-mono" style={{ fontWeight: 600 }}>{r.sum}</span>
                </div>
                <DSProgress value={r.p} variant="brand" height="thick" />
                <div className="ds-body-s ds-muted ds-mono" style={{ marginTop: 4 }}>{r.p}% пула</div>
              </div>
            ))}
            <div className="ds-divider" style={{ margin: '10px 0' }}></div>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
              <span className="ds-title-m">Итого пул</span>
              <span className="ds-headline-s ds-mono" style={{ color: 'var(--ds-primary)' }}>2 184 000 ₽</span>
            </div>
          </div>
        </div>
      </DSCard>
    </MgrShell>
  );
}

function MgrQualifications() {
  return (
    <MgrShell activeId="pr" title="Квалификации" subtitle="требования и текущее распределение партнёров">
      <FinTable cols={[
        {label:'квалификация'},{label:'требование к ЛП', right:true, mono:true},{label:'требование к ГП', right:true, mono:true},{label:'команда от', right:true, mono:true},{label:'бонус', right:true},{label:'партнёров', right:true, mono:true},
      ]} rows={[
        [<DSChip>Стажёр</DSChip>, '—', '—', '0', '—', '24'],
        [<DSChip>Консультант</DSChip>, '500 К ₽', '—', '0', '+0.5%', '68'],
        [<DSChip variant="brand">★ Senior</DSChip>, '2 000 000 ₽', '5 000 000 ₽', '3', '+1%', '34'],
        [<DSChip variant="brand">★ Тим-лид</DSChip>, '3 000 000 ₽', '10 000 000 ₽', '8', '+1.5%', '12'],
        [<DSChip variant="brand">★ Директор</DSChip>, '5 000 000 ₽', '25 000 000 ₽', '20', '+2%', '4'],
      ]} />
    </MgrShell>
  );
}

function MgrCharges() {
  return (
    <MgrShell activeId="com" title="Прочие начисления" subtitle="бонусы, штрафы, корректировки">
      <FinTable cols={[
        {label:'дата', mono:true},{label:'партнёр'},{label:'тип'},{label:'основание'},{label:'сумма', right:true, mono:true},{label:'статус'},
      ]} rows={[
        ['25.05','Иванов И.',<DSChip variant="success">бонус</DSChip>,'выполнение цели Q2', '+ 50 000 ₽', <DSStatus variant="active">начислено</DSStatus>],
        ['24.05','Сидорова А.',<DSChip variant="success">бонус</DSChip>,'приведение партнёра','+ 30 000 ₽', <DSStatus variant="active">начислено</DSStatus>],
        ['22.05','Иванов А.',<DSChip variant="error">штраф</DSChip>,'нарушение регламента','− 20 000 ₽', <DSStatus variant="warn">проверка</DSStatus>],
        ['20.05','Петров С.',<DSChip>корректировка</DSChip>,'возврат контракта C-188','− 8 000 ₽', <DSStatus variant="active">начислено</DSStatus>],
      ]} />
    </MgrShell>
  );
}

// ─────────── РЕЕСТР ВЫПЛАТ ───────────
function MgrPayments() {
  return (
    <MgrShell activeId="pay" title="Реестр выплат" subtitle="апрель 2026 · 148 партнёров · 12.4 М ₽"
      actions={<React.Fragment><DSButton variant="outlined">⤓ XLSX</DSButton><DSButton variant="filled" danger>Закрыть период</DSButton></React.Fragment>}>
      <DSCard variant="elevated">
        <div style={{ padding: '14px 18px', display: 'flex', alignItems: 'center', gap: 14, borderBottom: '1px solid var(--ds-outline-variant)' }}>
          <DSChip variant="success">черновик готов</DSChip>
          <DSChip>148 партнёров</DSChip>
          <DSChip>12 412 530 ₽</DSChip>
          <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
            <DSChip onClick={()=>{}} active>все</DSChip>
            <DSChip onClick={()=>{}}>к выплате</DSChip>
            <DSChip onClick={()=>{}}>заморожены</DSChip>
            <DSChip onClick={()=>{}}>выплачено</DSChip>
          </div>
        </div>
        <table className="ds-table">
          <thead><tr><th style={{width: 28}}><DSCheckbox checked={false}/></th><th>партнёр</th><th>счёт</th><th style={{textAlign:'right'}}>начислено</th><th style={{textAlign:'right'}}>удержано</th><th style={{textAlign:'right'}}>к выплате</th><th>статус</th></tr></thead>
          <tbody>
            {[
              ['Иванов И.','**** 4812', '428 400', '0', '428 400', 'wait'],
              ['Сидорова А.','**** 1198', '580 000', '0', '580 000', 'wait'],
              ['Карпов М.','**** 8721', '276 000', '0', '276 000', 'wait'],
              ['Петров П.','**** 5532', '692 000', '0', '692 000', 'wait'],
              ['Лебедева Н.','**** 3344', '68 000', '0', '68 000', 'wait'],
              ['Иванов А.','**** 9921', '180 000', '180 000', '0', 'err'],
              ['Никитин В.','**** 7733', '124 000', '0', '124 000', 'active'],
            ].map((r, i) => (
              <tr key={i}>
                <td><DSCheckbox checked={false}/></td>
                <td><div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials={r[0].split(' ').map(p=>p[0]).join('').slice(0,2)} size="sm"/>{r[0]}</div></td>
                <td className="ds-mono ds-muted">{r[1]}</td>
                <td className="ds-mono" style={{textAlign:'right'}}>{r[2]} ₽</td>
                <td className="ds-mono" style={{textAlign:'right', color: r[3] !== '0' ? 'var(--ds-error)' : 'var(--ds-on-surface-muted)'}}>{r[3]} ₽</td>
                <td className="ds-mono" style={{textAlign:'right', fontWeight:700, color:'var(--ds-primary)'}}>{r[5]} ₽</td>
                <td>{r[6] === 'wait' ? <DSStatus variant="info">в реестре</DSStatus> : r[6] === 'err' ? <DSStatus variant="err">заморожен</DSStatus> : <DSStatus variant="active">выплачено</DSStatus>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── ПОДДЕРЖКА / ЧАТ СТАФФ ───────────
function MgrSupport() {
  return (
    <MgrShell activeId="sup" title="Поддержка" subtitle="8 открытых тикетов · средний ответ — 6 мин">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
        {[
          { lbl: 'открытые', v: '8', c: 'var(--ds-warning)' },
          { lbl: 'на мне', v: '3', c: 'var(--ds-primary)' },
          { lbl: 'SLA первый ответ', v: '6 мин', c: 'var(--ds-success)' },
          { lbl: 'CSAT за неделю', v: '4.8 / 5', c: 'var(--ds-success)' },
        ].map((k, i) => (
          <DSCard key={i} variant="elevated"><div style={{padding:14}}>
            <div className="ds-label-m ds-muted">{k.lbl}</div>
            <div className="ds-headline-s ds-mono" style={{marginTop:4, color: k.c}}>{k.v}</div>
          </div></DSCard>
        ))}
      </div>
      <FinTable cols={[
        {label:'тикет', mono:true},{label:'партнёр'},{label:'тема'},{label:'категория'},{label:'возраст'},{label:'ответственный'},{label:'статус'},
      ]} rows={[
        ['T-0481','Иванов И.','Не загружается документ','Технический','12 мин','МК',<DSStatus variant="warn">в работе</DSStatus>],
        ['T-0480','Петрова А.','Вопрос по комиссии','Финансы','34 мин','—',<DSStatus variant="err">новый</DSStatus>],
        ['T-0479','Сидоров Д.','Как открыть PRE-IPO','Продукты','1 ч','ЖО',<DSStatus variant="info">обучение</DSStatus>],
        ['T-0478','Морозова Е.','Реестр не сходится','Финансы','2 ч','МК',<DSStatus variant="warn">в работе</DSStatus>],
      ]} />
    </MgrShell>
  );
}

// ─────────── АНКЕТЫ ПАРТНЁРОВ ───────────
function MgrQuestionnaires() {
  return (
    <MgrShell activeId="pr" title="Анкеты партнёров" subtitle="12 заявок · 4 ждут проверки">
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        {[
          { name: 'Петрова Ольга', city: 'Москва', exp: '5 лет в страховании', who: 'Иванов И.', age: '2 ч' },
          { name: 'Морозов Иван', city: 'СПб', exp: 'Без опыта', who: 'Сидорова А.', age: '5 ч' },
          { name: 'Соколова Анна', city: 'Казань', exp: '8 лет в банковской сфере', who: 'Карпов М.', age: '1 д' },
          { name: 'Никитин Андрей', city: 'Москва', exp: '3 года финансового консультирования', who: 'Петров П.', age: '2 д' },
        ].map((q, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <DSAvatar initials={q.name.split(' ').map(p=>p[0]).slice(0,2).join('')} size="lg" />
                <div style={{ flex: 1 }}>
                  <div className="ds-title-l">{q.name}</div>
                  <div className="ds-body-s ds-muted">{q.city} · {q.exp}</div>
                </div>
                <DSStatus variant="warn">ждёт</DSStatus>
              </div>
              <div className="ds-body-s ds-muted" style={{ marginTop: 14, padding: '10px 12px', background: 'var(--ds-surface-container-low)', borderRadius: 8 }}>
                Приглашён партнёром: <b>{q.who}</b> · подана {q.age} назад
              </div>
              <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                <DSButton variant="filled" style={{ flex: 1 }}>Одобрить</DSButton>
                <DSButton variant="outlined">Уточнить</DSButton>
                <DSButton variant="text" danger>Отклонить</DSButton>
              </div>
            </div>
          </DSCard>
        ))}
      </div>
    </MgrShell>
  );
}

// ─────────── РЕКВИЗИТЫ ───────────
function MgrRequisites() {
  return (
    <MgrShell activeId="pr" title="Реквизиты" subtitle="банковские реквизиты партнёров">
      <FinTable cols={[
        {label:'партнёр'},{label:'банк'},{label:'счёт', mono:true},{label:'ИНН', mono:true},{label:'статус'},{label:''},
      ]} rows={[
        ['Иванов И.', 'Тинькофф Банк', '4081 7810 *** 4812', '7707083893', <DSStatus variant="active">подтверждён</DSStatus>, <DSButton variant="text" size="sm">✎</DSButton>],
        ['Сидорова А.', 'Сбербанк', '4081 7810 *** 1198', '7807012345', <DSStatus variant="active">подтверждён</DSStatus>, <DSButton variant="text" size="sm">✎</DSButton>],
        ['Карпов М.', 'Альфа-Банк', '4081 7810 *** 8721', '7727654321', <DSStatus variant="warn">проверка</DSStatus>, <DSButton variant="text" size="sm">✎</DSButton>],
        ['Лебедева Н.', '—', '—', '—', <DSStatus variant="err">не заполнены</DSStatus>, <DSButton variant="filled" size="sm">запросить</DSButton>],
      ]} />
    </MgrShell>
  );
}

Object.assign(window, {
  MgrPeriods, MgrPeriodWizard, MgrContracts, MgrContractUpload, MgrPartners, MgrStatuses,
  MgrAcceptance, MgrTransactions, MgrImport, MgrCommissions, MgrPool, MgrQualifications,
  MgrCharges, MgrPayments, MgrSupport, MgrQuestionnaires, MgrRequisites, MgrShell, FinTable,
});
