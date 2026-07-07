/**
 * DS Consulting — Socket.IO Real-time Chat Server
 *
 * Handles:
 * - User joins ticket room
 * - Real-time message delivery
 * - Typing indicators
 * - Online presence
 * - Notification push
 *
 * Runs on port 3001 (WS) + 3002 (HTTP emit API).
 *
 * Security:
 * - WS clients MUST pass a Sanctum token in `handshake.auth.token`.
 *   The server validates it against Laravel's /api/v1/auth/me.
 *   Unauthorized sockets are disconnected.
 * - HTTP emit endpoints (/emit, /notify) require `Authorization: Bearer <SOCKET_EMIT_SECRET>`.
 *   The secret is shared with Laravel via env.
 */

// Загружаем .env из директории сервера. Без этого pm2-форк не видит
// LARAVEL_API_URL/SOCKET_EMIT_SECRET → токены не валидируются.
try { require('dotenv').config(); } catch {}

const { Server } = require('socket.io');
const http = require('http');

const PORT = process.env.SOCKET_PORT || 3001;
const API_PORT = parseInt(PORT) + 1; // 3002
const ALLOWED_ORIGINS = (process.env.SOCKET_ORIGINS || 'http://localhost:8000,https://dev.dsconsult.ru').split(',');
const LARAVEL_API_URL = process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000';
const EMIT_SECRET = process.env.SOCKET_EMIT_SECRET || '';
const NODE_ENV = process.env.NODE_ENV || 'development';
const IS_PRODUCTION = NODE_ENV === 'production';
// Сокращено с 5 мин до 60 сек: при logout / отозванном токене предыдущая
// задержка позволяла читать чат до 5 мин. 60 сек — компромисс между
// защитой и нагрузкой на /api/v1/auth/me. Lazy-cleanup при каждом
// validateToken отбрасывает истёкшие записи, чтобы Map не рос бесконечно.
const TOKEN_CACHE_TTL_MS = 60 * 1000; // 1 minute
const ACCESS_CACHE_TTL_MS = 60 * 1000; // 1 minute
const CACHE_CLEANUP_INTERVAL_MS = 5 * 60 * 1000; // periodic sweep
// Устойчивость к кратковременной недоступности Laravel API (например, во
// время деплоя, когда php-fpm перезагружается). fetch без таймаута может
// зависнуть; без ретраев/grace один сетевой сбой мгновенно выкидывает всех
// клиентов в «нет связи с сервером».
const FETCH_TIMEOUT_MS = 5000;   // одиночный запрос не висит дольше 5 сек
const FETCH_RETRIES = 2;         // 1 + 2 = 3 попытки суммарно
// Grace: если валидация НЕ смогла достучаться до API (сетевой сбой, не 401),
// используем последний успешный результат ещё столько времени — переживаем
// деплой без разрыва сессий. На реальный отзыв токена это не влияет:
// Laravel вернул бы 401 (res.ok=false), а не сетевую ошибку.
const TOKEN_GRACE_MS = 5 * 60 * 1000;
const ACCESS_GRACE_MS = 5 * 60 * 1000;

if (!EMIT_SECRET) {
  if (IS_PRODUCTION) {
    console.error('[FATAL] SOCKET_EMIT_SECRET is required in production. Generate one with: openssl rand -hex 32');
    process.exit(1);
  }
  console.warn('[!] SOCKET_EMIT_SECRET is not set — HTTP emit endpoints will reject all requests. Set it in .env.');
}

const io = new Server(PORT, {
  cors: {
    origin: ALLOWED_ORIGINS,
    methods: ['GET', 'POST'],
    credentials: true,
  },
  pingTimeout: 60000,
  pingInterval: 25000,
});

// token → { user: { userId, userName }, expiresAt }
const tokenCache = new Map();
// `${token}:${ticketId}` → { allowed, expiresAt }
const accessCache = new Map();
// userId → { socketId, userName, rooms: Set }
const onlineUsers = new Map();

/**
 * fetch с таймаутом и ретраями. Бросает только когда все попытки исчерпаны
 * (сетевой сбой/таймаут). HTTP-ответ (в т.ч. 401/403/5xx) возвращается как
 * есть — это не сбой соединения, ретраить не нужно.
 */
async function fetchWithRetry(url, opts) {
  let lastErr;
  for (let attempt = 0; attempt <= FETCH_RETRIES; attempt++) {
    try {
      return await fetch(url, { ...opts, signal: AbortSignal.timeout(FETCH_TIMEOUT_MS) });
    } catch (e) {
      lastErr = e;
      if (attempt < FETCH_RETRIES) {
        await new Promise((r) => setTimeout(r, 200 * (attempt + 1)));
      }
    }
  }
  throw lastErr;
}

async function validateToken(token) {
  if (!token) return null;

  const cached = tokenCache.get(token);
  if (cached && cached.expiresAt > Date.now()) {
    return cached.user;
  }

  try {
    const res = await fetchWithRetry(`${LARAVEL_API_URL}/api/v1/auth/me`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) {
      // Если сервер сказал «не валиден» (401/403) — снимаем все
      // зависимые access-кэши, чтобы клиент не висел в комнате.
      invalidateToken(token);
      return null;
    }
    const u = await res.json();
    // Fallback цепочка: ФИО → email (без домена) → #id. Не пишем
    // 'Unknown' — это «прилипает» в typing-indicator у клиентов
    // (например, «Unknown печатает…» вместо реального имени).
    const fullName = [u.lastName, u.firstName].filter(Boolean).join(' ').trim();
    const emailHandle = typeof u.email === 'string' ? u.email.split('@')[0] : '';
    const user = {
      userId: String(u.id),
      userName: fullName || emailHandle || `Пользователь #${u.id}`,
    };
    const now = Date.now();
    tokenCache.set(token, {
      user,
      expiresAt: now + TOKEN_CACHE_TTL_MS,
      graceUntil: now + TOKEN_CACHE_TTL_MS + TOKEN_GRACE_MS,
    });
    return user;
  } catch (e) {
    // Сюда попадаем ТОЛЬКО при сетевом сбое/таймауте (API недоступен), а не
    // при 401. Отдаём последний успешный результат в пределах grace-окна —
    // клиент переживает деплой без «нет связи». Кэш не перезаписываем, чтобы
    // grace был жёстко ограничен исходным graceUntil.
    console.error('[!] Token validation error (network, grace fallback):', e.message);
    if (cached && cached.graceUntil > Date.now()) {
      return cached.user;
    }
    return null;
  }
}

/** Снять все кэши, связанные с токеном — вызывается при logout/инвалидации. */
function invalidateToken(token) {
  if (!token) return;
  tokenCache.delete(token);
  // Снимаем access-cache по префиксу `${token}:`.
  const prefix = `${token}:`;
  for (const key of accessCache.keys()) {
    if (key.startsWith(prefix)) accessCache.delete(key);
  }
}

/** Периодическая чистка истёкших записей — защита от роста памяти. */
function sweepCaches() {
  const now = Date.now();
  for (const [k, v] of tokenCache) {
    if ((v.graceUntil ?? v.expiresAt) <= now) tokenCache.delete(k);
  }
  for (const [k, v] of accessCache) {
    if ((v.graceUntil ?? v.expiresAt) <= now) accessCache.delete(k);
  }
}
setInterval(sweepCaches, CACHE_CLEANUP_INTERVAL_MS).unref?.();

/**
 * Ask Laravel whether this token may view the ticket. Backed by
 * ChatTicketPolicy on the server side; result cached for 1 minute per
 * (token, ticketId) pair to keep the hot path cheap.
 */
async function canAccessTicket(token, ticketId) {
  if (!token || !ticketId) return false;
  const key = `${token}:${ticketId}`;
  const cached = accessCache.get(key);
  if (cached && cached.expiresAt > Date.now()) {
    return cached.allowed;
  }
  try {
    const res = await fetchWithRetry(`${LARAVEL_API_URL}/api/v1/chat/tickets/${ticketId}/can-access`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const allowed = res.ok;
    const now = Date.now();
    accessCache.set(key, {
      allowed,
      expiresAt: now + ACCESS_CACHE_TTL_MS,
      graceUntil: now + ACCESS_CACHE_TTL_MS + ACCESS_GRACE_MS,
    });
    return allowed;
  } catch (e) {
    // Сетевой сбой (API недоступен) — не рвём доступ у того, кому он уже был
    // разрешён: отдаём кэшированный результат в пределах grace-окна.
    console.error('[!] Access check error (network, grace fallback):', e.message);
    if (cached && cached.graceUntil > Date.now()) {
      return cached.allowed;
    }
    return false;
  }
}

// === Handshake auth middleware ===
io.use(async (socket, next) => {
  const token = socket.handshake.auth?.token;
  const user = await validateToken(token);
  if (!user) {
    return next(new Error('Unauthorized'));
  }
  socket.data.userId = user.userId;
  socket.data.userName = user.userName;
  socket.data.token = token; // kept in memory only; needed for per-ticket access checks
  next();
});

io.on('connection', (socket) => {
  const { userId, userName } = socket.data;

  onlineUsers.set(userId, {
    socketId: socket.id,
    userName,
    rooms: new Set(),
  });
  console.log(`[+] User ${userName} (${userId}) connected. Online: ${onlineUsers.size}`);

  // === Join ticket room ===
  socket.on('ticket:join', async (ticketId) => {
    const allowed = await canAccessTicket(socket.data.token, ticketId);
    if (!allowed) {
      console.warn(`[!] ${userName} (${userId}) denied access to ticket:${ticketId}`);
      socket.emit('ticket:access-denied', { ticketId });
      return;
    }
    const room = `ticket:${ticketId}`;
    socket.join(room);
    onlineUsers.get(userId)?.rooms.add(room);
    socket.to(room).emit('ticket:user-joined', { userId, userName, ticketId });
    console.log(`[→] ${userName} joined room ${room}`);
  });

  // === Leave ticket room ===
  socket.on('ticket:leave', (ticketId) => {
    const room = `ticket:${ticketId}`;
    socket.leave(room);
    onlineUsers.get(userId)?.rooms.delete(room);
    socket.to(room).emit('ticket:user-left', { userId, userName, ticketId });
  });

  // === Join / leave task room (модуль «Задачи») ===
  // Доступ к данным задачи уже ограничен на уровне REST (staff/admin),
  // здесь по комнате рассылаются только события комментариев.
  socket.on('task:join', (taskId) => {
    const room = `task:${taskId}`;
    socket.join(room);
    onlineUsers.get(userId)?.rooms.add(room);
  });
  socket.on('task:leave', (taskId) => {
    const room = `task:${taskId}`;
    socket.leave(room);
    onlineUsers.get(userId)?.rooms.delete(room);
  });
  socket.on('task:typing', ({ taskId, isTyping }) => {
    const room = `task:${taskId}`;
    if (!socket.rooms.has(room)) return;
    socket.to(room).emit('task:typing', { userId, userName, isTyping });
  });

  // === Typing indicator ===
  // Only honor typing for rooms this socket has actually joined (join
  // gate runs the access check). Prevents a client from spraying typing
  // events into arbitrary ticket rooms by guessing IDs.
  socket.on('ticket:typing', ({ ticketId, isTyping }) => {
    const room = `ticket:${ticketId}`;
    if (!socket.rooms.has(room)) return;
    socket.to(room).emit('ticket:typing', {
      userId,
      userName,
      isTyping,
    });
  });

  // === Disconnect ===
  socket.on('disconnect', () => {
    const user = onlineUsers.get(userId);
    if (user) {
      user.rooms.forEach((room) => {
        socket.to(room).emit('ticket:user-left', { userId, userName });
      });
    }
    onlineUsers.delete(userId);
    console.log(`[-] User ${userName} (${userId}) disconnected. Online: ${onlineUsers.size}`);
  });
});

// === HTTP API for Laravel to emit events ===
function checkEmitAuth(req, res) {
  // No fallback: if secret isn't configured the endpoint must reject
  // (prevents an unconfigured dev instance from acting as an open relay).
  const auth = req.headers.authorization || '';
  if (!EMIT_SECRET || auth !== `Bearer ${EMIT_SECRET}`) {
    res.writeHead(401);
    res.end(JSON.stringify({ error: 'Unauthorized' }));
    return false;
  }
  return true;
}

const apiServer = http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
  res.setHeader('Content-Type', 'application/json');

  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  if (req.method === 'POST' && req.url === '/emit') {
    if (!checkEmitAuth(req, res)) return;
    let body = '';
    req.on('data', (chunk) => body += chunk);
    req.on('end', () => {
      try {
        const { event, room, data } = JSON.parse(body);
        if (room) {
          io.to(room).emit(event, data);
        } else {
          io.emit(event, data);
        }
        res.writeHead(200);
        res.end(JSON.stringify({ ok: true }));
      } catch (e) {
        res.writeHead(400);
        res.end(JSON.stringify({ error: e.message }));
      }
    });
    return;
  }

  if (req.method === 'POST' && req.url === '/notify') {
    if (!checkEmitAuth(req, res)) return;
    let body = '';
    req.on('data', (chunk) => body += chunk);
    req.on('end', () => {
      try {
        const { userId: targetUserId, event, data } = JSON.parse(body);
        const target = onlineUsers.get(String(targetUserId));
        if (target) {
          io.to(target.socketId).emit(event, data);
        }
        res.writeHead(200);
        res.end(JSON.stringify({ ok: true, delivered: !!target }));
      } catch (e) {
        res.writeHead(400);
        res.end(JSON.stringify({ error: e.message }));
      }
    });
    return;
  }

  // Laravel шлёт сюда при logout / token revoke, чтобы немедленно
  // снять кэшированные права для этого токена и отключить активные
  // socket-соединения с этим токеном.
  if (req.method === 'POST' && req.url === '/invalidate-token') {
    if (!checkEmitAuth(req, res)) return;
    let body = '';
    req.on('data', (chunk) => body += chunk);
    req.on('end', () => {
      try {
        const { token } = JSON.parse(body);
        if (!token) {
          res.writeHead(400);
          res.end(JSON.stringify({ error: 'token required' }));
          return;
        }
        invalidateToken(token);
        // Принудительно дисконнектим все сокеты с этим токеном.
        let kicked = 0;
        for (const [, sock] of io.sockets.sockets) {
          if (sock.data?.token === token) {
            sock.disconnect(true);
            kicked++;
          }
        }
        res.writeHead(200);
        res.end(JSON.stringify({ ok: true, kicked }));
      } catch (e) {
        res.writeHead(400);
        res.end(JSON.stringify({ error: e.message }));
      }
    });
    return;
  }

  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200);
    res.end(JSON.stringify({
      status: 'ok',
      connections: io.engine.clientsCount,
      onlineUsers: onlineUsers.size,
    }));
    return;
  }

  res.writeHead(404);
  res.end(JSON.stringify({ error: 'Not found' }));
});

apiServer.on('error', (e) => {
  console.error('[!] HTTP API server error:', e.message);
});
io.engine?.on?.('connection_error', (e) => {
  // Ошибки рукопожатия/транспорта не должны валить процесс.
  console.error('[!] Socket engine connection error:', e?.message || e?.code);
});

apiServer.listen(API_PORT, () => {
  console.log(`\n🚀 DS Socket.IO Server`);
  console.log(`   WebSocket:    ws://0.0.0.0:${PORT}`);
  console.log(`   HTTP API:     http://0.0.0.0:${API_PORT}`);
  console.log(`   Origins:      ${ALLOWED_ORIGINS.join(', ')}`);
  console.log(`   Laravel API:  ${LARAVEL_API_URL}`);
  console.log(`   Emit secret:  ${EMIT_SECRET ? 'configured' : 'NOT SET (open)'}`);
  console.log('');
});

// Graceful shutdown: при SIGTERM/SIGINT (docker stop, kill -15, Ctrl+C)
// уведомляем клиентов и закрываем серверы, чтобы in-flight запросы
// долетели. После 10 сек — force exit.
let shuttingDown = false;
function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`\n[${signal}] Graceful shutdown started…`);

  // Уведомляем всех клиентов — они увидят свою кнопку «переподключиться».
  io.emit('server:shutdown', { reason: signal });

  const forceTimer = setTimeout(() => {
    console.error('[!] Forced exit after 10s timeout');
    process.exit(1);
  }, 10_000);
  forceTimer.unref?.();

  Promise.allSettled([
    new Promise((resolve) => io.close(resolve)),
    new Promise((resolve) => apiServer.close(resolve)),
  ]).then(() => {
    console.log('[✓] Shutdown complete');
    process.exit(0);
  });
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

// === Защита от крэш-лупа ===
// Realtime-сервер — это stateless-релей поверх Laravel. Одна случайная
// ошибка (например, брошенная внутри socket-обработчика или в чужой
// библиотеке) не должна убивать процесс и разрывать связь у всех клиентов.
// Логируем и продолжаем работу вместо process.exit → pm2 больше не
// перезапускает сервер по кругу (была история в 195 рестартов).
process.on('uncaughtException', (err) => {
  console.error('[FATAL] uncaughtException (проигнорирован, сервер продолжает работу):', err?.stack || err);
});
process.on('unhandledRejection', (reason) => {
  console.error('[FATAL] unhandledRejection (проигнорирован):', reason?.stack || reason?.message || reason);
});
