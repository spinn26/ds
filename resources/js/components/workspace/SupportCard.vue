<template>
  <section v-if="people.length" class="card">
    <h2 class="card-h">
      <Users :size="18" :stroke-width="1.8" />
      Моя поддержка
    </h2>

    <div v-for="(p, i) in people" :key="p.role" :class="['person', { divided: i > 0 }]">
      <span :class="['avatar', { lead: p.lead }]">{{ initials(p.personName) }}</span>
      <div class="person-body">
        <div class="role">{{ p.role }}</div>
        <div class="name">{{ p.personName }}</div>
        <div v-if="rank(p)" class="rank">{{ rank(p) }}</div>

        <div class="chips">
          <!-- Сам номер и адрес уходят в href: длинный e-mail ломал карточку,
               поэтому на экране остаётся глагол (per design/components/ContactCard.md). -->
          <a v-if="p.phone" class="chip" :href="`tel:${p.phone}`" :title="phoneLabel(p.phone)">
            <Phone :size="14" :stroke-width="1.8" />Позвонить
          </a>
          <a v-if="p.email" class="chip" :href="`mailto:${p.email}`" :title="p.email">
            <Mail :size="14" :stroke-width="1.8" />Почта
          </a>
          <a v-if="p.telegram" class="chip" :href="`https://t.me/${p.telegram}`"
            target="_blank" rel="noopener" :title="`@${p.telegram}`">
            <Send :size="14" :stroke-width="1.8" />Telegram
          </a>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { Users, Phone, Mail, Send } from 'lucide-vue-next';

/**
 * «Моя поддержка» — наставник и лидер сети одной карточкой.
 * Аватар — инициалы: фото наставника API не отдаёт, и выдумывать его нечем.
 */
const props = defineProps({
  mentor: { type: Object, default: null },
  leader: { type: Object, default: null },
});

const people = computed(() => [
  props.mentor ? { ...props.mentor, role: 'Наставник' } : null,
  props.leader ? { ...props.leader, role: 'Лидер сети', lead: true } : null,
].filter(Boolean));

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
}

// Квалификация приходит строкой «4 [ФК]» — показываем «4 · ФК».
function rank(p) {
  const m = String(p.qualification || '').match(/^(\d+)\s*\[(.+)\]$/);
  return m ? `${m[1]} · ${m[2]}` : (p.qualification || '');
}

function phoneLabel(phone) {
  const d = String(phone).replace(/\D/g, '');
  if (d.length !== 11) return phone;
  return `+${d[0]} ${d.slice(1, 4)} ${d.slice(4, 7)}-${d.slice(7, 9)}-${d.slice(9)}`;
}
</script>

<style scoped>
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
  padding: var(--space-5);
}
.card-h {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
  font: 600 17px/24px var(--font-sans);
  color: var(--ink);
}
.card-h svg { color: var(--brand); }

.person { display: flex; gap: var(--space-3); }
.person.divided {
  margin-top: var(--space-4);
  padding-top: var(--space-4);
  border-top: 1px solid var(--border);
}

.avatar {
  flex: 0 0 auto;
  width: 44px;
  height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--brand);
  color: var(--on-brand);
  font: 600 14px/1 var(--font-sans);
}
.avatar.lead { background: var(--accent); color: #fff; }

.person-body { min-width: 0; }
.role {
  font: 600 11px/14px var(--font-sans);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ink-muted);
}
.name { font: 600 15px/22px var(--font-sans); color: var(--ink); }
.rank { font: 400 12px/16px var(--font-sans); color: var(--ink-muted); }

.chips { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-3); }
.chip {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: 5px 10px;
  border-radius: var(--radius-sm);
  background: var(--brand-soft);
  color: var(--brand);
  font: 500 13px/18px var(--font-sans);
  text-decoration: none;
}
.chip:hover { filter: brightness(0.97); }
.chip:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
</style>
