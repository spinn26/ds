// ds-screens-auth-partner.jsx — Auth + Partner Cabinet screens

// ─────────── helpers reused ───────────
// (FullShell, PartnerSidebar, AppBar already in ds-layouts.jsx as window globals)

// ─────────── AUTH · LOGIN ───────────
function AuthLogin({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ height: '100%', display: 'grid', gridTemplateColumns: '1fr 480px', overflow: 'hidden' }}>
      {/* hero half */}
      <div style={{ position: 'relative', overflow: 'hidden', background: 'linear-gradient(135deg, var(--ds-primary) 0%, var(--ds-secondary) 100%)', color: 'var(--ds-on-primary)', padding: '48px', display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
        <div style={{ position: 'absolute', inset: 0, opacity: 0.5 }}>
          <BrandWaves height={'100%'} style={{ width: '100%', height: '100%' }} />
        </div>
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 14 }}>
          <DSMark size={40} />
          <div>
            <div style={{ font: 'var(--ds-type-title-l)', color: '#fff' }}>DS Consulting</div>
            <div style={{ font: 'var(--ds-type-body-s)', opacity: 0.85 }}>Партнёрская платформа</div>
          </div>
        </div>

        <div style={{ position: 'relative' }}>
          <div style={{ font: 'var(--ds-type-display-s)', color: '#fff', letterSpacing: '-0.6px', maxWidth: 520, lineHeight: 1.15 }}>
            Партнёрский кабинет для финансовых консультантов
          </div>
          <div style={{ font: 'var(--ds-type-body-l)', marginTop: 16, maxWidth: 460, opacity: 0.95 }}>
            Клиенты, контракты, комиссии и обучение — в одном месте. Real-time чат с поддержкой и кураторами.
          </div>

          <div style={{ marginTop: 32, display: 'flex', gap: 28 }}>
            {[
              { v: '4 800+', l: 'партнёров' },
              { v: '12.8 М', l: 'контрактов' },
              { v: '99.9%', l: 'uptime' },
            ].map((s, i) => (
              <div key={i}>
                <div style={{ font: 'var(--ds-type-headline-s)', color: '#fff', fontFamily: 'JetBrains Mono, monospace', fontVariantNumeric: 'tabular-nums' }}>{s.v}</div>
                <div style={{ font: 'var(--ds-type-body-s)', opacity: 0.85, marginTop: 2 }}>{s.l}</div>
              </div>
            ))}
          </div>
        </div>

        <div style={{ position: 'relative', font: 'var(--ds-type-body-s)', opacity: 0.75 }}>
          © DS Consulting · 2026 · 152-ФЗ
        </div>
      </div>

      {/* form half */}
      <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: '48px 56px', background: 'var(--ds-surface)' }}>
        <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 6 }}>вход в кабинет</div>
        <div className="ds-headline-l" style={{ marginBottom: 6 }}>С возвращением</div>
        <div className="ds-body-m ds-muted" style={{ marginBottom: 28 }}>
          Войдите, чтобы продолжить работу с клиентами и контрактами.
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <DSField label="Email" defaultValue="ivanov@dscons.ru" lg />
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 6 }}>
              <span className="ds-label-m ds-muted">Пароль</span>
              <a className="ds-body-s" style={{ color: 'var(--ds-primary)', textDecoration: 'none' }}>забыли?</a>
            </div>
            <div className="ds-field__input" style={{ height: 48, padding: '0 14px' }}>
              <input type="password" defaultValue="••••••••••" />
              <span className="suffix">👁</span>
            </div>
          </div>
          <DSCheckbox checked={true} label="Запомнить меня на этом устройстве" />
          <DSButton variant="filled" size="lg" block>Войти в кабинет</DSButton>

          <div style={{ display: 'flex', alignItems: 'center', gap: 14, color: 'var(--ds-on-surface-muted)', font: 'var(--ds-type-body-s)' }}>
            <div style={{ flex: 1, height: 1, background: 'var(--ds-outline-variant)' }}></div>
            <span>или</span>
            <div style={{ flex: 1, height: 1, background: 'var(--ds-outline-variant)' }}></div>
          </div>

          <DSButton variant="outlined" size="lg" block>📱 Войти через Telegram</DSButton>

          <div className="ds-body-s ds-muted" style={{ textAlign: 'center', marginTop: 12 }}>
            Ещё не партнёр? <a style={{ color: 'var(--ds-primary)' }}>Подать заявку</a>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─────────── PARTNER DASHBOARD ───────────
function PartnerDashboard({ theme = 'light' }) {
  return (
    <FullShell
      theme={theme}
      sidebar={<PartnerSidebar activeId="dash" />}
      content={
        <React.Fragment>
          <AppBar title="Дашборд" subtitle="личная статистика · май 2026">
            <div style={{ display: 'flex', alignItems: 'center', gap: 4, border: '1px solid var(--ds-outline-variant)', borderRadius: 'var(--ds-radius-md)', padding: 4 }}>
              <DSChip onClick={()=>{}}>день</DSChip>
              <DSChip onClick={()=>{}}>неделя</DSChip>
              <DSChip onClick={()=>{}} active>месяц</DSChip>
              <DSChip onClick={()=>{}}>квартал</DSChip>
              <DSChip onClick={()=>{}}>год</DSChip>
            </div>
          </AppBar>

          <div style={{ overflow: 'auto', padding: '24px 28px', display: 'flex', flexDirection: 'column', gap: 16 }}>

            {/* KPI grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
              {[
                { lbl: 'Личные продажи · ЛП', v: '2 480 000', unit: '₽', delta: '+18%', up: true, ctx: 'vs апрель' },
                { lbl: 'Групповые · ГП', v: '8 920 000', unit: '₽', delta: '+5%', up: true, ctx: 'vs апрель' },
                { lbl: 'Новые групповые · НГП', v: '1 240 000', unit: '₽', delta: '−12%', up: false, ctx: 'vs апрель' },
                { lbl: 'Отрыв от второго', v: '3.6', unit: '×', delta: 'стабильно', up: null, ctx: 'квалификация' },
              ].map((k, i) => (
                <DSCard key={i} variant="elevated">
                  <div style={{ padding: 16 }}>
                    <div className="ds-label-m ds-muted">{k.lbl}</div>
                    <div style={{ display: 'flex', alignItems: 'baseline', gap: 4, marginTop: 6 }}>
                      <span className="ds-headline-m ds-mono">{k.v}</span>
                      <span className="ds-body-m ds-muted">{k.unit}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 }}>
                      <div className="ds-body-s" style={{ color: k.up === null ? 'var(--ds-on-surface-muted)' : k.up ? 'var(--ds-success)' : 'var(--ds-error)', fontWeight: 600 }}>
                        {k.up === true ? '↑ ' : k.up === false ? '↓ ' : '· '}{k.delta}
                      </div>
                      <div className="ds-body-s ds-muted">{k.ctx}</div>
                    </div>
                  </div>
                </DSCard>
              ))}
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 16 }}>
              {/* big chart */}
              <DSCard variant="elevated">
                <div style={{ padding: '16px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div>
                    <div className="ds-title-l">Динамика продаж</div>
                    <div className="ds-body-s ds-muted">помесячно · последние 12 месяцев</div>
                  </div>
                  <div style={{ display: 'flex', gap: 12, fontSize: 12, color: 'var(--ds-on-surface-variant)' }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}><span style={{ width: 10, height: 10, background: 'var(--ds-primary)', borderRadius: 2 }}></span>ЛП</span>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}><span style={{ width: 10, height: 10, background: 'var(--ds-secondary)', borderRadius: 2 }}></span>ГП</span>
                  </div>
                </div>
                <div style={{ padding: '8px 18px 18px' }}>
                  <SalesChart />
                </div>
              </DSCard>

              {/* products */}
              <DSCard variant="elevated">
                <div style={{ padding: '16px 18px 10px' }}>
                  <div className="ds-title-l">Топ продуктов</div>
                  <div className="ds-body-s ds-muted">по обороту · май</div>
                </div>
                <div style={{ padding: '0 18px 16px', display: 'flex', flexDirection: 'column', gap: 12 }}>
                  {[
                    { name: 'Эволюция', v: '5 240 000 ₽', p: 92, share: '58%' },
                    { name: 'СОЗ', v: '2 480 000 ₽', p: 44, share: '27%' },
                    { name: 'PRE-IPO', v: '980 000 ₽', p: 18, share: '11%' },
                    { name: 'Прочее', v: '380 000 ₽', p: 7, share: '4%' },
                  ].map((p, i) => (
                    <div key={i}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 4 }}>
                        <span className="ds-title-s">{p.name}</span>
                        <span className="ds-body-s ds-muted ds-mono">{p.share}</span>
                      </div>
                      <DSProgress value={p.p} />
                      <div className="ds-body-s ds-mono ds-muted" style={{ marginTop: 4 }}>{p.v}</div>
                    </div>
                  ))}
                </div>
              </DSCard>
            </div>

            {/* conversion funnel + leaderboard */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <DSCard variant="elevated">
                <div style={{ padding: '16px 18px 10px' }}>
                  <div className="ds-title-l">Воронка клиентов</div>
                  <div className="ds-body-s ds-muted">за май</div>
                </div>
                <div style={{ padding: '0 18px 18px', display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {[
                    { stage: 'Заявки', n: 124, p: 100, color: 'var(--ds-primary)' },
                    { stage: 'Контакт установлен', n: 98, p: 79, color: 'var(--ds-primary)' },
                    { stage: 'Заинтересованы', n: 64, p: 52, color: 'var(--ds-secondary)' },
                    { stage: 'Подписан контракт', n: 28, p: 23, color: 'var(--ds-secondary)' },
                  ].map((s, i) => (
                    <div key={i} style={{ display: 'grid', gridTemplateColumns: '160px 1fr 60px', gap: 12, alignItems: 'center' }}>
                      <span className="ds-body-m">{s.stage}</span>
                      <div style={{ height: 22, background: 'var(--ds-surface-container-high)', borderRadius: 4, overflow: 'hidden' }}>
                        <div style={{ width: s.p + '%', height: '100%', background: s.color, display: 'flex', alignItems: 'center', padding: '0 10px', color: '#fff', font: 'var(--ds-type-label-m)' }}>{s.p}%</div>
                      </div>
                      <span className="ds-body-m ds-mono" style={{ textAlign: 'right' }}>{s.n}</span>
                    </div>
                  ))}
                </div>
              </DSCard>

              <DSCard variant="elevated">
                <div style={{ padding: '16px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <div>
                    <div className="ds-title-l">Команда · по ГП</div>
                    <div className="ds-body-s ds-muted">прямые партнёры</div>
                  </div>
                  <DSButton variant="text">все →</DSButton>
                </div>
                <div style={{ padding: '0 18px 18px' }}>
                  {[
                    ['СА','Сидорова А.','★ Senior','3 480 000 ₽', 92],
                    ['КМ','Карпов М.','Консультант','2 120 000 ₽', 64],
                    ['ПП','Петров П.','Консультант','1 840 000 ₽', 52],
                    ['ЛН','Лебедева Н.','Стажёр','680 000 ₽', 24],
                  ].map((u, i) => (
                    <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)' }}>
                      <span className="ds-mono ds-body-s ds-muted" style={{ width: 16 }}>{i+1}</span>
                      <DSAvatar initials={u[0]} />
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div className="ds-title-s">{u[1]}</div>
                        <div className="ds-body-s ds-muted">{u[2]}</div>
                      </div>
                      <div style={{ textAlign: 'right' }}>
                        <div className="ds-body-m ds-mono">{u[3]}</div>
                        <div style={{ marginTop: 4, width: 80, marginLeft: 'auto' }}><DSProgress value={u[4]} height="thin" /></div>
                      </div>
                    </div>
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

function SalesChart() {
  // simple grouped line/area chart
  const months = ['Июн','Июл','Авг','Сен','Окт','Ноя','Дек','Янв','Фев','Мар','Апр','Май'];
  const lp = [120,135,128,162,180,158,210,240,235,260,270,295];
  const gp = [380,420,395,510,580,495,640,720,710,800,840,920];
  const max = Math.max(...gp) * 1.1;
  const points = (arr) => arr.map((v, i) => `${(i / (arr.length - 1)) * 100},${100 - (v / max) * 100}`).join(' ');
  return (
    <div>
      <svg viewBox="0 0 100 100" preserveAspectRatio="none" style={{ width: '100%', height: 200 }}>
        <defs>
          <linearGradient id="ar1" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="var(--ds-secondary)" stopOpacity="0.25" />
            <stop offset="100%" stopColor="var(--ds-secondary)" stopOpacity="0" />
          </linearGradient>
          <linearGradient id="ar2" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="var(--ds-primary)" stopOpacity="0.25" />
            <stop offset="100%" stopColor="var(--ds-primary)" stopOpacity="0" />
          </linearGradient>
        </defs>
        {/* grid */}
        {[0, 25, 50, 75, 100].map((y, i) => (
          <line key={i} x1="0" x2="100" y1={y} y2={y} stroke="var(--ds-outline-variant)" strokeWidth="0.25" vectorEffect="non-scaling-stroke" />
        ))}
        {/* area GP */}
        <polygon points={`0,100 ${points(gp)} 100,100`} fill="url(#ar1)" />
        <polyline points={points(gp)} fill="none" stroke="var(--ds-secondary)" strokeWidth="1.2" vectorEffect="non-scaling-stroke" />
        {/* area LP */}
        <polygon points={`0,100 ${points(lp)} 100,100`} fill="url(#ar2)" />
        <polyline points={points(lp)} fill="none" stroke="var(--ds-primary)" strokeWidth="1.6" vectorEffect="non-scaling-stroke" />
      </svg>
      <div style={{ display: 'flex', gap: 8, marginTop: 4 }}>
        {months.map((m, i) => (
          <div key={i} style={{ flex: 1, textAlign: 'center', fontSize: 10, color: 'var(--ds-on-surface-muted)' }}>{m}</div>
        ))}
      </div>
    </div>
  );
}

// ─────────── PARTNER · CLIENTS ───────────
function PartnerClients({ theme = 'light' }) {
  const rows = [
    { id: 1, name: 'Иванов Иван Иванович', phone: '+7 905 123-45-67', region: 'Москва', status: 'active', sum: '2 400 000 ₽', date: '12 мая', resp: 'ИП' },
    { id: 2, name: 'Петрова Анна Сергеевна', phone: '+7 916 222-33-44', region: 'СПб', status: 'warn', sum: '1 850 000 ₽', date: '14 мая', resp: 'СА' },
    { id: 3, name: 'Сидоров Дмитрий', phone: '+7 925 555-66-77', region: 'Казань', status: 'active', sum: '3 600 000 ₽', date: '15 мая', resp: 'ИП' },
    { id: 4, name: 'Кузнецова Мария', phone: '+7 985 444-22-11', region: 'Москва', status: 'draft', sum: '—', date: '20 мая', resp: 'ИП' },
    { id: 5, name: 'Лебедев Николай', phone: '+7 905 999-88-77', region: 'Москва', status: 'active', sum: '1 200 000 ₽', date: '21 мая', resp: 'КМ' },
    { id: 6, name: 'Морозова Екатерина', phone: '+7 916 333-22-11', region: 'Екатеринбург', status: 'warn', sum: '900 000 ₽', date: '22 мая', resp: 'СА' },
    { id: 7, name: 'Соколов Андрей', phone: '+7 925 111-22-33', region: 'Новосибирск', status: 'active', sum: '4 800 000 ₽', date: '23 мая', resp: 'ИП' },
  ];
  const statusLabels = { active: 'клиент', warn: 'на проверке', draft: 'черновик', err: 'отказ' };

  return (
    <FullShell
      theme={theme}
      sidebar={<PartnerSidebar activeId="cli" />}
      content={
        <React.Fragment>
          <AppBar title="Клиенты" subtitle="142 клиента · 89 активных контрактов">
            <DSButton variant="outlined">⤓ экспорт</DSButton>
            <DSButton variant="filled">＋ Новый клиент</DSButton>
          </AppBar>

          <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 14 }}>
            {/* filter row */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 280, maxWidth: 380 }}>
                <DSField placeholder="Поиск по ФИО, телефону или ИНН" prefix="⌕" />
              </div>
              <DSChip onClick={()=>{}} active>все · 142</DSChip>
              <DSChip onClick={()=>{}}>активные · 89</DSChip>
              <DSChip onClick={()=>{}}>на проверке · 31</DSChip>
              <DSChip onClick={()=>{}}>черновики · 12</DSChip>
              <DSChip onClick={()=>{}}>архив · 10</DSChip>
              <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                <DSButton variant="outlined" size="sm">⏷ регион</DSButton>
                <DSButton variant="outlined" size="sm">⏷ ответственный</DSButton>
                <DSButton variant="outlined" size="sm">⏷ период</DSButton>
              </div>
            </div>

            {/* table */}
            <DSCard variant="elevated">
              <table className="ds-table">
                <thead>
                  <tr>
                    <th style={{ width: 28 }}><DSCheckbox checked={false} /></th>
                    <th>клиент</th>
                    <th>телефон</th>
                    <th>регион</th>
                    <th>статус</th>
                    <th style={{ textAlign: 'right' }}>сумма по контрактам</th>
                    <th>добавлен</th>
                    <th>ответственный</th>
                    <th style={{ width: 32 }}></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(r => (
                    <tr key={r.id}>
                      <td><DSCheckbox checked={false} /></td>
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                          <DSAvatar initials={r.name.split(' ').map(p => p[0]).slice(0,2).join('')} size="sm" />
                          <span style={{ font: 'var(--ds-type-title-s)' }}>{r.name}</span>
                        </div>
                      </td>
                      <td className="ds-mono">{r.phone}</td>
                      <td>{r.region}</td>
                      <td><DSStatus variant={r.status}>{statusLabels[r.status]}</DSStatus></td>
                      <td className="ds-mono" style={{ textAlign: 'right', fontWeight: r.sum === '—' ? 400 : 600 }}>{r.sum}</td>
                      <td className="ds-muted">{r.date}</td>
                      <td><DSAvatar initials={r.resp} size="sm" /></td>
                      <td><DSButton variant="text" icon size="sm">⋮</DSButton></td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div style={{ padding: '12px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderTop: '1px solid var(--ds-outline-variant)' }}>
                <div className="ds-body-s ds-muted">показано 1–7 из 142</div>
                <div style={{ display: 'flex', gap: 4, alignItems: 'center' }}>
                  <DSButton variant="outlined" icon size="sm">←</DSButton>
                  <DSButton variant="tonal" size="sm">1</DSButton>
                  <DSButton variant="text" size="sm">2</DSButton>
                  <DSButton variant="text" size="sm">3</DSButton>
                  <span className="ds-faint" style={{ padding: '0 6px' }}>…</span>
                  <DSButton variant="text" size="sm">21</DSButton>
                  <DSButton variant="outlined" icon size="sm">→</DSButton>
                </div>
              </div>
            </DSCard>
          </div>
        </React.Fragment>
      }
    />
  );
}

// ─────────── PARTNER · CONTRACTS ───────────
function PartnerContracts({ theme = 'light' }) {
  const rows = [
    { id: 'C-2024-0381', client: 'Иванов И.И.', product: 'Эволюция', program: 'Базовый · 5 лет', supplier: 'Альфа-Капитал', sum: '2 400 000 ₽', status: 'active', date: '12.05' },
    { id: 'C-2024-0380', client: 'Петрова А.С.', product: 'СОЗ', program: 'Долгосрочная', supplier: 'ВТБ', sum: '1 850 000 ₽', status: 'warn', date: '14.05' },
    { id: 'C-2024-0378', client: 'Сидоров Д.', product: 'PRE-IPO', program: 'Премиум', supplier: 'Сбер', sum: '3 600 000 ₽', status: 'active', date: '15.05' },
    { id: 'C-2024-0376', client: 'Кузнецова М.', product: 'Эволюция', program: 'Базовый · 3 года', supplier: 'Альфа-Капитал', sum: '900 000 ₽', status: 'draft', date: '18.05' },
    { id: 'C-2024-0375', client: 'Лебедев Н.', product: 'Эволюция', program: 'Базовый · 5 лет', supplier: 'Альфа-Капитал', sum: '1 200 000 ₽', status: 'active', date: '20.05' },
    { id: 'C-2024-0372', client: 'Морозова Е.', product: 'СОЗ', program: 'Долгосрочная', supplier: 'ВТБ', sum: '900 000 ₽', status: 'err', date: '21.05' },
  ];
  const statusLabels = { active: 'действует', warn: 'на проверке', draft: 'черновик', err: 'отклонён' };

  return (
    <FullShell
      theme={theme}
      sidebar={<PartnerSidebar activeId="con" />}
      content={
        <React.Fragment>
          <AppBar title="Контракты" subtitle="мои контракты · 89 активных">
            <DSButton variant="outlined">⤓ экспорт</DSButton>
            <DSButton variant="filled">＋ Новый контракт</DSButton>
          </AppBar>

          <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 14 }}>
            {/* summary cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
              {[
                { lbl: 'Всего контрактов', v: '142', ctx: 'за всё время' },
                { lbl: 'Активных', v: '89', ctx: 'действующих сейчас' },
                { lbl: 'На проверке', v: '12', ctx: 'у бэк-офиса' },
                { lbl: 'Сумма активных', v: '128.4 М ₽', ctx: 'по всем продуктам' },
              ].map((k, i) => (
                <DSCard key={i} variant="elevated">
                  <div style={{ padding: 14 }}>
                    <div className="ds-label-m ds-muted">{k.lbl}</div>
                    <div className="ds-headline-s ds-mono" style={{ marginTop: 4 }}>{k.v}</div>
                    <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{k.ctx}</div>
                  </div>
                </DSCard>
              ))}
            </div>

            {/* tabs + filters */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
              <DSTabs items={[
                { value: 'mine', label: 'Мои', count: 142 },
                { value: 'team', label: 'Команды', count: 318 },
              ]} active="mine" />
              <div style={{ flex: 1, maxWidth: 320 }}>
                <DSField placeholder="Поиск по номеру или клиенту" prefix="⌕" />
              </div>
            </div>

            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <DSChip onClick={()=>{}} active>все статусы</DSChip>
              <DSChip onClick={()=>{}}>действуют</DSChip>
              <DSChip onClick={()=>{}}>на проверке</DSChip>
              <DSChip onClick={()=>{}}>черновики</DSChip>
              <DSChip onClick={()=>{}}>отклонённые</DSChip>
              <div style={{ width: 1, height: 24, background: 'var(--ds-outline-variant)', margin: '0 4px' }}></div>
              <DSChip onClick={()=>{}}>Эволюция · 78</DSChip>
              <DSChip onClick={()=>{}}>СОЗ · 41</DSChip>
              <DSChip onClick={()=>{}}>PRE-IPO · 23</DSChip>
            </div>

            <DSCard variant="elevated">
              <table className="ds-table">
                <thead>
                  <tr>
                    <th>номер</th>
                    <th>клиент</th>
                    <th>продукт</th>
                    <th>программа</th>
                    <th>поставщик</th>
                    <th style={{ textAlign: 'right' }}>сумма</th>
                    <th>дата</th>
                    <th>статус</th>
                    <th style={{ width: 32 }}></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(r => (
                    <tr key={r.id}>
                      <td className="ds-mono" style={{ fontWeight: 600 }}>{r.id}</td>
                      <td>{r.client}</td>
                      <td><DSChip variant="brand">{r.product}</DSChip></td>
                      <td className="ds-muted">{r.program}</td>
                      <td>{r.supplier}</td>
                      <td className="ds-mono" style={{ textAlign: 'right', fontWeight: 600 }}>{r.sum}</td>
                      <td className="ds-muted">{r.date}</td>
                      <td><DSStatus variant={r.status}>{statusLabels[r.status]}</DSStatus></td>
                      <td><DSButton variant="text" icon size="sm">⋮</DSButton></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </DSCard>
          </div>
        </React.Fragment>
      }
    />
  );
}

// ─────────── PARTNER · PRODUCTS ───────────
function PartnerProducts({ theme = 'light' }) {
  const products = [
    { name: 'Эволюция', tag: 'инвест', desc: 'Накопительный продукт с защитой капитала. Минимальный срок — 12 месяцев.', cover: 'linear-gradient(135deg, #1B5E20, #6EE87A)', com: '4–6%', sold: 78, edu: 100 },
    { name: 'СОЗ', tag: 'долгосрочный', desc: 'Долгосрочное страхование с инвестиционной составляющей. От 3 лет.', cover: 'linear-gradient(135deg, #2E7D32, #A4E0AC)', com: '5–8%', sold: 41, edu: 100 },
    { name: 'PRE-IPO', tag: 'премиум', desc: 'Доступ к акциям компаний до выхода на биржу. Для квалифицированных инвесторов.', cover: 'linear-gradient(135deg, #1B5E20, #4361A8)', com: '8–12%', sold: 23, edu: 100 },
    { name: 'Эволюция-2', tag: 'новинка', desc: 'Обновлённый продукт линейки Эволюция с дополнительной защитой.', cover: 'linear-gradient(135deg, #2E7D32, #6EE87A)', com: '5–7%', sold: 0, edu: 0, locked: true },
    { name: 'ОПС-ИЖС', tag: 'программа', desc: 'Программа добровольного пенсионного обеспечения.', cover: 'linear-gradient(135deg, #3d4a3f, #8aa68d)', com: '3–5%', sold: 12, edu: 100 },
    { name: 'Капитал-7', tag: 'долгосрочный', desc: 'Сберегательная программа на 7 лет с фиксированной доходностью.', cover: 'linear-gradient(135deg, #2d3a30, #6e8470)', com: '4%', sold: 8, edu: 60 },
  ];

  return (
    <FullShell
      theme={theme}
      sidebar={<PartnerSidebar activeId="prod" />}
      content={
        <React.Fragment>
          <AppBar title="Продукты" subtitle="каталог · 6 продуктов · 5 открыты к продаже" />

          <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 14 }}>
            {/* filters */}
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <DSChip onClick={()=>{}} active>все</DSChip>
              <DSChip onClick={()=>{}}>инвест · 2</DSChip>
              <DSChip onClick={()=>{}}>долгосрочные · 2</DSChip>
              <DSChip onClick={()=>{}}>премиум · 1</DSChip>
              <DSChip onClick={()=>{}}>программы · 1</DSChip>
              <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                <DSButton variant="outlined" size="sm">⏷ сортировка</DSButton>
              </div>
            </div>

            {/* products grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
              {products.map((p, i) => (
                <DSCard key={i} variant="elevated" style={{ opacity: p.locked ? 0.7 : 1 }}>
                  <div style={{ aspectRatio: '16/9', background: p.cover, position: 'relative', overflow: 'hidden' }}>
                    <div style={{ position: 'absolute', inset: 0, backgroundImage: 'repeating-linear-gradient(45deg, transparent 0 20px, rgba(255,255,255,0.06) 20px 21px)' }}></div>
                    {p.locked && (
                      <div style={{ position: 'absolute', top: 10, right: 10 }}>
                        <DSChip>🔒 в обучении</DSChip>
                      </div>
                    )}
                    {!p.locked && p.sold > 50 && (
                      <div style={{ position: 'absolute', top: 10, right: 10 }}>
                        <DSChip variant="brand">★ топ продукт</DSChip>
                      </div>
                    )}
                    <div style={{ position: 'absolute', left: 14, bottom: 14, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)' }}>
                      <div className="ds-label-m" style={{ opacity: 0.85 }}>★ {p.tag}</div>
                      <div className="ds-headline-s" style={{ color: '#fff' }}>{p.name}</div>
                    </div>
                  </div>
                  <div style={{ padding: 16 }}>
                    <div className="ds-body-m ds-muted" style={{ minHeight: 44 }}>{p.desc}</div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginTop: 12, padding: '10px 0', borderTop: '1px solid var(--ds-outline-soft)', borderBottom: '1px solid var(--ds-outline-soft)' }}>
                      <div>
                        <div className="ds-label-m ds-muted">комиссия</div>
                        <div className="ds-title-m ds-mono" style={{ color: 'var(--ds-primary)' }}>{p.com}</div>
                      </div>
                      <div style={{ width: 1, height: 28, background: 'var(--ds-outline-variant)' }}></div>
                      <div>
                        <div className="ds-label-m ds-muted">продано</div>
                        <div className="ds-title-m ds-mono">{p.sold}</div>
                      </div>
                      {p.edu < 100 && (
                        <div style={{ marginLeft: 'auto', textAlign: 'right' }}>
                          <div className="ds-label-m ds-muted">обучение</div>
                          <div style={{ width: 60, marginTop: 4 }}><DSProgress value={p.edu} height="thin" /></div>
                        </div>
                      )}
                    </div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                      {p.locked ? (
                        <DSButton variant="tonal" block>пройти обучение →</DSButton>
                      ) : (
                        <React.Fragment>
                          <DSButton variant="filled" style={{ flex: 1 }}>＋ Заявка</DSButton>
                          <DSButton variant="outlined">обучение</DSButton>
                        </React.Fragment>
                      )}
                    </div>
                  </div>
                </DSCard>
              ))}
            </div>
          </div>
        </React.Fragment>
      }
    />
  );
}

Object.assign(window, { AuthLogin, PartnerDashboard, PartnerClients, PartnerContracts, PartnerProducts, SalesChart });
