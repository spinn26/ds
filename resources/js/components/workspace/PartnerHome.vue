<template>
  <div class="home">
    <div class="home-grid">
      <div class="home-main">
        <!-- Hero живёт в основной колонке, а не во всю ширину: правая
             колонка начинается от самого верха (per макет v2-glavnaya). -->
        <HeroFocus :name="firstName" :promo="data.promo" />
        <KpiBand :stats="data.partnerStats || {}" />
        <NewsFeed :items="news" />
      </div>

      <aside class="home-aside">
        <!-- Место для виджетов сотрудника (Мой день, Кто онлайн): у кого есть
             и карточка партнёра, и роль в бэкофисе, они не должны пропасть. -->
        <slot name="aside-top" />

        <SupportCard :mentor="data.mentor" :leader="data.networkLeader" />
        <QuickActions />
        <MyNoteWidget />

        <section class="card">
          <header class="card-head">
            <h2 class="card-h">
              <MessageCircle :size="18" :stroke-width="1.8" />
              Сообщения
            </h2>
            <router-link class="card-more" to="/chat">Все</router-link>
          </header>

          <div v-if="!messages.length" class="empty">
            <span class="empty-ic"><Inbox :size="20" :stroke-width="1.8" /></span>
            Новых сообщений нет
          </div>
          <router-link v-for="m in messages" :key="m.id" class="msg" :to="`/chat?ticket=${m.id}`">
            <span class="msg-title">{{ m.subject || 'Обращение' }}</span>
            <span class="msg-text">{{ m.last_message_preview }}</span>
          </router-link>
        </section>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { MessageCircle, Inbox } from 'lucide-vue-next';
import HeroFocus from './HeroFocus.vue';
import KpiBand from './KpiBand.vue';
import NewsFeed from './NewsFeed.vue';
import SupportCard from './SupportCard.vue';
import QuickActions from './QuickActions.vue';
import MyNoteWidget from '../MyNoteWidget.vue';

/**
 * Рабочий стол партнёра, версия 2 (per design/components/DashboardPage.md).
 * Данные приходят одним ответом /workspace — страница их только раскладывает.
 */
const props = defineProps({
  data: { type: Object, default: () => ({}) },
  firstName: { type: String, default: '' },
});

const news = computed(() => props.data.news || []);
const messages = computed(() => props.data.recentMessages || []);
</script>

<style scoped>
.home { display: flex; flex-direction: column; gap: var(--space-6); max-width: none; }

.home-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: var(--space-6);
  align-items: start;
}
.home-main { display: flex; flex-direction: column; gap: var(--space-6); min-width: 0; }
.home-aside { display: flex; flex-direction: column; gap: var(--space-6); }

.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  padding: var(--space-5);
}
.card-head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); }
.card-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font: 600 17px/24px var(--font-sans);
  color: var(--ink);
}
.card-h svg { color: var(--brand); }
.card-more { font: 500 13px/18px var(--font-sans); color: var(--brand); text-decoration: none; }

.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-6) 0 var(--space-4);
  font: 400 14px/22px var(--font-sans);
  color: var(--ink-muted);
}
.empty-ic {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--ink-muted);
}

.msg {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: var(--space-3) 0;
  text-decoration: none;
  color: inherit;
}
.msg + .msg { border-top: 1px solid var(--border); }
.msg-title { font: 600 14px/20px var(--font-sans); color: var(--ink); }
.msg-text {
  font: 400 13px/18px var(--font-sans);
  color: var(--ink-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* < 1180px правая колонка уходит под основную (per BRAND.md). */
@media (max-width: 1180px) {
  .home-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 860px) {
  .home { gap: var(--space-4); }
  .home-grid, .home-main, .home-aside { gap: var(--space-4); }
}
</style>
