// ds-patterns.jsx — состояния (loading / empty / error / success / permission)
// + nav patterns (sidebar, app-bar)

// ─────────── STATES ───────────
function DSP_States({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 16, marginBottom: 18 }}>
        <div>
          <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 4 }}>patterns</div>
          <div className="ds-headline-l">Состояния</div>
          <div className="ds-body-l ds-muted" style={{ marginTop: 6, maxWidth: 720 }}>
            на каждом экране: loading · empty · error · success · permission denied
          </div>
        </div>
        <DSChip variant={theme === 'dark' ? 'brand' : 'success'}>{theme === 'dark' ? '☾ тёмная' : '☀ светлая'}</DSChip>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>

        {/* LOADING — skeleton */}
        <DSCard variant="elevated">
          <div style={{ padding: 18 }}>
            <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 10 }}>loading · skeleton</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
              <DSSkel w={44} h={44} style={{ borderRadius: 50 }} />
              <div style={{ flex: 1 }}>
                <DSSkel w="60%" h={14} />
                <DSSkel w="40%" h={11} style={{ marginTop: 6 }} />
              </div>
              <DSSkel w={80} h={28} />
            </div>
            {[1,2,3].map(i => (
              <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 80px 60px', gap: 16, padding: '10px 0', borderTop: '1px solid var(--ds-outline-soft)' }}>
                <DSSkel h={12} />
                <DSSkel h={12} />
                <DSSkel h={12} />
              </div>
            ))}
            <div className="ds-body-s ds-muted" style={{ marginTop: 12, fontStyle: 'italic' }}>
              Не fullscreen spinner. Скелетон в форме контента — глаз готов к тому, что появится.
            </div>
          </div>
        </DSCard>

        {/* EMPTY — illustration + CTA */}
        <DSCard variant="elevated">
          <div style={{ padding: 32, textAlign: 'center' }}>
            <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 18 }}>empty</div>
            <EmptyIllustration />
            <div className="ds-title-l" style={{ marginTop: 16 }}>Здесь будут ваши клиенты</div>
            <div className="ds-body-m ds-muted" style={{ marginTop: 6, maxWidth: 320, margin: '6px auto 0' }}>
              Добавьте первого клиента — он автоматически свяжется с контрактами и реестром.
            </div>
            <div style={{ marginTop: 18, display: 'flex', gap: 8, justifyContent: 'center' }}>
              <DSButton variant="filled">＋ Добавить клиента</DSButton>
              <DSButton variant="text">импорт CSV</DSButton>
            </div>
          </div>
        </DSCard>

        {/* ERROR — retry */}
        <DSCard variant="elevated">
          <div style={{ padding: 32, textAlign: 'center' }}>
            <div className="ds-label-m" style={{ color: 'var(--ds-error)', marginBottom: 18 }}>error</div>
            <div style={{ display: 'inline-flex', width: 72, height: 72, borderRadius: 50, background: 'var(--ds-error-container)', color: 'var(--ds-error)', alignItems: 'center', justifyContent: 'center', fontSize: 30 }}>!</div>
            <div className="ds-title-l" style={{ marginTop: 16 }}>Не удалось загрузить данные</div>
            <div className="ds-body-m ds-muted" style={{ marginTop: 6, maxWidth: 360, margin: '6px auto 0' }}>
              Похоже, сервер сейчас недоступен. Попробуйте через минуту или обратитесь в поддержку.
            </div>
            <div style={{ marginTop: 18, display: 'flex', gap: 8, justifyContent: 'center' }}>
              <DSButton variant="filled">↻ Попробовать снова</DSButton>
              <DSButton variant="text">в поддержку →</DSButton>
            </div>
            <div className="ds-mono ds-body-s ds-faint" style={{ marginTop: 14 }}>error · 503 · req-id 8c2f-04a1</div>
          </div>
        </DSCard>

        {/* SUCCESS */}
        <DSCard variant="elevated">
          <div style={{ padding: 32, textAlign: 'center', position: 'relative', overflow: 'hidden' }}>
            <BrandWaves height={120} style={{ position: 'absolute', left: 0, right: 0, bottom: 0, opacity: 0.4 }} />
            <div style={{ position: 'relative' }}>
              <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 18 }}>success</div>
              <div style={{ display: 'inline-flex', width: 80, height: 80, borderRadius: 50, background: 'var(--ds-success-container)', color: 'var(--ds-success)', alignItems: 'center', justifyContent: 'center', fontSize: 36 }}>✓</div>
              <div className="ds-title-l" style={{ marginTop: 16 }}>Реестр выплат отправлен</div>
              <div className="ds-body-m ds-muted" style={{ marginTop: 6, maxWidth: 360, margin: '6px auto 0' }}>
                148 партнёров · 12 482 530 ₽. Деньги дойдут в течение 2 рабочих дней.
              </div>
              <div style={{ marginTop: 18, display: 'flex', gap: 8, justifyContent: 'center' }}>
                <DSButton variant="filled">К реестру →</DSButton>
                <DSButton variant="text">экспорт отчёта</DSButton>
              </div>
            </div>
          </div>
        </DSCard>

        {/* PERMISSION DENIED */}
        <DSCard variant="elevated" style={{ gridColumn: '1 / -1' }}>
          <div style={{ padding: 28, display: 'grid', gridTemplateColumns: '120px 1fr auto', gap: 20, alignItems: 'center' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: 100, height: 100, borderRadius: 50, background: 'var(--ds-surface-container-high)', color: 'var(--ds-on-surface-muted)', fontSize: 40 }}>🔒</div>
            <div>
              <div className="ds-label-m" style={{ color: 'var(--ds-on-surface-muted)', marginBottom: 4 }}>permission denied</div>
              <div className="ds-title-l">Раздел доступен только администраторам</div>
              <div className="ds-body-m ds-muted" style={{ marginTop: 6 }}>
                Ваша роль <b>«Партнёр»</b>. Чтобы получить доступ к реестру выплат — обратитесь к руководителю отдела или в поддержку.
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <DSButton variant="outlined">← назад</DSButton>
              <DSButton variant="filled">в поддержку</DSButton>
            </div>
          </div>
        </DSCard>

      </div>
    </div>
  );
}

function EmptyIllustration() {
  return (
    <svg viewBox="0 0 200 130" style={{ width: 200, height: 130 }}>
      <defs>
        <linearGradient id="emp-grad" x1="0" x2="1" y1="0" y2="1">
          <stop offset="0%" stopColor="var(--ds-primary)" stopOpacity="0.2" />
          <stop offset="100%" stopColor="var(--ds-secondary)" stopOpacity="0.5" />
        </linearGradient>
      </defs>
      <rect x="22" y="34" width="118" height="80" rx="10" fill="var(--ds-surface-container-high)" />
      <rect x="34" y="48" width="60" height="6" rx="3" fill="var(--ds-on-surface-faint)" />
      <rect x="34" y="62" width="80" height="6" rx="3" fill="var(--ds-on-surface-faint)" opacity="0.6" />
      <rect x="34" y="76" width="40" height="6" rx="3" fill="var(--ds-on-surface-faint)" opacity="0.4" />
      <circle cx="148" cy="76" r="34" fill="url(#emp-grad)" />
      <circle cx="148" cy="76" r="18" fill="var(--ds-surface)" />
      <text x="148" y="83" textAnchor="middle" fontFamily="Inter, sans-serif" fontSize="22" fontWeight="600" fill="var(--ds-primary)">＋</text>
    </svg>
  );
}

// ─────────── NAV PATTERNS — sidebar + appbar ───────────
function DSP_Nav({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 16, marginBottom: 18 }}>
        <div>
          <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 4 }}>patterns</div>
          <div className="ds-headline-l">Навигация</div>
          <div className="ds-body-l ds-muted" style={{ marginTop: 6, maxWidth: 720 }}>
            app-bar + navigation-drawer · 3 варианта по ролям
          </div>
        </div>
        <DSChip variant={theme === 'dark' ? 'brand' : 'success'}>{theme === 'dark' ? '☾ тёмная' : '☀ светлая'}</DSChip>
      </div>

      {/* AppBar */}
      <div className="ds-card" style={{ overflow: 'hidden' }}>
        <div className="ds-label-m ds-muted" style={{ padding: '14px 18px 4px' }}>App-bar · top</div>
        <div style={{
          height: 60, padding: '0 18px', display: 'flex', alignItems: 'center', gap: 16,
          background: 'var(--ds-surface)', borderTop: '1px solid var(--ds-outline-variant)',
          borderBottom: '1px solid var(--ds-outline-variant)',
        }}>
          <DSMark size={28} />
          <div className="ds-title-m">DS Consulting</div>
          <div style={{ flex: 1, maxWidth: 480, marginLeft: 28 }}>
            <DSField placeholder="Поиск по клиентам, контрактам, материалам…" prefix="⌕" />
          </div>
          <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ position: 'relative' }}>
              <DSButton variant="text" icon>⚐</DSButton>
              <div style={{ position: 'absolute', top: 0, right: 0 }}><DSBadge>4</DSBadge></div>
            </div>
            <DSButton variant="text" icon>❑</DSButton>
            <DSDivider vertical style={{ height: 24 }} />
            <DSAvatar initials="ИП" status />
          </div>
        </div>
      </div>

      {/* Sidebars */}
      <div style={{ display: 'grid', gridTemplateColumns: '260px 240px 260px', gap: 18, marginTop: 18 }}>
        <SidebarSample role="partner" />
        <SidebarSample role="rail" />
        <SidebarSample role="manager" />
      </div>
    </div>
  );
}

function SidebarSample({ role }) {
  if (role === 'rail') {
    const items = [
      { ico: '⌂', label: 'Главная', active: true },
      { ico: '▦', label: 'Дашборд' },
      { ico: '⚇', label: 'Клиенты', badge: 12 },
      { ico: '▤', label: 'Контракты' },
      { ico: '¤', label: 'Финансы' },
      { ico: '⏏', label: 'Обучение' },
      { ico: '❑', label: 'Чат', badge: 3 },
      { ico: '⚙', label: 'Настройки' },
    ];
    return (
      <div className="ds-card" style={{ padding: 8, height: 460, display: 'flex', flexDirection: 'column', gap: 4 }}>
        <div className="ds-label-m ds-muted" style={{ padding: '6px 10px', textAlign: 'center' }}>rail</div>
        {items.map((it, i) => (
          <div key={i} title={it.label} style={{
            position: 'relative',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            width: 56, height: 48, margin: '0 auto', borderRadius: 12,
            background: it.active ? 'var(--ds-primary-soft)' : 'transparent',
            color: it.active ? 'var(--ds-primary)' : 'var(--ds-on-surface-variant)',
            fontSize: 18,
          }}>
            {it.ico}
            {it.badge && <div style={{ position: 'absolute', top: 4, right: 4 }}><DSBadge>{it.badge}</DSBadge></div>}
          </div>
        ))}
      </div>
    );
  }

  if (role === 'manager') {
    // dark, manager
    return (
      <div data-ds-theme="dark" style={{ borderRadius: 12, overflow: 'hidden', height: 460, background: 'var(--ds-surface)', border: '1px solid var(--ds-outline-variant)' }}>
        <div style={{ padding: '14px 14px 6px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: '1px solid var(--ds-outline-variant)' }}>
          <DSMark size={24} />
          <div>
            <div className="ds-title-s" style={{ color: 'var(--ds-on-surface)' }}>DS Платформа</div>
            <div className="ds-body-s ds-muted">Менеджер</div>
          </div>
        </div>
        <div style={{ padding: 8, overflow: 'auto', height: 'calc(100% - 56px)' }}>
          {[
            { sec: 'инструменты', items: [
              { ico: '⌂', label: 'Workspace', active: true, count: 12 },
              { ico: '▦', label: 'Периоды' },
              { ico: '☷', label: 'Структура' },
            ]},
            { sec: 'данные', items: [
              { ico: '⚇', label: 'Партнёры' },
              { ico: '⚇', label: 'Клиенты' },
              { ico: '▤', label: 'Менеджер контрактов' },
              { ico: '⤒', label: 'Загрузка контрактов' },
              { ico: '✓', label: 'Приёмка', count: 7 },
            ]},
            { sec: 'финансы', items: [
              { ico: '¤', label: 'Импорт транзакций' },
              { ico: '⇄', label: 'Транзакции' },
              { ico: '%', label: 'Комиссии' },
              { ico: '◷', label: 'Реестр выплат' },
            ]},
          ].map((g, gi) => (
            <React.Fragment key={gi}>
              <div className="ds-nav-section" style={{ color: 'var(--ds-on-surface-muted)' }}>{g.sec.toUpperCase()}</div>
              {g.items.map((it, i) => (
                <div key={i} className="ds-nav-item" data-active={it.active ? 'true' : 'false'}>
                  <span className="ds-ico" style={{ fontSize: 16 }}>{it.ico}</span>
                  <span>{it.label}</span>
                  {it.count && <span className="count">{it.count}</span>}
                </div>
              ))}
            </React.Fragment>
          ))}
        </div>
      </div>
    );
  }

  // partner
  return (
    <div className="ds-card" style={{ height: 460, overflow: 'hidden' }}>
      <div style={{ padding: '14px 14px 6px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: '1px solid var(--ds-outline-variant)' }}>
        <DSMark size={24} />
        <div>
          <div className="ds-title-s">DS Consulting</div>
          <div className="ds-body-s ds-muted">Партнёр · senior</div>
        </div>
      </div>
      <div style={{ padding: 8, overflow: 'auto', height: 'calc(100% - 56px)' }}>
        {[
          { sec: 'работа', items: [
            { ico: '⌂', label: 'Главная', active: true },
            { ico: '▦', label: 'Дашборд' },
            { ico: '☷', label: 'Структура' },
          ]},
          { sec: 'продажи', items: [
            { ico: '⚇', label: 'Клиенты', count: 142 },
            { ico: '▤', label: 'Контракты', count: 89 },
            { ico: '¤', label: 'Финансы' },
            { ico: '⏏', label: 'Продукты' },
          ]},
          { sec: 'обучение', items: [
            { ico: '⏏', label: 'Курсы', count: 4 },
            { ico: '▥', label: 'База знаний' },
            { ico: '★', label: 'Конкурсы' },
          ]},
          { sec: 'аккаунт', items: [
            { ico: '❑', label: 'Чат', count: 3 },
            { ico: '⚇', label: 'Профиль' },
          ]},
        ].map((g, gi) => (
          <React.Fragment key={gi}>
            <div className="ds-nav-section">{g.sec.toUpperCase()}</div>
            {g.items.map((it, i) => (
              <div key={i} className="ds-nav-item" data-active={it.active ? 'true' : 'false'}>
                <span className="ds-ico" style={{ fontSize: 16 }}>{it.ico}</span>
                <span>{it.label}</span>
                {it.count && <span className="count">{it.count}</span>}
              </div>
            ))}
          </React.Fragment>
        ))}
      </div>
    </div>
  );
}

Object.assign(window, { DSP_States, DSP_Nav, SidebarSample, EmptyIllustration });
