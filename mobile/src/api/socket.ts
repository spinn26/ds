import { io, type Socket } from 'socket.io-client';

// Production-сокет на той же машине, что и веб (см. CLAUDE.md: socket-server
// на портах 3001 WS / 3002 HTTP-emit). По умолчанию подключаемся к
// wss://dev.dsconsult.ru. Можно переопределить через VITE_SOCKET_URL.
const SOCKET_URL = import.meta.env.VITE_SOCKET_URL || 'https://dev.dsconsult.ru';

export interface SocketHandle {
  socket: Socket;
  on(event: string, cb: (...args: unknown[]) => void): void;
}

export function connectSocket(opts: { ticketId?: number; userId?: number; userName?: string } = {}): SocketHandle | null {
  try {
    const socket = io(SOCKET_URL, {
      path: '/socket.io',
      transports: ['websocket', 'polling'],
      query: {
        userId: String(opts.userId ?? ''),
        userName: opts.userName ?? '',
        ticketId: opts.ticketId != null ? String(opts.ticketId) : '',
      },
      reconnection: true,
      reconnectionAttempts: 5,
      timeout: 8000,
    });

    if (opts.ticketId) {
      socket.on('connect', () => {
        socket.emit('ticket:join', { ticketId: opts.ticketId });
      });
    }

    return {
      socket,
      on(event, cb) { socket.on(event, cb as any); },
    };
  } catch {
    return null;
  }
}

export function disconnectSocket(handle: SocketHandle | null) {
  if (!handle) return;
  try { handle.socket.disconnect(); } catch {}
}
