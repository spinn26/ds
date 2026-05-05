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

async function validateToken(token) {
  if (!token) return null;

  const cached = tokenCache.get(token);
  if (cached && cached.expiresAt > Date.now()) {
    return cached.user;
  }
  // Истёкший — выкидываем сразу, не ждём sweep.
  if (cached) tokenCache.delete(token);

  try {
    const res = await fetch(`${LARAVEL_API_URL}/api/v1/auth/me`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) {
      // Если сервер сказал «не валиден» (401/403) — снимаем все
      // зависимые access-кэши, чтобы клиент не висел в комнате.
      invalidateToken(token);
      return null;
    }
    const u = await res.json();
    const user = {
      userId: String(u.id),
      userName: [u.lastName, u.firstName].filter(Boolean).join(' ').trim() || 'Unknown',
    };
    tokenCache.set(token, { user, expiresAt: Date.now() + TOKEN_CACHE_TTL_MS });
    return user;
  } catch (e) {
    console.error('[!] Token validation error:', e.message);
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
    if (v.expiresAt <= now) tokenCache.delete(k);
  }
  for (const [k, v] of accessCache) {
    if (v.expiresAt <= now) accessCache.delete(k);
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
    const res = await fetch(`${LARAVEL_API_URL}/api/v1/chat/tickets/${ticketId}/can-access`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const allowed = res.ok;
    accessCache.set(key, { allowed, expiresAt: Date.now() + ACCESS_CACHE_TTL_MS });
    return allowed;
  } catch (e) {
    console.error('[!] Access check error:', e.message);
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
