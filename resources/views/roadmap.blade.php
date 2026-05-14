<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0A0F12">
    <meta name="description" content="Роадмап DS Consulting — что мы делаем, выпускаем и планируем.">
    <meta property="og:title" content="DS Consulting — Роадмап">
    <meta property="og:description" content="Что мы делаем, выпускаем и планируем.">
    <meta property="og:type" content="website">
    <title>Роадмап — DS Consulting</title>

    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-0: #06090b;
            --bg-1: #0a1014;
            --brand: #6EE87A;
            --brand-dim: #4ec461;
            --primary: #2E7D32;
            --text: #e8f0ec;
            --text-dim: rgba(232, 240, 236, 0.62);
            --text-mute: rgba(232, 240, 236, 0.4);
            --border: rgba(110, 232, 122, 0.12);
            --border-strong: rgba(110, 232, 122, 0.28);
            --glass: rgba(255, 255, 255, 0.03);
            --glass-strong: rgba(255, 255, 255, 0.06);
        }

        html, body {
            background: var(--bg-0);
            color: var(--text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        /* Фоновые декоративные градиентные пятна */
        body::before, body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.45;
            pointer-events: none;
            z-index: 0;
            animation: float 18s ease-in-out infinite alternate;
        }
        body::before {
            width: 600px; height: 600px;
            background: radial-gradient(circle, #6EE87A 0%, transparent 70%);
            top: -200px; left: -200px;
        }
        body::after {
            width: 700px; height: 700px;
            background: radial-gradient(circle, #2E7D32 0%, transparent 70%);
            bottom: -300px; right: -200px;
            animation-delay: -9s;
        }
        @keyframes float {
            from { transform: translate(0, 0) scale(1); }
            to   { transform: translate(80px, 50px) scale(1.15); }
        }

        /* Шумовая сетка поверх */
        .grid-overlay {
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(110, 232, 122, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(110, 232, 122, 0.04) 1px, transparent 1px);
            background-size: 60px 60px;
            mask-image: radial-gradient(ellipse at center, black 0%, transparent 70%);
            -webkit-mask-image: radial-gradient(ellipse at center, black 0%, transparent 70%);
            pointer-events: none;
            z-index: 1;
        }

        .wrap {
            position: relative;
            z-index: 2;
            max-width: 960px;
            margin: 0 auto;
            padding: clamp(48px, 8vw, 96px) 24px 96px;
        }

        /* === Hero === */
        .hero { margin-bottom: 64px; }
        .hero .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border: 1px solid var(--border-strong);
            border-radius: 999px;
            font-size: 13px;
            color: var(--brand);
            background: rgba(110, 232, 122, 0.06);
            backdrop-filter: blur(8px);
            margin-bottom: 24px;
            animation: fade-up 0.6s ease-out both;
        }
        .hero .eyebrow .dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 12px var(--brand);
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        .hero h1 {
            font-size: clamp(40px, 7vw, 72px);
            font-weight: 800;
            line-height: 1.05;
            letter-spacing: -0.03em;
            margin-bottom: 20px;
            background: linear-gradient(120deg, #ffffff 0%, #6EE87A 60%, #4ec461 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: fade-up 0.7s 0.05s ease-out both;
        }
        .hero p.lead {
            font-size: clamp(16px, 2vw, 19px);
            color: var(--text-dim);
            max-width: 620px;
            animation: fade-up 0.7s 0.1s ease-out both;
        }

        /* === Stats row === */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin: 48px 0 64px;
            animation: fade-up 0.7s 0.15s ease-out both;
        }
        @media (max-width: 600px) { .stats { grid-template-columns: 1fr; } }
        .stat {
            padding: 20px 22px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 16px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .stat .num {
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 6px;
        }
        .stat .num.shipped { color: var(--brand); }
        .stat .num.progress { color: #FFD166; }
        .stat .num.planned { color: var(--text-dim); }
        .stat .label {
            font-size: 13px;
            color: var(--text-mute);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        /* === Filters === */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 32px;
        }
        .filter-btn {
            padding: 8px 16px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text-dim);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(8px);
            font-family: inherit;
        }
        .filter-btn:hover {
            border-color: var(--border-strong);
            color: var(--text);
        }
        .filter-btn.active {
            background: rgba(110, 232, 122, 0.12);
            border-color: var(--brand);
            color: var(--brand);
        }

        /* === Timeline === */
        .timeline {
            position: relative;
            padding-left: 32px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 8px; top: 8px; bottom: 8px;
            width: 2px;
            background: linear-gradient(to bottom,
                rgba(110, 232, 122, 0.6) 0%,
                rgba(110, 232, 122, 0.2) 50%,
                transparent 100%);
        }

        .entry {
            position: relative;
            margin-bottom: 28px;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .entry.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .entry::before {
            content: '';
            position: absolute;
            left: -32px;
            top: 22px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--bg-0);
            border: 2px solid var(--brand);
            box-shadow: 0 0 0 4px rgba(110, 232, 122, 0.1);
        }
        .entry[data-status="planned"]::before {
            border-color: var(--text-mute);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.04);
        }
        .entry[data-status="in_progress"]::before {
            border-color: #FFD166;
            box-shadow: 0 0 0 4px rgba(255, 209, 102, 0.12);
            animation: pulse-ring 2s ease-in-out infinite;
        }
        @keyframes pulse-ring {
            0%, 100% { box-shadow: 0 0 0 4px rgba(255, 209, 102, 0.12); }
            50%      { box-shadow: 0 0 0 8px rgba(255, 209, 102, 0.04); }
        }

        .card {
            padding: 24px 28px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 18px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            transition: transform 0.25s ease, border-color 0.25s ease, background 0.25s ease;
        }
        .card:hover {
            border-color: var(--border-strong);
            background: var(--glass-strong);
            transform: translateY(-2px);
        }
        .card .head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 12px;
        }
        .card .icon {
            flex-shrink: 0;
            width: 40px; height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: rgba(110, 232, 122, 0.08);
            color: var(--brand);
            font-size: 22px;
        }
        .card .head-text { flex: 1; min-width: 0; }
        .card h3 {
            font-size: 19px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
            letter-spacing: -0.01em;
        }
        .card .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            font-size: 12.5px;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 999px;
            font-weight: 500;
        }
        .chip.status-planned    { background: rgba(255, 255, 255, 0.06); color: var(--text-dim); }
        .chip.status-in_progress { background: rgba(255, 209, 102, 0.12); color: #FFD166; }
        .chip.status-shipped    { background: rgba(110, 232, 122, 0.12); color: var(--brand); }
        .chip.category {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-mute);
        }
        .card .date { color: var(--text-mute); }
        .card .desc {
            color: var(--text-dim);
            white-space: pre-wrap;
            margin-top: 10px;
            font-size: 15px;
        }

        /* === Empty / loading === */
        .skeleton {
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 24px 28px;
            margin-bottom: 20px;
        }
        .skeleton .line {
            height: 14px;
            background: linear-gradient(90deg, var(--glass) 0%, var(--glass-strong) 50%, var(--glass) 100%);
            background-size: 200% 100%;
            border-radius: 4px;
            margin-bottom: 10px;
            animation: shimmer 1.4s linear infinite;
        }
        .skeleton .line.short { width: 40%; }
        .skeleton .line.medium { width: 70%; }
        @keyframes shimmer {
            from { background-position: 200% 0; }
            to   { background-position: -200% 0; }
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-mute);
        }
        .empty-state .mdi {
            font-size: 56px;
            color: var(--text-mute);
            opacity: 0.4;
            margin-bottom: 16px;
        }

        /* === Footer === */
        .footer {
            margin-top: 80px;
            padding-top: 32px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: var(--text-mute);
            flex-wrap: wrap;
            gap: 12px;
        }
        .footer a {
            color: var(--brand);
            text-decoration: none;
            transition: opacity 0.2s ease;
        }
        .footer a:hover { opacity: 0.7; }

        @keyframes fade-up {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <div class="grid-overlay"></div>

    <main class="wrap">
        <section class="hero">
            <span class="eyebrow"><span class="dot"></span> Обновляется в реальном времени</span>
            <h1>Роадмап продукта</h1>
            <p class="lead">
                Что мы строим, что уже выпустили и куда движемся дальше.
                Открыто и без воды.
            </p>
        </section>

        <section class="stats" id="stats">
            <div class="stat">
                <div class="num shipped" data-key="shipped">—</div>
                <div class="label">Выпущено</div>
            </div>
            <div class="stat">
                <div class="num progress" data-key="in_progress">—</div>
                <div class="label">В работе</div>
            </div>
            <div class="stat">
                <div class="num planned" data-key="planned">—</div>
                <div class="label">В планах</div>
            </div>
        </section>

        <div class="filters" id="filters">
            <button type="button" class="filter-btn active" data-filter="all">Все</button>
            <button type="button" class="filter-btn" data-filter="in_progress">
                <i class="mdi mdi-progress-clock"></i> В работе
            </button>
            <button type="button" class="filter-btn" data-filter="planned">
                <i class="mdi mdi-clock-outline"></i> В планах
            </button>
            <button type="button" class="filter-btn" data-filter="shipped">
                <i class="mdi mdi-check-circle-outline"></i> Выпущено
            </button>
        </div>

        <section class="timeline" id="timeline">
            <div class="skeleton">
                <div class="line medium"></div>
                <div class="line short"></div>
            </div>
            <div class="skeleton">
                <div class="line medium"></div>
                <div class="line short"></div>
            </div>
            <div class="skeleton">
                <div class="line medium"></div>
                <div class="line short"></div>
            </div>
        </section>

        <footer class="footer">
            <div>&copy; {{ date('Y') }} DS Consulting</div>
            <div><a href="/">← Вернуться на платформу</a></div>
        </footer>
    </main>

    <script>
    (function () {
        const API_URL = '/api/v1/roadmap';
        const STATUS_LABELS = { planned: 'В планах', in_progress: 'В работе', shipped: 'Выпущено' };
        const MONTHS = ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июня', 'июля', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'];

        const timeline = document.getElementById('timeline');
        const filtersEl = document.getElementById('filters');
        const statsEl = document.getElementById('stats');

        let allItems = [];
        let currentFilter = 'all';
        let observer = null;

        function escapeHtml(s) {
            if (s == null) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return '';
            return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
        }

        function renderStats(items) {
            const counts = { planned: 0, in_progress: 0, shipped: 0 };
            for (const it of items) counts[it.status] = (counts[it.status] || 0) + 1;
            statsEl.querySelectorAll('[data-key]').forEach(el => {
                el.textContent = counts[el.dataset.key] || 0;
            });
        }

        function render() {
            const filtered = currentFilter === 'all'
                ? allItems
                : allItems.filter(it => it.status === currentFilter);

            if (!filtered.length) {
                timeline.innerHTML = `
                    <div class="empty-state">
                        <i class="mdi mdi-rocket-launch-outline"></i>
                        <div>Записей по этому фильтру пока нет.</div>
                    </div>`;
                return;
            }

            timeline.innerHTML = filtered.map((it) => {
                const iconClass = (it.icon && /^mdi-[a-z0-9-]+$/.test(it.icon))
                    ? `mdi ${it.icon}`
                    : 'mdi mdi-rocket-launch-outline';
                const dateStr = it.released_at
                    ? formatDate(it.released_at)
                    : (it.status === 'shipped' ? formatDate(it.created_at) : '');
                const desc = it.description
                    ? `<div class="desc">${escapeHtml(it.description)}</div>`
                    : '';
                const category = it.category
                    ? `<span class="chip category">${escapeHtml(it.category)}</span>`
                    : '';
                const datePart = dateStr
                    ? `<span class="date">${escapeHtml(dateStr)}</span>`
                    : '';

                return `
                    <article class="entry" data-status="${escapeHtml(it.status)}">
                        <div class="card">
                            <div class="head">
                                <div class="icon"><i class="${iconClass}"></i></div>
                                <div class="head-text">
                                    <h3>${escapeHtml(it.title)}</h3>
                                    <div class="meta">
                                        <span class="chip status-${escapeHtml(it.status)}">
                                            ${STATUS_LABELS[it.status] || it.status}
                                        </span>
                                        ${category}
                                        ${datePart}
                                    </div>
                                </div>
                            </div>
                            ${desc}
                        </div>
                    </article>`;
            }).join('');

            // Fade-in observer
            if (observer) observer.disconnect();
            observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

            timeline.querySelectorAll('.entry').forEach(el => observer.observe(el));
        }

        filtersEl.addEventListener('click', (e) => {
            const btn = e.target.closest('.filter-btn');
            if (!btn) return;
            filtersEl.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            render();
        });

        fetch(API_URL, { headers: { 'Accept': 'application/json' } })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                allItems = Array.isArray(data.items) ? data.items : [];
                renderStats(allItems);
                if (!allItems.length) {
                    timeline.innerHTML = `
                        <div class="empty-state">
                            <i class="mdi mdi-rocket-launch-outline"></i>
                            <div>Скоро здесь появятся обновления.</div>
                        </div>`;
                    return;
                }
                render();
            })
            .catch(() => {
                timeline.innerHTML = `
                    <div class="empty-state">
                        <i class="mdi mdi-cloud-off-outline"></i>
                        <div>Не удалось загрузить роадмап. Попробуйте обновить страницу.</div>
                    </div>`;
            });
    })();
    </script>
</body>
</html>
