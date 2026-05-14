import { createVuetify } from 'vuetify';
import 'vuetify/styles';
import '@mdi/font/css/materialdesignicons.css';

// Цвета берём из веб-проекта (CLAUDE.md / DS identity 2023):
// primary #2E7D32 (тёмно-зелёный), brand #6EE87A (мятный),
// brand-ink #0A2B10 (текст на мятном).
export default createVuetify({
  theme: {
    defaultTheme: 'dsLight',
    themes: {
      dsLight: {
        dark: false,
        colors: {
          primary: '#2E7D32',
          secondary: '#1B5E20',
          brand: '#6EE87A',
          'brand-ink': '#0A2B10',
          background: '#FAFAFA',
          surface: '#FFFFFF',
          'on-surface': '#1B1B1B',
          success: '#43A047',
          warning: '#FB8C00',
          error: '#E53935',
          info: '#1E88E5',
        },
      },
      dsDark: {
        dark: true,
        colors: {
          primary: '#6EE87A',
          secondary: '#2E7D32',
          brand: '#6EE87A',
          'brand-ink': '#0A2B10',
          background: '#0A2B10',
          surface: '#0F1F12',
          'on-surface': '#E8F5E9',
          success: '#66BB6A',
          warning: '#FFA726',
          error: '#EF5350',
          info: '#42A5F5',
        },
      },
    },
  },
  defaults: {
    VBtn: { variant: 'flat' },
    VTextField: { variant: 'outlined', density: 'comfortable' },
    VCard: { rounded: 'lg' },
  },
});
