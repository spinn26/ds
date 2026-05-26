// ds-screens-manager-admin.jsx — Manager Workspace + Admin Home redesign

// ─────────── MANAGER · WORKSPACE (Kanban) ───────────
function ManagerSidebar({ activeId = 'ws' }) {
  const groups = [
    { sec: 'работа', items: [
      { id: 'ws',  ico: '⌂', label: 'Workspace', count: 12 },
      { id: 'per', ico: '◷', label: 'Периоды' },
      { id: 'st',  ico: '☷', label: 'Структура' },
    ]},
    { sec: 'данные', items: [
      { id: 'pr',  ico: '⚇', label: 'Партнёры' },
      { id: 'cli', ico: '⚇', label: 'Клиенты' },
      { id: 'con', ico: '▤', label: 'Контракты' },
      { id: 'up',  ico: '⤒', label: 'Загрузка контрактов' },
      { id: 'acc', ico: '✓', label: 'Приёмка', count: 7 },
    ]},
    { sec: 'финансы', items: [
      { id: 'imp', ico: '⤒', label: 'Импорт транзакций' },
      { id: 'trx', ico: '⇄', label: 'Транзакции' },
      { id: 'com', ico: '%', label: 'Комиссии' },
      { id: 'pool',ico: '◉', label: 'Бассейн' },
      { id: 'pay', ico: '¤', label: 'Реестр выплат' },
    ]},
    { sec: 'поддержка', items: [
      { id: 'chat',ico: '❑', label: 'Чат стафф', count: 4 },
      { id: 'sup', ico: '⚐', label: 'Поддержка', count: 8 },
    ]},
  ];
  return (
    <React.Fragment>
      <div style={{ padding: '16px 16px 12px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: '1px solid var(--ds-outline-variant)' }}>
        <DSMark size={28} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="ds-title-s">DS Платформа</div>
          <div className="ds-body-s ds-muted">Менеджер · бэк-офис</div>
        </div>
      </div>
      <div style={{ padding: 8, overflow: 'auto', flex: 1 }}>
        {groups.map((g, gi) => (
          <React.Fragment key={gi}>
            <div className="ds-nav-section">{g.sec.toUpperCase()}</div>
            {g.items.map((it, i) => (
              <div key={i} className="ds-nav-item" data-active={it.id === activeId ? 'true' : 'false'}>
                <span className="ds-ico" style={{ fontSize: 16 }}>{it.ico}</span>
                <span>{it.label}</span>
                {it.count && <span className="count">{it.count}</span>}
              </div>
            ))}
          </React.Fragment>
        ))}
      </div>
      <div style={{ padding: 12, borderTop: '1px solid var(--ds-outline-variant)', display: 'flex', alignItems: 'center', gap: 10 }}>
        <DSAvatar initials="МК" status />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="ds-title-s">Михаил Карпов</div>
          <div className="ds-body-s ds-muted">фин. менеджер</div>
        </div>
      </div>
    </React.Fragment>
  );
}

function ManagerWorkspace({ theme = 'light' }) {
  const cols = [
    { id: 'inbox', label: 'Входящие', count: 12, color: 'var(--ds-on-surface-muted)', cards: [
      { id: 'T-0481', title: 'Контракт C-2024-0381 — KYC', client: 'Иванов И.И.', sum: '2.4 М ₽', tags: ['срочно'], priority: 'high', age: '12 мин' },
      { id: 'T-0480', title: 'Уточнить программу — клиент сомневается', client: 'Петрова А.С.', sum: '1.85 М ₽', tags: [], priority: 'mid', age: '34 мин' },
      { id: 'T-0479', title: 'Реквизиты не прошли валидацию', client: 'Сидоров Д.', sum: '3.6 М ₽', tags: [], priority: 'mid', age: '1 ч' },
    ]},
    { id: 'work', label: 'В работе', count: 4, color: 'var(--ds-warning)', cards: [
      { id: 'T-0475', title: 'Перепроверка ИНН + сверка с ФНС', client: 'Кузнецова М.', sum: '0.9 М ₽', tags: ['комплаенс'], priority: 'mid', age: '3 ч', assignee: 'МК' },
      { id: 'T-0473', title: 'Подгрузить недостающие документы', client: 'Лебедев Н.', sum: '1.2 М ₽', tags: [], priority: 'low', age: '5 ч', assignee: 'МК' },
    ]},
    { id: 'wait', label: 'Ждут клиента', count: 7, color: 'var(--ds-info)', cards: [
      { id: 'T-0468', title: 'Запрошены сканы паспорта', client: 'Морозова Е.', sum: '0.9 М ₽', tags: [], priority: 'low', age: '1 д', assignee: 'МК' },
      { id: 'T-0464', title: 'Подписание ДОУ', client: 'Соколов А.', sum: '4.8 М ₽', tags: ['VIP'], priority: 'high', age: '2 д', assignee: 'МК' },
      { id: 'T-0460', title: 'Подтверждение по телефону', client: 'Васильев К.', sum: '1.5 М ₽', tags: [], priority: 'mid', age: '3 д', assignee: 'МК' },
    ]},
    { id: 'done', label: 'Готовы', count: 31, color: 'var(--ds-primary)', cards: [
      { id: 'T-0451', title: 'Контракт принят, передан в реестр', client: 'Никитин В.', sum: '2.1 М ₽', tags: [], priority: 'low', age: 'сегодня', assignee: 'МК' },
      { id: 'T-0448', title: 'Передано на выплату', client: 'Орлова Т.', sum: '780 К ₽', tags: [], priority: 'low', age: 'сегодня', assignee: 'МК' },
    ]},
  ];

  return (
    <FullShell
      theme={theme}
      sidebar={<ManagerSidebar activeId="ws" />}
      content={
        <React.Fragment>
          <AppBar title="Workspace" subtitle="мои задачи · 54 активные · 31 готова">
            <DSField placeholder="поиск по задачам" prefix="⌕" />
            <DSButton variant="outlined" size="sm">⏷ фильтры</DSButton>
            <DSButton variant="filled" size="sm">＋ Задача</DSButton>
          </AppBar>

          {/* metrics strip */}
          <div style={{ padding: '14px 28px', display: 'flex', gap: 12, borderBottom: '1px solid var(--ds-outline-variant)', background: 'var(--ds-surface-container-low)' }}>
            {[
              { lbl: 'входящие сегодня', v: '12', d: '+4 за час' },
              { lbl: 'в работе у меня', v: '4', d: 'средний возраст 3.5 ч' },
              { lbl: 'просрочены', v: '0', d: 'хорошо!', c: 'success' },
              { lbl: 'SLA первый ответ', v: '8 мин', d: 'цель — 15 мин', c: 'success' },
              { lbl: 'сегодня закрыто', v: '7', d: 'из плана 10', c: 'warn' },
            ].map((m, i) => (
              <div key={i} style={{ display: 'flex', flexDirection: 'column', gap: 2, padding: '4px 16px', borderLeft: i === 0 ? 'none' : '1px solid var(--ds-outline-variant)' }}>
                <div className="ds-label-m ds-muted">{m.lbl}</div>
                <div className="ds-title-l ds-mono" style={{ color: m.c === 'success' ? 'var(--ds-success)' : m.c === 'warn' ? 'var(--ds-warning)' : 'var(--ds-on-surface)' }}>{m.v}</div>
                <div className="ds-body-s ds-muted">{m.d}</div>
              </div>
            ))}
          </div>

          {/* Kanban */}
          <div style={{ overflow: 'auto', padding: '18px 22px', flex: 1 }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 14, height: '100%' }}>
              {cols.map(c => (
                <div key={c.id} style={{ display: 'flex', flexDirection: 'column', minHeight: 0 }}>
                  {/* column header */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '6px 4px 12px' }}>
                    <span style={{ width: 8, height: 8, borderRadius: 50, background: c.color }}></span>
                    <span className="ds-title-m">{c.label}</span>
                    <DSChip>{c.count}</DSChip>
                    <DSButton variant="text" icon size="sm" style={{ marginLeft: 'auto' }}>＋</DSButton>
                  </div>
                  {/* cards */}
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 10, overflow: 'auto', paddingBottom: 14 }}>
                    {c.cards.map(card => (
                      <DSCard key={card.id} variant="elevated">
                        <div style={{ padding: 12 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                            <span className="ds-mono ds-body-s ds-muted">{card.id}</span>
                            {card.priority === 'high' && <DSStatus variant="err">высокий</DSStatus>}
                            {card.priority === 'mid' && <DSStatus variant="warn">средний</DSStatus>}
                            <DSButton variant="text" icon size="sm" style={{ marginLeft: 'auto', width: 24, height: 24 }}>⋮</DSButton>
                          </div>
                          <div className="ds-title-s" style={{ marginBottom: 6 }}>{card.title}</div>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 8, padding: '8px 10px', background: 'var(--ds-surface-container-low)', borderRadius: 8 }}>
                            <DSAvatar initials={card.client.split(' ').map(p => p[0]).join('').slice(0,2)} size="sm" />
                            <div style={{ flex: 1, minWidth: 0 }}>
                              <div className="ds-body-s" style={{ fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{card.client}</div>
                              <div className="ds-body-s ds-mono ds-muted">{card.sum}</div>
                            </div>
                          </div>
                          {card.tags.length > 0 && (
                            <div style={{ display: 'flex', gap: 4, marginTop: 8 }}>
                              {card.tags.map((t, i) => (
                                <DSChip key={i} variant={t === 'срочно' ? 'error' : t === 'VIP' ? 'brand' : 'info'}>{t}</DSChip>
                              ))}
                            </div>
                          )}
                          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 }}>
                            <span className="ds-body-s ds-muted">⏱ {card.age}</span>
                            {card.assignee && <DSAvatar initials={card.assignee} size="sm" />}
                          </div>
                        </div>
                      </DSCard>
                    ))}
                    <button className="ds-btn ds-btn--text" style={{ width: '100%', justifyContent: 'center', color: 'var(--ds-on-surface-muted)', borderRadius: 10, padding: '10px 0', border: '1.5px dashed var(--ds-outline-variant)', background: 'transparent', height: 'auto' }}>＋ добавить задачу</button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </React.Fragment>
      }
    />
  );
}

// ─────────── ADMIN · HOME REDESIGN ───────────
// Замена скриншота "Добрый день, Admin!" — те же блоки, новая компоновка.
function AdminHomeRedesign() {
  return (
    <FullShell
      theme="dark"
      sidebar={<AdminSidebar activeId="dash" />}
      content={
        <React.Fragment>
          <AppBar title="Главная" subtitle="понедельник, 25 мая 2026">
            <DSChip variant="error">● серьёзный сбой · калькулятор</DSChip>
            <DSButton variant="text" icon>⚙</DSButton>
          </AppBar>

          <div style={{ overflow: 'auto', padding: '24px 28px', display: 'grid', gridTemplateColumns: '1fr 360px', gap: 20, alignItems: 'start' }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

              {/* Hero greeting */}
              <div className="ds-card" style={{ padding: 0, overflow: 'hidden', position: 'relative' }}>
                <BrandWaves height={200} style={{ position: 'absolute', inset: 0, opacity: 0.5 }} />
                <div style={{ position: 'relative', padding: '28px 32px' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <span style={{ fontSize: 28 }}>👋</span>
                    <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>добрый день</div>
                  </div>
                  <div className="ds-display-s" style={{ marginTop: 8 }}>
                    Анна, оборот сегодня — <span style={{ color: 'var(--ds-primary)' }}>4.8 М ₽</span>
                  </div>
                  <div className="ds-body-l ds-muted" style={{ marginTop: 8 }}>
                    +18% к прошлому понедельнику · 142 активных партнёра · 31 закрытие в очереди
                  </div>
                </div>
              </div>

              {/* day metrics */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
                {[
                  { ico: '✓', lbl: 'закрыто тикетов', v: 0, c: 'success' },
                  { ico: '❑', lbl: 'отправлено сообщений', v: 8, c: 'info' },
                  { ico: '◷', lbl: 'у меня в работе', v: 0, c: 'warning' },
                  { ico: '↺', lbl: 'действий за день', v: 1, c: 'tertiary' },
                ].map((s, i) => (
                  <DSCard key={i} variant="elevated">
                    <div style={{ padding: 16 }}>
                      <div style={{ width: 36, height: 36, borderRadius: 10, background: `var(--ds-${s.c}-container)`, color: `var(--ds-on-${s.c}-container)`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 16 }}>{s.ico}</div>
                      <div className="ds-headline-s ds-mono" style={{ marginTop: 12 }}>{s.v}</div>
                      <div className="ds-body-s ds-muted" style={{ marginTop: 2 }}>{s.lbl}</div>
                    </div>
                  </DSCard>
                ))}
              </div>

              {/* news + announcements */}
              <DSCard variant="elevated">
                <div style={{ padding: '14px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div className="ds-title-l">📅 Новости и объявления</div>
                  <DSButton variant="text" size="sm">все →</DSButton>
                </div>
                <div style={{ padding: '0 18px 18px', display: 'flex', flexDirection: 'column', gap: 0 }}>
                  {[
                    { dot: 'warning', tag: 'важно', title: 'Первая новость', desc: 'Обновление условий по продукту Эволюция — действует с 1 июня. Изучите новые требования к KYC.', date: '17.04.2026' },
                    { dot: 'info', tag: 'обновление', title: 'Новый шаблон импорта транзакций', desc: 'Загружен новый CSV-шаблон. Старый версии перестанут поддерживаться с 1 июля.', date: '15.04.2026' },
                    { dot: 'success', tag: 'релиз', title: 'Реестр выплат — март закрыт', desc: '148 партнёров получили начисления. Общая сумма — 12.4 М ₽.', date: '01.04.2026' },
                  ].map((n, i) => (
                    <div key={i} style={{ display: 'flex', gap: 14, padding: '14px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)' }}>
                      <div style={{ width: 8, height: 8, borderRadius: 50, background: `var(--ds-${n.dot})`, marginTop: 8, flexShrink: 0 }}></div>
                      <div style={{ flex: 1 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <DSChip variant={n.dot === 'warning' ? 'warning' : n.dot === 'success' ? 'success' : 'info'}>{n.tag}</DSChip>
                          <span className="ds-title-s">{n.title}</span>
                          <span className="ds-body-s ds-muted" style={{ marginLeft: 'auto' }}>{n.date}</span>
                        </div>
                        <div className="ds-body-m ds-muted" style={{ marginTop: 6 }}>{n.desc}</div>
                      </div>
                    </div>
                  ))}
                </div>
              </DSCard>

              {/* tasks */}
              <DSCard variant="elevated">
                <div style={{ padding: '14px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div className="ds-title-l">✓ Мои задачи · 2 активных · 3 готовы</div>
                  <DSButton variant="tonal" size="sm">＋ добавить</DSButton>
                </div>
                <div style={{ padding: '0 18px 18px', display: 'flex', flexDirection: 'column' }}>
                  {[
                    { done: false, t: 'Деплой приложения', date: '26 мая', p: 'high' },
                    { done: false, t: 'Доработать систему чатов по задаче Аллы', date: '28 мая', p: 'mid' },
                    { done: true, t: 'Проверить работу мобильной версии и приложений', date: '31 мая', p: 'low' },
                    { done: true, t: 'Изменить загрузку контрактов — есть колонки со старой платформы', date: '31 мая', p: 'high' },
                    { done: true, t: 'Согласовать новый шаблон отчётов', date: '20 мая', p: 'mid' },
                  ].map((t, i) => (
                    <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)' }}>
                      <DSCheckbox checked={t.done} />
                      <span className="ds-body-m" style={{ flex: 1, color: t.done ? 'var(--ds-on-surface-muted)' : 'var(--ds-on-surface)', textDecoration: t.done ? 'line-through' : 'none' }}>{t.t}</span>
                      <span className="ds-body-s ds-muted">⌗ {t.date}</span>
                      {t.p === 'high' && <DSStatus variant="err">высокий</DSStatus>}
                      {t.p === 'mid' && <DSStatus variant="warn">средний</DSStatus>}
                      {t.p === 'low' && <DSStatus variant="info">низкий</DSStatus>}
                      <DSButton variant="text" icon size="sm">×</DSButton>
                    </div>
                  ))}
                </div>
              </DSCard>
            </div>

            {/* RIGHT column */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {/* Online */}
              <DSCard variant="elevated">
                <div style={{ padding: '14px 16px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div className="ds-title-l">⚇ Кто онлайн · 4</div>
                  <DSButton variant="text" icon size="sm">↻</DSButton>
                </div>
                <div style={{ padding: '0 8px 12px' }}>
                  {[
                    ['ЖО','Жосан Ольга','Обучение · куратор'],
                    ['КМ','Карпов М.','Бэк-офис'],
                    ['СА','Сидорова А.','Партнёр'],
                    ['ИП','Иванов И.','Партнёр'],
                  ].map((u, i) => (
                    <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 10px', borderRadius: 8 }}>
                      <DSAvatar initials={u[0]} status />
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div className="ds-title-s">{u[1]}</div>
                        <div className="ds-body-s ds-muted">{u[2]}</div>
                      </div>
                      <DSButton variant="text" icon size="sm">❑</DSButton>
                    </div>
                  ))}
                </div>
              </DSCard>

              {/* Note */}
              <DSCard variant="elevated">
                <div style={{ padding: '14px 16px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div className="ds-title-l">📝 Заметка</div>
                  <span className="ds-body-s ds-muted">сохранено · 15:02</span>
                </div>
                <div style={{ padding: '0 16px 16px' }}>
                  <div className="ds-field__input" style={{ minHeight: 100, padding: '10px 12px', alignItems: 'flex-start' }}>
                    <span className="ds-body-m ds-muted" style={{ flex: 1 }}>Запишите что-нибудь — сохранится автоматически</span>
                  </div>
                </div>
              </DSCard>

              {/* Quick actions */}
              <DSCard variant="elevated">
                <div style={{ padding: '14px 16px 10px' }}>
                  <div className="ds-title-l">⚡ Быстрые действия</div>
                </div>
                <div style={{ padding: '0 16px 16px', display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {[
                    { ico: '❑', lbl: 'Обратная связь' },
                    { ico: '⚇', lbl: 'Профиль' },
                    { ico: '+', lbl: 'Создать партнёра' },
                    { ico: '⤓', lbl: 'Экспорт реестра' },
                  ].map((a, i) => (
                    <button key={i} className="ds-btn ds-btn--outlined ds-btn--block" style={{ justifyContent: 'flex-start', gap: 12 }}>
                      <span style={{ fontSize: 14 }}>{a.ico}</span>
                      <span>{a.lbl}</span>
                      <span style={{ marginLeft: 'auto', color: 'var(--ds-on-surface-muted)' }}>→</span>
                    </button>
                  ))}
                </div>
              </DSCard>
            </div>
          </div>
        </React.Fragment>
      }
    />
  );
}

Object.assign(window, { ManagerSidebar, ManagerWorkspace, AdminHomeRedesign });
