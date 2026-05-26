// ds-missing-manager.jsx — недостающие экраны менеджерского кабинета
// PermissionsMatrix · PeriodCard · Transfers · EducationConstructor ·
// KbConstructor · HomeworkQueue · ChatAnalytics · StaffChatKanban ·
// Reports · EducationAnalytics

// ─────────── PERMISSIONS MATRIX ───────────
function MgrPermissions() {
  const groups = [
    { key: 'admin', name: 'Администратор', system: true, desc: 'полный доступ', perms: ['full','full','full','full','full','full','full','full'] },
    { key: 'head', name: 'Руководитель', system: true, desc: 'аналитика + чтение', perms: ['edit','read','edit','edit','full','full','edit','read'] },
    { key: 'finance', name: 'Фин. менеджер', system: false, desc: 'расчёт + выплаты', perms: ['edit','full','full','read','read','full',null,null] },
    { key: 'backoffice', name: 'Бэк-офис', system: false, desc: 'контракты + клиенты', perms: ['full','read','read','read','full','read','read','read'] },
    { key: 'support', name: 'Поддержка', system: false, desc: 'тикеты + база знаний', perms: [null,null,null,null,'read',null,'full','full'] },
    { key: 'calc', name: 'Расчёт', system: false, desc: 'комиссии, без правок', perms: ['read','read','edit','read','read','read',null,null] },
    { key: 'corr', name: 'Правки', system: false, desc: 'корректировки', perms: ['edit','edit','edit','read','read','read',null,null] },
    { key: 'edu', name: 'Куратор обучения', system: false, desc: 'курсы + домашки', perms: [null,null,null,null,'edit',null,'edit','full'] },
  ];
  const sections = ['Контракты','Транзакции','Комиссии','Партнёры','Клиенты','Выплаты','Тикеты','Обучение'];
  const levelLabel = { read: 'Просмотр', edit: 'Правка', full: 'Полный' };
  const levelClass = { read: 'ds-status--neutral', edit: 'ds-status--info', full: 'ds-status--active' };

  return (
    <MgrShell activeId="set" title="Группы и права" subtitle="матрица доступа · 8 групп · 8 разделов"
      actions={<><DSButton variant="text" size="sm">＋ Раздел</DSButton><DSButton variant="filled" size="sm">＋ Группа</DSButton></>}>

      {/* legend + search */}
      <DSCard variant="elevated">
        <div style={{ padding: '14px 16px', display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap', borderBottom: '1px solid var(--ds-outline-variant)' }}>
          <div className="ds-body-s ds-muted">уровни:</div>
          <DSStatus variant="neutral">Просмотр</DSStatus>
          <DSStatus variant="info">Правка</DSStatus>
          <DSStatus variant="active">Полный</DSStatus>
          <div style={{ flex: 1 }}></div>
          <div style={{ width: 260 }}>
            <DSField prefix="⌕" placeholder="поиск группы или раздела" />
          </div>
          <div className="ds-body-s ds-muted">8 групп · 8 разделов · 47 правил</div>
        </div>

        <table className="ds-table">
          <thead>
            <tr>
              <th style={{ width: 280 }}>группа</th>
              {sections.map((s, i) => <th key={i} style={{ textAlign: 'center', fontSize: 11 }}>{s}</th>)}
              <th style={{ width: 36 }}></th>
            </tr>
          </thead>
          <tbody>
            {groups.map((g, i) => (
              <tr key={i} style={g.system ? { background: 'var(--ds-surface-container-low)' } : undefined}>
                <td>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <DSAvatar size="sm" initials={g.name.split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase()} />
                    <div>
                      <div className="ds-title-s" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        {g.name}
                        {g.system && <DSChip variant="brand">системная</DSChip>}
                      </div>
                      <div className="ds-body-s ds-muted">{g.key} · {g.desc}</div>
                    </div>
                  </div>
                </td>
                {g.perms.map((p, j) => (
                  <td key={j} style={{ textAlign: 'center' }}>
                    {p ? <span className={`ds-status ${levelClass[p]}`}>{levelLabel[p]}</span> : <span className="ds-muted">—</span>}
                  </td>
                ))}
                <td><DSButton variant="text" icon size="sm">⋮</DSButton></td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── PERIOD · CARD (детальная страница периода) ───────────
function MgrPeriodCard() {
  return (
    <MgrShell activeId="per" title="Период 2026-05" subtitle="apr 2026 · открыт · виден партнёрам"
      actions={<>
        <DSButton variant="outlined" size="sm">◀ Рабочий стол</DSButton>
        <DSButton variant="filled" danger size="sm">🔒 Закрыть период</DSButton>
      </>}>

      <DSAlert variant="info" title="Период открыт — отчёты видны партнёрам">
        Доступность управляет видимостью отчёта Партнёрам, заморозка — финальная фиксация цифр. Применённые ниже шаги уже отображаются в кабинете.
      </DSAlert>

      {/* status banner */}
      <DSCard variant="elevated">
        <div style={{ padding: 16, display: 'flex', alignItems: 'center', gap: 16 }}>
          <div style={{ width: 52, height: 52, borderRadius: 14, background: 'var(--ds-primary-container)', color: 'var(--ds-on-primary-container)', display: 'grid', placeItems: 'center', fontSize: 22 }}>📅</div>
          <div style={{ flex: 1 }}>
            <div className="ds-title-m">май 2026 · открыт</div>
            <div className="ds-body-s ds-muted">создан 01.05 · последнее обновление 25.05 14:32 · 482 контракта · 12.8 М ₽ к выплате</div>
          </div>
          <DSStatus variant="warning">в процессе</DSStatus>
          <DSButton variant="outlined" size="sm">📊 К отчёту</DSButton>
        </div>
      </DSCard>

      {/* 3 runners */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
        {/* PENALTIES */}
        <DSCard variant="elevated">
          <div style={{ padding: 18 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
              <div style={{ width: 32, height: 32, borderRadius: 8, background: 'var(--ds-error-container)', color: 'var(--ds-on-error-container)', display: 'grid', placeItems: 'center' }}>⚠</div>
              <div className="ds-title-s">Штрафы — §5</div>
            </div>
            <div className="ds-body-s ds-muted" style={{ marginBottom: 12 }}>отрыв ≥ 70% и невыполнение ОП по ГП</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 14 }}>
              <div><div className="ds-headline-s">14</div><div className="ds-body-s ds-muted">партнёров</div></div>
              <div><div className="ds-headline-s">28</div><div className="ds-body-s ds-muted">комиссий</div></div>
              <div><div className="ds-headline-s ds-mono">×0.5</div><div className="ds-body-s ds-muted">отрыв</div></div>
              <div><div className="ds-headline-s ds-mono">×0.8</div><div className="ds-body-s ds-muted">ОП по ГП</div></div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <DSButton variant="outlined" size="sm">⊙ Preview</DSButton>
              <DSButton variant="filled" danger size="sm">Применить</DSButton>
            </div>
            <div className="ds-body-s" style={{ marginTop: 10, color: 'var(--ds-on-surface)', opacity: 0.6 }}>превью обновлено 25.05 09:14</div>
          </div>
        </DSCard>

        {/* POOL */}
        <DSCard variant="elevated">
          <div style={{ padding: 18 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
              <div style={{ width: 32, height: 32, borderRadius: 8, background: 'var(--ds-primary-container)', color: 'var(--ds-on-primary-container)', display: 'grid', placeItems: 'center' }}>💎</div>
              <div className="ds-title-s">Пул — §6</div>
            </div>
            <div className="ds-body-s ds-muted" style={{ marginBottom: 12 }}>распределение фонда по уровням Senior+</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 14 }}>
              <div><div className="ds-headline-s ds-mono">12.4 М ₽</div><div className="ds-body-s ds-muted">выручка ДС</div></div>
              <div><div className="ds-headline-s ds-mono">2.48 М ₽</div><div className="ds-body-s ds-muted">фонд</div></div>
              <div><div className="ds-headline-s ds-mono">2.42 М ₽</div><div className="ds-body-s ds-muted">распределено</div></div>
              <div><div className="ds-headline-s">42</div><div className="ds-body-s ds-muted">партнёра</div></div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <DSButton variant="outlined" size="sm">⊙ Preview</DSButton>
              <DSButton variant="filled" size="sm">Применить</DSButton>
            </div>
            <div className="ds-body-s" style={{ marginTop: 10, color: 'var(--ds-on-surface)', opacity: 0.6 }}>применено 24.05 18:22</div>
          </div>
        </DSCard>

        {/* PAYMENTS */}
        <DSCard variant="elevated">
          <div style={{ padding: 18 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
              <div style={{ width: 32, height: 32, borderRadius: 8, background: 'var(--ds-secondary-container)', color: 'var(--ds-on-secondary-container)', display: 'grid', placeItems: 'center' }}>🏦</div>
              <div className="ds-title-s">Реестр выплат — §7</div>
            </div>
            <div className="ds-body-s ds-muted" style={{ marginBottom: 12 }}>сборка реестра для бухгалтерии</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 14 }}>
              <div><div className="ds-headline-s">148</div><div className="ds-body-s ds-muted">выплат</div></div>
              <div><div className="ds-headline-s ds-mono">12.8 М ₽</div><div className="ds-body-s ds-muted">сумма</div></div>
              <div><div className="ds-headline-s">142</div><div className="ds-body-s ds-muted">✓ готовы</div></div>
              <div><div className="ds-headline-s" style={{ color: 'var(--ds-warning)' }}>6</div><div className="ds-body-s ds-muted">без реквизитов</div></div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <DSButton variant="outlined" size="sm">Собрать</DSButton>
              <DSButton variant="filled" size="sm">Открыть</DSButton>
            </div>
            <div className="ds-body-s" style={{ marginTop: 10, color: 'var(--ds-on-surface)', opacity: 0.6 }}>сборка готова</div>
          </div>
        </DSCard>
      </div>

      {/* journal */}
      <DSCard variant="elevated">
        <div style={{ padding: '14px 16px', borderBottom: '1px solid var(--ds-outline-variant)' }}>
          <div className="ds-title-s">Журнал периода</div>
        </div>
        <table className="ds-table">
          <thead><tr><th>событие</th><th>исполнитель</th><th>дата</th><th>результат</th></tr></thead>
          <tbody>
            {[
              ['Импорт транзакций · Альфа-Капитал', 'Иванов И. · finance', '25.05 09:00', 'успех · 412 строк'],
              ['Расчёт комиссий', 'автомат', '25.05 09:18', '482 контракта · 12.8 М ₽'],
              ['Применение штрафов §5', 'Петрова А. · head', '24.05 18:22', '14 партнёров · 28 комиссий'],
              ['Распределение пула §6', 'Петрова А. · head', '24.05 18:30', '42 партнёра · 2.42 М ₽'],
              ['Сборка реестра выплат', 'Иванов И. · finance', '25.05 10:14', '148 выплат · 12.8 М ₽'],
              ['Открытие периода для партнёров', 'Иванов И. · finance', '25.05 12:00', 'видно всем'],
            ].map((r, i) => (
              <tr key={i}>
                <td><span className="ds-title-s">{r[0]}</span></td>
                <td>{r[1]}</td>
                <td className="ds-mono">{r[2]}</td>
                <td><DSStatus variant="active">{r[3]}</DSStatus></td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── TRANSFERS · перестановки наставников ───────────
function MgrTransfers() {
  const items = [
    { p: 'Соколов А.', from: 'Иванов И.', to: 'Петров С.', exec: 'Сидорова Е. · head', date: '25.05 14:22', reason: 'неактивный наставник 90 дн' },
    { p: 'Никитина О.', from: 'Кузнецов М.', to: 'Иванов И.', exec: 'Сидорова Е. · head', date: '24.05 18:01', reason: 'личная просьба партнёра' },
    { p: 'Васильев К.', from: '— (новый)', to: 'Орлова Т.', exec: 'Орлова Т. · backoffice', date: '24.05 11:14', reason: 'первичное назначение' },
    { p: 'Григорьев Д.', from: 'Морозов В.', to: 'Иванов И.', exec: 'Сидорова Е. · head', date: '22.05 16:48', reason: 'наставник терминирован' },
    { p: 'Лебедев Н.', from: 'Иванов И.', to: 'Петров С.', exec: 'Иванов И. · backoffice', date: '21.05 09:33', reason: 'перераспределение нагрузки' },
    { p: 'Михайлова К.', from: 'Орлова Т.', to: 'Иванов И.', exec: 'Сидорова Е. · head', date: '20.05 13:11', reason: 'кросс-региональная' },
    { p: 'Андреев П.', from: 'Орлова Т.', to: 'Петров С.', exec: 'Сидорова Е. · head', date: '18.05 10:02', reason: 'наставник в отпуске' },
  ];
  return (
    <MgrShell activeId="pr" title="История перестановок" subtitle="перенос партнёров между наставниками · 38 за май"
      actions={<><DSButton variant="text" size="sm">📥 Выгрузка</DSButton><DSButton variant="filled" size="sm">＋ Перенос</DSButton></>}>

      <DSCard variant="elevated">
        <div style={{ padding: '14px 16px', display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
          <div style={{ width: 280 }}><DSField prefix="⌕" placeholder="ФИО партнёра или наставника" /></div>
          <DSChip variant="brand" active>май 2026</DSChip>
          <DSChip>апр</DSChip>
          <DSChip>мар</DSChip>
          <div style={{ flex: 1 }}></div>
          <div className="ds-body-s ds-muted">38 переносов · 5 за неделю</div>
        </div>
      </DSCard>

      <DSCard variant="elevated">
        <table className="ds-table">
          <thead><tr><th>партнёр</th><th>от</th><th></th><th>к</th><th>исполнитель</th><th>дата</th><th>причина</th></tr></thead>
          <tbody>
            {items.map((r, i) => (
              <tr key={i}>
                <td><span className="ds-title-s">{r.p}</span></td>
                <td><div style={{ display: 'flex', alignItems: 'center', gap: 6 }}><DSAvatar size="xs" initials={r.from === '— (новый)' ? '?' : r.from.split(' ').map(w=>w[0]).join('')} /><span>{r.from}</span></div></td>
                <td className="ds-muted" style={{ textAlign: 'center' }}>→</td>
                <td><div style={{ display: 'flex', alignItems: 'center', gap: 6 }}><DSAvatar size="xs" initials={r.to.split(' ').map(w=>w[0]).join('')} /><span>{r.to}</span></div></td>
                <td><span className="ds-body-s">{r.exec}</span></td>
                <td className="ds-mono">{r.date}</td>
                <td><span className="ds-body-s ds-muted">{r.reason}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── EDUCATION CONSTRUCTOR ───────────
function MgrEducationConstructor() {
  const tree = [
    { lvl: 0, t: '★ Онбординг партнёра', s: '6 модулей · 18 уроков', sel: false, exp: true },
    { lvl: 1, t: 'Старт', sel: false },
    { lvl: 1, t: 'Продукты ДС', sel: false, exp: true },
    { lvl: 2, t: 'Эволюция', sel: false },
    { lvl: 2, t: 'Накопления +', sel: true },
    { lvl: 2, t: 'Финал', sel: false, lock: true },
    { lvl: 0, t: '★ Расчёт квалификаций', s: '4 модуля · 12 уроков', sel: false, exp: false },
    { lvl: 0, t: '★ Работа с клиентами', s: '5 модулей · 15 уроков', sel: false, exp: false },
    { lvl: 0, t: '★ Mentorstvo', s: '3 модуля · 9 уроков', sel: false, exp: false },
  ];

  return (
    <MgrShell activeId="edu" title="Конструктор обучения" subtitle="курсы · модули · уроки · тесты"
      actions={<><DSButton variant="text" size="sm">📥 Импорт</DSButton><DSButton variant="filled" size="sm">＋ Курс</DSButton></>}>

      <div style={{ display: 'grid', gridTemplateColumns: '320px 1fr', gap: 16, height: 700 }}>
        {/* TREE */}
        <DSCard variant="elevated" style={{ overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
          <div style={{ padding: 12, borderBottom: '1px solid var(--ds-outline-variant)' }}>
            <DSField prefix="⌕" placeholder="найти урок" />
          </div>
          <div style={{ overflow: 'auto', flex: 1, padding: '8px 0' }}>
            {tree.map((n, i) => (
              <div key={i} style={{
                padding: '7px 12px', paddingLeft: 12 + n.lvl * 18,
                background: n.sel ? 'var(--ds-primary-container)' : 'transparent',
                color: n.sel ? 'var(--ds-on-primary-container)' : 'inherit',
                cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 8,
                borderLeft: n.sel ? '3px solid var(--ds-primary)' : '3px solid transparent',
              }}>
                <span className="ds-muted" style={{ fontSize: 10, width: 12 }}>{n.lvl === 0 ? (n.exp ? '▾' : '▸') : ''}</span>
                <span style={{ flex: 1 }}>
                  <span className={n.lvl === 0 ? 'ds-title-s' : 'ds-body-m'}>{n.t}</span>
                  {n.s && <div className="ds-body-s ds-muted">{n.s}</div>}
                </span>
                {n.lock && <span className="ds-body-s ds-muted">🔒</span>}
              </div>
            ))}
            <div style={{ padding: '8px 12px' }}>
              <DSButton variant="text" size="sm" block>＋ Добавить узел</DSButton>
            </div>
          </div>
        </DSCard>

        {/* EDITOR */}
        <DSCard variant="elevated" style={{ overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--ds-outline-variant)', display: 'flex', alignItems: 'center', gap: 10 }}>
            <span className="ds-body-s ds-muted">Онбординг → Продукты ДС →</span>
            <span className="ds-title-m">Накопления +</span>
            <DSStatus variant="info">черновик</DSStatus>
            <div style={{ flex: 1 }}></div>
            <DSButton variant="outlined" size="sm">👁 Preview</DSButton>
            <DSButton variant="text" size="sm">Удалить</DSButton>
            <DSButton variant="filled" size="sm">Сохранить</DSButton>
          </div>

          <div style={{ overflow: 'auto', padding: 18, display: 'flex', flexDirection: 'column', gap: 14 }}>
            <DSTabs items={['Содержание','Тест','Доступ','Аналитика']} active={0} />

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              <DSField label="Название урока" defaultValue="Накопления + · программа на 10 лет" />
              <DSField label="Slug" defaultValue="accumulate-plus" prefix="/" />
              <DSField label="Длительность" defaultValue="12 минут" />
              <DSField label="Тип" defaultValue="видео + текст + домашка" />
            </div>

            <div>
              <div className="ds-label-m" style={{ marginBottom: 6 }}>описание</div>
              <DSField textarea defaultValue="Расскажем, кому подходит программа «Накопления +», какие у неё гарантии и как считать комиссию для разных сроков." />
            </div>

            <div>
              <div className="ds-label-m" style={{ marginBottom: 6 }}>блоки</div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {[
                  { ico: '▶', t: 'Видео-вступление · 4:12', d: 'https://vimeo.com/123…' },
                  { ico: '✎', t: 'Текстовый блок', d: '«Программа подходит клиентам 25-50 лет…» — 1 482 знака' },
                  { ico: '🖼', t: 'Инфографика · структура', d: 'PNG 1080×720 · загружено 18.05' },
                  { ico: '📎', t: 'PDF · условия', d: 'accumulate-plus-2026.pdf · 248 КБ' },
                  { ico: '✏', t: 'Домашка · файл + комментарий', d: 'модератор: Куратор обучения' },
                ].map((b, i) => (
                  <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 12, border: '1px solid var(--ds-outline-variant)', borderRadius: 10, background: 'var(--ds-surface-container-lowest)' }}>
                    <div style={{ width: 32, height: 32, borderRadius: 8, background: 'var(--ds-surface-container)', display: 'grid', placeItems: 'center' }}>{b.ico}</div>
                    <div style={{ flex: 1 }}>
                      <div className="ds-title-s">{b.t}</div>
                      <div className="ds-body-s ds-muted">{b.d}</div>
                    </div>
                    <DSButton variant="text" icon size="sm">↕</DSButton>
                    <DSButton variant="text" icon size="sm">✎</DSButton>
                    <DSButton variant="text" icon size="sm">×</DSButton>
                  </div>
                ))}
                <DSButton variant="outlined" size="sm">＋ Блок</DSButton>
              </div>
            </div>
          </div>
        </DSCard>
      </div>
    </MgrShell>
  );
}

// ─────────── KB CONSTRUCTOR ───────────
function MgrKbConstructor() {
  return (
    <MgrShell activeId="edu" title="База знаний · конструктор" subtitle="регламенты · инструкции · записи созвонов"
      actions={<><DSButton variant="text" size="sm">＋ Раздел</DSButton><DSButton variant="filled" size="sm">＋ Статья</DSButton></>}>

      <div style={{ display: 'grid', gridTemplateColumns: '300px 1fr', gap: 16, height: 700 }}>
        {/* SECTIONS */}
        <DSCard variant="elevated" style={{ overflow: 'auto' }}>
          <div style={{ padding: 12, borderBottom: '1px solid var(--ds-outline-variant)' }}>
            <DSField prefix="⌕" placeholder="найти раздел" />
          </div>
          {[
            { ico: '📋', t: 'Регламенты', n: 28, sel: false },
            { ico: '📘', t: 'Инструкции', n: 42, sel: true },
            { ico: '🎙', t: 'Записи созвонов', n: 18, sel: false },
            { ico: '🎓', t: 'Деловые игры', n: 9, sel: false },
            { ico: '⚖', t: 'Документы', n: 14, sel: false },
            { ico: '💡', t: 'FAQ партнёров', n: 36, sel: false },
          ].map((s, i) => (
            <div key={i} style={{
              padding: '12px 14px',
              background: s.sel ? 'var(--ds-primary-container)' : 'transparent',
              color: s.sel ? 'var(--ds-on-primary-container)' : 'inherit',
              borderLeft: s.sel ? '3px solid var(--ds-primary)' : '3px solid transparent',
              display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer',
            }}>
              <span style={{ fontSize: 18 }}>{s.ico}</span>
              <div style={{ flex: 1 }}>
                <div className="ds-title-s">{s.t}</div>
                <div className="ds-body-s ds-muted">{s.n} материалов</div>
              </div>
            </div>
          ))}
        </DSCard>

        {/* ARTICLE EDITOR */}
        <DSCard variant="elevated" style={{ overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--ds-outline-variant)', display: 'flex', alignItems: 'center', gap: 10 }}>
            <span className="ds-title-m">Инструкции · 42 статьи</span>
            <DSChip>опубликовано · 38</DSChip>
            <DSChip variant="warning">черновик · 4</DSChip>
            <div style={{ flex: 1 }}></div>
            <DSButton variant="filled" size="sm">＋ Новая статья</DSButton>
          </div>

          <div style={{ overflow: 'auto', padding: 16, display: 'flex', flexDirection: 'column', gap: 8 }}>
            {[
              { t: 'Как создать контракт', tags: ['контракты','инструкция'], v: true, d: '25.05 · Иванов И.' },
              { t: 'Загрузка транзакций — пошагово', tags: ['финансы','импорт'], v: true, d: '22.05 · Петрова А.' },
              { t: 'Реквизиты ИП — проверка', tags: ['реквизиты'], v: true, d: '20.05 · Иванов И.', video: true },
              { t: 'Работа со штрафами §5', tags: ['финансы','штрафы'], v: false, d: '24.05 · Сидорова Е.' },
              { t: 'Квалификация — переход на Senior', tags: ['квалификация'], v: true, d: '18.05 · Иванов И.', video: true },
              { t: 'Пул — что это и как считается', tags: ['пул','финансы'], v: false, d: '15.05 · Петрова А.' },
              { t: 'Перенос партнёра к другому наставнику', tags: ['структура'], v: true, d: '10.05 · Иванов И.' },
              { t: 'Анкета онбординга — обязательность', tags: ['онбординг'], v: true, d: '08.05 · Сидорова Е.' },
            ].map((a, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 14, border: '1px solid var(--ds-outline-variant)', borderRadius: 12, background: 'var(--ds-surface-container-lowest)' }}>
                <div style={{ width: 36, height: 36, borderRadius: 8, background: 'var(--ds-surface-container)', display: 'grid', placeItems: 'center', flexShrink: 0 }}>{a.video ? '▶' : '📄'}</div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div className="ds-title-s" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    {a.t}
                    {!a.v && <DSChip variant="warning">черновик</DSChip>}
                  </div>
                  <div style={{ display: 'flex', gap: 6, marginTop: 4, alignItems: 'center', flexWrap: 'wrap' }}>
                    {a.tags.map(t => <DSChip key={t}>#{t}</DSChip>)}
                    <span className="ds-body-s ds-muted">· обновлено {a.d}</span>
                  </div>
                </div>
                <DSButton variant="text" size="sm">✎</DSButton>
                <DSButton variant="text" icon size="sm">⋮</DSButton>
              </div>
            ))}
          </div>
        </DSCard>
      </div>
    </MgrShell>
  );
}

// ─────────── HOMEWORK QUEUE ───────────
function MgrHomework() {
  const items = [
    { p: 'Соколов А.', l: 'Продукт «Эволюция» · домашка', date: '25.05 12:14', wait: '2ч 18мин', urgent: true, ago: 'Файл + комментарий' },
    { p: 'Никитина О.', l: 'Накопления + · подбор клиента', date: '25.05 09:02', wait: '5ч', ago: 'Голосовая + 2 фото' },
    { p: 'Васильев К.', l: 'Базовый расчёт комиссии', date: '24.05 18:48', wait: '20ч', ago: 'Файл' },
    { p: 'Григорьев Д.', l: 'Возражения · кейс', date: '24.05 14:22', wait: '1д', ago: 'Текст 1 800 знаков' },
    { p: 'Лебедев Н.', l: 'Презентация программы', date: '23.05 11:18', wait: '2д', ago: 'PDF · 4 МБ' },
    { p: 'Михайлова К.', l: 'Mentorstvo · упражнение', date: '22.05 16:00', wait: '3д', ago: 'Файл + видео' },
  ];

  return (
    <MgrShell activeId="edu" title="Домашние задания" subtitle="очередь модератора · 6 ждут · среднее ожидание 18ч"
      actions={<>
        <DSChip variant="brand" active>Все · 6</DSChip>
        <DSChip>Срочно · 1</DSChip>
        <DSChip>Сегодня · 2</DSChip>
        <DSChip>Старше 24ч · 3</DSChip>
      </>}>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
        {[['6','в очереди','warning'],['2ч 18мин','самая старая','warning'],['148','одобрено за май','active'],['12','отклонено','neutral']].map((m, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 16 }}>
              <div className="ds-headline-s ds-mono" style={{ color: m[2] === 'warning' ? 'var(--ds-warning)' : 'var(--ds-on-surface)' }}>{m[0]}</div>
              <div className="ds-body-s ds-muted" style={{ marginTop: 2 }}>{m[1]}</div>
            </div>
          </DSCard>
        ))}
      </div>

      <DSCard variant="elevated">
        <table className="ds-table">
          <thead><tr><th>партнёр</th><th>урок</th><th>отправлено</th><th>ожидание</th><th>что прислал</th><th style={{ textAlign: 'right', width: 220 }}></th></tr></thead>
          <tbody>
            {items.map((r, i) => (
              <tr key={i} style={r.urgent ? { background: 'rgba(245, 158, 11, 0.05)' } : undefined}>
                <td>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <DSAvatar size="sm" initials={r.p.split(' ').map(w=>w[0]).join('').toUpperCase()} />
                    <span className="ds-title-s">{r.p}</span>
                  </div>
                </td>
                <td>{r.l}</td>
                <td className="ds-mono">{r.date}</td>
                <td>
                  {r.urgent
                    ? <DSStatus variant="warning">{r.wait}</DSStatus>
                    : <span className="ds-mono ds-muted">{r.wait}</span>}
                </td>
                <td><span className="ds-body-s ds-muted">{r.ago}</span></td>
                <td style={{ textAlign: 'right' }}>
                  <DSButton variant="text" size="sm">👁</DSButton>
                  <DSButton variant="outlined" size="sm">Отклонить</DSButton>{' '}
                  <DSButton variant="filled" size="sm">Одобрить</DSButton>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── EDUCATION ANALYTICS ───────────
function MgrEducationAnalytics() {
  const courses = [
    ['Онбординг партнёра', 142, 124, 18, 96],
    ['Расчёт квалификаций', 138, 102, 26, 84],
    ['Работа с клиентами', 142, 88, 38, 72],
    ['Mentorstvo', 32, 22, 9, 88],
    ['Продукты ДС', 142, 132, 8, 92],
  ];
  return (
    <MgrShell activeId="edu" title="Статистика обучения" subtitle="прогресс партнёров · май 2026">
      {/* KPI */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
        {[
          ['142','записаны','primary'],
          ['86%','средний прогресс','active'],
          ['368','тестов сдано','primary'],
          ['12.4 ч','среднее время курса','primary'],
        ].map((m, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18 }}>
              <div className="ds-headline-m ds-mono" style={{ color: m[2] === 'active' ? 'var(--ds-success)' : 'var(--ds-on-surface)' }}>{m[0]}</div>
              <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{m[1]}</div>
            </div>
          </DSCard>
        ))}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 14 }}>
        {/* Course progress */}
        <DSCard variant="elevated">
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--ds-outline-variant)' }}>
            <div className="ds-title-s">Прогресс по курсам</div>
            <div className="ds-body-s ds-muted">записаны · в процессе · завершили · тестов сдано %</div>
          </div>
          <table className="ds-table">
            <thead><tr><th>курс</th><th style={{ textAlign: 'right' }}>записаны</th><th style={{ textAlign: 'right' }}>в процессе</th><th style={{ textAlign: 'right' }}>завершили</th><th style={{ width: 220 }}>прогресс</th></tr></thead>
            <tbody>
              {courses.map((r, i) => {
                const pct = Math.round((r[1] - r[2] - r[3]) / r[1] * 100);
                return (
                  <tr key={i}>
                    <td><span className="ds-title-s">{r[0]}</span></td>
                    <td className="ds-mono" style={{ textAlign: 'right' }}>{r[1]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right' }}>{r[3]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right' }}>{r[1] - r[2] - r[3]}</td>
                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <div style={{ flex: 1 }}><DSProgress value={pct} variant="success" height={6} /></div>
                        <span className="ds-mono" style={{ width: 36, textAlign: 'right' }}>{pct}%</span>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </DSCard>

        {/* Top students */}
        <DSCard variant="elevated">
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--ds-outline-variant)' }}>
            <div className="ds-title-s">Лидеры</div>
            <div className="ds-body-s ds-muted">кто пройдёт все курсы быстрее</div>
          </div>
          <div style={{ padding: 6 }}>
            {[
              ['Сидорова А.', 96, 'Senior'],
              ['Иванов П.', 92, 'Mid'],
              ['Васильева Н.', 88, 'Junior'],
              ['Кузнецов О.', 84, 'Mid'],
              ['Морозов В.', 78, 'Mid'],
            ].map((r, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px' }}>
                <div style={{ width: 24, height: 24, borderRadius: 6, background: i === 0 ? 'var(--ds-primary)' : 'var(--ds-surface-container)', color: i === 0 ? 'var(--ds-on-primary)' : 'inherit', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 12 }}>{i + 1}</div>
                <DSAvatar size="sm" initials={r[0].split(' ').map(w=>w[0]).join('')} />
                <div style={{ flex: 1 }}>
                  <div className="ds-title-s">{r[0]}</div>
                  <div className="ds-body-s ds-muted">{r[2]} · {r[1]}%</div>
                </div>
              </div>
            ))}
          </div>
        </DSCard>
      </div>
    </MgrShell>
  );
}

// ─────────── CHAT ANALYTICS ───────────
function MgrChatAnalytics() {
  const cats = [
    ['Поддержка', 128, 0.42],
    ['Финансы', 96, 0.32],
    ['Контракты', 48, 0.16],
    ['Развитие', 22, 0.07],
    ['Жалобы', 9, 0.03],
  ];
  const ops = [
    ['Орлова Т.', 142, 4.2, 96],
    ['Петрова А.', 128, 5.1, 92],
    ['Иванов И.', 96, 6.8, 88],
    ['Сидорова Е.', 84, 5.4, 90],
  ];
  // simple polyline points for the chart
  const pts = [20, 35, 28, 48, 52, 42, 58, 64, 70, 58, 72, 80];
  const resPts = [22, 30, 32, 38, 42, 38, 50, 56, 62, 60, 68, 72];

  return (
    <MgrShell activeId="sup" title="Аналитика чата" subtitle="метрики ответов и нагрузки · последние 30 дней"
      actions={<>
        <DSChip>сегодня</DSChip>
        <DSChip>неделя</DSChip>
        <DSChip variant="brand" active>месяц</DSChip>
        <DSChip>квартал</DSChip>
        <DSButton variant="outlined" size="sm">📥 Отчёт смены</DSButton>
      </>}>

      {/* KPI */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
        {[
          { v: '303', l: 'всего тикетов', d: '+12% к апрелю', good: true },
          { v: '4 мин', l: 'ср. время ответа', d: 'цель: 5 мин', good: true },
          { v: '6.4 ч', l: 'ср. время решения', d: 'цель: 8 ч', good: true },
          { v: '4', l: 'SLA нарушено', d: '< 2%', good: false },
        ].map((m, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18 }}>
              <div className="ds-headline-m ds-mono">{m.v}</div>
              <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{m.l}</div>
              <div className="ds-body-s" style={{ marginTop: 6, color: m.good ? 'var(--ds-success)' : 'var(--ds-error)' }}>{m.good ? '✓' : '!'} {m.d}</div>
            </div>
          </DSCard>
        ))}
      </div>

      {/* status counters */}
      <DSCard variant="elevated">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6, 1fr)' }}>
          {[
            ['открыт', 18, 'var(--ds-info)'],
            ['в работе', 24, 'var(--ds-primary)'],
            ['ожидание', 12, 'var(--ds-warning)'],
            ['решён', 196, 'var(--ds-success)'],
            ['закрыт', 53, 'var(--ds-on-surface)'],
            ['всего', 303, 'var(--ds-on-surface)'],
          ].map((c, i) => (
            <div key={i} style={{ padding: 16, borderTop: `3px solid ${c[2]}`, borderRight: i < 5 ? '1px solid var(--ds-outline-variant)' : 'none' }}>
              <div className="ds-label-m ds-muted">{c[0]}</div>
              <div className="ds-headline-s ds-mono" style={{ marginTop: 4 }}>{c[1]}</div>
            </div>
          ))}
        </div>
      </DSCard>

      <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr', gap: 14 }}>
        {/* Chart */}
        <DSCard variant="elevated">
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--ds-outline-variant)' }}>
            <div className="ds-title-s">Динамика по дням</div>
            <div className="ds-body-s ds-muted">новые тикеты vs решённые</div>
          </div>
          <div style={{ padding: 18 }}>
            <svg viewBox="0 0 600 220" style={{ width: '100%', height: 220 }}>
              <defs>
                <linearGradient id="g1" x1="0" x2="0" y1="0" y2="1">
                  <stop offset="0%" stopColor="var(--ds-primary)" stopOpacity="0.25" />
                  <stop offset="100%" stopColor="var(--ds-primary)" stopOpacity="0" />
                </linearGradient>
              </defs>
              {/* grid */}
              {[40, 80, 120, 160].map((y, i) => <line key={i} x1="0" x2="600" y1={y} y2={y} stroke="var(--ds-outline-variant)" strokeWidth="1" />)}
              {/* new tickets area */}
              <path d={`M 0 ${220 - pts[0]*2} ${pts.map((p, i) => `L ${(i * 600) / (pts.length - 1)} ${220 - p*2}`).join(' ')} L 600 220 L 0 220 Z`} fill="url(#g1)" />
              <path d={`M 0 ${220 - pts[0]*2} ${pts.map((p, i) => `L ${(i * 600) / (pts.length - 1)} ${220 - p*2}`).join(' ')}`} stroke="var(--ds-primary)" strokeWidth="2" fill="none" />
              <path d={`M 0 ${220 - resPts[0]*2} ${resPts.map((p, i) => `L ${(i * 600) / (resPts.length - 1)} ${220 - p*2}`).join(' ')}`} stroke="var(--ds-success)" strokeWidth="2" strokeDasharray="4 3" fill="none" />
              {/* points */}
              {pts.map((p, i) => <circle key={i} cx={(i * 600) / (pts.length - 1)} cy={220 - p*2} r="3" fill="var(--ds-primary)" />)}
            </svg>
            <div style={{ display: 'flex', gap: 18, marginTop: 8, fontSize: 12 }}>
              <span><span style={{ display: 'inline-block', width: 10, height: 10, borderRadius: 2, background: 'var(--ds-primary)', marginRight: 6 }}></span>новые</span>
              <span><span style={{ display: 'inline-block', width: 10, height: 10, borderRadius: 2, background: 'var(--ds-success)', marginRight: 6 }}></span>решённые</span>
            </div>
          </div>
        </DSCard>

        {/* Categories */}
        <DSCard variant="elevated">
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--ds-outline-variant)' }}>
            <div className="ds-title-s">Категории</div>
            <div className="ds-body-s ds-muted">распределение тикетов</div>
          </div>
          <div style={{ padding: 14 }}>
            {cats.map((c, i) => (
              <div key={i} style={{ marginBottom: 12 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                  <span className="ds-body-m">{c[0]}</span>
                  <span className="ds-mono ds-muted">{c[1]} · {Math.round(c[2] * 100)}%</span>
                </div>
                <DSProgress value={c[2] * 100} variant="primary" height={8} />
              </div>
            ))}
          </div>
        </DSCard>
      </div>

      {/* Top operators */}
      <DSCard variant="elevated">
        <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--ds-outline-variant)' }}>
          <div className="ds-title-s">Топ-операторов</div>
          <div className="ds-body-s ds-muted">тикетов закрыто · среднее время ответа (мин) · CSAT %</div>
        </div>
        <table className="ds-table">
          <thead><tr><th>оператор</th><th style={{ textAlign: 'right' }}>закрыто</th><th style={{ textAlign: 'right' }}>ср. ответ</th><th style={{ textAlign: 'right' }}>CSAT</th><th style={{ width: 240 }}>загрузка</th></tr></thead>
          <tbody>
            {ops.map((r, i) => (
              <tr key={i}>
                <td>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <DSAvatar size="sm" initials={r[0].split(' ').map(w=>w[0]).join('')} />
                    <span className="ds-title-s">{r[0]}</span>
                  </div>
                </td>
                <td className="ds-mono" style={{ textAlign: 'right' }}>{r[1]}</td>
                <td className="ds-mono" style={{ textAlign: 'right' }}>{r[2]}</td>
                <td className="ds-mono" style={{ textAlign: 'right' }}><span style={{ color: 'var(--ds-success)' }}>{r[3]}%</span></td>
                <td><DSProgress value={(r[1] / 142) * 100} variant="primary" height={6} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </MgrShell>
  );
}

// ─────────── STAFF CHAT — KANBAN ───────────
function MgrStaffChatKanban() {
  const cols = [
    { id: 'open', t: 'Открыт', n: 18, c: 'var(--ds-info)', cards: [
      { s: 'Не могу загрузить транзакции', cat: 'Финансы', p: 'high', a: 'Орлова Т.', cust: 'Иванов И.', ago: '14 мин' },
      { s: 'Куда уходит «отрыв 70%»?', cat: 'Финансы', p: 'medium', cust: 'Петров С.', ago: '32 мин' },
      { s: 'Контракт удалился из ЛК', cat: 'Контракты', p: 'high', cust: 'Сидоров К.', ago: '1ч' },
    ]},
    { id: 'work', t: 'В работе', n: 24, c: 'var(--ds-primary)', cards: [
      { s: 'Подтверждение Telegram', cat: 'Поддержка', p: 'low', a: 'Иванов И.', cust: 'Никитина О.', ago: '2ч' },
      { s: 'Перенос наставника', cat: 'Поддержка', p: 'medium', a: 'Иванов И.', cust: 'Лебедев Н.', ago: '4ч', csat: 5 },
      { s: 'Двойной импорт транзакции', cat: 'Финансы', p: 'critical', incident: true, a: 'Орлова Т.', cust: 'Морозов В.', ago: '3ч' },
    ]},
    { id: 'wait', t: 'Ожидание', n: 12, c: 'var(--ds-warning)', cards: [
      { s: 'Проверка паспорта', cat: 'Поддержка', p: 'medium', a: 'Орлова Т.', cust: 'Васильев К.', ago: '6ч' },
      { s: 'Ждём документы ИП', cat: 'Поддержка', p: 'low', a: 'Орлова Т.', cust: 'Григорьев Д.', ago: '1д' },
    ]},
    { id: 'res', t: 'Решён', n: 196, c: 'var(--ds-success)', cards: [
      { s: 'Сброс пароля', cat: 'Поддержка', p: 'low', a: 'Иванов И.', cust: 'Михайлова К.', ago: '5 мин', csat: 5 },
      { s: 'Калькулятор объёмов — пояснение', cat: 'Финансы', p: 'low', a: 'Петрова А.', cust: 'Андреев П.', ago: '20 мин', csat: 4 },
    ]},
    { id: 'closed', t: 'Закрыт', n: 53, c: 'var(--ds-on-surface)', cards: [
      { s: 'Регистрация по реф-ссылке', cat: 'Поддержка', p: 'low', a: 'Иванов И.', cust: 'Соколов А.', ago: '1д' },
    ]},
  ];
  const prColor = { critical: 'var(--ds-error)', high: 'var(--ds-warning)', medium: 'var(--ds-primary)', low: 'var(--ds-on-surface)' };
  const prLabel = { critical: 'crit', high: 'high', medium: 'med', low: 'low' };

  return (
    <MgrShell activeId="sup" title="Тикеты · staff" subtitle="канбан-режим · 303 за месяц · 4 SLA-нарушения"
      actions={<>
        <DSChip variant="brand" active>канбан</DSChip>
        <DSChip>список</DSChip>
        <div style={{ width: 260 }}><DSField prefix="⌕" placeholder="поиск тикета или партнёра" /></div>
        <DSButton variant="filled" size="sm">＋ Тикет</DSButton>
      </>}>

      {/* smart views */}
      <DSCard variant="elevated">
        <div style={{ padding: '14px 16px', display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <DSChip variant="brand" active>Все · 303</DSChip>
          <DSChip>Мои · 42</DSChip>
          <DSChip>Без ответственного · 18</DSChip>
          <DSChip variant="warning">Просрочено · 4</DSChip>
          <div style={{ flex: 1 }}></div>
          <span className="ds-body-s ds-muted">Приоритет:</span>
          <DSChip variant="error">crit · 2</DSChip>
          <DSChip variant="warning">high · 12</DSChip>
          <DSChip>med · 48</DSChip>
          <DSChip>low · 241</DSChip>
        </div>
      </DSCard>

      {/* Kanban */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 12 }}>
        {cols.map((col) => (
          <div key={col.id} style={{ display: 'flex', flexDirection: 'column', gap: 10, minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderTop: `3px solid ${col.c}`, background: 'var(--ds-surface-container-low)', borderRadius: '0 0 10px 10px' }}>
              <span className="ds-title-s">{col.t}</span>
              <span className="ds-body-s ds-muted">· {col.n}</span>
              <div style={{ flex: 1 }}></div>
              <DSButton variant="text" icon size="sm">＋</DSButton>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, minHeight: 0 }}>
              {col.cards.map((c, i) => (
                <div key={i} style={{
                  background: 'var(--ds-surface)', border: '1px solid var(--ds-outline-variant)',
                  borderRadius: 12, padding: 12, position: 'relative', overflow: 'hidden',
                  boxShadow: '0 1px 2px rgba(0,0,0,0.04)',
                }}>
                  <div style={{ position: 'absolute', top: 0, left: 0, bottom: 0, width: 3, background: prColor[c.p] }}></div>
                  {c.incident && <div style={{ position: 'absolute', top: 10, right: 10, fontSize: 12, color: 'var(--ds-error)' }}>⚠</div>}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}>
                    <DSChip variant={c.p === 'critical' ? 'error' : c.p === 'high' ? 'warning' : 'default'}>{prLabel[c.p]}</DSChip>
                    <DSChip>{c.cat}</DSChip>
                  </div>
                  <div className="ds-title-s" style={{ lineHeight: 1.3, marginBottom: 8, paddingRight: c.incident ? 16 : 0 }}>{c.s}</div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11 }}>
                    <DSAvatar size="xs" initials={c.cust.split(' ').map(w=>w[0]).join('')} />
                    <span className="ds-body-s">{c.cust}</span>
                    {c.a && <><span className="ds-muted">→</span><DSAvatar size="xs" initials={c.a.split(' ').map(w=>w[0]).join('')} /></>}
                    <div style={{ flex: 1 }}></div>
                    <span className="ds-mono ds-muted">{c.ago}</span>
                    {c.csat && <span style={{ color: 'var(--ds-success)' }}>★{c.csat}</span>}
                  </div>
                </div>
              ))}
              {col.cards.length === 0 && <div style={{ padding: 18, textAlign: 'center', color: 'var(--ds-on-surface)', opacity: 0.5, fontSize: 12, border: '1px dashed var(--ds-outline-variant)', borderRadius: 10 }}>пусто</div>}
            </div>
          </div>
        ))}
      </div>
    </MgrShell>
  );
}

// ─────────── REPORTS ───────────
function MgrReports() {
  const items = [
    { ico: '💰', t: 'Сводный отчёт по комиссиям', d: 'все начисления и удержания партнёрам · по периоду', f: 'XLSX · PDF', last: '25.05 · 12.8 М ₽' },
    { ico: '📊', t: 'ЛП / ГП / НГП по периодам', d: 'объёмы продаж в разрезе партнёров и каскада', f: 'XLSX', last: '25.05' },
    { ico: '👥', t: 'Партнёры — активные / терминированные', d: 'отчёт по статусам и активационным периодам', f: 'XLSX', last: '24.05' },
    { ico: '📄', t: 'Контракты по продуктам', d: 'количество и оборот по программам', f: 'XLSX · PDF', last: '23.05' },
    { ico: '🏆', t: 'Пул и распределение', d: 'детализация выплат пула по уровням', f: 'XLSX', last: '24.05 · 2.42 М ₽' },
    { ico: '⚠', t: 'Штрафы §5 / Корректировки', d: 'все ручные корректировки за период', f: 'XLSX', last: '24.05' },
    { ico: '🏦', t: 'Реестр выплат — для бухгалтерии', d: 'формат для банк-клиента · с реквизитами', f: '1С · XLSX', last: '25.05 · 148 строк' },
    { ico: '📈', t: 'Воронка регистраций', d: 'регистрация → активация → продажа', f: 'PDF', last: '20.05' },
    { ico: '🎓', t: 'Прогресс обучения партнёров', d: 'кто в каком курсе и тесте', f: 'XLSX', last: '25.05' },
    { ico: '💬', t: 'Аналитика поддержки', d: 'тикеты · SLA · CSAT', f: 'XLSX · PDF', last: '25.05' },
  ];

  return (
    <MgrShell activeId="set" title="Отчёты" subtitle="каталог сформированных и on-demand-отчётов">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12 }}>
        {items.map((r, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18, display: 'flex', gap: 14, alignItems: 'flex-start' }}>
              <div style={{ width: 44, height: 44, borderRadius: 10, background: 'var(--ds-primary-container)', color: 'var(--ds-on-primary-container)', display: 'grid', placeItems: 'center', fontSize: 20, flexShrink: 0 }}>{r.ico}</div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div className="ds-title-s">{r.t}</div>
                <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{r.d}</div>
                <div style={{ display: 'flex', gap: 10, marginTop: 12, alignItems: 'center', flexWrap: 'wrap' }}>
                  <DSChip>{r.f}</DSChip>
                  <span className="ds-body-s ds-muted">последний прогон: {r.last}</span>
                  <div style={{ flex: 1 }}></div>
                  <DSButton variant="text" size="sm">⊙ Сгенерировать</DSButton>
                  <DSButton variant="outlined" size="sm">📥</DSButton>
                </div>
              </div>
            </div>
          </DSCard>
        ))}
      </div>
    </MgrShell>
  );
}

Object.assign(window, {
  MgrPermissions, MgrPeriodCard, MgrTransfers,
  MgrEducationConstructor, MgrKbConstructor, MgrHomework,
  MgrEducationAnalytics, MgrChatAnalytics, MgrStaffChatKanban, MgrReports,
});
