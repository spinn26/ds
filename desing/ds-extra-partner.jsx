// ds-extra-partner.jsx — оставшиеся экраны партнёрского кабинета
// Структура · Финансы Отчёт · Финансы Калькулятор · Конкурсы · Чат ·
// Профиль · Справка · Рефералы

// ─────────── СТРУКТУРА ───────────
function PartnerStructure({ theme = 'light' }) {
  const tree = [
    { lvl: 0, name: 'Иванов Иван', q: 'Senior', sales: '2.48 М', team: 12, active: true, status: 'a' },
    { lvl: 1, name: 'Сидорова Анна', q: 'Senior', sales: '3.48 М', team: 5, status: 'a' },
    { lvl: 2, name: 'Никитин В.', q: 'Консультант', sales: '1.20 М', status: 'a' },
    { lvl: 2, name: 'Орлова Т.', q: 'Стажёр', sales: '380 К', status: 'w' },
    { lvl: 1, name: 'Карпов М.', q: 'Консультант', sales: '2.12 М', team: 3, status: 'a' },
    { lvl: 2, name: 'Васильев К.', q: 'Стажёр', sales: '180 К', status: 'a' },
    { lvl: 1, name: 'Петров П.', q: 'Консультант', sales: '1.84 М', team: 2, status: 'a' },
    { lvl: 1, name: 'Лебедева Н.', q: 'Стажёр', sales: '680 К', team: 0, status: 'd' },
  ];

  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="struct" />} content={
      <React.Fragment>
        <AppBar title="Структура" subtitle="моя команда · 12 партнёров · 4 уровня глубины">
          <DSField placeholder="поиск по партнёру" prefix="⌕" />
          <DSButton variant="outlined" size="sm">⏷ статус</DSButton>
          <DSButton variant="filled" size="sm">＋ Пригласить</DSButton>
        </AppBar>
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 14 }}>
          {/* legend */}
          <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', alignItems: 'center' }}>
            <span className="ds-label-m ds-muted">статус 90 дней:</span>
            <DSStatus variant="active">активен</DSStatus>
            <DSStatus variant="warn">риск</DSStatus>
            <DSStatus variant="draft">неактивен</DSStatus>
            <DSStatus variant="err">заморожен</DSStatus>
          </div>
          <DSCard variant="elevated">
            <div style={{ padding: 8 }}>
              {tree.map((n, i) => (
                <div key={i} style={{
                  display: 'flex', alignItems: 'center', gap: 12,
                  padding: '12px 14px', borderRadius: 8,
                  background: n.active ? 'var(--ds-primary-soft)' : 'transparent',
                  marginLeft: n.lvl * 32, position: 'relative',
                }}>
                  {n.lvl > 0 && (
                    <span style={{ position: 'absolute', left: -16, top: 0, bottom: '50%', borderLeft: '1.5px solid var(--ds-outline-variant)', borderBottom: '1.5px solid var(--ds-outline-variant)', width: 12, borderBottomLeftRadius: 6 }}></span>
                  )}
                  <DSAvatar initials={n.name.split(' ').map(p => p[0]).slice(0,2).join('')} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div className="ds-title-s">{n.name}</div>
                    <div className="ds-body-s ds-muted">{n.q} {n.team !== undefined ? `· команда ${n.team}` : ''}</div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div className="ds-body-m ds-mono">{n.sales} ₽</div>
                    <div className="ds-body-s ds-muted">личные продажи</div>
                  </div>
                  <DSStatus variant={n.status === 'a' ? 'active' : n.status === 'w' ? 'warn' : n.status === 'd' ? 'draft' : 'err'}>{n.status === 'a' ? 'активен' : n.status === 'w' ? 'риск' : 'неактивен'}</DSStatus>
                  <DSButton variant="text" icon size="sm">⋮</DSButton>
                </div>
              ))}
            </div>
          </DSCard>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── ФИНАНСЫ · ОТЧЁТ ───────────
function PartnerFinanceReport({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="fin" />} content={
      <React.Fragment>
        <AppBar title="Финансы · отчёт" subtitle="моя финансовая сводка · май 2026">
          <DSChip onClick={()=>{}} active>май 2026 ⌄</DSChip>
          <DSButton variant="outlined" size="sm">⤓ PDF</DSButton>
        </AppBar>
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* big numbers */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
            {[
              { lbl: 'Начислено', v: '482 400', sub: 'комиссия · бонусы', tone: 'p' },
              { lbl: 'Выплачено', v: '328 200', sub: 'на банковский счёт', tone: 'g' },
              { lbl: 'К выплате 1 июня', v: '154 200', sub: 'войдёт в реестр', tone: 'w' },
            ].map((k, i) => (
              <DSCard key={i} variant="elevated">
                <div style={{ padding: 18 }}>
                  <div className="ds-label-m ds-muted">{k.lbl}</div>
                  <div className="ds-display-s ds-mono" style={{ marginTop: 8, color: k.tone === 'p' ? 'var(--ds-on-surface)' : k.tone === 'g' ? 'var(--ds-success)' : 'var(--ds-warning)' }}>{k.v} <span className="ds-body-l ds-muted">₽</span></div>
                  <div className="ds-body-s ds-muted" style={{ marginTop: 6 }}>{k.sub}</div>
                </div>
              </DSCard>
            ))}
          </div>

          {/* breakdown table */}
          <DSCard variant="elevated">
            <div style={{ padding: '16px 18px 10px' }}>
              <div className="ds-title-l">Разбивка начислений</div>
              <div className="ds-body-s ds-muted">snapshot на конец дня 25 мая · live SUM сходится</div>
            </div>
            <table className="ds-table">
              <thead>
                <tr><th>статья</th><th>основание</th><th style={{ textAlign: 'right' }}>snapshot</th><th style={{ textAlign: 'right' }}>live</th><th style={{ textAlign: 'right' }}>в отчёт</th></tr>
              </thead>
              <tbody>
                {[
                  ['ЛП — Эволюция','78 контрактов','248 400','248 400','248 400'],
                  ['ГП — Эволюция','5 партнёров','142 000','142 000','142 000'],
                  ['НГП — СОЗ','1 партнёр','62 000','62 000','62 000'],
                  ['Leader pool','доля 12%','38 000','38 000','38 000'],
                  ['Штрафы','возврат C-2024-0188','−8 000','−8 000','−8 000'],
                ].map((r, i) => (
                  <tr key={i}>
                    <td><span className="ds-title-s">{r[0]}</span></td>
                    <td className="ds-muted">{r[1]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right' }}>{r[2]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right' }}>{r[3]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right', fontWeight: 600 }}>{r[4]} ₽</td>
                  </tr>
                ))}
                <tr style={{ background: 'var(--ds-surface-container-low)' }}>
                  <td colSpan="4" className="ds-title-m" style={{ textAlign: 'right' }}>Итого начислено</td>
                  <td className="ds-mono ds-title-m" style={{ textAlign: 'right', color: 'var(--ds-primary)' }}>482 400 ₽</td>
                </tr>
              </tbody>
            </table>
          </DSCard>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── ФИНАНСЫ · КАЛЬКУЛЯТОР ───────────
function PartnerCalculator({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="fin" />} content={
      <React.Fragment>
        <AppBar title="Калькулятор комиссии" subtitle="прикинуть сделку до открытия" />
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 18 }}>
          {/* inputs */}
          <DSCard variant="elevated">
            <div style={{ padding: 20 }}>
              <div className="ds-title-l" style={{ marginBottom: 16 }}>Параметры сделки</div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <DSField label="Продукт" defaultValue="Эволюция" suffix="⌄" />
                <DSField label="Программа" defaultValue="Базовый · 5 лет" suffix="⌄" />
                <DSField label="Поставщик" defaultValue="Альфа-Капитал" suffix="⌄" />
                <DSField label="Сумма контракта" defaultValue="2 400 000" suffix="₽" />
                <div>
                  <div className="ds-label-m ds-muted" style={{ marginBottom: 8 }}>срок (лет)</div>
                  <div style={{ padding: '0 4px' }}><DSSlider value={50} /></div>
                  <div className="ds-mono ds-body-s ds-faint">5 лет</div>
                </div>
                <DSField label="Ваша квалификация" defaultValue="Senior-консультант" suffix="⌄" disabled />
                <DSField label="Размер вашей команды" defaultValue="12 партнёров" disabled />
              </div>
            </div>
          </DSCard>

          {/* output */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            <DSCard variant="elevated" style={{ background: 'linear-gradient(135deg, var(--ds-primary-soft), var(--ds-secondary-container))' }}>
              <div style={{ padding: 20 }}>
                <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>ваша комиссия</div>
                <div className="ds-display-m ds-mono" style={{ marginTop: 6, color: 'var(--ds-primary-deep)' }}>168 000 <span className="ds-body-l">₽</span></div>
                <div className="ds-body-m ds-muted" style={{ marginTop: 6 }}>≈ 7% от суммы контракта · выплата при подписании</div>
              </div>
            </DSCard>

            <DSCard variant="elevated">
              <div style={{ padding: 18 }}>
                <div className="ds-title-l" style={{ marginBottom: 14 }}>Разбивка</div>
                {[
                  { l: 'Базовая ставка ЛП · 5%', v: '120 000', c: 'var(--ds-on-surface)' },
                  { l: 'Бонус за квалификацию · +1%', v: '24 000', c: 'var(--ds-success)' },
                  { l: 'Leader pool · 1%', v: '24 000', c: 'var(--ds-success)' },
                  { l: 'Штрафы / удержания', v: '0', c: 'var(--ds-on-surface-muted)' },
                ].map((r, i) => (
                  <div key={i} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 0', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)' }}>
                    <span className="ds-body-m">{r.l}</span>
                    <span className="ds-body-m ds-mono" style={{ color: r.c, fontWeight: 600 }}>{r.v} ₽</span>
                  </div>
                ))}
                <div style={{ display: 'flex', justifyContent: 'space-between', padding: '14px 0 0', borderTop: '2px solid var(--ds-on-surface)', marginTop: 4 }}>
                  <span className="ds-title-m">Итого</span>
                  <span className="ds-title-l ds-mono" style={{ color: 'var(--ds-primary)' }}>168 000 ₽</span>
                </div>
              </div>
            </DSCard>

            <DSAlert variant="info" title="Расчёт ориентировочный">Финальная сумма зависит от приёмки документов и сверки с поставщиком.</DSAlert>
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── КОНКУРСЫ ───────────
function PartnerContests({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="cont" />} content={
      <React.Fragment>
        <AppBar title="Конкурсы" subtitle="2 активных · 3 завершённых · 1 ваш приз" />
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 18 }}>
          {/* active contest hero */}
          <DSCard variant="elevated" style={{ overflow: 'hidden', position: 'relative' }}>
            <BrandWaves height={140} style={{ position: 'absolute', inset: 0, opacity: 0.35 }} />
            <div style={{ position: 'relative', padding: '22px 28px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <DSChip variant="brand">★ активный · до 30 июня</DSChip>
                <DSChip variant="success">вы в топ-5</DSChip>
              </div>
              <div className="ds-headline-l" style={{ marginTop: 10 }}>Гонка лета 2026 · Эволюция</div>
              <div className="ds-body-m ds-muted" style={{ marginTop: 4 }}>Призовой фонд — 2 000 000 ₽ + поездка на Бали для топ-3</div>

              <div style={{ marginTop: 18, display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, alignItems: 'center' }}>
                <div>
                  <div className="ds-body-s ds-muted">ваш прогресс — 12 контрактов из 20</div>
                  <div style={{ marginTop: 6 }}><DSProgress value={60} variant="brand" height="thick" /></div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div className="ds-label-m ds-muted">осталось</div>
                  <div className="ds-headline-m ds-mono">36 дней</div>
                </div>
              </div>
            </div>
          </DSCard>

          {/* leaderboard */}
          <DSCard variant="elevated">
            <div style={{ padding: '16px 18px 10px', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
              <div className="ds-title-l">Лидерборд</div>
              <DSTabs items={[{value:'all',label:'все', count: 142},{value:'team',label:'моя команда', count: 12}]} active="all" />
            </div>
            <table className="ds-table">
              <thead><tr><th style={{width: 40}}>#</th><th>партнёр</th><th>квалификация</th><th style={{ textAlign: 'right' }}>контрактов</th><th style={{ textAlign: 'right' }}>сумма</th><th>прогресс</th></tr></thead>
              <tbody>
                {[
                  [1,'Соколов А.','★ Director',24,'12.4 М ₽', 100],
                  [2,'Морозов И.','★ Senior',22,'10.8 М ₽', 92],
                  [3,'Карпова О.','★ Senior',18,'9.2 М ₽', 78],
                  [4,'Петров С.','Консультант',15,'7.5 М ₽', 64],
                  [5,'Иванов И.','★ Senior · вы',12,'6.0 М ₽', 60, true],
                  [6,'Сидорова А.','Консультант',11,'5.4 М ₽', 54],
                ].map((r, i) => (
                  <tr key={i} style={{ background: r[6] ? 'var(--ds-primary-soft)' : undefined }}>
                    <td className="ds-mono" style={{ fontWeight: 700, color: i < 3 ? 'var(--ds-primary)' : 'var(--ds-on-surface)' }}>{r[0]}</td>
                    <td>{r[1]}</td>
                    <td className="ds-muted">{r[2]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right' }}>{r[3]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right' }}>{r[4]}</td>
                    <td><div style={{ width: 120 }}><DSProgress value={r[5]} height="thin" /></div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </DSCard>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── ЧАТ ───────────
function PartnerChat({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="chat" />} content={
      <div style={{ display: 'grid', gridTemplateColumns: '320px 1fr 300px', height: '100%', overflow: 'hidden' }}>
        {/* tickets list */}
        <div style={{ borderRight: '1px solid var(--ds-outline-variant)', display: 'flex', flexDirection: 'column', overflow: 'hidden', background: 'var(--ds-surface)' }}>
          <div style={{ padding: 14, borderBottom: '1px solid var(--ds-outline-variant)' }}>
            <DSField placeholder="поиск тикетов" prefix="⌕" />
            <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
              <DSChip onClick={()=>{}} active>все · 8</DSChip>
              <DSChip onClick={()=>{}}>открытые · 3</DSChip>
            </div>
          </div>
          <div style={{ overflow: 'auto', flex: 1 }}>
            {[
              { id: 1, name: 'Поддержка', last: 'Документ загружен. Проверяем…', time: '14:32', un: 2, active: true, cat: 'Контракты' },
              { id: 2, name: 'Куратор Ольга', last: 'Готово, можно сдавать тест', time: '12:18', un: 0, cat: 'Обучение' },
              { id: 3, name: 'Финансы — Мария', last: 'Реестр сформирован', time: 'вчера', un: 0, cat: 'Выплаты' },
              { id: 4, name: 'Поддержка', last: 'Решено', time: '23 мая', un: 0, cat: 'Технический' },
            ].map(t => (
              <div key={t.id} style={{
                padding: '14px 16px', borderBottom: '1px solid var(--ds-outline-soft)',
                background: t.active ? 'var(--ds-primary-soft)' : 'transparent',
                borderLeft: t.active ? '3px solid var(--ds-primary)' : '3px solid transparent',
                cursor: 'pointer',
              }}>
                <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
                  <div className="ds-title-s">{t.name}</div>
                  <div className="ds-body-s ds-muted">{t.time}</div>
                </div>
                <div className="ds-body-s" style={{ marginTop: 4, color: 'var(--ds-on-surface-variant)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{t.last}</div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 8 }}>
                  <DSChip>{t.cat}</DSChip>
                  {t.un > 0 && <DSBadge>{t.un}</DSBadge>}
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* messages */}
        <div style={{ display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
          <div style={{ padding: '14px 22px', borderBottom: '1px solid var(--ds-outline-variant)', display: 'flex', alignItems: 'center', gap: 12 }}>
            <DSAvatar initials="ПД" status />
            <div style={{ flex: 1 }}>
              <div className="ds-title-m">Поддержка · Контракт C-2024-0381</div>
              <div className="ds-body-s ds-muted">отвечает обычно за 8 минут · онлайн</div>
            </div>
            <DSButton variant="text" icon>⋮</DSButton>
          </div>
          <div style={{ flex: 1, overflow: 'auto', padding: '20px 24px', display: 'flex', flexDirection: 'column', gap: 10 }}>
            {[
              { who: 'them', text: 'Здравствуйте! Видим, что вы загрузили скан паспорта клиента, но не хватает разворота с пропиской.' },
              { who: 'me', text: 'Сейчас перешлю, секунду.' },
              { who: 'me', text: '', file: '📎 ivanov_propiska.pdf · 1.2 МБ' },
              { who: 'them', text: 'Документ загружен. Проверяем… напишу как обработаем.', time: '14:32' },
            ].map((m, i) => (
              <div key={i} style={{ display: 'flex', justifyContent: m.who === 'me' ? 'flex-end' : 'flex-start' }}>
                <div style={{
                  maxWidth: '70%', padding: '10px 14px', borderRadius: 14,
                  background: m.who === 'me' ? 'var(--ds-primary)' : 'var(--ds-surface-container)',
                  color: m.who === 'me' ? 'var(--ds-on-primary)' : 'var(--ds-on-surface)',
                  borderBottomRightRadius: m.who === 'me' ? 4 : 14,
                  borderBottomLeftRadius: m.who === 'me' ? 14 : 4,
                }}>
                  {m.text}
                  {m.file && <div style={{ padding: '6px 10px', background: 'rgba(0,0,0,0.06)', borderRadius: 8, marginTop: 4 }}>{m.file}</div>}
                </div>
              </div>
            ))}
          </div>
          <div style={{ padding: 14, borderTop: '1px solid var(--ds-outline-variant)' }}>
            <div className="ds-field__input" style={{ height: 48 }}>
              <span className="prefix">📎</span>
              <input placeholder="Написать сообщение…" />
              <button className="ds-btn ds-btn--filled" style={{ height: 32 }}>отправить</button>
            </div>
          </div>
        </div>

        {/* context panel */}
        <div style={{ borderLeft: '1px solid var(--ds-outline-variant)', padding: 18, background: 'var(--ds-surface-container-low)', overflow: 'auto' }}>
          <div className="ds-label-m ds-muted">контекст тикета</div>
          <div className="ds-title-l" style={{ marginTop: 6 }}>Контракт C-2024-0381</div>
          <div className="ds-body-s ds-muted">Иванов И.И. · Эволюция · 2.4 М ₽</div>
          <div style={{ marginTop: 16 }}>
            <DSStatus variant="warn">на проверке</DSStatus>
          </div>
          <div className="ds-divider" style={{ margin: '16px 0' }}></div>
          <div className="ds-label-m ds-muted">SLA</div>
          <div style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0' }}>
            <span className="ds-body-s">первый ответ</span>
            <span className="ds-body-s ds-mono" style={{ color: 'var(--ds-success)' }}>4 мин ✓</span>
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0' }}>
            <span className="ds-body-s">решение</span>
            <span className="ds-body-s ds-mono ds-muted">в работе</span>
          </div>
        </div>
      </div>
    } />
  );
}

// ─────────── ПРОФИЛЬ ───────────
function PartnerProfile({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="prof" />} content={
      <React.Fragment>
        <AppBar title="Профиль" subtitle="личные данные · документы · безопасность" />
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'grid', gridTemplateColumns: '260px 1fr', gap: 22 }}>
          {/* tabs nav */}
          <DSCard variant="elevated">
            <div style={{ padding: 14 }}>
              {[
                { ico: '⚇', label: 'Личные данные', active: true },
                { ico: '▤', label: 'Документы' },
                { ico: '¤', label: 'Реквизиты' },
                { ico: '🔒', label: 'Безопасность' },
                { ico: '⚐', label: 'Уведомления' },
                { ico: '📱', label: 'Telegram-bot' },
              ].map((it, i) => (
                <div key={i} className="ds-nav-item" data-active={it.active ? 'true' : 'false'} style={{ marginBottom: 2 }}>
                  <span className="ds-ico" style={{ fontSize: 16 }}>{it.ico}</span>
                  <span>{it.label}</span>
                </div>
              ))}
            </div>
          </DSCard>

          {/* content */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <DSCard variant="elevated">
              <div style={{ padding: 22, display: 'flex', gap: 22, alignItems: 'center' }}>
                <DSAvatar initials="ИП" size="xl" />
                <div style={{ flex: 1 }}>
                  <div className="ds-headline-s">Иванов Иван Иванович</div>
                  <div className="ds-body-m ds-muted">★ Senior-консультант · ID партнёра DS-04812</div>
                  <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                    <DSChip variant="success">активен</DSChip>
                    <DSChip>с 14 марта 2023</DSChip>
                  </div>
                </div>
                <DSButton variant="outlined">сменить фото</DSButton>
              </div>
            </DSCard>

            <DSCard variant="elevated">
              <div style={{ padding: 22 }}>
                <div className="ds-title-l" style={{ marginBottom: 14 }}>Личные данные</div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                  <DSField label="Фамилия" defaultValue="Иванов" />
                  <DSField label="Имя" defaultValue="Иван" />
                  <DSField label="Отчество" defaultValue="Иванович" />
                  <DSField label="Дата рождения" defaultValue="14.03.1985" />
                  <DSField label="Email" defaultValue="ivanov@dscons.ru" suffix="✓" />
                  <DSField label="Телефон" defaultValue="+7 905 123-45-67" suffix="✓" />
                  <DSField label="Город" defaultValue="Москва" />
                  <DSField label="Часовой пояс" defaultValue="UTC+3 Москва" suffix="⌄" />
                </div>
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 18 }}>
                  <DSButton variant="text">отменить</DSButton>
                  <DSButton variant="filled">сохранить</DSButton>
                </div>
              </div>
            </DSCard>
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── СПРАВКА · INSTRUCTIONS ───────────
function PartnerHelp({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="prof" />} content={
      <React.Fragment>
        <AppBar title="Справка" subtitle="инструкции по работе с платформой">
          <DSField placeholder="искать в справке" prefix="⌕" />
        </AppBar>
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'grid', gridTemplateColumns: '280px 1fr', gap: 22 }}>
          {/* TOC */}
          <DSCard variant="elevated">
            <div style={{ padding: 14 }}>
              {[
                { sec: 'старт', items: ['Первые шаги', 'Настройка профиля', 'Что такое квалификация'] },
                { sec: 'работа с клиентами', items: ['Добавить клиента', 'Антидубль', 'Импорт CSV'] },
                { sec: 'контракты', items: ['Создание контракта', 'Загрузка документов', 'Статусы'] },
                { sec: 'обучение', items: ['Курсы и тесты', 'База знаний'] },
              ].map((g, gi) => (
                <div key={gi} style={{ marginBottom: 12 }}>
                  <div className="ds-label-m ds-muted" style={{ padding: '6px 10px' }}>{g.sec.toUpperCase()}</div>
                  {g.items.map((it, i) => (
                    <div key={i} className="ds-nav-item" data-active={gi === 0 && i === 0 ? 'true' : 'false'}>
                      <span>{it}</span>
                    </div>
                  ))}
                </div>
              ))}
            </div>
          </DSCard>

          <div style={{ maxWidth: 720 }}>
            <DSCard variant="elevated">
              <div style={{ padding: 28 }}>
                <div className="ds-label-m ds-muted">старт · 3 мин чтения</div>
                <div className="ds-headline-l" style={{ marginTop: 6 }}>Первые шаги в DS Consulting</div>
                <div className="ds-body-l ds-muted" style={{ marginTop: 10 }}>Краткое руководство для нового партнёра — настройка профиля, добавление первого клиента и сдача допуск-теста.</div>
                <div className="ds-divider" style={{ margin: '22px 0' }}></div>
                <div className="ds-body-l" style={{ lineHeight: 1.7 }}>
                  Добро пожаловать в платформу DS Consulting. Чтобы начать продавать продукты компании, выполните три простых шага. На каждом из них вам поможет встроенный чат с куратором.
                </div>
                <ol style={{ marginTop: 16, paddingLeft: 22, lineHeight: 1.8 }}>
                  <li><b>Заполните профиль</b> — паспортные данные, ИНН и банковские реквизиты нужны для расчёта комиссий.</li>
                  <li><b>Пройдите вводный курс</b> — 8 модулей, 23 урока, итоговый тест.</li>
                  <li><b>Сдайте тест по продукту</b> — это открывает доступ к продаже в разделе «Продукты».</li>
                </ol>
                <DSAlert variant="info" title="Совет">Не пытайтесь сдать тест без обучения — попытки не ограничены, но статистика показывает, что подготовившиеся сдают с первого раза в 92% случаев.</DSAlert>
              </div>
            </DSCard>
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── РЕФЕРАЛЫ ───────────
function PartnerReferrals({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="prof" />} content={
      <React.Fragment>
        <AppBar title="Рефералы" subtitle="приведи партнёра — получи 30 000 ₽ после его первой сделки" />
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 16 }}>

          {/* hero link */}
          <DSCard variant="elevated" style={{ overflow: 'hidden', position: 'relative' }}>
            <BrandWaves height={180} style={{ position: 'absolute', inset: 0, opacity: 0.4 }} />
            <div style={{ position: 'relative', padding: '22px 28px' }}>
              <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>★ ваша реферальная ссылка</div>
              <div className="ds-body-m ds-muted" style={{ marginTop: 6 }}>отправьте друзьям-консультантам — они получат 5 000 ₽ welcome-бонус</div>
              <div style={{ marginTop: 16, display: 'flex', gap: 8 }}>
                <div className="ds-field__input" style={{ height: 48, flex: 1, fontFamily: 'JetBrains Mono, monospace', fontSize: 13, background: 'var(--ds-surface)' }}>
                  <span className="prefix">🔗</span>
                  <input defaultValue="https://dscons.ru/ref/ivanov-04812" />
                </div>
                <DSButton variant="filled" size="lg">⎘ копировать</DSButton>
                <DSButton variant="tonal" size="lg">📱 Telegram</DSButton>
              </div>
            </div>
          </DSCard>

          {/* stats */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 }}>
            {[
              { lbl: 'переходов по ссылке', v: '142', d: 'за 30 дней' },
              { lbl: 'регистраций', v: '24', d: 'из них 8 — этот месяц' },
              { lbl: 'активных партнёров', v: '12', d: 'из приведённых' },
              { lbl: 'заработано', v: '360 000 ₽', d: 'за всё время' },
            ].map((k, i) => (
              <DSCard key={i} variant="elevated">
                <div style={{ padding: 14 }}>
                  <div className="ds-label-m ds-muted">{k.lbl}</div>
                  <div className="ds-headline-s ds-mono" style={{ marginTop: 4 }}>{k.v}</div>
                  <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{k.d}</div>
                </div>
              </DSCard>
            ))}
          </div>

          {/* referred partners */}
          <DSCard variant="elevated">
            <div style={{ padding: '16px 18px 10px' }}>
              <div className="ds-title-l">Приведённые партнёры</div>
            </div>
            <table className="ds-table">
              <thead><tr><th>партнёр</th><th>зарегистрирован</th><th>статус</th><th>сделок</th><th style={{ textAlign: 'right' }}>ваш бонус</th></tr></thead>
              <tbody>
                {[
                  ['Морозова Е.', '15 марта', 'active', 14, '30 000 ₽'],
                  ['Соколов А.', '02 апреля', 'active', 8, '30 000 ₽'],
                  ['Никитин В.', '20 апреля', 'active', 5, '30 000 ₽'],
                  ['Петрова О.', '03 мая', 'draft', 0, 'ожидает сделки'],
                ].map((r, i) => (
                  <tr key={i}>
                    <td><div style={{ display: 'flex', alignItems: 'center', gap: 10 }}><DSAvatar initials={r[0].split(' ').map(p=>p[0]).join('').slice(0,2)} size="sm" />{r[0]}</div></td>
                    <td className="ds-muted">{r[1]}</td>
                    <td><DSStatus variant={r[2] === 'active' ? 'active' : 'draft'}>{r[2] === 'active' ? 'активен' : 'регистрация'}</DSStatus></td>
                    <td className="ds-mono">{r[3]}</td>
                    <td className="ds-mono" style={{ textAlign: 'right', fontWeight: 600, color: r[4].includes('₽') ? 'var(--ds-success)' : 'var(--ds-on-surface-muted)' }}>{r[4]}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </DSCard>
        </div>
      </React.Fragment>
    } />
  );
}

Object.assign(window, { PartnerStructure, PartnerFinanceReport, PartnerCalculator, PartnerContests, PartnerChat, PartnerProfile, PartnerHelp, PartnerReferrals });
