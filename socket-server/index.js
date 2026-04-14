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
 * Runs on port 3001 (configurable via SOCKET_PORT env)
 */

const { Server } = require('socket.io');

const PORT = process.env.SOCKET_PORT || 3001;
const ALLOWED_ORIGINS = (process.env.SOCKET_ORIGINS || 'http://localhost:8000,https://dev.dsconsult.ru').split(',');

const io = new Server(PORT, {
  cors: {
    origin: ALLOWED_ORIGINS,
    methods: ['GET', 'POST'],
    credentials: true,
  },
  pingTimeout: 60000,
  pingInterval: 25000,
});

// Track online users: { userId: { socketId, userName, rooms: Set } }
const onlineUsers = new Map();

io.on('connection', (socket) => {
  const userId = socket.handshake.query.userId;
  const userName = socket.handshake.query.userName || 'Unknown';

  if (userId) {
    onlineUsers.set(userId, {
      socketId: socket.id,
      userName,
      rooms: new Set(),
    });
    console.log(`[+] User ${userName} (${userId}) connected. Online: ${onlineUsers.size}`);
  }

  // === Join ticket room ===
  socket.on('ticket:join', (ticketId) => {
    const room = `ticket:${ticketId}`;
    socket.join(room);
    if (userId && onlineUsers.has(userId)) {
      onlineUsers.get(userId).rooms.add(room);
    }
    // Notify others in room
    socket.to(room).emit('ticket:user-joined', { userId, userName, ticketId });
    console.log(`[→] ${userName} joined room ${room}`);
  });

  // === Leave ticket room ===
  socket.on('ticket:leave', (ticketId) => {
    const room = `ticket:${ticketId}`;
    socket.leave(room);
    if (userId && onlineUsers.has(userId)) {
      onlineUsers.get(userId).rooms.delete(room);
    }
    socket.to(room).emit('ticket:user-left', { userId, userName, ticketId });
  });

  // === New message (from Laravel backend via HTTP) ===
  // This is emitted BY the server when Laravel calls the HTTP endpoint
  // Client-side messages go through Laravel API → Laravel emits to Socket.IO

  // === Typing indicator ===
  socket.on('ticket:typing', ({ ticketId, isTyping }) => {
    socket.to(`ticket:${ticketId}`).emit('ticket:typing', {
      userId,
      userName,
      isTyping,
    });
  });

  // === Disconnect ===
  socket.on('disconnect', () => {
    if (userId) {
      const user = onlineUsers.get(userId);
      if (user) {
        // Leave all rooms
        user.rooms.forEach((room) => {
          socket.to(room).emit('ticket:user-left', { userId, userName });
        });
      }
      onlineUsers.delete(userId);
      console.log(`[-] User ${userName} (${userId}) disconnected. Online: ${onlineUsers.size}`);
    }
  });
});

// === HTTP API for Laravel to emit events ===
const http = require('http');

const apiServer = http.createServer((req, res) => {
  // CORS
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

  // Health check
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200);
    res.end(JSON.stringify({
      status: 'ok',
      connections: io.engine.clientsCount,
      onlineUsers: onlineUsers.size,
    }));
    return;
  }

  // Notify specific user
  if (req.method === 'POST' && req.url === '/notify') {
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

  res.writeHead(404);
  res.end(JSON.stringify({ error: 'Not found' }));
});

const API_PORT = parseInt(PORT) + 1; // 3002
apiServer.listen(API_PORT, () => {
  console.log(`\n🚀 DS Socket.IO Server`);
  console.log(`   WebSocket: ws://0.0.0.0:${PORT}`);
  console.log(`   HTTP API:  http://0.0.0.0:${API_PORT}`);
  console.log(`   Origins:   ${ALLOWED_ORIGINS.join(', ')}\n`);
});
