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
      faviconUrl: null,
      loginTitle: null,
      colors: { light: {}, dark: {} },
      typography: { fontFamily: '', baseSize: null },
      radius: { sm: null, md: null, lg: null, xl: null },
      shadows: { card: '' },
      tokens: {},
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
      // 2) Сгенерированные дизайн-токены (типографика, скругления).
      this.injectGenerated(config);
      // 3) Фавикон.
      if (config.faviconUrl) this.setFavicon(config.faviconUrl);
      // 4) Пользовательский кастомный CSS (поверх всего).
      this.injectCss(config.customCss || '');
      // 5) Логотип/бренд — читаются лейаутами через геттеры стора.
    },

    /** CSS из типографики/скруглений — отдельный <style>, чтобы не смешивать
       с пользовательским CSS. */
    injectGenerated(config) {
      const t = config.typography || {};
      const r = config.radius || {};
      let root = '';
      if (t.fontFamily) root += `--ds-font-sans:${t.fontFamily};`;
      if (r.sm) root += `--ds-radius-sm:${r.sm}px;`;
      if (r.md) root += `--ds-radius-md:${r.md}px;`;
      if (r.lg) root += `--ds-radius-lg:${r.lg}px;`;
      if (r.xl) root += `--ds-radius-xl:${r.xl}px;`;

      // Расширенные токены: произвольные --ds-* (отступы, высоты, анимации,
      // тени-уровни и т.д.). Значение — полная CSS-строка (с единицами).
      const tokens = config.tokens || {};
      for (const [k, v] of Object.entries(tokens)) {
        if (v !== null && v !== undefined && v !== '') root += `--ds-${k}:${v};`;
      }

      let css = root ? `:root{${root}}` : '';
      if (t.fontFamily) css += `.v-application,body{font-family:${t.fontFamily} !important;}`;
      if (t.baseSize) css += `.v-application{font-size:${t.baseSize}px;}`;
      // Чтобы скругления были видны на Vuetify-компонентах, маппим их.
      if (r.lg) css += `.v-card{border-radius:${r.lg}px !important;}`;
      if (r.md) css += `.v-btn,.v-field{border-radius:${r.md}px !important;}`;

      // Тень карточек — пресет.
      const SHADOWS = {
        none: 'none',
        soft: '0 1px 2px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.04)',
        medium: '0 2px 4px rgba(0,0,0,0.06), 0 8px 24px rgba(0,0,0,0.08)',
        strong: '0 6px 12px rgba(0,0,0,0.10), 0 18px 44px rgba(0,0,0,0.14)',
      };
      const cardShadow = config.shadows?.card;
      if (cardShadow && SHADOWS[cardShadow]) {
        css += `.v-card{box-shadow:${SHADOWS[cardShadow]} !important;}`;
      }

      this.setStyleEl('ds-design-vars', css);
    },

    setFavicon(href) {
      let link = document.querySelector("link[rel~='icon']");
      if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
      }
      link.href = href;
    },

    injectCss(css) {
      this.setStyleEl('ds-custom-css', css || '');
    },

    setStyleEl(id, css) {
      let el = document.getElementById(id);
      if (!css) {
        if (el) el.textContent = '';
        return;
      }
      if (!el) {
        el = document.createElement('style');
        el.id = id;
        document.head.appendChild(el);
      }
      el.textContent = css;
    },
  },
});
