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
const TOKEN_CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes
const ACCESS_CACHE_TTL_MS = 60 * 1000; // 1 minute

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

  try {
    const res = await fetch(`${LARAVEL_API_URL}/api/v1/auth/me`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return null;
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
