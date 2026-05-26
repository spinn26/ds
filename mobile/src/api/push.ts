import { Capacitor } from '@capacitor/core';
import api from '@/api';

export type PushPermResult = 'granted' | 'denied' | 'prompt' | 'unsupported';

/**
 * Push требует настроенного Firebase Cloud Messaging:
 *   - google-services.json в mobile/android/app/
 *   - applied 'com.google.gms.google-services' в android/app/build.gradle
 *   - GoogleService-Info.plist + Push capability на iOS
 *
 * Если Firebase НЕ настроен, вызов PushNotifications.register() выбрасывает
 * native FATAL EXCEPTION ещё до того, как JS try/catch сработает, — и
 * приложение мгновенно крашится при старте.
 *
 * Флаг защищает от этого: пока Firebase не подключим, push не пытается
 * регистрироваться. Включить — поставить VITE_PUSH_ENABLED=1 в .env.
 */
const PUSH_ENABLED = import.meta.env.VITE_PUSH_ENABLED === '1' || import.meta.env.VITE_PUSH_ENABLED === 'true';

/**
 * Спросить разрешение на push, либо вернуть текущее состояние.
 * Возвращает 'unsupported' на web (Capacitor plugin не работает в браузере).
 */
export async function requestPushPermissions(): Promise<PushPermResult> {
  if (!Capacitor.isNativePlatform()) return 'unsupported';
  if (!PUSH_ENABLED) return 'unsupported';
  try {
    const { PushNotifications } = await import('@capacitor/push-notifications');
    const r = await PushNotifications.requestPermissions();
    if (r.receive === 'granted') {
      await PushNotifications.register();
      return 'granted';
    }
    if (r.receive === 'denied') return 'denied';
    return 'prompt';
  } catch {
    return 'denied';
  }
}

export async function getPushPermissions(): Promise<PushPermResult> {
  if (!Capacitor.isNativePlatform()) return 'unsupported';
  if (!PUSH_ENABLED) return 'unsupported';
  try {
    const { PushNotifications } = await import('@capacitor/push-notifications');
    const r = await PushNotifications.checkPermissions();
    return r.receive === 'granted' ? 'granted' : r.receive === 'denied' ? 'denied' : 'prompt';
  } catch {
    return 'unsupported';
  }
}

// Push-уведомления настраиваются только на native (Android/iOS) И когда
// Firebase реально подключён (VITE_PUSH_ENABLED=1). Иначе тихо no-op.
export async function setupPushNotifications(): Promise<void> {
  if (!Capacitor.isNativePlatform()) return;
  if (!PUSH_ENABLED) {
    // eslint-disable-next-line no-console
    console.log('[push] disabled (VITE_PUSH_ENABLED not set)');
    return;
  }
  try {
    const { PushNotifications } = await import('@capacitor/push-notifications');

    const permResult = await PushNotifications.requestPermissions();
    if (permResult.receive !== 'granted') return;

    await PushNotifications.register();

    PushNotifications.addListener('registration', async (token) => {
      try {
        await api.post('/auth/device/register', {
          token: token.value,
          platform: Capacitor.getPlatform(),
        });
      } catch {
        // ignore — endpoint появится позже
      }
    });

    PushNotifications.addListener('registrationError', (err) => {
      // eslint-disable-next-line no-console
      console.warn('[push] registration error', err);
    });

    PushNotifications.addListener('pushNotificationReceived', (n) => {
      // eslint-disable-next-line no-console
      console.log('[push] received', n);
    });

    PushNotifications.addListener('pushNotificationActionPerformed', (a) => {
      const link = (a.notification?.data as any)?.link;
      if (link && typeof window !== 'undefined') {
        window.location.pathname = link;
      }
    });
  } catch (e) {
    // eslint-disable-next-line no-console
    console.warn('[push] setup failed', e);
  }
}
