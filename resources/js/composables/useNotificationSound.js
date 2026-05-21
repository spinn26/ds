/**
 * useNotificationSound — короткий звуковой сигнал «новое сообщение»
 * через Web Audio API. Не требует mp3-файлов, работает в любом браузере.
 *
 * Звук: двойной тон 880Hz → 1320Hz, общая длительность ~250 ms — узнаваемая
 * «динь-дон» нотификация без надоедливости.
 *
 * Autoplay policy: AudioContext создаётся при первом play() и может быть
 * в состоянии 'suspended' пока пользователь не взаимодействовал со
 * страницей. play() автоматически вызывает resume(); если context
 * остался suspended (user не кликал — невозможно после логина, но всё же),
 * play() просто не звучит и не падает.
 *
 * Глобальное состояние:
 *   - localStorage.notif_sound_enabled === '0' — звук выключен полностью.
 *   - Дебаунс 1 сек между звуками: спам сокет-сообщений не должен
 *     превращаться в трещотку.
 */

let audioCtx = null;
let lastPlayAt = 0;

function getCtx() {
  if (audioCtx) return audioCtx;
  const Ctor = window.AudioContext || window.webkitAudioContext;
  if (!Ctor) return null;
  try {
    audioCtx = new Ctor();
  } catch {
    audioCtx = null;
  }
  return audioCtx;
}

/**
 * Сыграть короткий beep с задержкой.
 * @param {AudioContext} ctx
 * @param {number} freq Гц
 * @param {number} startAt секунд относительно ctx.currentTime
 * @param {number} duration секунд
 */
function beep(ctx, freq, startAt, duration) {
  const osc = ctx.createOscillator();
  const gain = ctx.createGain();
  osc.type = 'sine';
  osc.frequency.value = freq;
  // Огибающая — мягкий attack/release, чтобы не было щелчка.
  gain.gain.setValueAtTime(0, ctx.currentTime + startAt);
  gain.gain.linearRampToValueAtTime(0.18, ctx.currentTime + startAt + 0.01);
  gain.gain.linearRampToValueAtTime(0, ctx.currentTime + startAt + duration);
  osc.connect(gain);
  gain.connect(ctx.destination);
  osc.start(ctx.currentTime + startAt);
  osc.stop(ctx.currentTime + startAt + duration);
}

export function useNotificationSound() {
  function isEnabled() {
    return localStorage.getItem('notif_sound_enabled') !== '0';
  }

  function setEnabled(value) {
    localStorage.setItem('notif_sound_enabled', value ? '1' : '0');
  }

  function play() {
    if (!isEnabled()) return;
    // Дебаунс — не чаще 1 раза в секунду, иначе пачка сокет-сообщений
    // превращается в трещотку.
    const now = Date.now();
    if (now - lastPlayAt < 1000) return;
    lastPlayAt = now;

    const ctx = getCtx();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
      ctx.resume().catch(() => {});
    }
    if (ctx.state !== 'running' && ctx.state !== 'suspended') return;
    try {
      beep(ctx, 880, 0, 0.12);
      beep(ctx, 1320, 0.12, 0.13);
    } catch {
      // Silently fail — звук это nice-to-have, не критично.
    }
  }

  return { play, isEnabled, setEnabled };
}
