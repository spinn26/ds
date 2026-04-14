import { ref, onUnmounted } from 'vue';

let socket = null;
let socketPromise = null;
const connected = ref(false);

/**
 * Lazy-load Socket.IO client and connect.
 */
async function getSocket() {
  if (socket?.connected) return socket;

  if (!socketPromise) {
    socketPromise = (async () => {
      // Dynamic import — only load socket.io-client when needed
      const { io } = await import('socket.io-client');

      const host = window.__SOCKET_URL__ || (window.location.protocol === 'https:' ? 'wss:' : 'ws:') + '//' + window.location.hostname + ':3001';

      const userId = localStorage.getItem('auth_user_id');
      const userName = localStorage.getItem('auth_user_name') || '';

      socket = io(host, {
        query: { userId, userName },
        transports: ['websocket', 'polling'],
        reconnection: true,
        reconnectionDelay: 2000,
        reconnectionAttempts: 10,
      });

      socket.on('connect', () => {
        connected.value = true;
        console.log('[Socket] Connected:', socket.id);
      });

      socket.on('disconnect', () => {
        connected.value = false;
        console.log('[Socket] Disconnected');
      });

      return socket;
    })();
  }

  return socketPromise;
}

/**
 * Composable for using Socket.IO in ticket chat.
 */
export function useTicketSocket(ticketId, onNewMessage, onTyping) {
  let joined = false;

  const join = async () => {
    const s = await getSocket();
    s.emit('ticket:join', ticketId);
    joined = true;

    s.on('ticket:new-message', (data) => {
      if (data.ticketId == ticketId && onNewMessage) {
        onNewMessage(data);
      }
    });

    s.on('ticket:typing', (data) => {
      if (onTyping) onTyping(data);
    });

    s.on('ticket:user-joined', (data) => {
      console.log('[Socket] User joined:', data.userName);
    });
  };

  const leave = async () => {
    if (socket && joined) {
      socket.emit('ticket:leave', ticketId);
      socket.off('ticket:new-message');
      socket.off('ticket:typing');
      socket.off('ticket:user-joined');
      joined = false;
    }
  };

  const emitTyping = async (isTyping) => {
    if (socket?.connected) {
      socket.emit('ticket:typing', { ticketId, isTyping });
    }
  };

  onUnmounted(leave);

  return { join, leave, emitTyping, connected };
}

/**
 * Composable for receiving notifications in real-time.
 */
export function useNotificationSocket(onNotification) {
  const setup = async () => {
    const s = await getSocket();
    s.on('notification', (data) => {
      if (onNotification) onNotification(data);
    });
  };

  onUnmounted(() => {
    if (socket) {
      socket.off('notification');
    }
  });

  return { setup, connected };
}

/**
 * Store user info for socket auth.
 */
export function setSocketUser(userId, userName) {
  localStorage.setItem('auth_user_id', userId);
  localStorage.setItem('auth_user_name', userName);
}
