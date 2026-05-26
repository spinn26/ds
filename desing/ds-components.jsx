// ds-components.jsx — каталог компонентов DS Consulting

function DSC_Header({ theme, title, subtitle }) {
  return (
    <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 16, marginBottom: 18 }}>
      <div>
        <div className="ds-label-m" style={{ color: 'var(--ds-primary)', marginBottom: 4 }}>components</div>
        <div className="ds-headline-l">{title}</div>
        {subtitle && <div className="ds-body-l ds-muted" style={{ marginTop: 6, maxWidth: 720 }}>{subtitle}</div>}
      </div>
      <DSChip variant={theme === 'dark' ? 'brand' : 'success'}>{theme === 'dark' ? '☾ тёмная' : '☀ светлая'}</DSChip>
    </div>
  );
}

function CatalogSection({ title, code, children, columns = 'auto' }) {
  return (
    <div className="ds-card" style={{ padding: 22, marginBottom: 16 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 14 }}>
        <div className="ds-title-l">{title}</div>
        {code && <div className="ds-mono ds-body-s ds-faint">{code}</div>}
      </div>
      <div style={{
        display: columns === 'auto' ? 'flex' : 'grid',
        gridTemplateColumns: columns !== 'auto' ? columns : undefined,
        flexWrap: columns === 'auto' ? 'wrap' : undefined,
        gap: 12, alignItems: 'flex-start',
      }}>
        {children}
      </div>
    </div>
  );
}

// ─────────── BUTTONS · CHIPS · STATUS · BADGES ───────────
function DSC_Buttons({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSC_Header theme={theme} title="Кнопки · чипы · статусы" subtitle="5 вариантов кнопок MD3 + 3 размера. Чипы для фильтров и статусы для пиллов." />

      <CatalogSection title="Варианты кнопки" code="<v-btn variant='…'>">
        <DSButton variant="filled">filled</DSButton>
        <DSButton variant="tonal">tonal</DSButton>
        <DSButton variant="outlined">outlined</DSButton>
        <DSButton variant="text">text</DSButton>
        <DSButton variant="elevated">elevated</DSButton>
        <DSButton variant="filled" danger>danger</DSButton>
      </CatalogSection>

      <CatalogSection title="Размеры" code="size='sm' | default | 'lg'">
        <DSButton variant="filled" size="sm">small</DSButton>
        <DSButton variant="filled">default</DSButton>
        <DSButton variant="filled" size="lg">large</DSButton>
        <DSButton variant="filled" disabled>disabled</DSButton>
      </CatalogSection>

      <CatalogSection title="С иконкой + only-icon" code="prepend-icon / icon-only">
        <DSButton variant="filled">＋ Добавить</DSButton>
        <DSButton variant="outlined">⤓ Скачать</DSButton>
        <DSButton variant="tonal">✎ Редактировать</DSButton>
        <DSButton variant="outlined" icon>＋</DSButton>
        <DSButton variant="outlined" icon>⋮</DSButton>
        <DSButton variant="filled" icon>＋</DSButton>
      </CatalogSection>

      <CatalogSection title="Чипы — фильтры (toggle)" code="<v-chip filter>">
        <DSChip onClick={()=>{}} active>все</DSChip>
        <DSChip onClick={()=>{}}>активные</DSChip>
        <DSChip onClick={()=>{}}>на проверке</DSChip>
        <DSChip onClick={()=>{}}>заморожены</DSChip>
        <DSChip onClick={()=>{}}>архив</DSChip>
      </CatalogSection>

      <CatalogSection title="Чипы — теги, метки, статусы" code="status / variant chips">
        <DSChip>обычный</DSChip>
        <DSChip variant="success">✓ принят</DSChip>
        <DSChip variant="warning">◐ на доработке</DSChip>
        <DSChip variant="error">× отклонён</DSChip>
        <DSChip variant="info">i запланирован</DSChip>
        <DSChip variant="brand">★ partner-director</DSChip>
      </CatalogSection>

      <CatalogSection title="Status pill — с точкой">
        <DSStatus variant="active">активен</DSStatus>
        <DSStatus variant="draft">черновик</DSStatus>
        <DSStatus variant="warn">требует доработки</DSStatus>
        <DSStatus variant="err">отклонён</DSStatus>
        <DSStatus variant="info">в процессе</DSStatus>
      </CatalogSection>

      <CatalogSection title="Бэйджи · аватары">
        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <div style={{ position: 'relative', display: 'inline-flex' }}>
            <DSButton variant="outlined" icon>⚐</DSButton>
            <div style={{ position: 'absolute', top: -4, right: -4 }}><DSBadge>4</DSBadge></div>
          </div>
          <div style={{ position: 'relative', display: 'inline-flex' }}>
            <DSButton variant="outlined" icon>❑</DSButton>
            <div style={{ position: 'absolute', top: -4, right: -4 }}><DSBadge>12</DSBadge></div>
          </div>
          <DSBadge dot />
          <DSAvatar initials="ИП" />
          <DSAvatar initials="МК" size="lg" status />
          <DSAvatar initials="АК" size="xl" />
        </div>
      </CatalogSection>
    </div>
  );
}

// ─────────── INPUTS · FORM CONTROLS ───────────
function DSC_Inputs({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSC_Header theme={theme} title="Поля и формы" subtitle="text-field, textarea, select, switch, checkbox, radio, slider" />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>

        <CatalogSection title="Text-field — состояния" code="<v-text-field variant='outlined'>" columns="1fr">
          <DSField label="Имя клиента" placeholder="Иванов Иван Иванович" />
          <DSField label="Телефон" placeholder="+7 ___ ___-__-__" prefix="📞" />
          <DSField label="Сумма контракта" suffix="₽" placeholder="0" />
          <DSField label="Email · в фокусе" defaultValue="ivanov@dscons.ru" />
          <DSField label="Email" defaultValue="ivanov" error="неверный формат" />
          <DSField label="ИНН" defaultValue="7707083893" disabled hint="заполнено из реквизитов, изменить нельзя" />
          <DSField label="Комментарий" textarea placeholder="Развернуть подробности заявки…" />
        </CatalogSection>

        <CatalogSection title="Переключатели" code="<v-switch> <v-checkbox> <v-radio>" columns="1fr">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <DSSwitch on={true} label="Уведомления push" />
            <DSSwitch on={false} label="Дайджест по почте" />
            <DSSwitch on={true} label="Двухфакторная аутентификация" />
          </div>
          <DSDivider style={{ margin: '6px 0' }} />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <DSCheckbox checked={true} label="Согласен с обработкой данных" />
            <DSCheckbox checked={false} label="Подписаться на новости" />
            <DSCheckbox checked={true} label="Учитывать в реестре выплат" />
          </div>
          <DSDivider style={{ margin: '6px 0' }} />
          <div className="ds-label-m ds-muted">квалификация партнёра</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <DSRadio checked={true} label="Стажёр" />
            <DSRadio checked={false} label="Консультант" />
            <DSRadio checked={false} label="Senior-консультант" />
            <DSRadio checked={false} label="Тим-лид" />
          </div>
          <DSDivider style={{ margin: '6px 0' }} />
          <div className="ds-label-m ds-muted">сумма контракта · слайдер</div>
          <DSSlider value={32} />
          <div className="ds-mono ds-body-s ds-faint">от 1 200 000 ₽ до 4 800 000 ₽</div>
        </CatalogSection>

      </div>

      <CatalogSection title="Field · sizes & layout" code="size lg + inline-row">
        <div style={{ width: '100%', display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: 12, alignItems: 'end' }}>
          <DSField label="Поиск клиента" placeholder="ФИО, телефон или ИНН" prefix="⌕" lg />
          <DSField label="Регион" placeholder="Москва" lg suffix="⌄" />
          <DSField label="Статус" placeholder="все" lg suffix="⌄" />
          <DSButton variant="filled" size="lg">Найти</DSButton>
        </div>
      </CatalogSection>
    </div>
  );
}

// ─────────── ALERTS · PROGRESS · TABS ───────────
function DSC_Feedback({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSC_Header theme={theme} title="Feedback и навигация" subtitle="alert, progress, tabs, tooltip, snackbar — то, через что система говорит с юзером" />

      <CatalogSection title="Alert — 4 цвета" code="<v-alert type='…'>" columns="1fr 1fr">
        <DSAlert variant="success" title="Контракт принят">Документы загружены, продукт открыт в каталоге.</DSAlert>
        <DSAlert variant="warning" title="Нужна доработка">3 строки в реестре не прошли валидацию — исправьте ИНН клиентов.</DSAlert>
        <DSAlert variant="error" title="Импорт не удался">Файл не соответствует шаблону. <a style={{ color: 'inherit', textDecoration: 'underline' }}>Скачать шаблон</a>.</DSAlert>
        <DSAlert variant="info" title="Закрытие периода">Реестр выплат за март будет сформирован 1 апреля в 12:00.</DSAlert>
      </CatalogSection>

      <CatalogSection title="Progress · linear" columns="1fr">
        <div style={{ width: '100%' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
            <span className="ds-label-m">прогресс курса</span>
            <span className="ds-mono ds-body-s ds-muted">62%</span>
          </div>
          <DSProgress value={62} />
        </div>
        <div style={{ width: '100%' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
            <span className="ds-label-m">квалификация — до следующего уровня</span>
            <span className="ds-mono ds-body-s ds-muted">800 / 1 000 баллов</span>
          </div>
          <DSProgress value={80} variant="brand" height="thick" />
        </div>
        <div style={{ width: '100%' }}>
          <span className="ds-label-m">мини-progress в строке таблицы</span>
          <div style={{ marginTop: 6, width: 200 }}><DSProgress value={45} height="thin" /></div>
        </div>
      </CatalogSection>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <CatalogSection title="Tabs" columns="1fr">
          <DSTabs items={[
            { value: 'inbox', label: 'Входящие', count: 12 },
            { value: 'work', label: 'В работе', count: 4 },
            { value: 'wait', label: 'Ждут клиента', count: 7 },
            { value: 'done', label: 'Готовы', count: 31 },
          ]} active="work" />
          <div className="ds-body-s ds-muted">текст таба активен • badge меняет фон на primary-soft</div>
        </CatalogSection>

        <CatalogSection title="Snackbar / Tooltip" columns="1fr">
          <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
            <span className="ds-tooltip">подсказка</span>
            <DSButton variant="text">наведи —</DSButton>
          </div>
          <div className="ds-card" style={{
            background: 'var(--ds-on-surface)', color: 'var(--ds-surface)',
            padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 12,
            border: 'none', boxShadow: 'var(--ds-shadow-3)', maxWidth: 420,
          }}>
            <span className="ds-ico" style={{ fontSize: 16 }}>✓</span>
            <span className="ds-body-m" style={{ flex: 1 }}>Контракт сохранён</span>
            <button className="ds-btn ds-btn--text" style={{ color: 'var(--ds-secondary)' }}>отменить</button>
          </div>
        </CatalogSection>
      </div>
    </div>
  );
}

// ─────────── CARDS · DIALOGS ───────────
function DSC_Cards({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSC_Header theme={theme} title="Карточки и диалоги" subtitle="базовые контейнеры контента" />

      <CatalogSection title="Card · варианты" columns="1fr 1fr 1fr">
        <DSCard>
          <div style={{ padding: 18 }}>
            <div className="ds-label-m ds-muted">default · outlined</div>
            <div className="ds-title-l" style={{ marginTop: 4 }}>Карточка по умолчанию</div>
            <div className="ds-body-s ds-muted" style={{ marginTop: 6 }}>Стандарт. Фон surface, тонкая граница, без тени.</div>
          </div>
        </DSCard>
        <DSCard variant="elevated">
          <div style={{ padding: 18 }}>
            <div className="ds-label-m ds-muted">elevated · с тенью</div>
            <div className="ds-title-l" style={{ marginTop: 4 }}>Поднятая карточка</div>
            <div className="ds-body-s ds-muted" style={{ marginTop: 6 }}>Когда нужно физически отделить от фона.</div>
          </div>
        </DSCard>
        <DSCard variant="filled">
          <div style={{ padding: 18 }}>
            <div className="ds-label-m ds-muted">filled · surface-container</div>
            <div className="ds-title-l" style={{ marginTop: 4 }}>Залитая карточка</div>
            <div className="ds-body-s ds-muted" style={{ marginTop: 6 }}>Для группировки настроек или вторичной информации.</div>
          </div>
        </DSCard>
      </CatalogSection>

      <CatalogSection title="Card · структура (title + content + actions)" columns="1fr 1fr">
        <DSCard variant="elevated">
          <div style={{ padding: 20 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <div className="ds-title-l">Реестр выплат · март</div>
                <div className="ds-body-s ds-muted" style={{ marginTop: 2 }}>148 партнёров · 12 482 530 ₽</div>
              </div>
              <DSStatus variant="warn">на проверке</DSStatus>
            </div>
            <div style={{ marginTop: 16 }}><DSProgress value={68} /></div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
              <DSButton variant="text">Отменить</DSButton>
              <DSButton variant="tonal">Согласовать</DSButton>
              <DSButton variant="filled">Закрыть период</DSButton>
            </div>
          </div>
        </DSCard>

        <DSCard variant="elevated">
          {/* media + content */}
          <div style={{ aspectRatio: '16/9', background: 'linear-gradient(135deg, var(--ds-primary), var(--ds-secondary))', position: 'relative' }}>
            <div style={{ position: 'absolute', left: 14, bottom: 14, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)' }}>
              <div className="ds-label-m">★ продукт</div>
              <div className="ds-title-l" style={{ color: '#fff' }}>Эволюция</div>
            </div>
          </div>
          <div style={{ padding: 18 }}>
            <div className="ds-body-m ds-muted">Инвестиционный продукт с защитой капитала.</div>
            <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
              <DSButton variant="filled">К продукту</DSButton>
              <DSButton variant="outlined">Обучение</DSButton>
            </div>
          </div>
        </DSCard>
      </CatalogSection>

      <CatalogSection title="Dialog (confirm)" columns="1fr">
        <DSCard variant="elevated" style={{ maxWidth: 460, boxShadow: 'var(--ds-shadow-4)' }}>
          <div style={{ padding: '24px 24px 8px' }}>
            <div className="ds-title-l">Удалить контракт?</div>
            <div className="ds-body-m ds-muted" style={{ marginTop: 8 }}>
              Контракт #C-2024-0381 будет удалён без возможности восстановления. Связанные транзакции останутся.
            </div>
          </div>
          <div style={{ padding: 16, display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <DSButton variant="text">Отмена</DSButton>
            <DSButton variant="filled" danger>Удалить</DSButton>
          </div>
        </DSCard>
      </CatalogSection>
    </div>
  );
}

// ─────────── TABLES · DATA ───────────
function DSC_Table({ theme = 'light' }) {
  const rows = [
    { id: 'C-2024-0381', client: 'Иванов И.И.', product: 'Эволюция', sum: '2 400 000 ₽', status: 'active' },
    { id: 'C-2024-0382', client: 'Петров П.С.', product: 'СОЗ',      sum: '1 850 000 ₽', status: 'warn' },
    { id: 'C-2024-0383', client: 'Сидорова А.', product: 'PRE-IPO',  sum: '3 600 000 ₽', status: 'active' },
    { id: 'C-2024-0384', client: 'Кузнецов Д.', product: 'Эволюция', sum: '900 000 ₽',   status: 'draft' },
    { id: 'C-2024-0385', client: 'Лебедева Н.', product: 'Эволюция', sum: '1 200 000 ₽', status: 'err' },
  ];
  const statusLabels = { active: 'активен', warn: 'на проверке', draft: 'черновик', err: 'отклонён' };

  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ padding: 28, overflow: 'auto' }}>
      <DSC_Header theme={theme} title="Таблицы и данные" subtitle="<v-data-table-server> в нашей стилизации" />

      {/* toolbar */}
      <DSCard variant="elevated" style={{ overflow: 'hidden' }}>
        <div style={{ padding: 14, display: 'flex', alignItems: 'center', gap: 10, borderBottom: '1px solid var(--ds-outline-variant)' }}>
          <div style={{ flex: 1, maxWidth: 320 }}>
            <DSField placeholder="Поиск по контрактам" prefix="⌕" />
          </div>
          <DSChip onClick={()=>{}} active>все · 142</DSChip>
          <DSChip onClick={()=>{}}>активные · 89</DSChip>
          <DSChip onClick={()=>{}}>проверка · 31</DSChip>
          <DSChip onClick={()=>{}}>отклонённые · 22</DSChip>
          <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
            <DSButton variant="outlined">⤓ экспорт</DSButton>
            <DSButton variant="filled">＋ новый</DSButton>
          </div>
        </div>

        <table className="ds-table">
          <thead>
            <tr>
              <th style={{ width: 28 }}><DSCheckbox checked={false} /></th>
              <th>номер</th>
              <th>клиент</th>
              <th>продукт</th>
              <th style={{ textAlign: 'right' }}>сумма</th>
              <th>статус</th>
              <th style={{ width: 32 }}></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={i}>
                <td><DSCheckbox checked={false} /></td>
                <td className="ds-mono">{r.id}</td>
                <td>{r.client}</td>
                <td>{r.product}</td>
                <td className="ds-mono" style={{ textAlign: 'right' }}>{r.sum}</td>
                <td><DSStatus variant={r.status}>{statusLabels[r.status]}</DSStatus></td>
                <td><DSButton variant="text" icon size="sm">⋮</DSButton></td>
              </tr>
            ))}
          </tbody>
        </table>

        <div style={{ padding: '12px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderTop: '1px solid var(--ds-outline-variant)' }}>
          <div className="ds-body-s ds-muted">показано 1–5 из 142</div>
          <div style={{ display: 'flex', gap: 4, alignItems: 'center' }}>
            <DSButton variant="outlined" icon size="sm">←</DSButton>
            <DSButton variant="tonal" size="sm">1</DSButton>
            <DSButton variant="text" size="sm">2</DSButton>
            <DSButton variant="text" size="sm">3</DSButton>
            <span className="ds-faint" style={{ padding: '0 6px' }}>…</span>
            <DSButton variant="text" size="sm">29</DSButton>
            <DSButton variant="outlined" icon size="sm">→</DSButton>
          </div>
        </div>
      </DSCard>
    </div>
  );
}

Object.assign(window, { DSC_Buttons, DSC_Inputs, DSC_Feedback, DSC_Cards, DSC_Table });
