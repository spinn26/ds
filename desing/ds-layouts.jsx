// ds-layouts.jsx — примеры применения системы
// MainLayout (партнёр) light/dark, AdminLayout (тёмная), Mobile variants

// ─────────── shared shell parts ───────────
function FullShell({ theme, sidebar, content }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ height: '100%', display: 'grid', gridTemplateColumns: '240px 1fr', overflow: 'hidden' }}>
      <aside style={{ background: 'var(--ds-surface)', borderRight: '1px solid var(--ds-outline-variant)', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
        {sidebar}
      </aside>
      <main style={{ overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
        {content}
      </main>
    </div>
  );
}

function PartnerSidebar({ activeId = 'home' }) {
  const groups = [
    { sec: 'работа', items: [
      { id: 'home',  ico: '⌂', label: 'Главная' },
      { id: 'dash',  ico: '▦', label: 'Дашборд' },
      { id: 'struct',ico: '☷', label: 'Структура' },
    ]},
    { sec: 'продажи', items: [
      { id: 'cli',   ico: '⚇', label: 'Клиенты', count: 142 },
      { id: 'con',   ico: '▤', label: 'Контракты', count: 89 },
      { id: 'fin',   ico: '¤', label: 'Финансы' },
      { id: 'prod',  ico: '⏏', label: 'Продукты' },
    ]},
    { sec: 'обучение', items: [
      { id: 'edu',   ico: '⏏', label: 'Курсы', count: 4 },
      { id: 'kb',    ico: '▥', label: 'База знаний' },
      { id: 'cont',  ico: '★', label: 'Конкурсы' },
    ]},
    { sec: 'аккаунт', items: [
      { id: 'chat',  ico: '❑', label: 'Чат', count: 3 },
      { id: 'prof',  ico: '⚇', label: 'Профиль' },
    ]},
  ];
  return (
    <React.Fragment>
      <div style={{ padding: '16px 16px 12px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: '1px solid var(--ds-outline-variant)' }}>
        <DSMark size={28} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="ds-title-s">DS Consulting</div>
          <div className="ds-body-s ds-muted">Партнёр · senior</div>
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
        <DSAvatar initials="ИП" status />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="ds-title-s" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>Иван Петров</div>
          <div className="ds-body-s ds-muted">ivanov@dscons.ru</div>
        </div>
        <DSButton variant="text" icon size="sm">⋯</DSButton>
      </div>
    </React.Fragment>
  );
}

function AdminSidebar({ activeId = 'dash' }) {
  const groups = [
    { sec: 'аналитика', items: [
      { id: 'dash',  ico: '▦', label: 'Дашборд', active: true },
      { id: 'owner', ico: '★', label: 'Owner-дашборд' },
      { id: 'coh',   ico: '☷', label: 'Когорты' },
      { id: 'anom',  ico: '⚠', label: 'Аномалии', count: 3 },
    ]},
    { sec: 'управление', items: [
      { id: 'usr',   ico: '⚇', label: 'Пользователи' },
      { id: 'pr',    ico: '⚇', label: 'Партнёры' },
      { id: 'cli',   ico: '⚇', label: 'Клиенты' },
      { id: 'rec',   ico: '≈', label: 'Сверка балансов' },
      { id: 'bulk',  ico: '⊞', label: 'Массовые операции' },
    ]},
    { sec: 'контент', items: [
      { id: 'news',  ico: '◉', label: 'Новости' },
      { id: 'road',  ico: '→', label: 'Роадмап' },
      { id: 'prod',  ico: '⏏', label: 'Продукты' },
      { id: 'edu',   ico: '⏏', label: 'Конструктор курсов' },
    ]},
    { sec: 'система', items: [
      { id: 'ref',   ico: '▤', label: 'Справочники' },
      { id: 'set',   ico: '⚙', label: 'Настройки' },
      { id: 'mon',   ico: '◷', label: 'Мониторинг' },
      { id: 'st',    ico: '●', label: 'Статус системы' },
    ]},
  ];
  return (
    <React.Fragment>
      <div style={{ padding: '16px 16px 12px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: '1px solid var(--ds-outline-variant)' }}>
        <DSMark size={28} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="ds-title-s">DS Платформа</div>
          <div className="ds-body-s ds-muted">Администратор</div>
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
        <DSAvatar initials="АК" status />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="ds-title-s">Анна Карпова</div>
          <div className="ds-body-s ds-muted">владелец</div>
        </div>
      </div>
    </React.Fragment>
  );
}

function AppBar({ children, title, subtitle }) {
  return (
    <div style={{
      height: 60, padding: '0 28px', display: 'flex', alignItems: 'center', gap: 16,
      background: 'var(--ds-surface)', borderBottom: '1px solid var(--ds-outline-variant)',
      flexShrink: 0,
    }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        {title && <div className="ds-title-m">{title}</div>}
        {subtitle && <div className="ds-body-s ds-muted">{subtitle}</div>}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        <div style={{ position: 'relative' }}>
          <DSButton variant="text" icon>⚐</DSButton>
          <div style={{ position: 'absolute', top: 0, right: 0 }}><DSBadge>4</DSBadge></div>
        </div>
        <DSButton variant="text" icon>❑</DSButton>
        {children}
      </div>
    </div>
  );
}

// ─────────── Partner Workspace ───────────
function PartnerWorkspace({ theme = 'light' }) {
  return (
    <FullShell
      theme={theme}
      sidebar={<PartnerSidebar activeId="home" />}
      content={
        <React.Fragment>
          <AppBar title="Главная" subtitle="понедельник, 25 мая · доброе утро, Иван" />
          <div style={{ overflow: 'auto', padding: '24px 28px', display: 'grid', gridTemplateColumns: '1fr 320px', gap: 20, alignItems: 'start' }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

              {/* HERO + quals */}
              <div className="ds-card" style={{ padding: 0, overflow: 'hidden', position: 'relative' }}>
                <BrandWaves height={200} style={{ position: 'absolute', inset: 0, opacity: 0.45 }} />
                <div style={{ position: 'relative', padding: '24px 28px' }}>
                  <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>квалификация</div>
                  <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 20, marginTop: 6 }}>
                    <div>
                      <div className="ds-headline-l">Senior-консультант</div>
                      <div className="ds-body-m ds-muted" style={{ marginTop: 4 }}>до уровня «Тим-лид» — 200 баллов</div>
                    </div>
                    <DSChip variant="brand">★ 10/12</DSChip>
                  </div>
                  <div style={{ marginTop: 16 }}>
                    <DSProgress value={80} variant="brand" height="thick" />
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
                      <span className="ds-body-s ds-muted ds-mono">800 / 1 000 баллов</span>
                      <span className="ds-body-s ds-muted">осталось 16 дней до закрытия</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* KPI cards */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
                {[
                  { lbl: 'ЛП за месяц', v: '2 480 000 ₽', delta: '+18%', up: true },
                  { lbl: 'ГП за месяц', v: '8 920 000 ₽', delta: '+5%', up: true },
                  { lbl: 'НГП', v: '1 240 000 ₽', delta: '−12%', up: false },
                  { lbl: 'Отрыв', v: '3.6×', delta: 'стаб.', up: null },
                ].map((k, i) => (
                  <DSCard key={i} variant="elevated">
                    <div style={{ padding: 14 }}>
                      <div className="ds-label-m ds-muted">{k.lbl}</div>
                      <div className="ds-headline-s ds-mono" style={{ marginTop: 4 }}>{k.v}</div>
                      <div className="ds-body-s" style={{ marginTop: 4, color: k.up === null ? 'var(--ds-on-surface-muted)' : k.up ? 'var(--ds-success)' : 'var(--ds-error)' }}>
                        {k.up === true ? '↑ ' : k.up === false ? '↓ ' : '· '}{k.delta}
                      </div>
                    </div>
                  </DSCard>
                ))}
              </div>

              {/* Recent contracts */}
              <DSCard variant="elevated">
                <div style={{ padding: '16px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div className="ds-title-l">Последние контракты</div>
                  <DSButton variant="text">все →</DSButton>
                </div>
                <table className="ds-table">
                  <thead>
                    <tr>
                      <th>номер</th>
                      <th>клиент</th>
                      <th>продукт</th>
                      <th style={{ textAlign: 'right' }}>сумма</th>
                      <th>статус</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[
                      ['C-2024-0381','Иванов И.И.','Эволюция','2 400 000 ₽','active'],
                      ['C-2024-0380','Петров П.С.','СОЗ','1 850 000 ₽','warn'],
                      ['C-2024-0378','Сидорова А.','PRE-IPO','3 600 000 ₽','active'],
                    ].map((r, i) => (
                      <tr key={i}>
                        <td className="ds-mono">{r[0]}</td>
                        <td>{r[1]}</td>
                        <td>{r[2]}</td>
                        <td className="ds-mono" style={{ textAlign: 'right' }}>{r[3]}</td>
                        <td><DSStatus variant={r[4]}>{({active:'активен',warn:'на проверке'})[r[4]]}</DSStatus></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </DSCard>

              {/* Education progress */}
              <DSCard variant="elevated">
                <div style={{ padding: 18 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 14 }}>
                    <div className="ds-title-l">Обучение в процессе</div>
                    <DSButton variant="text">к курсам →</DSButton>
                  </div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                    {[
                      { t: 'Эволюция — продукт', s: 'урок 3 из 5', p: 78 },
                      { t: 'Возражения', s: 'урок 4 из 14', p: 28 },
                    ].map((c, i) => (
                      <div key={i} style={{ padding: 14, border: '1px solid var(--ds-outline-variant)', borderRadius: 10 }}>
                        <div className="ds-title-m">{c.t}</div>
                        <div className="ds-body-s ds-muted" style={{ marginTop: 2 }}>{c.s}</div>
                        <div style={{ marginTop: 10 }}><DSProgress value={c.p} /></div>
                      </div>
                    ))}
                  </div>
                </div>
              </DSCard>
            </div>

            {/* RIGHT column */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <DSCard variant="brand">
                <div style={{ padding: 16 }}>
                  <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>★ мой день</div>
                  <div className="ds-title-l" style={{ marginTop: 6 }}>3 задачи на сегодня</div>
                  <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {[
                      'Перезвонить Петрову П.С.',
                      'Подгрузить чек-лист по Эволюции',
                      'Дослушать урок «Возражения»',
                    ].map((t, i) => (
                      <div key={i} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '6px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-variant)' }}>
                        <DSCheckbox checked={false} />
                        <span className="ds-body-m" style={{ flex: 1 }}>{t}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </DSCard>

              <DSCard variant="elevated">
                <div style={{ padding: 16 }}>
                  <div className="ds-title-l">Кто онлайн · 4</div>
                  <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 10 }}>
                    {[
                      ['ЖО','Жосан Ольга','Обучение · куратор'],
                      ['КМ','Карпов Михаил','Бэк-офис'],
                      ['СА','Сидорова А.','Партнёр-стажёр'],
                      ['АК','Анна Карпова','Руководитель'],
                    ].map((u, i) => (
                      <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <DSAvatar initials={u[0]} status />
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div className="ds-title-s">{u[1]}</div>
                          <div className="ds-body-s ds-muted">{u[2]}</div>
                        </div>
                        <DSButton variant="text" icon size="sm">❑</DSButton>
                      </div>
                    ))}
                  </div>
                </div>
              </DSCard>

              <DSAlert variant="info" title="Закрытие периода">
                Реестр выплат за май будет сформирован 1 июня в 12:00.
              </DSAlert>
            </div>
          </div>
        </React.Fragment>
      }
    />
  );
}

// ─────────── Admin Dashboard ───────────
function AdminDashboard() {
  return (
    <FullShell
      theme="dark"
      sidebar={<AdminSidebar activeId="dash" />}
      content={
        <React.Fragment>
          <AppBar title="Owner-дашборд" subtitle="понедельник, 25 мая 2026 · DS Consulting">
            <DSChip variant="error">● серьёзный сбой · калькулятор</DSChip>
            <DSButton variant="filled" size="sm">⚙</DSButton>
          </AppBar>

          <div style={{ overflow: 'auto', padding: '24px 28px', display: 'grid', gridTemplateColumns: '1fr 340px', gap: 20, alignItems: 'start' }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

              {/* Greeting + waves */}
              <div className="ds-card" style={{ padding: 0, overflow: 'hidden', position: 'relative' }}>
                <BrandWaves height={200} style={{ position: 'absolute', inset: 0, opacity: 0.55 }} />
                <div style={{ position: 'relative', padding: '24px 28px' }}>
                  <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>👋 добрый день, Анна</div>
                  <div className="ds-display-s" style={{ marginTop: 6 }}>оборот сегодня — <span style={{ color: 'var(--ds-primary)' }}>4.8 М ₽</span></div>
                  <div className="ds-body-l ds-muted" style={{ marginTop: 8 }}>+18% к прошлому понедельнику · 142 активных партнёра</div>
                </div>
              </div>

              {/* KPI */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
                {[
                  { lbl: 'оборот за май', v: '128 М ₽', delta: '+22%', up: true },
                  { lbl: 'новые контракты', v: '482', delta: '+15%', up: true },
                  { lbl: 'активные партнёры', v: '142', delta: '+3', up: true },
                  { lbl: 'отток за месяц', v: '−6', delta: '−2', up: false },
                ].map((k, i) => (
                  <DSCard key={i} variant="elevated">
                    <div style={{ padding: 14 }}>
                      <div className="ds-label-m ds-muted">{k.lbl}</div>
                      <div className="ds-headline-s ds-mono" style={{ marginTop: 4 }}>{k.v}</div>
                      <div className="ds-body-s" style={{ marginTop: 4, color: k.up ? 'var(--ds-success)' : 'var(--ds-error)' }}>
                        {k.up ? '↑ ' : '↓ '}{k.delta}
                      </div>
                    </div>
                  </DSCard>
                ))}
              </div>

              {/* Chart placeholder */}
              <DSCard variant="elevated">
                <div style={{ padding: '16px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div>
                    <div className="ds-title-l">Динамика оборота</div>
                    <div className="ds-body-s ds-muted">помесячно · последние 12 месяцев</div>
                  </div>
                  <div style={{ display: 'flex', gap: 6 }}>
                    <DSChip onClick={()=>{}}>1М</DSChip>
                    <DSChip onClick={()=>{}}>3М</DSChip>
                    <DSChip onClick={()=>{}} active>12М</DSChip>
                  </div>
                </div>
                <div style={{ padding: '8px 18px 18px' }}>
                  <ChartBars />
                </div>
              </DSCard>
            </div>

            {/* RIGHT */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <DSCard variant="filled">
                <div style={{ padding: 16 }}>
                  <div className="ds-label-m ds-muted">мой день · 25 мая</div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 12 }}>
                    {[
                      { ico: '✓', v: '0', l: 'закрыто тикетов', c: 'success' },
                      { ico: '❑', v: '8', l: 'сообщений', c: 'info' },
                      { ico: '◷', v: '0', l: 'у меня в работе', c: 'warning' },
                      { ico: '↺', v: '1', l: 'действий за день', c: 'tertiary' },
                    ].map((s, i) => (
                      <div key={i} style={{ padding: 14, borderRadius: 10, background: 'var(--ds-surface)', textAlign: 'center' }}>
                        <div style={{ width: 28, height: 28, borderRadius: 50, margin: '0 auto', background: `var(--ds-${s.c}-container)`, color: `var(--ds-on-${s.c}-container)`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 14 }}>{s.ico}</div>
                        <div className="ds-headline-s ds-mono" style={{ marginTop: 8 }}>{s.v}</div>
                        <div className="ds-body-s ds-muted">{s.l}</div>
                      </div>
                    ))}
                  </div>
                </div>
              </DSCard>

              <DSCard variant="elevated">
                <div style={{ padding: 16 }}>
                  <div className="ds-title-l">Аномалии · 3</div>
                  <div style={{ marginTop: 10, display: 'flex', flexDirection: 'column' }}>
                    {[
                      ['warn','Резкий рост возвратов','+340% за 48ч'],
                      ['err','Калькулятор: серьёзный сбой','12 ошибок/мин'],
                      ['warn','Мёртвые партнёры','7 без активности 60+ дней'],
                    ].map((a, i) => (
                      <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '10px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)' }}>
                        <DSStatus variant={a[0]}>{a[0] === 'err' ? 'high' : 'med'}</DSStatus>
                        <div style={{ flex: 1 }}>
                          <div className="ds-title-s">{a[1]}</div>
                          <div className="ds-body-s ds-muted">{a[2]}</div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </DSCard>

              <DSCard variant="elevated">
                <div style={{ padding: 16 }}>
                  <div className="ds-title-l">Заметка <span className="ds-body-s ds-muted" style={{ marginLeft: 8, fontWeight: 400 }}>сохранено · 15:02</span></div>
                  <div style={{ marginTop: 10, padding: 12, background: 'var(--ds-surface-container)', borderRadius: 8, minHeight: 80, color: 'var(--ds-on-surface-muted)', fontSize: 13 }}>Запишите что-нибудь — сохранится автоматически</div>
                </div>
              </DSCard>
            </div>
          </div>
        </React.Fragment>
      }
    />
  );
}

function ChartBars() {
  const data = [82, 95, 78, 110, 124, 98, 132, 145, 138, 156, 162, 178];
  const max = Math.max(...data);
  const months = ['Июн','Июл','Авг','Сен','Окт','Ноя','Дек','Янв','Фев','Мар','Апр','Май'];
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, height: 140 }}>
        {data.map((v, i) => (
          <div key={i} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', height: '100%' }}>
            <div style={{ width: '100%', height: `${(v / max) * 100}%`, background: i === data.length - 1 ? 'var(--ds-primary)' : 'var(--ds-primary-container)', borderRadius: '6px 6px 2px 2px', position: 'relative' }}>
              {i === data.length - 1 && (
                <div className="ds-mono ds-body-s" style={{ position: 'absolute', top: -22, left: '50%', transform: 'translateX(-50%)', color: 'var(--ds-primary)', fontWeight: 600 }}>{v}М</div>
              )}
            </div>
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        {months.map((m, i) => (
          <div key={i} style={{ flex: 1, textAlign: 'center', fontSize: 10, color: 'var(--ds-on-surface-muted)' }}>{m}</div>
        ))}
      </div>
    </div>
  );
}

// ─────────── Mobile partner workspace ───────────
function PartnerMobile({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ height: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
      {/* mobile appbar */}
      <div style={{ height: 56, padding: '0 14px', display: 'flex', alignItems: 'center', gap: 10, background: 'var(--ds-surface)', borderBottom: '1px solid var(--ds-outline-variant)', flexShrink: 0 }}>
        <DSButton variant="text" icon size="sm">☰</DSButton>
        <DSMark size={24} />
        <div className="ds-title-s" style={{ flex: 1 }}>Главная</div>
        <div style={{ position: 'relative' }}>
          <DSButton variant="text" icon size="sm">⚐</DSButton>
          <div style={{ position: 'absolute', top: -2, right: -2 }}><DSBadge>4</DSBadge></div>
        </div>
        <DSAvatar initials="ИП" size="sm" />
      </div>

      <div style={{ overflow: 'auto', padding: 14, display: 'flex', flexDirection: 'column', gap: 12 }}>
        {/* hero */}
        <div className="ds-card" style={{ padding: 0, overflow: 'hidden', position: 'relative' }}>
          <BrandWaves height={140} style={{ position: 'absolute', inset: 0, opacity: 0.55 }} />
          <div style={{ position: 'relative', padding: 16 }}>
            <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>квалификация</div>
            <div className="ds-headline-m" style={{ marginTop: 4 }}>Senior</div>
            <div className="ds-body-s ds-muted">до «Тим-лида» — 200 баллов</div>
            <div style={{ marginTop: 10 }}><DSProgress value={80} variant="brand" /></div>
            <div className="ds-body-s ds-mono ds-muted" style={{ marginTop: 6 }}>800 / 1 000</div>
          </div>
        </div>

        {/* KPI 2x2 */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          {[
            { lbl: 'ЛП', v: '2.48 М ₽', up: true, d: '+18%' },
            { lbl: 'ГП', v: '8.92 М ₽', up: true, d: '+5%' },
            { lbl: 'НГП', v: '1.24 М ₽', up: false, d: '−12%' },
            { lbl: 'Отрыв', v: '3.6×', up: null, d: 'стаб.' },
          ].map((k, i) => (
            <DSCard key={i} variant="elevated">
              <div style={{ padding: 12 }}>
                <div className="ds-label-m ds-muted">{k.lbl}</div>
                <div className="ds-title-l ds-mono" style={{ marginTop: 4 }}>{k.v}</div>
                <div className="ds-body-s" style={{ marginTop: 2, color: k.up === null ? 'var(--ds-on-surface-muted)' : k.up ? 'var(--ds-success)' : 'var(--ds-error)' }}>
                  {k.up === true ? '↑ ' : k.up === false ? '↓ ' : '· '}{k.d}
                </div>
              </div>
            </DSCard>
          ))}
        </div>

        {/* card list */}
        <DSCard variant="elevated">
          <div style={{ padding: 14 }}>
            <div className="ds-title-l" style={{ marginBottom: 12 }}>Последние контракты</div>
            {[
              ['C-0381','Иванов И.','Эволюция','2.4 М ₽','active'],
              ['C-0380','Петров П.','СОЗ','1.85 М ₽','warn'],
              ['C-0378','Сидорова А.','PRE-IPO','3.6 М ₽','active'],
            ].map((r, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div className="ds-title-s">{r[1]}</div>
                  <div className="ds-body-s ds-muted">{r[2]} · <span className="ds-mono">{r[0]}</span></div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div className="ds-body-m ds-mono">{r[3]}</div>
                  <DSStatus variant={r[4]} style={{ marginTop: 2 }}>{({active:'активен',warn:'проверка'})[r[4]]}</DSStatus>
                </div>
              </div>
            ))}
          </div>
        </DSCard>

        {/* mobile course */}
        <DSCard variant="brand">
          <div style={{ padding: 14 }}>
            <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>★ продолжить обучение</div>
            <div className="ds-title-l" style={{ marginTop: 4 }}>Эволюция — урок 3 из 5</div>
            <div style={{ marginTop: 10 }}><DSProgress value={62} /></div>
            <DSButton variant="filled" block style={{ marginTop: 14 }}>продолжить →</DSButton>
          </div>
        </DSCard>
      </div>

      {/* bottom tabbar (mobile) */}
      <div style={{ display: 'flex', padding: '8px 6px', background: 'var(--ds-surface)', borderTop: '1px solid var(--ds-outline-variant)', flexShrink: 0 }}>
        {[
          { ico: '⌂', label: 'Главная', active: true },
          { ico: '⚇', label: 'Клиенты' },
          { ico: '▤', label: 'Сделки' },
          { ico: '⏏', label: 'Учёба' },
          { ico: '❑', label: 'Чат', badge: 3 },
        ].map((t, i) => (
          <div key={i} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2, padding: '4px 0', color: t.active ? 'var(--ds-primary)' : 'var(--ds-on-surface-variant)', position: 'relative' }}>
            <div style={{ fontSize: 18 }}>{t.ico}</div>
            <div style={{ fontSize: 10, fontWeight: 600 }}>{t.label}</div>
            {t.badge && <div style={{ position: 'absolute', top: 0, right: '30%' }}><DSBadge>{t.badge}</DSBadge></div>}
          </div>
        ))}
      </div>
    </div>
  );
}

// Tablet — collapsed sidebar version
function PartnerTablet({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ height: '100%', display: 'grid', gridTemplateColumns: '72px 1fr', overflow: 'hidden' }}>
      <aside style={{ background: 'var(--ds-surface)', borderRight: '1px solid var(--ds-outline-variant)', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
        <div style={{ height: 60, display: 'flex', alignItems: 'center', justifyContent: 'center', borderBottom: '1px solid var(--ds-outline-variant)' }}>
          <DSMark size={28} />
        </div>
        <div style={{ padding: 8, flex: 1, display: 'flex', flexDirection: 'column', gap: 4 }}>
          {[
            { ico: '⌂', active: true },
            { ico: '▦' },
            { ico: '⚇', badge: 12 },
            { ico: '▤' },
            { ico: '¤' },
            { ico: '⏏' },
            { ico: '❑', badge: 3 },
            { ico: '⚙' },
          ].map((it, i) => (
            <div key={i} style={{
              position: 'relative', width: 56, height: 48, margin: '0 auto', borderRadius: 12,
              display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18,
              background: it.active ? 'var(--ds-primary-soft)' : 'transparent',
              color: it.active ? 'var(--ds-primary)' : 'var(--ds-on-surface-variant)',
            }}>
              {it.ico}
              {it.badge && <div style={{ position: 'absolute', top: 4, right: 4 }}><DSBadge>{it.badge}</DSBadge></div>}
            </div>
          ))}
        </div>
        <div style={{ padding: 12, borderTop: '1px solid var(--ds-outline-variant)', display: 'flex', justifyContent: 'center' }}>
          <DSAvatar initials="ИП" status />
        </div>
      </aside>

      <main style={{ overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
        <AppBar title="Главная" subtitle="понедельник, 25 мая · доброе утро, Иван" />
        <div style={{ overflow: 'auto', padding: '20px 22px', display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div className="ds-card" style={{ padding: 0, overflow: 'hidden', position: 'relative' }}>
            <BrandWaves height={160} style={{ position: 'absolute', inset: 0, opacity: 0.45 }} />
            <div style={{ position: 'relative', padding: '20px 24px' }}>
              <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>квалификация</div>
              <div className="ds-headline-m" style={{ marginTop: 4 }}>Senior-консультант</div>
              <div className="ds-body-m ds-muted" style={{ marginTop: 4 }}>до уровня «Тим-лид» — 200 баллов</div>
              <div style={{ marginTop: 12 }}><DSProgress value={80} variant="brand" height="thick" /></div>
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 10 }}>
            {[
              { lbl: 'ЛП', v: '2.48 М ₽', delta: '+18%', up: true },
              { lbl: 'ГП', v: '8.92 М ₽', delta: '+5%', up: true },
              { lbl: 'НГП', v: '1.24 М ₽', delta: '−12%', up: false },
              { lbl: 'Отрыв', v: '3.6×', delta: 'стаб.', up: null },
            ].map((k, i) => (
              <DSCard key={i} variant="elevated">
                <div style={{ padding: 12 }}>
                  <div className="ds-label-m ds-muted">{k.lbl}</div>
                  <div className="ds-title-l ds-mono" style={{ marginTop: 4 }}>{k.v}</div>
                  <div className="ds-body-s" style={{ marginTop: 2, color: k.up === null ? 'var(--ds-on-surface-muted)' : k.up ? 'var(--ds-success)' : 'var(--ds-error)' }}>
                    {k.up === true ? '↑ ' : k.up === false ? '↓ ' : '· '}{k.delta}
                  </div>
                </div>
              </DSCard>
            ))}
          </div>
        </div>
      </main>
    </div>
  );
}

Object.assign(window, { PartnerWorkspace, AdminDashboard, PartnerMobile, PartnerTablet });
