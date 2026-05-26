// ds-extra-admin.jsx — админские экраны (тёмная тема)

function AdminShell({ activeId, title, subtitle, actions, children }) {
  return (
    <FullShell theme="dark" sidebar={<AdminSidebar activeId={activeId} />} content={
      <React.Fragment>
        <AppBar title={title} subtitle={subtitle}>{actions}</AppBar>
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 14 }}>
          {children}
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── ПОЛЬЗОВАТЕЛИ ───────────
function AdminUsers() {
  return (
    <AdminShell activeId="usr" title="Пользователи" subtitle="все аккаунты системы · 168 активных"
      actions={<DSButton variant="filled">＋ Создать</DSButton>}>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, maxWidth: 380 }}><DSField placeholder="email или ФИО" prefix="⌕" /></div>
        <DSChip onClick={()=>{}} active>все · 168</DSChip>
        <DSChip onClick={()=>{}}>партнёры · 142</DSChip>
        <DSChip onClick={()=>{}}>менеджеры · 18</DSChip>
        <DSChip onClick={()=>{}}>контент · 4</DSChip>
        <DSChip onClick={()=>{}}>админы · 4</DSChip>
      </div>
      <FinTable cols={[
        {label:'пользователь'},{label:'email'},{label:'роль'},{label:'2FA'},{label:'последний вход'},{label:'статус'},{label:''},
      ]} rows={[
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="ИП"/>Иванов И.И.</div>,'ivanov@dscons.ru', <DSChip>Партнёр</DSChip>, '✓', '5 мин назад', <DSStatus variant="active">активен</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="МК"/>Карпов М.</div>,'karpov@dscons.ru', <DSChip variant="info">Менеджер</DSChip>, '✓', '2 ч назад', <DSStatus variant="active">активен</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="ЖО"/>Жосан О.</div>,'zhosan@dscons.ru', <DSChip variant="brand">Контент</DSChip>, '✓', '14 мин назад', <DSStatus variant="active">активен</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="АК"/>Карпова А.</div>,'akarpova@dscons.ru', <DSChip variant="success">Админ</DSChip>, '✓', 'сейчас', <DSStatus variant="active">активен</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="ЛН"/>Лебедева Н.</div>,'lebedeva@dscons.ru', <DSChip>Партнёр</DSChip>, '×', '12 дней назад', <DSStatus variant="warn">риск</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><DSAvatar initials="ИА"/>Иванов А.</div>,'aivanov@dscons.ru', <DSChip>Партнёр</DSChip>, '×', '45 дней назад', <DSStatus variant="err">заморожен</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
      ]} />
    </AdminShell>
  );
}

// ─────────── СВЕРКА БАЛАНСОВ ───────────
function AdminReconciliation() {
  return (
    <AdminShell activeId="rec" title="Сверка балансов" subtitle="snapshot vs live SUM · поиск расхождений"
      actions={<DSButton variant="filled">↻ Пересчитать всё</DSButton>}>
      <DSAlert variant="success" title="Сегодня сверка прошла чисто">
        Расхождений между snapshot и live SUM не обнаружено. Последний прогон — 25 мая, 14:08.
      </DSAlert>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 }}>
        {[
          { lbl: 'проверено партнёров', v: '142', c: 'var(--ds-on-surface)' },
          { lbl: 'расхождений', v: '0', c: 'var(--ds-success)' },
          { lbl: 'дельта', v: '0 ₽', c: 'var(--ds-success)' },
        ].map((k, i) => (
          <DSCard key={i} variant="elevated"><div style={{padding:18}}>
            <div className="ds-label-m ds-muted">{k.lbl}</div>
            <div className="ds-headline-m ds-mono" style={{marginTop:6, color:k.c}}>{k.v}</div>
          </div></DSCard>
        ))}
      </div>
      <DSCard variant="elevated">
        <div style={{ padding: '16px 18px 10px' }}>
          <div className="ds-title-l">История прогонов</div>
        </div>
        <table className="ds-table">
          <thead><tr><th>дата</th><th>тип</th><th>проверено</th><th style={{textAlign:'right'}}>дельта</th><th>результат</th></tr></thead>
          <tbody>
            {[
              ['25.05 14:08', 'авто (день)', 142, '0 ₽', <DSStatus variant="active">чисто</DSStatus>],
              ['25.05 09:00', 'авто (утро)', 142, '0 ₽', <DSStatus variant="active">чисто</DSStatus>],
              ['24.05 14:08', 'авто', 142, '+12 ₽', <DSStatus variant="warn">мини-дельта</DSStatus>],
              ['22.05 11:32', 'ручной', 142, '−4 800 ₽', <DSStatus variant="err">исправлено</DSStatus>],
            ].map((r, i) => (
              <tr key={i}>
                <td className="ds-mono">{r[0]}</td>
                <td className="ds-muted">{r[1]}</td>
                <td className="ds-mono">{r[2]}</td>
                <td className="ds-mono" style={{textAlign:'right'}}>{r[3]}</td>
                <td>{r[4]}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </AdminShell>
  );
}

// ─────────── АНОМАЛИИ ───────────
function AdminAnomalies() {
  return (
    <AdminShell activeId="anom" title="Аномалии" subtitle="автоматически найденные отклонения · 3 high · 5 mid">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {[
          { p: 'err', title: 'Калькулятор: серьёзный сбой', desc: '12 ошибок/мин в течение последнего часа. Возможно, упал расчётный сервис.', when: '14 мин назад', action: 'Перезапустить сервис' },
          { p: 'warn', title: 'Резкий рост возвратов по продукту Эволюция', desc: '+340% за последние 48 часов. Возможен системный бак или маркетинговая ошибка.', when: '2 ч назад', action: 'Открыть отчёт' },
          { p: 'warn', title: '7 партнёров без активности 60+ дней', desc: 'Активность падает в когорте Q1 2026. Стоит запустить ре-онбординг.', when: '1 д назад', action: 'Запустить email-кампанию' },
          { p: 'info', title: 'Импорт транзакций задержался на 4 часа', desc: 'Файл от Альфа-Капитал пришёл позже обычного. Закрытие периода может сдвинуться.', when: '1 д назад', action: 'Связаться с поставщиком' },
        ].map((a, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18, display: 'flex', gap: 16, alignItems: 'flex-start' }}>
              <div style={{
                width: 44, height: 44, borderRadius: 10, flexShrink: 0,
                background: a.p === 'err' ? 'var(--ds-error-container)' : a.p === 'warn' ? 'var(--ds-warning-container)' : 'var(--ds-info-container)',
                color: a.p === 'err' ? 'var(--ds-error)' : a.p === 'warn' ? 'var(--ds-warning)' : 'var(--ds-info)',
                display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20, fontWeight: 700,
              }}>{a.p === 'err' ? '!' : a.p === 'warn' ? '⚠' : 'i'}</div>
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <DSStatus variant={a.p === 'err' ? 'err' : a.p === 'warn' ? 'warn' : 'info'}>{a.p === 'err' ? 'high' : a.p === 'warn' ? 'mid' : 'low'}</DSStatus>
                  <span className="ds-title-l">{a.title}</span>
                  <span className="ds-body-s ds-muted" style={{ marginLeft: 'auto' }}>{a.when}</span>
                </div>
                <div className="ds-body-m ds-muted" style={{ marginTop: 8 }}>{a.desc}</div>
                <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
                  <DSButton variant="tonal" size="sm">{a.action} →</DSButton>
                  <DSButton variant="text" size="sm">отметить как разобранное</DSButton>
                </div>
              </div>
            </div>
          </DSCard>
        ))}
      </div>
    </AdminShell>
  );
}

// ─────────── КАЛЕНДАРЬ ───────────
function AdminCalendar() {
  // simple month grid
  const days = Array.from({length: 35}, (_, i) => i - 3);
  const events = {
    1: [{ c: 'var(--ds-primary)', l: 'Реестр апр' }],
    8: [{ c: 'var(--ds-info)', l: 'Импорт ВТБ' }],
    15: [{ c: 'var(--ds-warning)', l: 'Сверка' }],
    25: [{ c: 'var(--ds-primary)', l: 'Закрытие май' }, { c: 'var(--ds-info)', l: 'Импорт Альфа' }],
    27: [{ c: 'var(--ds-info)', l: 'Импорт Сбер' }],
    30: [{ c: 'var(--ds-success)', l: 'Конец месяца' }],
  };
  return (
    <AdminShell activeId="cal" title="Календарь операций" subtitle="плановые задачи · май 2026"
      actions={<React.Fragment>
        <DSButton variant="outlined" size="sm">←</DSButton>
        <DSChip>май 2026</DSChip>
        <DSButton variant="outlined" size="sm">→</DSButton>
        <DSButton variant="filled" size="sm">＋ Событие</DSButton>
      </React.Fragment>}>
      <DSCard variant="elevated">
        <div style={{ padding: 14 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 4, marginBottom: 8 }}>
            {['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].map((d, i) => (
              <div key={i} className="ds-label-m ds-muted" style={{ textAlign: 'center', padding: 6 }}>{d}</div>
            ))}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 4 }}>
            {days.map((d, i) => {
              const valid = d > 0 && d <= 31;
              const ev = valid ? events[d] : null;
              const today = d === 25;
              return (
                <div key={i} style={{
                  minHeight: 90, padding: 8, borderRadius: 8,
                  background: today ? 'var(--ds-primary-soft)' : 'var(--ds-surface-container-low)',
                  border: today ? '2px solid var(--ds-primary)' : '1px solid var(--ds-outline-variant)',
                  opacity: valid ? 1 : 0.4,
                }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                    <span className="ds-mono ds-title-s" style={{ color: today ? 'var(--ds-primary)' : 'var(--ds-on-surface)' }}>{valid ? d : ''}</span>
                    {today && <DSChip variant="brand">сегодня</DSChip>}
                  </div>
                  {ev && ev.map((e, ei) => (
                    <div key={ei} style={{ marginTop: 4, padding: '3px 6px', borderRadius: 4, fontSize: 10.5, color: 'white', background: e.c, fontWeight: 600 }}>{e.l}</div>
                  ))}
                </div>
              );
            })}
          </div>
        </div>
      </DSCard>
    </AdminShell>
  );
}

// ─────────── КОГОРТЫ ───────────
function AdminCohorts() {
  const cohorts = ['янв-2025','фев-2025','мар-2025','апр-2025','май-2025','июн-2025','июл-2025','авг-2025','сен-2025','окт-2025','ноя-2025','дек-2025'];
  const months = 12;
  const data = cohorts.map((c, i) => Array.from({length: months - i}, (_, j) => {
    const base = 100 - j * (5 + i * 0.5);
    return Math.max(20, Math.round(base));
  }));
  const color = (v) => {
    if (v >= 80) return 'var(--ds-primary)';
    if (v >= 60) return 'var(--ds-primary-container)';
    if (v >= 40) return 'var(--ds-warning-container)';
    return 'var(--ds-error-container)';
  };
  return (
    <AdminShell activeId="coh" title="Когорты" subtitle="retention партнёров · % активных по месяцам">
      <DSCard variant="elevated">
        <div style={{ padding: 20, overflow: 'auto' }}>
          <table className="ds-table" style={{ width: 'auto' }}>
            <thead>
              <tr>
                <th>когорта</th>
                <th>размер</th>
                {Array.from({length: months}, (_, i) => <th key={i} style={{textAlign:'center', minWidth: 60}}>M{i}</th>)}
              </tr>
            </thead>
            <tbody>
              {cohorts.map((c, i) => (
                <tr key={c}>
                  <td className="ds-mono ds-title-s">{c}</td>
                  <td className="ds-mono ds-muted">{Math.round(50 + Math.random() * 30)}</td>
                  {Array.from({length: months}, (_, j) => {
                    const v = data[i][j];
                    return (
                      <td key={j} style={{ padding: 4 }}>
                        {v !== undefined ? (
                          <div style={{
                            background: color(v), color: v >= 80 ? 'white' : 'var(--ds-on-surface)',
                            borderRadius: 4, textAlign: 'center', padding: '8px 4px',
                            font: 'var(--ds-type-label-m)', fontFamily: 'JetBrains Mono, monospace',
                          }}>{v}%</div>
                        ) : <div style={{height: 32}}></div>}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </DSCard>
    </AdminShell>
  );
}

// ─────────── МАССОВЫЕ ОПЕРАЦИИ ───────────
function AdminBulkOps() {
  return (
    <AdminShell activeId="bulk" title="Массовые операции" subtitle="bulk-actions по партнёрам и контрактам">
      <DSAlert variant="warning" title="Внимание — необратимые действия">
        Все операции применяются ко всем выбранным записям сразу. Перед выполнением — обязательный preview эффекта.
      </DSAlert>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
        {[
          { ico: '↻', name: 'Пересчитать комиссии', desc: 'Перерасчёт по всем партнёрам за указанный период', cnt: 'затронет 142 партнёра', c: 'info' },
          { ico: '+', name: 'Начислить бонус', desc: 'Разовое начисление бонуса по фильтру', cnt: 'нужно настроить фильтр', c: 'success' },
          { ico: '✉', name: 'Разослать email', desc: 'Письмо по сегменту с шаблоном', cnt: 'выбрано 0 партнёров', c: 'info' },
          { ico: '🔒', name: 'Заморозить', desc: 'Массовая блокировка доступа', cnt: 'нужно подтверждение от 2 админов', c: 'error' },
          { ico: '☆', name: 'Сменить квалификацию', desc: 'Принудительное изменение по условию', cnt: 'обычно автоматически', c: 'warning' },
          { ico: '⤓', name: 'Экспорт сегмента', desc: 'Выгрузка по сегменту в Excel', cnt: 'все поля', c: 'info' },
        ].map((op, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18 }}>
              <div style={{
                width: 44, height: 44, borderRadius: 10,
                background: `var(--ds-${op.c}-container)`, color: `var(--ds-${op.c})`,
                display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20,
              }}>{op.ico}</div>
              <div className="ds-title-l" style={{ marginTop: 12 }}>{op.name}</div>
              <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{op.desc}</div>
              <div className="ds-body-s ds-mono ds-muted" style={{ marginTop: 10 }}>{op.cnt}</div>
              <DSButton variant="tonal" block style={{ marginTop: 14 }}>настроить →</DSButton>
            </div>
          </DSCard>
        ))}
      </div>
    </AdminShell>
  );
}

// ─────────── ТРИГГЕРЫ УВЕДОМЛЕНИЙ ───────────
function AdminTriggers() {
  return (
    <AdminShell activeId="set" title="Триггеры уведомлений" subtitle="правила: событие → канал → шаблон"
      actions={<DSButton variant="filled">＋ Новый триггер</DSButton>}>
      <FinTable cols={[
        {label:'триггер'},{label:'событие'},{label:'каналы'},{label:'шаблон'},{label:'статус'},
      ]} rows={[
        ['Контракт принят','contract.accepted', <div style={{display:'flex',gap:4}}><DSChip variant="info">push</DSChip><DSChip variant="info">email</DSChip></div>, 'contract_accepted_v2', <DSSwitch on={true} />],
        ['Тест по продукту сдан','product_test.passed', <div style={{display:'flex',gap:4}}><DSChip variant="info">push</DSChip><DSChip variant="info">telegram</DSChip></div>, 'product_unlocked_v1', <DSSwitch on={true} />],
        ['Выплата зачислена','payment.completed', <div style={{display:'flex',gap:4}}><DSChip variant="info">push</DSChip><DSChip variant="info">email</DSChip><DSChip variant="info">sms</DSChip></div>, 'payment_done_v3', <DSSwitch on={true} />],
        ['Новый клиент в команде','team.new_client', <DSChip variant="info">in-app</DSChip>, 'team_new_client', <DSSwitch on={true} />],
        ['Партнёр в зоне риска','partner.at_risk', <DSChip variant="info">email</DSChip>, 'reonboarding_drip_1', <DSSwitch on={false} />],
      ]} />
    </AdminShell>
  );
}

// ─────────── ПОЧТОВАЯ РАССЫЛКА ───────────
function AdminMail() {
  return (
    <AdminShell activeId="set" title="Почтовые рассылки" subtitle="кампании по сегментам"
      actions={<DSButton variant="filled">＋ Создать рассылку</DSButton>}>
      <DSCard variant="elevated">
        <div style={{ padding: '16px 18px 10px' }}>
          <div className="ds-title-l">Кампании</div>
        </div>
        <table className="ds-table">
          <thead><tr><th>кампания</th><th>сегмент</th><th>отправлено</th><th>open rate</th><th>click rate</th><th>статус</th></tr></thead>
          <tbody>
            {[
              ['Релиз продукта Эволюция-2', 'все Senior+', '46', '78%', '34%', <DSStatus variant="active">завершено</DSStatus>],
              ['Закрытие апреля — реестр', 'все партнёры', '142', '92%', '64%', <DSStatus variant="active">завершено</DSStatus>],
              ['Реактивация когорты Q1', 'не активны 60+ дней', '24', '12%', '4%', <DSStatus variant="warn">завершено</DSStatus>],
              ['Конкурс лета', 'все', '0', '—', '—', <DSStatus variant="info">запланировано · 1 июня</DSStatus>],
              ['Welcome-серия', 'новые партнёры', '8', '—', '—', <DSStatus variant="info">drip · идёт</DSStatus>],
            ].map((r, i) => (
              <tr key={i}>
                <td><span className="ds-title-s">{r[0]}</span></td>
                <td className="ds-muted">{r[1]}</td>
                <td className="ds-mono">{r[2]}</td>
                <td className="ds-mono" style={{color: parseFloat(r[3]) > 50 ? 'var(--ds-success)' : 'var(--ds-on-surface)'}}>{r[3]}</td>
                <td className="ds-mono">{r[4]}</td>
                <td>{r[5]}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </AdminShell>
  );
}

// ─────────── ИНТЕГРАЦИИ ───────────
function AdminIntegrations() {
  const items = [
    { ico: '☁', name: 'Альфа-Капитал API', desc: 'Импорт транзакций · ежедневно в 09:00', status: 'active', extra: 'последний прогон — 25.05 09:02' },
    { ico: '☁', name: 'ВТБ Brokerage', desc: 'Импорт транзакций · ежедневно в 10:00', status: 'active', extra: 'последний прогон — 25.05 10:04' },
    { ico: '☁', name: 'Сбер Инвестиции', desc: 'Импорт транзакций · ежедневно в 11:00', status: 'warn', extra: 'предупреждение: задержка 4 ч' },
    { ico: '📞', name: 'Mango Office', desc: 'Телефония, запись звонков', status: 'active', extra: '142 минут за сегодня' },
    { ico: '💳', name: 'Tinkoff Acquiring', desc: 'Эквайринг для выплат', status: 'active', extra: '148 платежей готовы' },
    { ico: '✉', name: 'SendPulse', desc: 'Email-рассылки', status: 'active', extra: '12 482 писем в месяц' },
    { ico: '🤖', name: 'Telegram Bot API', desc: 'Бот для партнёров и админки', status: 'active', extra: '4 800 подписчиков' },
    { ico: '◐', name: 'Slack', desc: 'Уведомления в чат админов', status: 'paused', extra: 'выключено вручную' },
  ];
  return (
    <AdminShell activeId="set" title="Интеграции" subtitle="внешние сервисы · 7 активных">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 14 }}>
        {items.map((it, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18, display: 'flex', alignItems: 'center', gap: 14 }}>
              <div style={{
                width: 44, height: 44, borderRadius: 10,
                background: 'var(--ds-surface-container-high)',
                display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 22,
              }}>{it.ico}</div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div className="ds-title-l">{it.name}</div>
                <div className="ds-body-s ds-muted">{it.desc}</div>
                <div className="ds-body-s ds-mono ds-muted" style={{ marginTop: 4 }}>{it.extra}</div>
              </div>
              {it.status === 'active' && <DSStatus variant="active">подключено</DSStatus>}
              {it.status === 'warn' && <DSStatus variant="warn">внимание</DSStatus>}
              {it.status === 'paused' && <DSStatus variant="draft">пауза</DSStatus>}
              <DSButton variant="text" icon>⋮</DSButton>
            </div>
          </DSCard>
        ))}
      </div>
    </AdminShell>
  );
}

// ─────────── API-КЛЮЧИ ───────────
function AdminApiKeys() {
  return (
    <AdminShell activeId="set" title="API-ключи" subtitle="токены для внешних потребителей"
      actions={<DSButton variant="filled">＋ Сгенерировать ключ</DSButton>}>
      <DSAlert variant="info" title="Ротация ключей">
        Рекомендуем ротировать ключи раз в 90 дней. Срок жизни старого ключа после ротации — 24 часа.
      </DSAlert>
      <FinTable cols={[
        {label:'название'},{label:'токен', mono:true},{label:'скоупы'},{label:'создан'},{label:'последний запрос'},{label:''},
      ]} rows={[
        ['Mobile app · prod','ds_live_a8f3_••••2c4d', <div style={{display:'flex',gap:4}}><DSChip>read</DSChip><DSChip>write</DSChip></div>, '14.03.2026', '2 мин назад', <DSButton variant="text" icon size="sm">⋮</DSButton>],
        ['CRM integration','ds_live_p2m4_••••8a91', <div style={{display:'flex',gap:4}}><DSChip>read</DSChip></div>, '02.01.2026', '18 ч назад', <DSButton variant="text" icon size="sm">⋮</DSButton>],
        ['Analytics dashboard','ds_live_x1y2_••••3d22', <div style={{display:'flex',gap:4}}><DSChip>read</DSChip></div>, '20.04.2026', '5 мин назад', <DSButton variant="text" icon size="sm">⋮</DSButton>],
        ['Test environment','ds_test_d6f8_••••9e10', <div style={{display:'flex',gap:4}}><DSChip>read</DSChip><DSChip>write</DSChip><DSChip variant="warning">admin</DSChip></div>, '01.05.2026', 'не использовался', <DSButton variant="text" icon size="sm">⋮</DSButton>],
      ]} />
    </AdminShell>
  );
}

// ─────────── НАСТРОЙКИ ───────────
function AdminSettings() {
  return (
    <AdminShell activeId="set" title="Настройки системы" subtitle="конфигурация платформы">
      <div style={{ display: 'grid', gridTemplateColumns: '240px 1fr', gap: 22 }}>
        <DSCard variant="elevated">
          <div style={{ padding: 14 }}>
            {[
              ['Общие', true], ['Комиссии', false], ['Срок активности', false],
              ['Темы и UI', false], ['Безопасность', false], ['Резервные копии', false], ['Журналы', false],
            ].map((it, i) => (
              <div key={i} className="ds-nav-item" data-active={it[1] ? 'true' : 'false'}><span>{it[0]}</span></div>
            ))}
          </div>
        </DSCard>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <DSCard variant="elevated">
            <div style={{ padding: 22 }}>
              <div className="ds-title-l" style={{ marginBottom: 14 }}>Компания</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                <DSField label="Название" defaultValue="DS Consulting" />
                <DSField label="Юридическое лицо" defaultValue="ООО «ДС Консалтинг»" />
                <DSField label="ИНН" defaultValue="7707083893" />
                <DSField label="Часовой пояс по умолчанию" defaultValue="UTC+3 Москва" suffix="⌄" />
              </div>
            </div>
          </DSCard>
          <DSCard variant="elevated">
            <div style={{ padding: 22 }}>
              <div className="ds-title-l" style={{ marginBottom: 14 }}>Параметры расчёта</div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <DSSwitch on={true} label="Автоматическое закрытие периода 1-го числа в 12:00" />
                <DSSwitch on={true} label="Автоматическая ротация квалификаций" />
                <DSSwitch on={false} label="Включить дроп-out для неактивных партнёров (90 дней)" />
                <DSSwitch on={true} label="Snapshot-сверка каждое утро в 09:00" />
              </div>
            </div>
          </DSCard>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <DSButton variant="text">отменить</DSButton>
            <DSButton variant="filled">сохранить</DSButton>
          </div>
        </div>
      </div>
    </AdminShell>
  );
}

// ─────────── СПРАВОЧНИКИ ───────────
function AdminReferences() {
  const catalogs = [
    ['Продукты', 6, 'mdi-package-variant'],
    ['Категории продуктов', 4, 'mdi-tag-multiple'],
    ['Валюты', 8, 'mdi-currency-rub'],
    ['Статусы контрактов', 7, 'mdi-state-machine'],
    ['Типы конкурсов', 5, 'mdi-trophy'],
    ['Критерии квалификаций', 12, 'mdi-target'],
    ['Должности', 9, 'mdi-account-tie'],
    ['Титулы', 6, 'mdi-medal'],
    ['Типы встреч', 4, 'mdi-calendar-clock'],
    ['Регионы', 89, 'mdi-map'],
    ['Источники лидов', 14, 'mdi-source-branch'],
    ['Шаблоны email', 28, 'mdi-email-edit'],
  ];
  return (
    <AdminShell activeId="ref" title="Справочники" subtitle="12 каталогов · единая CRUD-логика">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
        {catalogs.map((c, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 6 }}>
              <div className="ds-title-m">{c[0]}</div>
              <div className="ds-body-s ds-muted ds-mono">{c[1]} элементов</div>
              <DSButton variant="text" style={{ marginTop: 4, alignSelf: 'flex-start', padding: 0 }}>открыть →</DSButton>
            </div>
          </DSCard>
        ))}
      </div>
    </AdminShell>
  );
}

// ─────────── НОВОСТИ + РОАДМАП ───────────
function AdminNews() {
  return (
    <AdminShell activeId="news" title="Новости" subtitle="лента для партнёров"
      actions={<DSButton variant="filled">＋ Новая запись</DSButton>}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        {[
          { p: 'warning', t: 'Обновление условий по продукту Эволюция', desc: 'С 1 июня меняются требования к KYC. Изучите регламент.', date: '17.04.2026', vis: 'все партнёры' },
          { p: 'info', t: 'Новый шаблон импорта транзакций', desc: 'Загружен новый CSV-шаблон…', date: '15.04.2026', vis: 'менеджеры' },
          { p: 'success', t: 'Реестр март закрыт', desc: '148 партнёров получили начисления', date: '01.04.2026', vis: 'все партнёры' },
        ].map((n, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{ padding: 18, display: 'flex', alignItems: 'center', gap: 14 }}>
              <DSChip variant={n.p}>{n.p === 'warning' ? 'важно' : n.p === 'success' ? 'релиз' : 'обновление'}</DSChip>
              <div style={{ flex: 1 }}>
                <div className="ds-title-l">{n.t}</div>
                <div className="ds-body-s ds-muted" style={{ marginTop: 2 }}>{n.desc}</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className="ds-body-s ds-muted">{n.date}</div>
                <div className="ds-body-s ds-muted">{n.vis}</div>
              </div>
              <DSButton variant="text" icon>⋮</DSButton>
            </div>
          </DSCard>
        ))}
      </div>
    </AdminShell>
  );
}

function AdminRoadmap() {
  return (
    <AdminShell activeId="road" title="Роадмап" subtitle="публичный план развития платформы">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
        {[
          { col: 'Запланировано', c: 'var(--ds-on-surface-muted)', items: ['Мобильное приложение для iOS', 'Конструктор маркетинговых материалов', 'A/B тестирование рассылок', 'Виджет «персональный план»'] },
          { col: 'В работе', c: 'var(--ds-warning)', items: ['Новая база знаний 2.0', 'Calculator v2', 'Импорт от Тинькофф', 'Голосовые комментарии в чате'] },
          { col: 'Выпущено', c: 'var(--ds-success)', items: ['Тёмная тема партнёрского кабинета', 'Real-time онлайн-статусы', 'Telegram-bot интеграция', 'Раздел «Обучение» v1', 'Owner-дашборд'] },
        ].map((g, gi) => (
          <div key={gi}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
              <span style={{ width: 8, height: 8, borderRadius: 50, background: g.c }}></span>
              <span className="ds-title-l">{g.col}</span>
              <DSChip>{g.items.length}</DSChip>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {g.items.map((it, i) => (
                <DSCard key={i} variant="elevated">
                  <div style={{ padding: 14 }}>
                    <div className="ds-title-s">{it}</div>
                    <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>обновлено 25 мая</div>
                  </div>
                </DSCard>
              ))}
            </div>
          </div>
        ))}
      </div>
    </AdminShell>
  );
}

// ─────────── ПРОДУКТЫ АДМИН + КОНКУРСЫ АДМИН ───────────
function AdminProducts() {
  return (
    <AdminShell activeId="prod" title="Продукты · CRUD" subtitle="6 продуктов · программы · ставки комиссий"
      actions={<DSButton variant="filled">＋ Новый продукт</DSButton>}>
      <FinTable cols={[
        {label:'продукт'},{label:'программ'},{label:'базовая ставка', mono:true},{label:'leader pool', mono:true},{label:'продано', right:true, mono:true},{label:'статус'},{label:''},
      ]} rows={[
        [<div style={{display:'flex',alignItems:'center',gap:10}}><div style={{width:28,height:28,borderRadius:6,background:'linear-gradient(135deg, #1B5E20, #6EE87A)'}}></div>Эволюция</div>, 4, '5%', '1%', '78', <DSStatus variant="active">опубликован</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><div style={{width:28,height:28,borderRadius:6,background:'linear-gradient(135deg, #2E7D32, #A4E0AC)'}}></div>СОЗ</div>, 2, '6%', '1.5%', '41', <DSStatus variant="active">опубликован</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><div style={{width:28,height:28,borderRadius:6,background:'linear-gradient(135deg, #1B5E20, #4361A8)'}}></div>PRE-IPO</div>, 1, '10%', '2%', '23', <DSStatus variant="active">опубликован</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
        [<div style={{display:'flex',alignItems:'center',gap:10}}><div style={{width:28,height:28,borderRadius:6,background:'linear-gradient(135deg, #2E7D32, #6EE87A)'}}></div>Эволюция-2</div>, 0, '6%', '1%', '0', <DSStatus variant="warn">черновик</DSStatus>, <DSButton variant="text" icon size="sm">⋮</DSButton>],
      ]} />
    </AdminShell>
  );
}

function AdminContests() {
  return (
    <AdminShell activeId="news" title="Конкурсы · CRUD" subtitle="2 активных · 3 завершённых"
      actions={<DSButton variant="filled">＋ Новый конкурс</DSButton>}>
      <FinTable cols={[
        {label:'конкурс'},{label:'период'},{label:'фонд'},{label:'критерий'},{label:'участников'},{label:'статус'},
      ]} rows={[
        ['Гонка лета 2026', '1 мая — 30 июня', '2 000 000 ₽', 'кол-во контрактов · Эволюция', '142', <DSStatus variant="active">активен</DSStatus>],
        ['Q2 — старт нового продукта', '1 апр — 30 июня', '500 000 ₽', 'продажа Эволюция-2', '0', <DSStatus variant="info">старт 1 июня</DSStatus>],
        ['Зимняя гонка', '1 дек — 28 фев', '1 500 000 ₽', 'суммарный оборот', '142', <DSStatus variant="draft">завершён</DSStatus>],
      ]} />
    </AdminShell>
  );
}

// ─────────── МОНИТОРИНГ ───────────
function AdminMonitoring() {
  return (
    <AdminShell activeId="mon" title="Мониторинг" subtitle="системные метрики в реальном времени">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
        {[
          { lbl: 'API · p95 ответ', v: '142 мс', c: 'var(--ds-success)' },
          { lbl: 'Очереди · в работе', v: '24', c: 'var(--ds-on-surface)' },
          { lbl: 'Ошибок 5xx (час)', v: '12', c: 'var(--ds-warning)' },
          { lbl: 'Socket.IO · клиентов', v: '184', c: 'var(--ds-primary)' },
        ].map((k, i) => (
          <DSCard key={i} variant="elevated">
            <div style={{padding:16}}>
              <div className="ds-label-m ds-muted">{k.lbl}</div>
              <div className="ds-headline-m ds-mono" style={{marginTop:6, color:k.c}}>{k.v}</div>
            </div>
          </DSCard>
        ))}
      </div>
      <DSCard variant="elevated">
        <div style={{padding:'16px 18px 10px'}}>
          <div className="ds-title-l">Сервисы</div>
        </div>
        <table className="ds-table">
          <thead><tr><th>сервис</th><th>версия</th><th>uptime</th><th>latency p95</th><th>статус</th></tr></thead>
          <tbody>
            {[
              ['Laravel API', 'v2.4.8', '99.98%', '142 мс', <DSStatus variant="active">здоров</DSStatus>],
              ['PostgreSQL primary', '16.2', '99.99%', '8 мс', <DSStatus variant="active">здоров</DSStatus>],
              ['PostgreSQL replica', '16.2', '99.99%', '12 мс', <DSStatus variant="active">здоров</DSStatus>],
              ['Redis cache', '7.2', '100%', '1 мс', <DSStatus variant="active">здоров</DSStatus>],
              ['Socket.IO', 'v4.7', '99.94%', '32 мс', <DSStatus variant="warn">нагрузка</DSStatus>],
              ['Калькулятор', 'v1.8', '94.2%', '480 мс', <DSStatus variant="err">сбой</DSStatus>],
              ['Email queue', 'SendPulse', '99.8%', '—', <DSStatus variant="active">здоров</DSStatus>],
            ].map((r, i) => (
              <tr key={i}>
                <td><span className="ds-title-s">{r[0]}</span></td>
                <td className="ds-mono ds-muted">{r[1]}</td>
                <td className="ds-mono">{r[2]}</td>
                <td className="ds-mono">{r[3]}</td>
                <td>{r[4]}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </DSCard>
    </AdminShell>
  );
}

// ─────────── СТАТУС СИСТЕМЫ (публичный) ───────────
function AdminStatus() {
  return (
    <AdminShell activeId="st" title="Статус системы" subtitle="публичный status-page · /status">
      <DSCard variant="elevated" style={{ background: 'linear-gradient(135deg, var(--ds-success-container), var(--ds-primary-container))' }}>
        <div style={{ padding: 28, display: 'flex', alignItems: 'center', gap: 18 }}>
          <div style={{ width: 56, height: 56, borderRadius: 50, background: 'var(--ds-success)', color: 'var(--ds-on-success)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 28 }}>✓</div>
          <div>
            <div className="ds-headline-m" style={{ color: 'var(--ds-on-success-container)' }}>Все системы в норме</div>
            <div className="ds-body-m ds-muted" style={{ marginTop: 4 }}>Последний инцидент — 12 дней назад · uptime за 90 дней: 99.94%</div>
          </div>
        </div>
      </DSCard>

      <DSCard variant="elevated">
        <div style={{ padding: 22 }}>
          {[
            { name: 'API', up: 99.98 },
            { name: 'Партнёрский кабинет', up: 99.96 },
            { name: 'Админ-панель', up: 100 },
            { name: 'Real-time чат', up: 99.94 },
            { name: 'Калькулятор', up: 94.2 },
            { name: 'Импорт транзакций', up: 99.8 },
          ].map((s, i) => (
            <div key={i} style={{ padding: '16px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 8 }}>
                <span className="ds-title-m">{s.name}</span>
                <span className="ds-body-m ds-mono" style={{ color: s.up >= 99 ? 'var(--ds-success)' : 'var(--ds-warning)' }}>{s.up}%</span>
              </div>
              {/* 90 days of dots */}
              <div style={{ display: 'flex', gap: 2 }}>
                {Array.from({length: 90}, (_, j) => {
                  const bad = (s.up < 99 && j > 80 && j < 84) || (s.up < 99.95 && j === 24);
                  return (
                    <div key={j} style={{
                      flex: 1, height: 24, borderRadius: 2,
                      background: bad ? 'var(--ds-error)' : 'var(--ds-success)',
                    }} title={`день ${90 - j}: ${bad ? 'инцидент' : 'OK'}`}></div>
                  );
                })}
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
                <span className="ds-body-s ds-muted">90 дней назад</span>
                <span className="ds-body-s ds-muted">сегодня</span>
              </div>
            </div>
          ))}
        </div>
      </DSCard>
    </AdminShell>
  );
}

Object.assign(window, {
  AdminUsers, AdminReconciliation, AdminAnomalies, AdminCalendar, AdminCohorts,
  AdminBulkOps, AdminTriggers, AdminMail, AdminIntegrations, AdminApiKeys, AdminSettings,
  AdminReferences, AdminNews, AdminRoadmap, AdminProducts, AdminContests, AdminMonitoring, AdminStatus,
  AdminShell,
});
