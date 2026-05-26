// ds-missing-partner.jsx — недостающие экраны партнёрского кабинета и auth
// AuthRegister · Education + Course + Lesson + Test + KB · Communication
// Terminated · Forbidden · NotFound · SystemStatus · InSmartWidget

// ─────────── AUTH · REGISTER ───────────
function AuthRegister({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ height: '100%', overflow: 'hidden', position: 'relative', background: 'linear-gradient(135deg, #0a2b10 0%, #14401b 55%, #1B5E20 100%)' }}>
      {/* blobs */}
      <div style={{ position: 'absolute', width: 520, height: 520, borderRadius: '50%', background: 'radial-gradient(circle, rgba(110,232,122,0.35), transparent 60%)', top: -160, left: -120, filter: 'blur(40px)' }}></div>
      <div style={{ position: 'absolute', width: 420, height: 420, borderRadius: '50%', background: 'radial-gradient(circle, rgba(110,232,122,0.22), transparent 60%)', bottom: -150, right: -100, filter: 'blur(40px)' }}></div>
      <div style={{ position: 'absolute', width: 280, height: 280, borderRadius: '50%', background: 'radial-gradient(circle, rgba(67,97,168,0.25), transparent 60%)', top: '40%', right: '30%', filter: 'blur(60px)' }}></div>

      <div style={{ height: '100%', display: 'grid', placeItems: 'center', padding: 24, position: 'relative' }}>
        <div style={{ width: 640, maxWidth: '100%', background: 'rgba(22,26,23,0.72)', backdropFilter: 'blur(16px)', border: '1px solid rgba(255,255,255,0.08)', borderRadius: 24, padding: 36, boxShadow: '0 20px 60px rgba(0,0,0,0.4)' }}>
          {/* brand */}
          <div style={{ textAlign: 'center', marginBottom: 18 }}>
            <div style={{ display: 'inline-flex', alignItems: 'center', gap: 10 }}>
              <DSMark size={32} />
              <div style={{ color: '#fff', fontWeight: 800, fontSize: 22, letterSpacing: -0.4 }}>DS</div>
            </div>
            <div style={{ fontSize: 10, letterSpacing: 4, color: '#6EE87A', fontWeight: 700, marginTop: 6 }}>КОНСАЛТИНГ ПЛАТФОРМА</div>
          </div>
          <div style={{ textAlign: 'center', color: '#fff', fontSize: 24, fontWeight: 700 }}>Регистрация</div>

          {/* referral banner */}
          <div style={{ marginTop: 18, padding: '12px 14px', borderRadius: 10, background: 'rgba(110,232,122,0.14)', border: '1px solid rgba(110,232,122,0.3)', display: 'flex', alignItems: 'center', gap: 12, color: '#C8FBCE' }}>
            <div style={{ width: 30, height: 30, borderRadius: '50%', background: 'rgba(110,232,122,0.25)', display: 'grid', placeItems: 'center' }}>✓</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 600, fontSize: 13 }}>Вас пригласил партнёр Иван Петров</div>
              <div style={{ fontSize: 11.5, opacity: 0.85 }}>код: DS-IP-9472 · регистрация открыта</div>
            </div>
          </div>

          {/* stepper */}
          <div style={{ display: 'flex', gap: 12, marginTop: 18 }}>
            {[
              { n: '1', l: 'Ввод данных', active: true },
              { n: '2', l: 'Проверка', active: false },
            ].map((s, i) => (
              <div key={i} style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 10 }}>
                <div style={{ width: 24, height: 24, borderRadius: '50%', background: s.active ? '#6EE87A' : 'rgba(255,255,255,0.12)', color: s.active ? '#0a2b10' : '#fff', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 12 }}>{s.n}</div>
                <span style={{ color: s.active ? '#fff' : 'rgba(255,255,255,0.5)', fontWeight: 600, fontSize: 13 }}>{s.l}</span>
                {i === 0 && <div style={{ flex: 1, height: 1, background: 'rgba(255,255,255,0.12)' }}></div>}
              </div>
            ))}
          </div>

          {/* form */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10, marginTop: 18 }}>
            {['Фамилия','Имя','Отчество'].map((p, i) => (
              <input key={i} placeholder={p} style={inpStyle()} />
            ))}
          </div>
          <div style={{ marginTop: 10 }}>
            <input placeholder="Email · вход в кабинет" style={inpStyle()} />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 10 }}>
            <input placeholder="+7 (___) ___-__-__" style={inpStyle()} />
            <input placeholder="@telegram" style={inpStyle()} />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 10 }}>
            <input placeholder="дд.мм.гггг — дата рождения" style={inpStyle()} />
            <input placeholder="Город" style={inpStyle()} />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 10 }}>
            <input placeholder="Пароль" type="password" style={inpStyle()} />
            <input placeholder="Подтвердите пароль" type="password" style={inpStyle()} />
          </div>

          <div style={{ marginTop: 14, display: 'flex', flexDirection: 'column', gap: 8 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 10, color: 'rgba(255,255,255,0.85)', fontSize: 13 }}>
              <span style={chkStyle(true)}>✓</span>Согласен на обработку персональных данных
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 10, color: 'rgba(255,255,255,0.85)', fontSize: 13 }}>
              <span style={chkStyle(false)}></span>Согласен с правилами использования платформы
            </label>
          </div>

          <button style={{ marginTop: 18, width: '100%', height: 48, borderRadius: 10, background: '#6EE87A', color: '#0a2b10', border: 0, fontWeight: 700, fontSize: 15, cursor: 'pointer' }}>Далее →</button>

          <div style={{ marginTop: 14, textAlign: 'center', color: 'rgba(255,255,255,0.6)', fontSize: 13 }}>
            Уже есть аккаунт? <span style={{ color: '#6EE87A', fontWeight: 600 }}>Войти</span>
          </div>
        </div>
      </div>
    </div>
  );
}
function inpStyle() {
  return {
    width: '100%', height: 44, borderRadius: 10, padding: '0 14px',
    background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.10)',
    color: '#fff', fontSize: 14, outline: 'none',
  };
}
function chkStyle(checked) {
  return {
    display: 'inline-grid', placeItems: 'center', width: 18, height: 18, borderRadius: 4,
    background: checked ? '#6EE87A' : 'transparent',
    border: checked ? 'none' : '1.5px solid rgba(255,255,255,0.3)',
    color: '#0a2b10', fontWeight: 700, fontSize: 11,
  };
}

// ─────────── PARTNER · EDUCATION HOME ───────────
function PartnerEducation({ theme = 'light' }) {
  const courses = [
    { n: '01', t: 'Онбординг партнёра', s: '6 модулей · 18 уроков', p: 100, done: true, lock: false },
    { n: '02', t: 'Эволюция — продукт', s: '5 уроков + тест', p: 62, done: false, lock: false, locked: false },
    { n: '03', t: 'Возражения и переговоры', s: '14 уроков', p: 28, done: false, lock: false },
    { n: '04', t: 'PRE-IPO · продукт', s: '7 уроков + тест', p: 0, done: false, lock: true },
    { n: '05', t: 'Тим-лид · управление командой', s: '12 уроков', p: 0, done: false, lock: true },
    { n: '06', t: 'СОЗ · накопительное страхование', s: '4 урока + тест', p: 0, done: false, lock: true },
  ];
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="edu" />} content={
      <React.Fragment>
        <AppBar title="Обучение" subtitle="6 курсов · 2 в процессе · 1 ждёт теста" />
        <div style={{ overflow: 'auto', padding: '24px 28px', display: 'flex', flexDirection: 'column', gap: 18 }}>

          {/* continue */}
          <DSCard variant="brand">
            <div style={{ display: 'grid', gridTemplateColumns: '56px 1fr auto', gap: 16, padding: 16, alignItems: 'center' }}>
              <div style={{ width: 56, height: 56, borderRadius: 14, background: 'var(--ds-primary)', color: 'var(--ds-on-primary)', display: 'grid', placeItems: 'center', fontSize: 22 }}>▶</div>
              <div>
                <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>ПРОДОЛЖИТЕ С ТОГО МЕСТА</div>
                <div className="ds-title-l" style={{ marginTop: 2 }}>Эволюция — урок 3 «Подбор клиентов»</div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 10 }}>
                  <div style={{ width: 240 }}><DSProgress value={62} /></div>
                  <span className="ds-body-s ds-muted ds-mono">62% · урок 3 из 5</span>
                </div>
              </div>
              <DSButton variant="filled" size="lg">продолжить →</DSButton>
            </div>
          </DSCard>

          {/* KB promo */}
          <DSCard variant="elevated">
            <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: 16 }}>
              <div style={{ width: 56, height: 56, borderRadius: 14, background: 'var(--ds-primary-soft)', color: 'var(--ds-primary)', display: 'grid', placeItems: 'center', fontSize: 22 }}>📚</div>
              <div style={{ flex: 1 }}>
                <div className="ds-title-l" style={{ color: 'var(--ds-primary)' }}>База знаний</div>
                <div className="ds-body-m ds-muted">Регламенты, инструкции, записи деловых игр и созвонов.</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className="ds-title-m ds-mono">142 материала</div>
                <div className="ds-body-s ds-muted">обновлено вчера</div>
              </div>
              <span style={{ color: 'var(--ds-on-surface-muted)', fontSize: 22 }}>→</span>
            </div>
          </DSCard>

          {/* my courses */}
          <div>
            <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 12 }}>
              <div className="ds-headline-s">Мои курсы <span className="ds-body-m ds-muted" style={{ fontWeight: 400 }}>· 6 из 6</span></div>
              <DSField placeholder="Поиск по курсам..." style={{ width: 240 }} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
              {courses.map((c, i) => (
                <DSCard key={i} variant="elevated">
                  <div style={{ padding: 16, position: 'relative' }}>
                    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                      <div style={{ fontSize: 32, fontWeight: 800, color: 'var(--ds-on-surface-muted)', letterSpacing: -1, lineHeight: 1 }}>{c.n}</div>
                      {c.lock ? (
                        <DSChip variant="warning">🔒 нужен тест</DSChip>
                      ) : c.done ? (
                        <DSChip variant="success">✓ изучен</DSChip>
                      ) : (
                        <DSChip variant="info">в процессе</DSChip>
                      )}
                    </div>
                    <div className="ds-title-l" style={{ marginTop: 10 }}>{c.t}</div>
                    <div className="ds-body-s ds-muted">{c.s}</div>
                    <div style={{ marginTop: 14 }}>
                      <DSProgress value={c.p} variant={c.p === 100 ? undefined : undefined} />
                      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
                        <span className="ds-body-s ds-mono ds-muted">{c.p}%</span>
                        <span className="ds-body-s" style={{ color: 'var(--ds-primary)', fontWeight: 600 }}>
                          {c.p === 0 ? 'Начать →' : c.p === 100 ? 'Перейти к тесту →' : 'Продолжить →'}
                        </span>
                      </div>
                    </div>
                  </div>
                </DSCard>
              ))}
            </div>
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── PARTNER · COURSE ───────────
function PartnerEducationCourse({ theme = 'light' }) {
  const tree = [
    { lvl: 0, t: 'Эволюция — продукт', p: 62, expanded: true, done: false, current: false },
    { lvl: 1, t: 'Урок 1. Описание продукта', p: 100, done: true },
    { lvl: 1, t: 'Урок 2. Кому подходит', p: 100, done: true },
    { lvl: 1, t: 'Урок 3. Подбор клиентов', p: 62, current: true },
    { lvl: 1, t: 'Урок 4. Возражения', p: 0 },
    { lvl: 1, t: 'Тест по курсу', p: 0, lock: true },
  ];
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="edu" />} content={
      <React.Fragment>
        <AppBar title="Эволюция — продукт" subtitle="Обучение → Эволюция" />
        <div style={{ display: 'grid', gridTemplateColumns: '280px 1fr', height: '100%', overflow: 'hidden' }}>

          {/* tree */}
          <aside style={{ borderRight: '1px solid var(--ds-outline-variant)', overflow: 'auto', padding: 18, background: 'var(--ds-surface-container-low)' }}>
            <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>КУРС</div>
            <div className="ds-title-l" style={{ marginTop: 4 }}>Эволюция</div>
            <div style={{ marginTop: 10 }}><DSProgress value={62} /></div>
            <div className="ds-body-s ds-mono ds-muted" style={{ marginTop: 6 }}>62% · 2 из 5</div>
            <div style={{ marginTop: 18, display: 'flex', flexDirection: 'column', gap: 2 }}>
              {tree.map((n, i) => (
                <div key={i} className="ds-list-item" data-active={n.current ? 'true' : undefined}
                  style={{ paddingLeft: 8 + n.lvl * 16, opacity: n.lock ? 0.55 : 1 }}>
                  <span style={{ width: 18, color: n.done ? 'var(--ds-success)' : 'var(--ds-on-surface-muted)' }}>
                    {n.lvl === 0 ? '▾' : n.lock ? '🔒' : n.done ? '✓' : n.current ? '▶' : '○'}
                  </span>
                  <span className={n.lvl === 0 ? 'ds-title-s' : 'ds-body-m'} style={{ flex: 1 }}>{n.t}</span>
                </div>
              ))}
            </div>
          </aside>

          {/* content */}
          <div style={{ overflow: 'auto', padding: '24px 28px', display: 'flex', flexDirection: 'column', gap: 16 }}>
            {/* hero */}
            <div className="ds-card" style={{ padding: 0, overflow: 'hidden', position: 'relative' }}>
              <BrandWaves height={180} style={{ position: 'absolute', inset: 0, opacity: 0.45 }} />
              <div style={{ position: 'relative', padding: '24px 28px' }}>
                <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>★ КУРС</div>
                <div className="ds-headline-l" style={{ marginTop: 6 }}>Эволюция — продукт</div>
                <div className="ds-body-m ds-muted" style={{ marginTop: 8, maxWidth: 540 }}>
                  Накопительный продукт с защитой капитала. Курс открывает доступ к продаже после прохождения теста.
                </div>
              </div>
            </div>

            {/* next lesson CTA */}
            <DSCard variant="brand">
              <div style={{ padding: 16, display: 'flex', alignItems: 'center', gap: 14 }}>
                <div style={{ width: 44, height: 44, borderRadius: 12, background: 'var(--ds-primary)', color: 'var(--ds-on-primary)', display: 'grid', placeItems: 'center', fontSize: 18 }}>▶</div>
                <div style={{ flex: 1 }}>
                  <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>СЛЕДУЮЩИЙ УРОК</div>
                  <div className="ds-title-l">Урок 3. Подбор клиентов</div>
                </div>
                <div style={{ flex: 1 }}>
                  <DSProgress value={62} variant="brand" />
                  <div className="ds-body-s ds-muted ds-mono" style={{ marginTop: 4 }}>прогресс курса · 62%</div>
                </div>
                <DSButton variant="filled" size="lg">открыть урок →</DSButton>
              </div>
            </DSCard>

            {/* lessons grid */}
            <div className="ds-title-l">Уроки курса</div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12 }}>
              {tree.filter(n => n.lvl === 1).map((l, i) => (
                <DSCard key={i} variant="elevated">
                  <div style={{ padding: 14, display: 'flex', alignItems: 'center', gap: 12 }}>
                    <div style={{ width: 36, height: 36, borderRadius: 10, background: l.done ? 'var(--ds-success-container)' : l.current ? 'var(--ds-primary-soft)' : 'var(--ds-surface-container-high)', color: l.done ? 'var(--ds-on-success-container)' : l.current ? 'var(--ds-primary)' : 'var(--ds-on-surface-muted)', display: 'grid', placeItems: 'center', fontSize: 14 }}>
                      {l.lock ? '🔒' : l.done ? '✓' : l.current ? '▶' : i + 1}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div className="ds-title-s">{l.t}</div>
                      <div className="ds-body-s ds-muted">{l.done ? 'изучен' : l.current ? 'в процессе' : l.lock ? 'нужно завершить уроки' : 'не начат'}</div>
                    </div>
                    {!l.lock && <span style={{ color: 'var(--ds-on-surface-muted)' }}>→</span>}
                  </div>
                </DSCard>
              ))}
            </div>
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── PARTNER · LESSON ───────────
function PartnerEducationLesson({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="edu" />} content={
      <React.Fragment>
        <div style={{ display: 'grid', gridTemplateColumns: '280px 1fr', height: '100%', overflow: 'hidden' }}>
          <aside style={{ borderRight: '1px solid var(--ds-outline-variant)', overflow: 'auto', padding: 18, background: 'var(--ds-surface-container-low)' }}>
            <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>КУРС</div>
            <div className="ds-title-l">Эволюция</div>
            <div style={{ marginTop: 10 }}><DSProgress value={62} /></div>
            <div className="ds-body-s ds-mono ds-muted" style={{ marginTop: 6 }}>62% · 2 из 5</div>
            <div style={{ marginTop: 18, display: 'flex', flexDirection: 'column', gap: 2 }}>
              {[
                { t: 'Урок 1. Описание продукта', s: 'done' },
                { t: 'Урок 2. Кому подходит', s: 'done' },
                { t: 'Урок 3. Подбор клиентов', s: 'current' },
                { t: 'Урок 4. Возражения', s: 'next' },
                { t: 'Тест по курсу', s: 'lock' },
              ].map((n, i) => (
                <div key={i} className="ds-list-item" data-active={n.s === 'current' ? 'true' : undefined}>
                  <span style={{ width: 18, color: n.s === 'done' ? 'var(--ds-success)' : 'var(--ds-on-surface-muted)' }}>
                    {n.s === 'done' ? '✓' : n.s === 'current' ? '▶' : n.s === 'lock' ? '🔒' : '○'}
                  </span>
                  <span className="ds-body-m">{n.t}</span>
                </div>
              ))}
            </div>
          </aside>

          <div style={{ overflow: 'auto' }}>
            {/* sticky header */}
            <div style={{ position: 'sticky', top: 0, padding: '20px 28px 16px', background: 'var(--ds-background)', borderBottom: '1px solid var(--ds-outline-variant)', display: 'flex', alignItems: 'flex-start', gap: 20 }}>
              <div style={{ flex: 1 }}>
                <div className="ds-label-m ds-muted">ЭВОЛЮЦИЯ · УРОК 3</div>
                <div className="ds-headline-m" style={{ marginTop: 4 }}>Подбор клиентов</div>
              </div>
              <DSButton variant="filled" size="lg">✓ Урок изучен</DSButton>
            </div>

            <div style={{ padding: '24px 28px', display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 800 }}>
              {/* video block */}
              <DSCard variant="elevated">
                <div style={{ aspectRatio: '16/9', background: 'linear-gradient(135deg, #0a2b10, #1b5e20)', position: 'relative', display: 'grid', placeItems: 'center' }}>
                  <div style={{ width: 72, height: 72, borderRadius: '50%', background: 'rgba(255,255,255,0.18)', backdropFilter: 'blur(8px)', display: 'grid', placeItems: 'center', color: '#fff', fontSize: 26 }}>▶</div>
                  <div style={{ position: 'absolute', bottom: 14, left: 18, right: 18, display: 'flex', alignItems: 'center', gap: 10, color: '#fff' }}>
                    <div style={{ flex: 1, height: 4, background: 'rgba(255,255,255,0.2)', borderRadius: 2, overflow: 'hidden' }}>
                      <div style={{ width: '34%', height: '100%', background: '#6EE87A' }}></div>
                    </div>
                    <span className="ds-mono ds-body-s">04:12 / 12:08</span>
                  </div>
                </div>
              </DSCard>

              {/* text block */}
              <div>
                <div className="ds-title-l" style={{ marginBottom: 10 }}>Как находить идеальных клиентов</div>
                <div className="ds-body-l" style={{ color: 'var(--ds-on-surface-variant)', lineHeight: 1.65 }}>
                  Продукт «Эволюция» подходит частным инвесторам с горизонтом 5+ лет. В этом уроке разберём 4 типа целевых клиентов и где их искать в холодных каналах.
                  Используйте чек-лист в приложении и шаблон скрипта первого касания.
                </div>
              </div>

              {/* doc block */}
              <DSCard variant="filled">
                <div style={{ padding: 14, display: 'flex', alignItems: 'center', gap: 12 }}>
                  <div style={{ width: 40, height: 40, borderRadius: 8, background: 'var(--ds-info-container)', color: 'var(--ds-on-info-container)', display: 'grid', placeItems: 'center' }}>📄</div>
                  <div style={{ flex: 1 }}>
                    <div className="ds-title-s">Чек-лист подбора клиентов · PDF</div>
                    <div className="ds-body-s ds-muted">обновлён 12 мая · 2 страницы</div>
                  </div>
                  <DSButton variant="outlined" size="sm">⬇ скачать</DSButton>
                </div>
              </DSCard>
            </div>
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── PARTNER · TEST ───────────
function PartnerEducationTest({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="edu" />} content={
      <React.Fragment>
        <div style={{ padding: '16px 28px', background: 'var(--ds-surface)', borderBottom: '1px solid var(--ds-outline-variant)', display: 'flex', alignItems: 'center', gap: 14 }}>
          <DSButton variant="text" size="sm">← к курсу «Эволюция»</DSButton>
          <div style={{ flex: 1 }}></div>
          <span className="ds-body-s ds-muted">попыток: без ограничения · для допуска нужно 100%</span>
        </div>
        <div style={{ overflow: 'auto', padding: '32px 28px', display: 'flex', justifyContent: 'center' }}>
          <div style={{ width: 720, maxWidth: '100%' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 10 }}>
              <div className="ds-title-m">Вопрос 3 из 8</div>
              <div className="ds-body-s ds-muted ds-mono">3 / 8</div>
            </div>
            <DSProgress value={37} height="thick" />

            <DSCard variant="elevated" style={{ marginTop: 22 }}>
              <div style={{ padding: 24 }}>
                <div className="ds-label-m" style={{ color: 'var(--ds-primary)' }}>Q3</div>
                <div className="ds-headline-s" style={{ marginTop: 8 }}>Кому подходит продукт «Эволюция»?</div>
                <div style={{ marginTop: 18, display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {[
                    { t: 'Клиентам с горизонтом инвестиций 5+ лет, готовым к умеренному риску.', selected: true },
                    { t: 'Молодым предпринимателям, только что зарегистрировавшим ИП.', selected: false },
                    { t: 'Клиентам, которые планируют снять средства в течение 12 месяцев.', selected: false },
                    { t: 'Студентам в возрасте до 25 лет.', selected: false },
                  ].map((a, i) => (
                    <div key={i} style={{
                      padding: '12px 14px', borderRadius: 10,
                      border: '1.5px solid ' + (a.selected ? 'var(--ds-primary)' : 'var(--ds-outline-variant)'),
                      background: a.selected ? 'var(--ds-primary-soft)' : 'var(--ds-surface)',
                      display: 'flex', alignItems: 'center', gap: 12, cursor: 'pointer',
                    }}>
                      <DSRadio checked={a.selected} />
                      <span className="ds-body-l">{a.t}</span>
                    </div>
                  ))}
                </div>
              </div>
            </DSCard>

            <div style={{ display: 'flex', gap: 6, justifyContent: 'center', marginTop: 18 }}>
              {Array.from({ length: 8 }).map((_, i) => (
                <span key={i} style={{
                  width: 10, height: 10, borderRadius: '50%',
                  background: i < 2 ? 'var(--ds-success)' : i === 2 ? 'var(--ds-primary)' : 'var(--ds-surface-container-highest)',
                }}></span>
              ))}
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 22 }}>
              <DSButton variant="outlined">← Назад</DSButton>
              <DSButton variant="filled" size="lg">Далее →</DSButton>
            </div>
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── PARTNER · KB SECTION ───────────
function PartnerEducationKb({ theme = 'light' }) {
  const sections = [
    { ico: '📋', t: 'Регламенты', d: 'Правила работы партнёра, нормативная база', n: 28 },
    { ico: '🎯', t: 'Скрипты продаж', d: 'Скрипты звонков и работы с возражениями', n: 14 },
    { ico: '🎓', t: 'Деловые игры', d: 'Записи практик и разборы кейсов', n: 22 },
    { ico: '📞', t: 'Записи созвонов', d: 'Совещания и обучающие созвоны', n: 36 },
    { ico: '🏆', t: 'Кейсы партнёров', d: 'Истории успешных сделок', n: 18 },
    { ico: '📊', t: 'Аналитика и тренды', d: 'Отчёты по рынку, обзоры продуктов', n: 24 },
  ];
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="kb" />} content={
      <React.Fragment>
        <AppBar title="База знаний" subtitle="142 материала в 6 разделах">
          <DSField placeholder="Поиск по базе знаний..." style={{ width: 280 }} />
        </AppBar>
        <div style={{ overflow: 'auto', padding: '24px 28px' }}>
          <div className="ds-body-m ds-muted" style={{ marginBottom: 14 }}>Обучение → База знаний</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
            {sections.map((s, i) => (
              <DSCard key={i} variant="elevated">
                <div style={{ padding: 18 }}>
                  <div style={{ width: 48, height: 48, borderRadius: 12, background: 'var(--ds-primary-soft)', color: 'var(--ds-primary)', display: 'grid', placeItems: 'center', fontSize: 22 }}>{s.ico}</div>
                  <div className="ds-title-l" style={{ marginTop: 14 }}>{s.t}</div>
                  <div className="ds-body-s ds-muted" style={{ marginTop: 4 }}>{s.n} материалов</div>
                  <div className="ds-body-m" style={{ marginTop: 8, color: 'var(--ds-on-surface-variant)' }}>{s.d}</div>
                  <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12, color: 'var(--ds-on-surface-muted)' }}>→</div>
                </div>
              </DSCard>
            ))}
          </div>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── PARTNER · COMMUNICATION (legacy) ───────────
function PartnerCommunication({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="chat" />} content={
      <React.Fragment>
        <AppBar title="Обратная связь" subtitle="3 новых сообщения · легаси-режим">
          <DSChip variant="brand">3 непрочитанных</DSChip>
          <DSButton variant="filled" size="sm">+ Написать сообщение</DSButton>
        </AppBar>
        <div style={{ overflow: 'auto', padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 12 }}>
          <DSCard variant="filled">
            <div style={{ padding: 14, display: 'flex', alignItems: 'center', gap: 12 }}>
              <span className="ds-label-m">Категория:</span>
              <DSChip active onClick={()=>{}}>все</DSChip>
              <DSChip onClick={()=>{}}>поддержка</DSChip>
              <DSChip onClick={()=>{}}>финансы</DSChip>
              <DSChip onClick={()=>{}}>контракты</DSChip>
              <DSChip onClick={()=>{}}>обучение</DSChip>
            </div>
          </DSCard>

          {[
            { from: 'DS', name: 'Бэк-офис · Карпов М.', cat: 'контракты', date: '25 мая · 12:08', unread: true,
              text: 'Контракт C-2024-0381 принят с замечанием по приложению №2. Перезаливите подписанный документ в реквизитах.' },
            { from: 'Вы', name: 'Вы', cat: 'финансы', date: '24 мая · 17:30', unread: false,
              text: 'Уточните, пожалуйста, расчёт комиссии по контракту C-2024-0378. По калькулятору должно быть 8.4%, в отчёте 7.2%.' },
            { from: 'DS', name: 'Куратор · Жосан О.', cat: 'обучение', date: '23 мая · 11:00', unread: true,
              text: 'Не забудьте досдать тест по курсу «Эволюция» — статус «Активен» закроется без него с 1 июня.' },
            { from: 'DS', name: 'Поддержка', cat: 'поддержка', date: '22 мая · 09:15', unread: false,
              text: 'Загрузка реквизитов прошла, документы переданы на проверку. Срок — до 26 мая.' },
          ].map((m, i) => (
            <DSCard key={i} variant="elevated">
              <div style={{ padding: 14, display: 'grid', gridTemplateColumns: '56px 1fr auto', gap: 12, alignItems: 'flex-start' }}>
                <DSAvatar initials={m.from === 'Вы' ? 'Я' : 'DS'} size="lg" style={{ background: m.from === 'Вы' ? 'linear-gradient(135deg, var(--ds-primary), var(--ds-secondary))' : 'linear-gradient(135deg, var(--ds-info), var(--ds-tertiary))' }} />
                <div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <div className="ds-title-s">{m.name}</div>
                    {m.unread && <DSChip variant="error">новое</DSChip>}
                    <DSChip>{m.cat}</DSChip>
                  </div>
                  <div className="ds-body-m" style={{ marginTop: 4, color: 'var(--ds-on-surface-variant)' }}>{m.text}</div>
                </div>
                <div style={{ textAlign: 'right', display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 6 }}>
                  <span className="ds-body-s ds-muted">{m.date}</span>
                  <div style={{ display: 'flex', gap: 6 }}>
                    {m.unread && <DSButton variant="outlined" size="sm">прочитано</DSButton>}
                    <DSButton variant="text" size="sm">ответить</DSButton>
                  </div>
                </div>
              </div>
            </DSCard>
          ))}
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── PARTNER · TERMINATED ───────────
function PartnerTerminated({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ height: '100%', display: 'grid', placeItems: 'center', padding: 28 }}>
      <DSCard variant="elevated" style={{ maxWidth: 560 }}>
        <div style={{ padding: 36, textAlign: 'center' }}>
          <div style={{ width: 88, height: 88, borderRadius: '50%', background: 'var(--ds-error-container)', color: 'var(--ds-error)', display: 'grid', placeItems: 'center', fontSize: 38, margin: '0 auto' }}>🔒</div>
          <div className="ds-headline-m" style={{ marginTop: 16, color: 'var(--ds-error)' }}>Доступ ограничен</div>
          <div className="ds-body-l ds-muted" style={{ marginTop: 8 }}>
            Ваш аккаунт находится в статусе «Терминирован». Доступ к разделам платформы временно закрыт.
          </div>
          <DSDivider style={{ margin: '22px 0' }} />
          <div style={{ textAlign: 'left', display: 'flex', flexDirection: 'column', gap: 18 }}>
            <div>
              <div className="ds-title-s" style={{ marginBottom: 6 }}>Что это значит:</div>
              <ul style={{ margin: 0, paddingLeft: 20, color: 'var(--ds-on-surface-variant)', fontSize: 14, lineHeight: 1.65 }}>
                <li>Условия активационного периода не выполнены</li>
                <li>Накопленные баллы обнулены</li>
                <li>Повторная регистрация возможна не более 3 раз</li>
              </ul>
            </div>
            <div>
              <div className="ds-title-s" style={{ marginBottom: 6 }}>Для восстановления доступа:</div>
              <ul style={{ margin: 0, paddingLeft: 20, color: 'var(--ds-on-surface-variant)', fontSize: 14, lineHeight: 1.65 }}>
                <li>Свяжитесь с техподдержкой</li>
                <li>Обсудите план активации с наставником</li>
              </ul>
            </div>
          </div>
          <div style={{ display: 'flex', justifyContent: 'center', gap: 10, marginTop: 22 }}>
            <DSButton variant="filled">Обратная связь</DSButton>
            <DSButton variant="outlined">Профиль</DSButton>
          </div>
        </div>
      </DSCard>
    </div>
  );
}

// ─────────── PARTNER · FORBIDDEN / NOT FOUND ───────────
function PartnerErrorPages({ theme = 'light' }) {
  return (
    <div className="ds ds-screen-bg" data-ds-theme={theme} style={{ height: '100%', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 28, padding: 28, alignItems: 'center' }}>
      {[
        { ico: '🚫', col: 'var(--ds-error)', bg: 'var(--ds-error-container)', t: 'Доступ запрещён', sub: '403', d: 'У вашей роли нет прав на этот раздел. Обратитесь к администратору, если считаете это ошибкой.' },
        { ico: '🧭', col: 'var(--ds-warning)', bg: 'var(--ds-warning-container)', t: 'Страница не найдена', sub: '404', d: 'Адрес «/finance/legacy-pool» не существует или был удалён. Возможно, ссылка устарела.' },
      ].map((e, i) => (
        <DSCard key={i} variant="elevated">
          <div style={{ padding: 36, textAlign: 'center' }}>
            <div style={{ position: 'relative', display: 'inline-grid', placeItems: 'center' }}>
              <div style={{ width: 96, height: 96, borderRadius: '50%', background: e.bg, color: e.col, display: 'grid', placeItems: 'center', fontSize: 42 }}>{e.ico}</div>
              <div className="ds-mono" style={{ position: 'absolute', top: -10, right: -22, padding: '2px 8px', background: e.col, color: '#fff', borderRadius: 10, fontSize: 11, fontWeight: 700 }}>{e.sub}</div>
            </div>
            <div className="ds-headline-m" style={{ marginTop: 16 }}>{e.t}</div>
            <div className="ds-body-l ds-muted" style={{ marginTop: 8 }}>{e.d}</div>
            <DSButton variant="filled" style={{ marginTop: 18 }}>На главную</DSButton>
          </div>
        </DSCard>
      ))}
    </div>
  );
}

// ─────────── PARTNER · SYSTEM STATUS ───────────
function PartnerSystemStatus({ theme = 'light' }) {
  const components = [
    { t: 'Кабинет партнёра', s: 'ok', d: 'отклик 142 мс · нагрузка низкая' },
    { t: 'Калькулятор комиссий', s: 'err', d: 'серьёзный сбой · идёт исправление' },
    { t: 'Импорт транзакций', s: 'warn', d: 'замедление · загрузка ~5 мин' },
    { t: 'Платёжный шлюз', s: 'ok', d: 'все провайдеры в норме' },
    { t: 'Чат и уведомления', s: 'ok', d: 'WebSocket online · 24 операторов' },
    { t: 'База знаний', s: 'ok', d: 'отклик 88 мс' },
  ];
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="prof" />} content={
      <React.Fragment>
        <AppBar title="Статус системы" subtitle="публичный мониторинг · обновляется каждые 60 сек">
          <DSChip variant="error">● серьёзный сбой</DSChip>
        </AppBar>
        <div style={{ overflow: 'auto', padding: '24px 28px', display: 'flex', flexDirection: 'column', gap: 18 }}>
          {/* overall banner */}
          <div style={{ borderRadius: 14, padding: '18px 22px', background: 'linear-gradient(135deg, #c62828, #93000a)', color: '#fff', display: 'flex', alignItems: 'center', gap: 16 }}>
            <div style={{ width: 56, height: 56, borderRadius: '50%', background: 'rgba(255,255,255,0.18)', display: 'grid', placeItems: 'center', fontSize: 28 }}>⚠</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 18, fontWeight: 700 }}>Серьёзный сбой · затронут расчёт комиссии</div>
              <div style={{ opacity: 0.85, fontSize: 13, marginTop: 4 }}>Обновлено · 13:24:18 МСК</div>
            </div>
            <div style={{ padding: '6px 12px', borderRadius: 999, background: 'rgba(255,255,255,0.16)', fontWeight: 600, fontSize: 13 }}>incident #DS-1042</div>
          </div>

          {/* components */}
          <DSCard variant="elevated">
            <div style={{ padding: '14px 18px 6px' }}>
              <div className="ds-title-l">Компоненты</div>
            </div>
            <div>
              {components.map((c, i) => (
                <div key={i} style={{ padding: '12px 18px', borderTop: i === 0 ? 'none' : '1px solid var(--ds-outline-soft)', display: 'flex', alignItems: 'center', gap: 14 }}>
                  <span style={{ width: 10, height: 10, borderRadius: '50%', background: c.s === 'ok' ? 'var(--ds-success)' : c.s === 'warn' ? 'var(--ds-warning)' : 'var(--ds-error)' }}></span>
                  <div style={{ flex: 1 }}>
                    <div className="ds-title-s">{c.t}</div>
                    <div className="ds-body-s ds-muted">{c.d}</div>
                  </div>
                  <DSStatus variant={c.s === 'ok' ? 'active' : c.s === 'warn' ? 'warn' : 'err'}>
                    {c.s === 'ok' ? 'работает' : c.s === 'warn' ? 'замедление' : 'сбой'}
                  </DSStatus>
                </div>
              ))}
            </div>
          </DSCard>

          {/* active incident */}
          <DSCard variant="elevated">
            <div style={{ padding: 18 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
                <DSChip variant="error">CRITICAL</DSChip>
                <div className="ds-title-l">Калькулятор · некорректные коэффициенты валют</div>
              </div>
              <div className="ds-body-m ds-muted">началось · 25 мая 12:48 · команда работает над инцидентом</div>
              <DSDivider style={{ margin: '14px 0' }} />
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {[
                  { t: 'identified', d: '13:18', m: 'Найдена причина — некорректный курс USD/RUB из источника. Откатываем на предыдущий срез.' },
                  { t: 'investigating', d: '12:52', m: 'Команда DevOps приступила к диагностике. Затронуты расчёты в RUB и USD.' },
                  { t: 'detected', d: '12:48', m: 'Автоматически зафиксировано отклонение более чем на 15% в комиссиях по 12 контрактам.' },
                ].map((u, i) => (
                  <div key={i} style={{ display: 'grid', gridTemplateColumns: '120px 1fr', gap: 14 }}>
                    <div>
                      <DSChip variant="info">{u.t}</DSChip>
                      <div className="ds-body-s ds-muted ds-mono" style={{ marginTop: 4 }}>{u.d}</div>
                    </div>
                    <div className="ds-body-m">{u.m}</div>
                  </div>
                ))}
              </div>
            </div>
          </DSCard>
        </div>
      </React.Fragment>
    } />
  );
}

// ─────────── PARTNER · INSMART WIDGET ───────────
function PartnerInsmart({ theme = 'light' }) {
  return (
    <FullShell theme={theme} sidebar={<PartnerSidebar activeId="prod" />} content={
      <React.Fragment>
        <AppBar title="InSmart · страховые продукты" subtitle="подбор и оформление полиса · встроенный виджет">
          <DSButton variant="outlined" size="sm">← к продуктам</DSButton>
        </AppBar>
        <div style={{ padding: '20px 28px', display: 'flex', flexDirection: 'column', gap: 14, height: '100%', overflow: 'hidden' }}>
          <DSAlert variant="info" title="Полностью автоматическая обработка">
            Все данные оформления, расчёт, начисления и реестры обрабатываются автоматически. Партнёр получает уведомление и оплату после активации полиса клиентом.
          </DSAlert>

          <DSCard variant="elevated" style={{ flex: 1, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
            <div style={{ height: 44, padding: '0 16px', borderBottom: '1px solid var(--ds-outline-variant)', display: 'flex', alignItems: 'center', gap: 10, background: 'var(--ds-surface-container-low)' }}>
              <span style={{ width: 10, height: 10, borderRadius: '50%', background: 'var(--ds-success)' }}></span>
              <span className="ds-body-s ds-mono ds-muted">insmart.ru/widget · session #w-3818</span>
              <div style={{ flex: 1 }}></div>
              <DSButton variant="text" size="sm">↻</DSButton>
              <DSButton variant="text" size="sm">⤢</DSButton>
            </div>
            <div style={{ flex: 1, display: 'grid', gridTemplateColumns: '320px 1fr', gap: 0, overflow: 'hidden', background: '#fff' }}>
              {/* fake widget left col */}
              <div style={{ padding: 24, borderRight: '1px solid var(--ds-outline-variant)', display: 'flex', flexDirection: 'column', gap: 14 }}>
                <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: 2, color: '#0277BD' }}>INSMART · ШАГ 2 ИЗ 4</div>
                <div style={{ fontSize: 22, fontWeight: 700, color: '#1A1F1B' }}>Транспортное средство</div>
                <div style={{ fontSize: 13, color: '#4A524C' }}>Найдём лучший тариф ОСАГО / КАСКО по выбранному ТС.</div>
                <DSField label="ВИН-код" placeholder="XW7BFRHK1NS123456" />
                <DSField label="Гос. номер" placeholder="А 123 МК 77" />
                <DSField label="Регион регистрации" placeholder="Москва" />
                <div style={{ flex: 1 }}></div>
                <div style={{ display: 'flex', gap: 8 }}>
                  <DSButton variant="outlined" size="sm">← назад</DSButton>
                  <DSButton variant="filled" size="sm" block>далее →</DSButton>
                </div>
              </div>
              {/* preview */}
              <div style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 12, background: '#f7f9f8' }}>
                <div style={{ fontSize: 14, color: '#4A524C', fontWeight: 600 }}>Найдено 3 предложения</div>
                {[
                  ['Альфа-Страхование','ОСАГО',6800,'30.05.2026'],
                  ['Согласие','ОСАГО',7240,'01.06.2026'],
                  ['Ингосстрах','КАСКО + ОСАГО',38450,'30.05.2026'],
                ].map((q, i) => (
                  <div key={i} style={{ padding: 14, background: '#fff', borderRadius: 12, border: '1px solid #E6E8E6', display: 'flex', alignItems: 'center', gap: 14 }}>
                    <div style={{ width: 44, height: 44, borderRadius: 10, background: '#E8F1E9', color: '#2E7D32', display: 'grid', placeItems: 'center', fontWeight: 700 }}>{q[0][0]}</div>
                    <div style={{ flex: 1 }}>
                      <div style={{ fontWeight: 600 }}>{q[0]}</div>
                      <div style={{ fontSize: 12, color: '#8A8F8B' }}>{q[1]} · действует с {q[3]}</div>
                    </div>
                    <div style={{ fontWeight: 700, fontSize: 18, fontVariantNumeric: 'tabular-nums' }}>{q[2].toLocaleString('ru')} ₽</div>
                    <button style={{ height: 36, padding: '0 14px', background: '#2E7D32', color: '#fff', border: 0, borderRadius: 8, fontWeight: 600, cursor: 'pointer' }}>выбрать</button>
                  </div>
                ))}
              </div>
            </div>
          </DSCard>
        </div>
      </React.Fragment>
    } />
  );
}

Object.assign(window, {
  AuthRegister, PartnerEducation, PartnerEducationCourse, PartnerEducationLesson,
  PartnerEducationTest, PartnerEducationKb, PartnerCommunication,
  PartnerTerminated, PartnerErrorPages, PartnerSystemStatus, PartnerInsmart,
});
