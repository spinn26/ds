// ds-primitives.jsx — компоненты дизайн-системы DS Consulting
// Все используют CSS-токены из ds-tokens.css.
// Компоненты названы по аналогии с Vuetify, чтобы при переносе фронт
// просто заменял <DSButton> → <v-btn> и т.д.

// One-time injection of component CSS that depends on tokens.
if (typeof document !== 'undefined' && !document.getElementById('ds-component-styles')) {
  const s = document.createElement('style');
  s.id = 'ds-component-styles';
  s.textContent = `
    .ds, .ds * { box-sizing: border-box; }
    .ds {
      font: var(--ds-type-body-m);
      color: var(--ds-on-surface);
      -webkit-font-smoothing: antialiased;
    }
    .ds h1, .ds h2, .ds h3, .ds h4, .ds p { margin: 0; }
    .ds-screen-bg { background: var(--ds-background); color: var(--ds-on-background); height: 100%; }

    /* type helpers */
    .ds-display-l { font: var(--ds-type-display-l); letter-spacing: -1px; }
    .ds-display-m { font: var(--ds-type-display-m); letter-spacing: -0.8px; }
    .ds-display-s { font: var(--ds-type-display-s); letter-spacing: -0.6px; }
    .ds-headline-l { font: var(--ds-type-headline-l); letter-spacing: -0.4px; }
    .ds-headline-m { font: var(--ds-type-headline-m); letter-spacing: -0.3px; }
    .ds-headline-s { font: var(--ds-type-headline-s); letter-spacing: -0.2px; }
    .ds-title-l { font: var(--ds-type-title-l); }
    .ds-title-m { font: var(--ds-type-title-m); }
    .ds-title-s { font: var(--ds-type-title-s); }
    .ds-body-l { font: var(--ds-type-body-l); }
    .ds-body-m { font: var(--ds-type-body-m); }
    .ds-body-s { font: var(--ds-type-body-s); }
    .ds-label-l { font: var(--ds-type-label-l); }
    .ds-label-m { font: var(--ds-type-label-m); letter-spacing: 0.6px; text-transform: uppercase; }
    .ds-label-s { font: var(--ds-type-label-s); letter-spacing: 1px; text-transform: uppercase; }
    .ds-mono { font-family: var(--ds-font-mono); font-variant-numeric: tabular-nums; }
    .ds-muted { color: var(--ds-on-surface-variant); }
    .ds-faint { color: var(--ds-on-surface-muted); }

    /* ─── button ─── */
    .ds-btn {
      display: inline-flex; align-items: center; justify-content: center;
      gap: 8px; height: var(--ds-h-control); padding: 0 20px;
      border-radius: var(--ds-radius-md); border: 1px solid transparent;
      font: var(--ds-type-label-l); cursor: pointer; white-space: nowrap;
      transition: background var(--ds-dur-fast) var(--ds-ease-standard),
                  color var(--ds-dur-fast) var(--ds-ease-standard),
                  border-color var(--ds-dur-fast) var(--ds-ease-standard),
                  box-shadow var(--ds-dur-fast) var(--ds-ease-standard),
                  transform var(--ds-dur-fast) var(--ds-ease-standard);
      user-select: none;
    }
    .ds-btn:focus-visible { outline: none; box-shadow: var(--ds-shadow-focus); }
    .ds-btn--filled { background: var(--ds-primary); color: var(--ds-on-primary); }
    .ds-btn--filled:hover { background: var(--ds-on-primary-container); }
    .ds-btn--tonal { background: var(--ds-primary-container); color: var(--ds-on-primary-container); }
    .ds-btn--tonal:hover { filter: brightness(0.95); }
    .ds-btn--outlined { background: transparent; border-color: var(--ds-outline); color: var(--ds-on-surface); }
    .ds-btn--outlined:hover { background: var(--ds-overlay); }
    .ds-btn--text { background: transparent; color: var(--ds-primary); padding: 0 12px; }
    .ds-btn--text:hover { background: var(--ds-primary-soft); }
    .ds-btn--elevated { background: var(--ds-surface); color: var(--ds-primary); box-shadow: var(--ds-shadow-1); }
    .ds-btn--elevated:hover { box-shadow: var(--ds-shadow-2); }
    .ds-btn--danger { background: var(--ds-error); color: var(--ds-on-error); }
    .ds-btn--danger:hover { filter: brightness(0.92); }
    .ds-btn--sm { height: var(--ds-h-control-sm); padding: 0 14px; font: var(--ds-type-label-m); }
    .ds-btn--lg { height: var(--ds-h-control-lg); padding: 0 28px; font-size: 15px; }
    .ds-btn--icon { width: var(--ds-h-control); padding: 0; }
    .ds-btn--icon.ds-btn--sm { width: var(--ds-h-control-sm); }
    .ds-btn--icon.ds-btn--lg { width: var(--ds-h-control-lg); }
    .ds-btn--block { width: 100%; }
    .ds-btn:disabled, .ds-btn--disabled {
      background: var(--ds-surface-container-high); color: var(--ds-on-surface-faint);
      border-color: transparent; cursor: not-allowed; pointer-events: none;
    }

    /* ─── chip ─── */
    .ds-chip {
      display: inline-flex; align-items: center; gap: 6px;
      height: 26px; padding: 0 10px;
      border-radius: var(--ds-radius-pill); border: 1px solid var(--ds-outline-variant);
      background: var(--ds-surface-container); color: var(--ds-on-surface-variant);
      font: var(--ds-type-label-m);
    }
    .ds-chip--filter { cursor: pointer; }
    .ds-chip--filter[data-active="true"] { background: var(--ds-primary-soft); color: var(--ds-primary); border-color: var(--ds-primary); }
    .ds-chip--success { background: var(--ds-success-container); color: var(--ds-on-success-container); border-color: transparent; }
    .ds-chip--warning { background: var(--ds-warning-container); color: var(--ds-on-warning-container); border-color: transparent; }
    .ds-chip--error { background: var(--ds-error-container); color: var(--ds-on-error-container); border-color: transparent; }
    .ds-chip--info { background: var(--ds-info-container); color: var(--ds-on-info-container); border-color: transparent; }
    .ds-chip--brand { background: var(--ds-secondary-container); color: var(--ds-on-secondary-container); border-color: transparent; }

    /* ─── badge ─── */
    .ds-badge {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 18px; height: 18px; padding: 0 5px;
      border-radius: var(--ds-radius-pill);
      background: var(--ds-error); color: var(--ds-on-error);
      font: var(--ds-type-label-s); font-size: 10px;
    }
    .ds-badge--dot { width: 8px; min-width: 8px; height: 8px; padding: 0; }

    /* ─── field ─── */
    .ds-field {
      display: flex; flex-direction: column; gap: 6px;
    }
    .ds-field__label { font: var(--ds-type-label-m); color: var(--ds-on-surface-variant); }
    .ds-field__input {
      display: flex; align-items: center; gap: 8px;
      height: var(--ds-h-control); padding: 0 12px;
      background: var(--ds-surface-container-low); border: 1px solid var(--ds-outline-variant);
      border-radius: var(--ds-radius-md);
      transition: border-color var(--ds-dur-fast) var(--ds-ease-standard),
                  box-shadow var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-field__input:focus-within { border-color: var(--ds-primary); box-shadow: var(--ds-shadow-focus); }
    .ds-field__input input, .ds-field__input textarea {
      border: 0; outline: 0; background: transparent;
      color: var(--ds-on-surface); font: var(--ds-type-body-m); flex: 1; min-width: 0;
    }
    .ds-field__input input::placeholder { color: var(--ds-on-surface-muted); }
    .ds-field__input .prefix, .ds-field__input .suffix { color: var(--ds-on-surface-muted); font-size: 14px; flex-shrink: 0; }
    .ds-field--lg .ds-field__input { height: var(--ds-h-control-lg); padding: 0 14px; font-size: 15px; }
    .ds-field__hint { font: var(--ds-type-body-s); color: var(--ds-on-surface-muted); }
    .ds-field__hint.error { color: var(--ds-error); }
    .ds-field--error .ds-field__input { border-color: var(--ds-error); }
    .ds-field--disabled .ds-field__input { background: var(--ds-surface-container); opacity: 0.6; }
    .ds-textarea .ds-field__input { height: auto; min-height: 88px; padding: 10px 12px; align-items: flex-start; }

    /* ─── switch ─── */
    .ds-switch {
      display: inline-flex; align-items: center; gap: 10px;
      cursor: pointer; user-select: none;
    }
    .ds-switch__track {
      width: 38px; height: 22px; border-radius: var(--ds-radius-pill);
      background: var(--ds-surface-container-highest);
      border: 1.5px solid var(--ds-outline);
      position: relative;
      transition: background var(--ds-dur-fast) var(--ds-ease-standard),
                  border-color var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-switch__thumb {
      position: absolute; top: 2px; left: 2px;
      width: 14px; height: 14px; border-radius: 50%;
      background: var(--ds-on-surface-muted);
      transition: transform var(--ds-dur-medium) var(--ds-ease-emphasized),
                  background var(--ds-dur-fast) var(--ds-ease-standard),
                  width var(--ds-dur-medium) var(--ds-ease-emphasized),
                  height var(--ds-dur-medium) var(--ds-ease-emphasized);
    }
    .ds-switch[data-on="true"] .ds-switch__track {
      background: var(--ds-primary); border-color: var(--ds-primary);
    }
    .ds-switch[data-on="true"] .ds-switch__thumb {
      transform: translateX(16px); width: 18px; height: 18px;
      top: 0; left: 0;
      background: var(--ds-on-primary);
    }

    /* ─── checkbox ─── */
    .ds-checkbox {
      display: inline-flex; align-items: center; gap: 10px;
      cursor: pointer; user-select: none;
      font: var(--ds-type-body-m); color: var(--ds-on-surface);
    }
    .ds-checkbox__box {
      width: 18px; height: 18px; border-radius: 4px;
      border: 1.5px solid var(--ds-outline);
      display: flex; align-items: center; justify-content: center;
      color: var(--ds-on-primary); font-size: 12px;
      transition: background var(--ds-dur-fast) var(--ds-ease-standard),
                  border-color var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-checkbox[data-checked="true"] .ds-checkbox__box {
      background: var(--ds-primary); border-color: var(--ds-primary);
    }

    /* ─── radio ─── */
    .ds-radio {
      display: inline-flex; align-items: center; gap: 10px;
      cursor: pointer; user-select: none;
      font: var(--ds-type-body-m); color: var(--ds-on-surface);
    }
    .ds-radio__dot {
      width: 18px; height: 18px; border-radius: 50%;
      border: 1.5px solid var(--ds-outline);
      display: flex; align-items: center; justify-content: center;
      transition: border-color var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-radio__dot::after {
      content: ''; width: 8px; height: 8px; border-radius: 50%;
      background: var(--ds-primary); transform: scale(0);
      transition: transform var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-radio[data-checked="true"] .ds-radio__dot { border-color: var(--ds-primary); }
    .ds-radio[data-checked="true"] .ds-radio__dot::after { transform: scale(1); }

    /* ─── slider ─── */
    .ds-slider {
      position: relative; height: 22px; display: flex; align-items: center;
    }
    .ds-slider__rail {
      width: 100%; height: 4px; border-radius: var(--ds-radius-pill);
      background: var(--ds-surface-container-highest);
      position: relative; overflow: hidden;
    }
    .ds-slider__fill {
      height: 100%; background: var(--ds-primary); border-radius: var(--ds-radius-pill);
    }
    .ds-slider__thumb {
      position: absolute; top: 50%; transform: translate(-50%, -50%);
      width: 16px; height: 16px; border-radius: 50%; background: var(--ds-primary);
      box-shadow: 0 0 0 4px var(--ds-primary-soft);
    }

    /* ─── card ─── */
    .ds-card {
      background: var(--ds-surface);
      border: 1px solid var(--ds-outline-variant);
      border-radius: var(--ds-radius-lg);
      overflow: hidden;
    }
    .ds-card--elevated { border-color: transparent; box-shadow: var(--ds-shadow-1); }
    .ds-card--filled { background: var(--ds-surface-container); border-color: transparent; }
    .ds-card--brand { background: var(--ds-primary-soft); border-color: transparent; }

    /* ─── alert ─── */
    .ds-alert {
      display: flex; gap: 14px; align-items: flex-start;
      padding: 14px 16px;
      border-radius: var(--ds-radius-md);
      border: 1px solid transparent;
    }
    .ds-alert__icon {
      width: 24px; height: 24px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; flex-shrink: 0;
    }
    .ds-alert__title { font: var(--ds-type-title-s); margin-bottom: 2px; }
    .ds-alert__body { font: var(--ds-type-body-s); color: var(--ds-on-surface-variant); }
    .ds-alert--success { background: var(--ds-success-container); color: var(--ds-on-success-container); }
    .ds-alert--success .ds-alert__icon { background: var(--ds-success); color: var(--ds-on-success); }
    .ds-alert--warning { background: var(--ds-warning-container); color: var(--ds-on-warning-container); }
    .ds-alert--warning .ds-alert__icon { background: var(--ds-warning); color: var(--ds-on-warning); }
    .ds-alert--error { background: var(--ds-error-container); color: var(--ds-on-error-container); }
    .ds-alert--error .ds-alert__icon { background: var(--ds-error); color: var(--ds-on-error); }
    .ds-alert--info { background: var(--ds-info-container); color: var(--ds-on-info-container); }
    .ds-alert--info .ds-alert__icon { background: var(--ds-info); color: var(--ds-on-info); }

    /* ─── progress ─── */
    .ds-progress { width: 100%; height: 6px; border-radius: var(--ds-radius-pill); background: var(--ds-surface-container-highest); overflow: hidden; }
    .ds-progress__fill { height: 100%; background: var(--ds-primary); border-radius: var(--ds-radius-pill); transition: width var(--ds-dur-slow) var(--ds-ease-decelerate); }
    .ds-progress--brand .ds-progress__fill { background: linear-gradient(90deg, var(--ds-primary), var(--ds-secondary)); }
    .ds-progress--thin { height: 3px; }
    .ds-progress--thick { height: 10px; }

    /* ─── tabs ─── */
    .ds-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--ds-outline-variant); }
    .ds-tab {
      padding: 10px 16px; cursor: pointer;
      font: var(--ds-type-label-l); color: var(--ds-on-surface-variant);
      border-bottom: 2px solid transparent; margin-bottom: -1px;
      transition: color var(--ds-dur-fast) var(--ds-ease-standard),
                  border-color var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-tab:hover { color: var(--ds-on-surface); }
    .ds-tab[data-active="true"] { color: var(--ds-primary); border-bottom-color: var(--ds-primary); }
    .ds-tab .ds-badge { margin-left: 6px; background: var(--ds-surface-container-highest); color: var(--ds-on-surface-variant); }
    .ds-tab[data-active="true"] .ds-badge { background: var(--ds-primary-soft); color: var(--ds-primary); }

    /* ─── avatar ─── */
    .ds-avatar {
      display: inline-flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; border-radius: 50%;
      background: linear-gradient(135deg, var(--ds-primary), var(--ds-secondary));
      color: var(--ds-on-primary);
      font: var(--ds-type-label-m); flex-shrink: 0;
    }
    .ds-avatar--sm { width: 24px; height: 24px; font-size: 10px; }
    .ds-avatar--lg { width: 44px; height: 44px; font-size: 15px; }
    .ds-avatar--xl { width: 72px; height: 72px; font-size: 22px; }
    .ds-avatar--status::after {
      content: ''; position: absolute; right: -1px; bottom: -1px;
      width: 10px; height: 10px; border-radius: 50%; background: var(--ds-success);
      border: 2px solid var(--ds-surface);
    }
    .ds-avatar-wrap { position: relative; display: inline-flex; }

    /* ─── icon (small placeholder, real impl uses mdi) ─── */
    .ds-ico {
      display: inline-flex; align-items: center; justify-content: center;
      width: 1em; height: 1em; font-size: 18px; line-height: 1;
    }

    /* ─── divider ─── */
    .ds-divider { height: 1px; background: var(--ds-outline-variant); border: 0; margin: 0; }
    .ds-divider--v { width: 1px; height: 100%; background: var(--ds-outline-variant); }

    /* ─── tooltip ─── */
    .ds-tooltip {
      display: inline-block; padding: 5px 10px;
      background: var(--ds-on-surface); color: var(--ds-surface);
      border-radius: var(--ds-radius-sm); font: var(--ds-type-label-m);
    }

    /* ─── skeleton ─── */
    .ds-skel {
      background: var(--ds-surface-container-high);
      border-radius: var(--ds-radius-sm);
      position: relative; overflow: hidden;
    }
    .ds-skel::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(90deg, transparent, var(--ds-overlay), transparent);
      animation: ds-shimmer 1.4s linear infinite;
    }
    @keyframes ds-shimmer { from { transform: translateX(-100%); } to { transform: translateX(100%); } }

    /* ─── table ─── */
    .ds-table { width: 100%; border-collapse: collapse; font: var(--ds-type-body-m); }
    .ds-table thead th {
      text-align: left; padding: 12px 16px;
      font: var(--ds-type-label-m); color: var(--ds-on-surface-muted);
      background: var(--ds-surface-container-low);
      border-bottom: 1px solid var(--ds-outline-variant);
      letter-spacing: 0.6px; text-transform: uppercase;
    }
    .ds-table tbody td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--ds-outline-soft);
      color: var(--ds-on-surface);
    }
    .ds-table tbody tr:hover td { background: var(--ds-surface-container-low); }
    .ds-table tbody tr:last-child td { border-bottom: 0; }

    /* ─── pill / status pill ─── */
    .ds-status {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 3px 9px; border-radius: var(--ds-radius-pill);
      font: var(--ds-type-label-m);
    }
    .ds-status::before {
      content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor;
    }
    .ds-status--active { background: var(--ds-success-container); color: var(--ds-on-success-container); }
    .ds-status--draft { background: var(--ds-surface-container-high); color: var(--ds-on-surface-variant); }
    .ds-status--warn { background: var(--ds-warning-container); color: var(--ds-on-warning-container); }
    .ds-status--err { background: var(--ds-error-container); color: var(--ds-on-error-container); }
    .ds-status--info { background: var(--ds-info-container); color: var(--ds-on-info-container); }

    /* ─── menu item / list item ─── */
    .ds-list-item {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 12px; border-radius: var(--ds-radius-md);
      cursor: pointer; color: var(--ds-on-surface);
      transition: background var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-list-item:hover { background: var(--ds-overlay); }
    .ds-list-item[data-active="true"] { background: var(--ds-primary-soft); color: var(--ds-primary); }

    /* ─── nav / sidebar ─── */
    .ds-nav-section { padding: 14px 8px 4px; font: var(--ds-type-label-s); color: var(--ds-on-surface-muted); letter-spacing: 1.2px; }
    .ds-nav-item {
      display: flex; align-items: center; gap: 12px;
      padding: 9px 12px; border-radius: var(--ds-radius-md);
      color: var(--ds-on-surface-variant); cursor: pointer;
      font: var(--ds-type-title-s); font-weight: 500;
      transition: background var(--ds-dur-fast) var(--ds-ease-standard), color var(--ds-dur-fast) var(--ds-ease-standard);
    }
    .ds-nav-item .ds-ico { color: var(--ds-on-surface-muted); }
    .ds-nav-item:hover { background: var(--ds-overlay); color: var(--ds-on-surface); }
    .ds-nav-item[data-active="true"] {
      background: var(--ds-primary-soft); color: var(--ds-primary);
    }
    .ds-nav-item[data-active="true"] .ds-ico { color: var(--ds-primary); }
    .ds-nav-item .count { margin-left: auto; font-variant-numeric: tabular-nums; font-size: 11px; color: var(--ds-on-surface-muted); }
  `;
  document.head.appendChild(s);
}

// ─────────────── React components ───────────────

function DSButton({ children, variant = 'filled', size, icon, block, danger, disabled, ...rest }) {
  const cls = ['ds-btn', `ds-btn--${variant}`];
  if (size) cls.push(`ds-btn--${size}`);
  if (icon) cls.push('ds-btn--icon');
  if (block) cls.push('ds-btn--block');
  if (danger) cls.push('ds-btn--danger');
  if (disabled) cls.push('ds-btn--disabled');
  return <button className={cls.join(' ')} {...rest}>{children}</button>;
}

function DSChip({ children, variant = 'default', active, onClick, ...rest }) {
  const cls = ['ds-chip'];
  if (variant !== 'default') cls.push(`ds-chip--${variant}`);
  if (onClick) cls.push('ds-chip--filter');
  return <span className={cls.join(' ')} data-active={active ? 'true' : undefined} onClick={onClick} {...rest}>{children}</span>;
}

function DSStatus({ children, variant = 'active' }) {
  return <span className={`ds-status ds-status--${variant}`}>{children}</span>;
}

function DSBadge({ children, dot }) {
  return <span className={'ds-badge' + (dot ? ' ds-badge--dot' : '')}>{dot ? '' : children}</span>;
}

function DSField({ label, hint, error, prefix, suffix, lg, textarea, disabled, ...rest }) {
  const cls = ['ds-field'];
  if (lg) cls.push('ds-field--lg');
  if (error) cls.push('ds-field--error');
  if (disabled) cls.push('ds-field--disabled');
  if (textarea) cls.push('ds-textarea');
  return (
    <div className={cls.join(' ')}>
      {label && <div className="ds-field__label">{label}</div>}
      <div className="ds-field__input">
        {prefix && <span className="prefix">{prefix}</span>}
        {textarea ? <textarea {...rest} /> : <input {...rest} />}
        {suffix && <span className="suffix">{suffix}</span>}
      </div>
      {(hint || error) && <div className={'ds-field__hint' + (error ? ' error' : '')}>{error || hint}</div>}
    </div>
  );
}

function DSSwitch({ on, label }) {
  return (
    <label className="ds-switch" data-on={on ? 'true' : 'false'}>
      <span className="ds-switch__track">
        <span className="ds-switch__thumb"></span>
      </span>
      {label && <span className="ds-body-m">{label}</span>}
    </label>
  );
}

function DSCheckbox({ checked, label }) {
  return (
    <label className="ds-checkbox" data-checked={checked ? 'true' : 'false'}>
      <span className="ds-checkbox__box">{checked ? '✓' : ''}</span>
      {label && <span>{label}</span>}
    </label>
  );
}

function DSRadio({ checked, label }) {
  return (
    <label className="ds-radio" data-checked={checked ? 'true' : 'false'}>
      <span className="ds-radio__dot"></span>
      {label && <span>{label}</span>}
    </label>
  );
}

function DSSlider({ value = 50 }) {
  return (
    <div className="ds-slider">
      <div className="ds-slider__rail">
        <div className="ds-slider__fill" style={{ width: value + '%' }}></div>
      </div>
      <div className="ds-slider__thumb" style={{ left: value + '%' }}></div>
    </div>
  );
}

function DSCard({ children, variant = 'default', style }) {
  const cls = ['ds-card'];
  if (variant !== 'default') cls.push(`ds-card--${variant}`);
  return <div className={cls.join(' ')} style={style}>{children}</div>;
}

function DSAlert({ variant = 'info', title, children, icon }) {
  const defIcons = { success: '✓', warning: '!', error: '!', info: 'i' };
  return (
    <div className={`ds-alert ds-alert--${variant}`}>
      <div className="ds-alert__icon">{icon || defIcons[variant]}</div>
      <div style={{ flex: 1, minWidth: 0 }}>
        {title && <div className="ds-alert__title">{title}</div>}
        <div className="ds-alert__body">{children}</div>
      </div>
    </div>
  );
}

function DSProgress({ value = 0, variant, height }) {
  const cls = ['ds-progress'];
  if (variant) cls.push(`ds-progress--${variant}`);
  if (height) cls.push(`ds-progress--${height}`);
  return (
    <div className={cls.join(' ')}>
      <div className="ds-progress__fill" style={{ width: value + '%' }}></div>
    </div>
  );
}

function DSTabs({ items, active }) {
  return (
    <div className="ds-tabs">
      {items.map((t, i) => (
        <div key={i} className="ds-tab" data-active={t.value === active ? 'true' : 'false'}>
          {t.label}
          {t.count !== undefined && <span className="ds-badge">{t.count}</span>}
        </div>
      ))}
    </div>
  );
}

function DSAvatar({ initials = '··', size, status, style }) {
  const cls = ['ds-avatar'];
  if (size) cls.push(`ds-avatar--${size}`);
  if (status) cls.push('ds-avatar--status');
  if (status) {
    return <span className="ds-avatar-wrap"><span className={cls.join(' ')} style={style}>{initials}</span></span>;
  }
  return <span className={cls.join(' ')} style={style}>{initials}</span>;
}

function DSSkel({ w = '100%', h = 14, style }) {
  return <div className="ds-skel" style={{ width: w, height: h, ...style }}></div>;
}

function DSDivider({ vertical, style }) {
  return <hr className={vertical ? 'ds-divider ds-divider--v' : 'ds-divider'} style={style} />;
}

// brand mark
function DSMark({ size = 28 }) {
  return (
    <div style={{
      width: size, height: size, borderRadius: size * 0.28,
      background: 'var(--ds-primary)', position: 'relative', flexShrink: 0,
    }}>
      <div style={{
        position: 'absolute', inset: `${size * 0.18}px ${size * 0.18}px ${size * 0.18}px ${size * 0.55}px`,
        background: 'var(--ds-secondary)', borderRadius: `0 ${size * 0.15}px ${size * 0.15}px 0`,
      }}></div>
    </div>
  );
}

// brand waves — parametric SVG pattern (decorative)
function BrandWaves({ height = 200, opacity = 1, style }) {
  return (
    <svg viewBox="0 0 800 200" preserveAspectRatio="none" style={{ width: '100%', height, display: 'block', opacity, ...style }}>
      <defs>
        <linearGradient id="bw1" x1="0" x2="1" y1="0" y2="1">
          <stop offset="0%" stopColor="var(--ds-primary)" />
          <stop offset="100%" stopColor="var(--ds-secondary)" />
        </linearGradient>
      </defs>
      <path d="M0 140 C 150 60, 350 200, 500 110 S 800 80, 800 80 L 800 200 L 0 200 Z" fill="url(#bw1)" opacity="0.22" />
      <path d="M0 170 C 200 110, 400 200, 600 150 S 800 120, 800 120 L 800 200 L 0 200 Z" fill="var(--ds-primary)" opacity="0.16" />
      <path d="M0 185 C 250 150, 500 195, 800 160 L 800 200 L 0 200 Z" fill="var(--ds-secondary)" opacity="0.14" />
    </svg>
  );
}

Object.assign(window, {
  DSButton, DSChip, DSStatus, DSBadge, DSField, DSSwitch, DSCheckbox, DSRadio,
  DSSlider, DSCard, DSAlert, DSProgress, DSTabs, DSAvatar, DSSkel, DSDivider,
  DSMark, BrandWaves,
});
