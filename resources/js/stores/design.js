import { defineStore } from 'pinia';
import api from '../api';

/**
 * Активный дизайн платформы (логотип / палитры тем / кастомный CSS).
 *
 * На старте SPA load(vuetify) тянет /design/active и применяет:
 *  - мутирует цвета тем Vuetify в рантайме (theme.themes.value[*].colors);
 *  - инжектит кастомный CSS в <style id="ds-custom-css">;
 *  - хранит логотип/бренд для лейаутов (читают через стор).
 * applyConfig можно звать и из админ-превью без перезагрузки.
 */
export const useDesignStore = defineStore('design', {
  state: () => ({
    config: {
      brandName: 'DS ПЛАТФОРМА',
      logoText: 'DS',
      logoUrl: null,
      colors: { light: {}, dark: {} },
      customCss: '',
    },
    loaded: false,
  }),
  getters: {
    logoText: (s) => s.config.logoText || 'DS',
    logoUrl: (s) => s.config.logoUrl || null,
    brandName: (s) => s.config.brandName || 'DS ПЛАТФОРМА',
  },
  actions: {
    async load(vuetify) {
      try {
        const { data } = await api.get('/design/active');
        if (data?.config) this.config = { ...this.config, ...data.config };
      } catch { /* не залогинен / нет таблицы — остаются дефолты */ }
      this.applyConfig(vuetify, this.config);
      this.loaded = true;
    },

    /** Применить произвольный конфиг (используется и для live-превью в админке). */
    applyConfig(vuetify, config) {
      if (!config) return;
      // 1) Цвета тем Vuetify (реактивно — UI перекрашивается сразу).
      if (vuetify?.theme?.themes?.value && config.colors) {
        for (const name of ['light', 'dark']) {
          const palette = config.colors[name];
          const target = vuetify.theme.themes.value[name];
          if (palette && target) {
            for (const [k, v] of Object.entries(palette)) {
              if (v) target.colors[k] = v;
            }
          }
        }
      }
      // 2) Кастомный CSS.
      this.injectCss(config.customCss || '');
      // 3) Логотип/бренд — через стор (config уже обновлён вызывающим).
    },

    injectCss(css) {
      let el = document.getElementById('ds-custom-css');
      if (!css) {
        if (el) el.textContent = '';
        return;
      }
      if (!el) {
        el = document.createElement('style');
        el.id = 'ds-custom-css';
        document.head.appendChild(el);
      }
      el.textContent = css;
    },
  },
});
