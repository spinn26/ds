import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'ru.dsconsult.partner',
  appName: 'DS Partner',
  // Capacitor берёт билд из dist (после `npm run build`). На время
  // разработки можно временно установить server.url='http://<your-ip>:5174'
  // для live-reload на физическом девайсе — но в репозиторий это не коммитим.
  webDir: 'dist',
  server: {
    androidScheme: 'https',
    iosScheme: 'capacitor',
  },
  ios: {
    contentInset: 'always',
  },
  android: {
    allowMixedContent: false,
  },
};

export default config;
