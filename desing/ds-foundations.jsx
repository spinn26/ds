// ds-foundations.jsx — фундамент дизайн-системы
// Палитра, типографика, spacing, радиусы, тени, motion, иконография, бренд.

// helper: read CSS variable by name relative to an element
function getVar(el, name) {
  return getComputedStyle(el).getPropertyValue(name).trim();
}

// ─────────── COLORS ───────────
function DSF_Colors({ theme = 'light' }) {
  // groups of MD3 roles to show
  const groups = [
    {
      title: 'Brand · primary',
      pairs: [
        ['--ds-primary', '--ds-on-primary'],
        ['--ds-primary-container', '--ds-on-primary-container'],
        ['--ds-primary-soft', '--ds-on-primary-container'],
      ],
    },
    {
      title: 'Brand · secondary (mint)',
      pairs: [
        ['--ds-secondary', '--ds-on-secondary'],
        ['--ds-secondary-container', '--ds-on-secondary-container'],
      ],
    },
    {
      title: 'Tertiary · info',
      pairs: [
        ['--ds-tertiary', '--ds-on-tertiary'],
        ['--ds-tertiary-container', '--ds-on-tertiary-container'],
      ],
    },
    {
      title: 'Status · success / warning / error / info',
      pairs: [
        ['--ds-success', '--ds-on-success'],
        ['--ds-success-container', '--ds-on-success-container'],
        ['--ds-warning', '--ds-on-warning'],
        ['--ds-warning-container', '--ds-on-warning-container'],
        ['--ds-error', '--ds-on-error'],
        ['--ds-error-container', '--ds-on-error-container'],
        ['--ds-info', '--ds-on-info'],
        ['--ds-info-container', '--ds-on-info-container'],
      ],
    },
  ];

  const surfaces = [
    '--ds-background', '--ds-surface',
    '--ds-surface-container-lowest', '--ds-surface-container-low',
    '--ds-surface-container', '--ds-surface-container-high',
    '--ds-surface-container-highest',
  ];

  const lines = [
    '--ds-outline', '--ds-outline-variant', '--ds-outline-soft',
  ];

  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSF_Header theme={theme} title="Цвет" subtitle="MD3 color roles · light + dark — изокартина с самой темы" />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 22, marginTop: 22 }}>
        {groups.map((g, gi) => (
          <div key={gi}>
            <div className="ds-label-m ds-muted" style={{ marginBottom: 10 }}>{g.title}</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {g.pairs.map((p, pi) => <ColorBar key={pi} bg={p[0]} fg={p[1]} />)}
            </div>
          </div>
        ))}

        <div>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 10 }}>Surface family · 5 ступеней</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {surfaces.map((s, i) => <ColorBar key={i} bg={s} fg="--ds-on-surface" />)}
          </div>
        </div>

        <div>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 10 }}>Outline / divider</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {lines.map((s, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <div style={{ flex: 1, height: 36, background: 'var(--ds-surface)', border: `1px solid var(${s})`, borderRadius: 8 }}></div>
                <div className="ds-mono ds-body-s" style={{ minWidth: 180 }}>{s}</div>
              </div>
            ))}
          </div>
          <div className="ds-label-m ds-muted" style={{ marginTop: 24, marginBottom: 10 }}>Text on surface</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <div className="ds-body-m" style={{ color: 'var(--ds-on-surface)' }}>on-surface — основной текст</div>
            <div className="ds-body-m" style={{ color: 'var(--ds-on-surface-variant)' }}>on-surface-variant — вторичный</div>
            <div className="ds-body-m" style={{ color: 'var(--ds-on-surface-muted)' }}>on-surface-muted — подписи, мета</div>
            <div className="ds-body-m" style={{ color: 'var(--ds-on-surface-faint)' }}>on-surface-faint — disabled</div>
          </div>
        </div>
      </div>
    </div>
  );
}

function ColorBar({ bg, fg }) {
  // computed values shown via ref-based effect
  const ref = React.useRef(null);
  const [val, setVal] = React.useState({ bg: '…', fg: '…' });
  React.useEffect(() => {
    if (!ref.current) return;
    setVal({ bg: getVar(ref.current, bg), fg: getVar(ref.current, fg) });
  }, [bg, fg]);
  return (
    <div ref={ref} style={{
      display: 'flex', alignItems: 'center', gap: 14,
      padding: '12px 14px',
      background: `var(${bg})`, color: `var(${fg})`,
      borderRadius: 8, border: '1px solid var(--ds-outline-variant)',
    }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="ds-label-l" style={{ textTransform: 'none', letterSpacing: 0 }}>{bg.replace('--ds-', '')}</div>
        <div className="ds-mono ds-body-s" style={{ opacity: 0.7, marginTop: 2 }}>{val.bg}</div>
      </div>
      <div className="ds-mono ds-body-s" style={{ opacity: 0.7, padding: '2px 8px', border: '1px solid currentColor', borderRadius: 4 }}>Aa</div>
    </div>
  );
}

// ─────────── TYPOGRAPHY ───────────
function DSF_Type({ theme = 'light' }) {
  const rows = [
    { scale: 'Display L', cls: 'ds-display-l', sample: 'DS Consulting', def: '700 · 56/1.12 · −1px' },
    { scale: 'Display M', cls: 'ds-display-m', sample: 'Платформа партнёров', def: '700 · 44/1.15 · −0.8px' },
    { scale: 'Display S', cls: 'ds-display-s', sample: 'Закрываем квартал', def: '700 · 36/1.2 · −0.6px' },
    { scale: 'Headline L', cls: 'ds-headline-l', sample: 'Реестр выплат · март', def: '700 · 30/1.22' },
    { scale: 'Headline M', cls: 'ds-headline-m', sample: 'Клиенты партнёра', def: '700 · 24/1.25' },
    { scale: 'Headline S', cls: 'ds-headline-s', sample: 'Карточка контракта', def: '700 · 20/1.3' },
    { scale: 'Title L', cls: 'ds-title-l', sample: 'Заголовок секции', def: '600 · 18/1.35' },
    { scale: 'Title M', cls: 'ds-title-m', sample: 'Подзаголовок карточки', def: '600 · 15/1.4' },
    { scale: 'Title S', cls: 'ds-title-s', sample: 'Список / навигация', def: '600 · 13/1.4' },
    { scale: 'Body L', cls: 'ds-body-l', sample: 'Длинный контент — описание курса или регламент. Текст 15px удобно читать на десктопе и планшете без щурения.', def: '400 · 15/1.55' },
    { scale: 'Body M', cls: 'ds-body-m', sample: 'Базовый текст в таблицах, формах, карточках. 14px — основная плотность интерфейса.', def: '400 · 14/1.5' },
    { scale: 'Body S', cls: 'ds-body-s', sample: 'Мета-информация, хинты, captions. Стараемся ограничивать использование.', def: '400 · 13/1.5' },
    { scale: 'Label L', cls: 'ds-label-l', sample: 'КНОПКА · ТАБ', def: '600 · 13/1.3' },
    { scale: 'Label M', cls: 'ds-label-m', sample: 'EYEBROW · UPPERCASE', def: '600 · 12/1.3 · +0.6' },
    { scale: 'Label S', cls: 'ds-label-s', sample: 'TAG · STATUS', def: '600 · 11/1.3 · +1' },
  ];
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSF_Header theme={theme} title="Типографика" subtitle="Inter — основной · JetBrains Mono — числа, токены, таймкоды" />

      <div style={{ marginTop: 18, padding: 22, background: 'var(--ds-surface)', border: '1px solid var(--ds-outline-variant)', borderRadius: 12, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 28 }}>
        <div>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 10 }}>основной — Inter</div>
          <div className="ds-display-m">Aa Bb Cc</div>
          <div className="ds-body-m ds-muted" style={{ marginTop: 6 }}>400 · 500 · 600 · 700</div>
        </div>
        <div>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 10 }}>числа — JetBrains Mono</div>
          <div className="ds-display-m ds-mono">1 234 567,89 ₽</div>
          <div className="ds-body-m ds-muted ds-mono" style={{ marginTop: 6 }}>tabular-nums</div>
        </div>
      </div>

      <div style={{ marginTop: 22, display: 'flex', flexDirection: 'column', gap: 14 }}>
        {rows.map((r, i) => (
          <div key={i} style={{
            display: 'grid', gridTemplateColumns: '120px 1fr 140px',
            gap: 18, alignItems: 'baseline',
            padding: '12px 16px', border: '1px solid var(--ds-outline-variant)', borderRadius: 10, background: 'var(--ds-surface)',
          }}>
            <div className="ds-label-m ds-muted">{r.scale}</div>
            <div className={r.cls}>{r.sample}</div>
            <div className="ds-mono ds-body-s ds-faint" style={{ textAlign: 'right' }}>{r.def}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─────────── SPACING / RADII / SHADOWS / MOTION ───────────
function DSF_Tokens({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSF_Header theme={theme} title="Spacing · радиусы · тени · motion" subtitle="скелетные токены, на которых стоят все компоненты" />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 22, marginTop: 22 }}>

        {/* spacing */}
        <div className="ds-card" style={{ padding: 22 }}>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 14 }}>отступы · 4px база</div>
          {[1,2,3,4,5,6,7,8,9,10].map(n => {
            const px = ({1:4,2:8,3:12,4:16,5:20,6:24,7:32,8:40,9:56,10:72})[n];
            return (
              <div key={n} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '6px 0' }}>
                <div className="ds-mono ds-body-s" style={{ width: 60, color: 'var(--ds-on-surface-variant)' }}>space-{n}</div>
                <div style={{ width: px, height: 14, background: 'var(--ds-primary)', borderRadius: 3 }}></div>
                <div className="ds-mono ds-body-s ds-faint">{px}px</div>
              </div>
            );
          })}
        </div>

        {/* radii */}
        <div className="ds-card" style={{ padding: 22 }}>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 14 }}>скругления</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
            {[
              { name: 'xs', px: 4 },
              { name: 'sm', px: 6 },
              { name: 'md', px: 8 },
              { name: 'lg', px: 12 },
              { name: 'xl', px: 16 },
              { name: '2xl', px: 24 },
              { name: 'pill', px: 999 },
            ].map((r, i) => (
              <div key={i} style={{ textAlign: 'center' }}>
                <div style={{
                  width: '100%', height: 56, background: 'var(--ds-primary-soft)',
                  border: '1.5px solid var(--ds-primary)', borderRadius: r.px,
                }}></div>
                <div className="ds-mono ds-body-s ds-muted" style={{ marginTop: 6 }}>{r.name}</div>
                <div className="ds-mono ds-body-s ds-faint">{r.px === 999 ? '999' : r.px + 'px'}</div>
              </div>
            ))}
          </div>
        </div>

        {/* shadows */}
        <div className="ds-card" style={{ padding: 22 }}>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 14 }}>тени · 4 уровня</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 16 }}>
            {[1,2,3,4].map(n => (
              <div key={n} style={{
                padding: 16, background: 'var(--ds-surface)',
                borderRadius: 12, boxShadow: `var(--ds-shadow-${n})`,
              }}>
                <div className="ds-mono ds-body-s ds-muted">shadow-{n}</div>
                <div className="ds-title-s" style={{ marginTop: 4 }}>elevation {n}</div>
              </div>
            ))}
          </div>
          <div style={{ marginTop: 18, padding: 14, background: 'var(--ds-surface)', borderRadius: 10, boxShadow: 'var(--ds-shadow-focus)' }}>
            <div className="ds-mono ds-body-s ds-muted">shadow-focus</div>
            <div className="ds-title-s" style={{ marginTop: 2 }}>focus ring · 3px primary 18%</div>
          </div>
        </div>

        {/* motion */}
        <div className="ds-card" style={{ padding: 22 }}>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 14 }}>motion</div>
          {[
            { name: 'fast', ms: 120, use: 'микро-фидбек (hover, focus)' },
            { name: 'medium', ms: 200, use: 'переключение состояний' },
            { name: 'slow', ms: 320, use: 'появление/исчезание панелей' },
            { name: 'emphasized', ms: 480, use: 'значимые переходы, success' },
          ].map((m, i) => (
            <div key={i} style={{ display: 'grid', gridTemplateColumns: '90px 60px 1fr', gap: 12, alignItems: 'baseline', padding: '6px 0' }}>
              <div className="ds-mono ds-body-s">dur-{m.name}</div>
              <div className="ds-mono ds-body-s ds-faint">{m.ms}ms</div>
              <div className="ds-body-s ds-muted">{m.use}</div>
            </div>
          ))}
          <div className="ds-divider" style={{ margin: '14px 0' }}></div>
          {[
            { name: 'standard', def: 'cubic-bezier(.2,0,0,1)' },
            { name: 'emphasized', def: 'cubic-bezier(.3,0,0,1)' },
            { name: 'decelerate', def: 'cubic-bezier(0,0,0,1)' },
            { name: 'accelerate', def: 'cubic-bezier(.3,0,1,1)' },
          ].map((e, i) => (
            <div key={i} style={{ display: 'grid', gridTemplateColumns: '120px 1fr', gap: 12, padding: '4px 0' }}>
              <div className="ds-mono ds-body-s">ease-{e.name}</div>
              <div className="ds-mono ds-body-s ds-faint">{e.def}</div>
            </div>
          ))}
        </div>

      </div>
    </div>
  );
}

// ─────────── BRAND ───────────
function DSF_Brand({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSF_Header theme={theme} title="Бренд" subtitle="лого, mark, BrandWaves — параметрический декор" />

      <div style={{ marginTop: 22, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 22 }}>
        <div className="ds-card" style={{ padding: 28 }}>
          <div className="ds-label-m ds-muted" style={{ marginBottom: 18 }}>лого · mark</div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 22, flexWrap: 'wrap' }}>
            <DSMark size={64} />
            <DSMark size={44} />
            <DSMark size={28} />
            <DSMark size={18} />
          </div>
          <div className="ds-divider" style={{ margin: '22px 0' }}></div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <DSMark size={36} />
            <div>
              <div className="ds-title-l" style={{ color: 'var(--ds-on-surface)' }}>DS Consulting</div>
              <div className="ds-body-s ds-muted">Партнёрская платформа</div>
            </div>
          </div>
        </div>

        <div className="ds-card" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ padding: 22 }}>
            <div className="ds-label-m ds-muted">BrandWaves · параметрический SVG</div>
            <div className="ds-body-s ds-muted" style={{ marginTop: 6 }}>декор для hero, входа, success-моментов</div>
          </div>
          <BrandWaves height={180} />
        </div>

        <div className="ds-card" style={{ padding: 0, overflow: 'hidden', gridColumn: '1 / -1' }}>
          <div style={{ position: 'relative', padding: '36px 36px 32px', overflow: 'hidden' }}>
            <BrandWaves height={220} style={{ position: 'absolute', left: 0, right: 0, bottom: 0, opacity: 0.55 }} />
            <div style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 14, marginBottom: 14 }}>
              <DSMark size={42} />
              <div className="ds-headline-m">Войдите в кабинет</div>
            </div>
            <div className="ds-body-l ds-muted" style={{ position: 'relative', maxWidth: 520 }}>
              Пример применения hero-секции с BrandWaves: онбординг, лог-ин, success-страница «продукт открыт».
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─────────── ICONOGRAPHY ───────────
function DSF_Icons({ theme = 'light' }) {
  const groups = [
    { title: 'Навигация', items: ['mdi-home', 'mdi-view-dashboard', 'mdi-account-group', 'mdi-handshake', 'mdi-file-document', 'mdi-cash-multiple', 'mdi-school', 'mdi-chat', 'mdi-cog', 'mdi-bell'] },
    { title: 'Действия', items: ['mdi-plus', 'mdi-pencil', 'mdi-delete', 'mdi-download', 'mdi-upload', 'mdi-content-copy', 'mdi-magnify', 'mdi-filter-variant', 'mdi-refresh', 'mdi-dots-vertical'] },
    { title: 'Статусы', items: ['mdi-check-circle', 'mdi-alert-circle', 'mdi-close-circle', 'mdi-information', 'mdi-lock', 'mdi-circle-outline', 'mdi-circle-slice-4'] },
    { title: 'Контент', items: ['mdi-file-pdf-box', 'mdi-image', 'mdi-video', 'mdi-headphones', 'mdi-link', 'mdi-presentation', 'mdi-text-box'] },
  ];
  const sample = { 'mdi-home':'⌂', 'mdi-view-dashboard':'▦', 'mdi-account-group':'⚇', 'mdi-handshake':'🤝', 'mdi-file-document':'▤', 'mdi-cash-multiple':'¤', 'mdi-school':'⏏', 'mdi-chat':'❑', 'mdi-cog':'⚙', 'mdi-bell':'⚐', 'mdi-plus':'+', 'mdi-pencil':'✎', 'mdi-delete':'🗑', 'mdi-download':'⤓', 'mdi-upload':'⤒', 'mdi-content-copy':'⎘', 'mdi-magnify':'⌕', 'mdi-filter-variant':'⏷', 'mdi-refresh':'↻', 'mdi-dots-vertical':'⋮', 'mdi-check-circle':'✓', 'mdi-alert-circle':'!', 'mdi-close-circle':'×', 'mdi-information':'i', 'mdi-lock':'🔒', 'mdi-circle-outline':'○', 'mdi-circle-slice-4':'◐', 'mdi-file-pdf-box':'▤', 'mdi-image':'▣', 'mdi-video':'▶', 'mdi-headphones':'♪', 'mdi-link':'∞', 'mdi-presentation':'▥', 'mdi-text-box':'≡' };
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSF_Header theme={theme} title="Иконография" subtitle="только MDI · 18/20/24px · ровно по линии текста" />
      <div style={{ marginTop: 22, display: 'flex', flexDirection: 'column', gap: 22 }}>
        {groups.map((g, gi) => (
          <div key={gi}>
            <div className="ds-label-m ds-muted" style={{ marginBottom: 10 }}>{g.title}</div>
            <div className="ds-card" style={{ padding: 14, display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 8 }}>
              {g.items.map((n, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 8, background: 'var(--ds-surface-container-low)' }}>
                  <div style={{ width: 28, height: 28, borderRadius: 6, background: 'var(--ds-surface)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--ds-on-surface-variant)', fontSize: 16, border: '1px solid var(--ds-outline-variant)' }}>{sample[n]}</div>
                  <div className="ds-mono ds-body-s">{n}</div>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─────────── shared header ───────────
function DSF_Header({ theme, title, subtitle }) {
  return (
    <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 16 }}>
      <div>
        <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 4 }}>foundation</div>
        <div className="ds-headline-l">{title}</div>
        <div className="ds-body-l ds-muted" style={{ marginTop: 6, maxWidth: 720 }}>{subtitle}</div>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <DSChip variant={theme === 'dark' ? 'brand' : 'success'}>{theme === 'dark' ? '☾ тёмная' : '☀ светлая'}</DSChip>
      </div>
    </div>
  );
}

Object.assign(window, { DSF_Colors, DSF_Type, DSF_Tokens, DSF_Brand, DSF_Icons });
