<template>
  <div class="chat-wrap">
    <!-- Connection-status banner: показываем только если socket упал, чтобы
         оператор не думал что чат «завис». Polling-fallback всё равно работает. -->
    <div v-if="!socketConnected" class="conn-banner">
      <v-icon size="14">mdi-wifi-off</v-icon>
      Real-time соединение потеряно. Сообщения придут с задержкой ~15 сек.
    </div>
    <!-- Splitpanes: 2 или 3 pane'а (sidebar + main [+ context]).
         На mobile splitter скрыт media-query'ем, видна одна панель v-if'ом. -->
    <Splitpanes
      class="chat-splitpanes"
      @resized="onPaneResize">
    <Pane :size="effectivePaneSizes[0]" :min-size="16" :max-size="44"
      v-if="!mobile || !activeChat">
    <!-- Left: ticket list (hidden on mobile when a chat is open) -->
    <aside class="chat-sidebar"
      :class="{ 'mobile-hidden': mobile && activeChat }">
      <div class="sidebar-head px-3 py-2">
        <div class="d-flex align-center ga-2">
          <div class="text-body-1 font-weight-bold flex-grow-1">Обращения</div>
          <v-btn size="x-small" :variant="bulkMode ? 'flat' : 'text'"
            :color="bulkMode ? 'primary' : undefined"
            :icon="!bulkMode"
            title="Массовые операции" @click="toggleBulk">
            <v-icon v-if="!bulkMode" size="18">mdi-checkbox-multiple-blank-outline</v-icon>
            <template v-else>
              <v-icon size="14" start>mdi-checkbox-marked</v-icon>
              Выбор
            </template>
          </v-btn>
        </div>
        <!-- Тех-поддержка платформы: staff (не админ) сообщает о баге /
             вопросе по системе. Тикет уходит с department=support — backend
             делает его инцидентом и показывает ролям admin + support. -->
        <div v-if="!isAdminRole" class="mt-2">
          <v-btn block size="small" variant="tonal" color="primary"
            prepend-icon="mdi-lifebuoy"
            title="Сообщить о проблеме платформы — тикет уйдёт администратору"
            @click="openTechSupport">
            В техподдержку
          </v-btn>
        </div>
      </div>

      <!-- Search -->
      <div class="px-3 pb-2">
        <v-text-field v-model="filter.search"
          placeholder="Поиск по теме, участникам и тексту сообщений…"
          prepend-inner-icon="mdi-magnify"
          variant="outlined" density="compact" hide-details clearable
          @update:model-value="debouncedLoad"
          @click:clear="filter.search = ''; loadChats()" />
      </div>

      <!-- Smart views — Linear-style: компактные label-чипы с числами -->
      <div class="sidebar-filters px-3 pb-2">
        <div class="d-flex flex-wrap ga-1">
          <v-chip size="x-small" label
            :color="smartView === 'all' ? 'primary' : undefined"
            :variant="smartView === 'all' ? 'flat' : 'tonal'"
            @click="smartView = 'all'">
            Все · {{ chats.length }}
          </v-chip>
          <v-chip size="x-small" label
            :color="smartView === 'mine' ? 'primary' : undefined"
            :variant="smartView === 'mine' ? 'flat' : 'tonal'"
            @click="smartView = 'mine'">
            Мои · {{ countMine }}
          </v-chip>
          <v-chip size="x-small" label
            :color="smartView === 'unassigned' ? 'primary' : undefined"
            :variant="smartView === 'unassigned' ? 'flat' : 'tonal'"
            @click="smartView = 'unassigned'">
            Без ответств. · {{ countUnassigned }}
          </v-chip>
          <v-chip size="x-small" label
            :color="smartView === 'stale' ? 'warning' : undefined"
            :variant="smartView === 'stale' ? 'flat' : 'tonal'"
            @click="smartView = 'stale'">
            Просрочено · {{ countStale }}
          </v-chip>
        </div>
        <div class="d-flex flex-wrap ga-1 mt-1">
          <v-chip v-for="s in statusFilterPills" :key="s.value" size="x-small" label
            :color="filter.status === s.value ? 'primary' : undefined"
            :variant="filter.status === s.value ? 'flat' : 'tonal'"
            @click="filter.status = s.value; loadChats()">{{ s.label }}</v-chip>
        </div>
        <div class="d-flex flex-wrap ga-1 mt-1">
          <v-chip v-for="p in priorityFilterPills" :key="p.value" size="x-small" label
            :color="filter.priority === p.value ? p.color : undefined"
            :variant="filter.priority === p.value ? 'flat' : 'tonal'"
            @click="filter.priority = p.value; loadChats()">{{ p.label }}</v-chip>
        </div>
      </div>

      <v-divider />
      <div class="sidebar-list" @scroll="onListScroll">
        <div v-for="t in filteredBySmartView" :key="t.id"
          class="chat-item"
          :class="{ active: activeChat?.id === t.id, 'has-unread': t.unread > 0, stale: isStale(t), pinned: t.pinned_at, 'bulk-mode': bulkMode, selected: selectedIds.has(t.id) }"
          @click="bulkMode ? toggleCardSelect(t, $event) : openChat(t)">
          <input v-if="bulkMode" type="checkbox" class="chat-item-cb"
            :checked="selectedIds.has(t.id)"
            @click.stop="toggleCardSelect(t, $event)" />
          <div class="chat-item-avatar" :style="{ background: t.customer_avatar ? 'transparent' : catColor(t.category || t.department) }">
            <v-img v-if="t.customer_avatar" :src="t.customer_avatar" cover class="chat-item-avatar-img" />
            <span v-else class="chat-item-avatar-initials">{{ initials(t.customer_name) }}</span>
          </div>
          <div v-if="t.priority && t.priority !== 'medium'" class="priority-bar" :style="{ background: prioClr(t.priority) }"></div>
          <div class="chat-item-body">
            <div class="chat-item-top">
              <span class="chat-item-subject">
                <v-icon v-if="t.pinned_at" size="12" color="primary" class="mr-1">mdi-pin</v-icon>{{ t.subject }}
              </span>
              <span class="chat-item-time" :class="{ stale: isStale(t) }">{{ ago(t.last_message_at) }}</span>
            </div>
            <!-- Last message preview — оператор видит без открытия что в последнем
                 сообщении: вопрос клиента или собственный ответ. -->
            <div v-if="t.lastMessage" class="chat-item-preview">
              <span v-if="t.lastMessageFromMe" class="chat-item-preview-prefix">Вы:</span>
              <span class="chat-item-preview-text">{{ t.lastMessage }}</span>
            </div>
            <div class="chat-item-bottom">
              <span class="customer">{{ t.customer_name }}</span>
              <span v-if="t.recipient_name" class="recipient"> → {{ t.recipient_name }}</span>
              <!-- «Новый для вас» — тикеты, в которые меня добавили через
                   chat_ticket_participants и я ни разу не открывал. Чтобы
                   адресат не пропустил приглашение, помимо bell-нотификации. -->
              <span v-if="t.is_new_for_me" class="chat-item-status-chip chip-new-for-me">
                Новый для вас
              </span>
              <span class="chat-item-status-chip" :style="{ background: statusClr(t.status) + '22', color: statusClr(t.status) }">{{ statusTxt(t.status) }}</span>
            </div>
            <!-- Роли: исполнитель / постановщик / наблюдатели -->
            <div class="chat-item-roles">
              <span v-if="t.assigned_name" class="role-chip role-assignee" title="Исполнитель">
                <v-icon size="10">mdi-account-hard-hat</v-icon> {{ t.assigned_name }}
              </span>
              <span v-if="t.creator_name" class="role-chip role-creator" title="Постановщик">
                <v-icon size="10">mdi-account-edit</v-icon> {{ t.creator_name }}
              </span>
              <template v-if="t.participants?.length">
                <span v-for="p in t.participants" :key="p.user_id" class="role-chip role-observer" title="Наблюдатель">
                  <v-icon size="10">mdi-eye-outline</v-icon> {{ p.user_name }}
                </span>
              </template>
            </div>
          </div>
          <button class="chat-item-pin" :class="{ active: t.pinned_at }" :title="t.pinned_at ? 'Открепить' : 'Закрепить'" @click.stop="togglePin(t, $event)">
            <v-icon size="14">{{ t.pinned_at ? 'mdi-pin' : 'mdi-pin-outline' }}</v-icon>
          </button>
          <span v-if="t.unread > 0" class="unread-badge">{{ t.unread }}</span>
          <span v-else-if="t.csat_rating" class="csat-badge" :title="`CSAT: ${t.csat_rating} из 5`">
            ★ {{ t.csat_rating }}
          </span>
        </div>
        <div v-if="!chats.length && !loading" class="sidebar-empty pa-4 text-center">
          <v-icon size="40" color="grey">mdi-inbox-outline</v-icon>
          <div class="text-body-2 text-medium-emphasis mt-2">Ничего не найдено</div>
        </div>
        <div v-if="loadingMore" class="py-2 text-center">
          <v-progress-circular size="18" width="2" indeterminate color="primary" />
        </div>
      </div>

      <!-- Bulk action bar (list-mode). В Канбане свой — это для list-view. -->
      <transition name="bulk-slide">
        <div v-if="bulkMode && anySelected" class="bulk-bar list-bulk-bar pa-2 d-flex flex-wrap align-center ga-2">
          <div class="text-body-2">Выбрано: <strong>{{ selectedIds.size }}</strong></div>
          <v-menu>
            <template #activator="{ props }">
              <v-btn v-bind="props" size="small" variant="tonal" prepend-icon="mdi-arrow-right-bold">Статус</v-btn>
            </template>
            <v-list density="compact">
              <v-list-item v-for="s in statuses" :key="s.value" @click="bulkSetStatus(s.value)">
                <template #prepend><v-icon size="14" :color="s.color">{{ s.icon }}</v-icon></template>
                <v-list-item-title class="text-body-2">{{ s.label }}</v-list-item-title>
              </v-list-item>
            </v-list>
          </v-menu>
          <v-menu>
            <template #activator="{ props }">
              <v-btn v-bind="props" size="small" variant="tonal" prepend-icon="mdi-flag">Приоритет</v-btn>
            </template>
            <v-list density="compact">
              <v-list-item v-for="p in priorities" :key="p.value" @click="bulkSetPriority(p.value)">
                <template #prepend><v-icon size="14" :color="p.color">mdi-circle</v-icon></template>
                <v-list-item-title class="text-body-2">{{ p.label }}</v-list-item-title>
              </v-list-item>
            </v-list>
          </v-menu>
          <v-menu>
            <template #activator="{ props }">
              <v-btn v-bind="props" size="small" variant="tonal" prepend-icon="mdi-account-plus">Назначить</v-btn>
            </template>
            <v-list density="compact" style="max-height: 320px; overflow-y: auto">
              <v-list-item @click="bulkAssign(currentUserId, currentUserName)">
                <template #prepend><v-icon size="14">mdi-account-check</v-icon></template>
                <v-list-item-title class="text-body-2 font-weight-bold">На себя</v-list-item-title>
              </v-list-item>
              <v-divider />
              <v-list-item v-for="s in staffList" :key="s.id" @click="bulkAssign(s.id, s.name)">
                <v-list-item-title class="text-body-2">{{ s.name }}</v-list-item-title>
              </v-list-item>
            </v-list>
          </v-menu>
          <v-btn size="small" variant="text" @click="selectedIds = new Set()">Сбросить</v-btn>
          <v-btn size="small" variant="text" color="error" @click="bulkMode = false">Выйти</v-btn>
        </div>
      </transition>
    </aside>
    </Pane>

    <!-- Center pane: chat-main. -->
    <Pane :size="effectivePaneSizes[1]" v-if="!mobile || activeChat">

    <!-- Center: messages -->
    <main class="chat-main" :class="{ 'mobile-hidden': mobile && !activeChat }">
      <template v-if="activeChat">
        <!-- Header with actions -->
        <div class="chat-header pa-3">
          <v-btn v-if="mobile" icon variant="text" size="small" @click="closeActiveChat">
            <v-icon>mdi-arrow-left</v-icon>
          </v-btn>
          <div class="chat-header-info">
            <!-- Inline-редактор названия: поиск по чатам идёт ТОЛЬКО по
                 subject, поэтому staff подбирает удобный для поиска ключ. -->
            <div v-if="editingSubject" class="d-flex align-center ga-2">
              <v-text-field v-model="editedSubject"
                density="compact" variant="outlined" hide-details autofocus
                maxlength="255" counter="255"
                @keydown.enter.prevent="saveSubject"
                @keydown.esc.prevent="cancelEditSubject"
                @blur="saveSubject" />
              <v-btn icon="mdi-check" size="x-small" color="success" variant="text"
                :loading="savingSubject" @mousedown.prevent="saveSubject" />
              <v-btn icon="mdi-close" size="x-small" variant="text"
                @mousedown.prevent="cancelEditSubject" />
            </div>
            <div v-else class="d-flex align-center ga-2 chat-subject-row">
              <div class="text-subtitle-1 font-weight-bold">{{ activeChat.subject }}</div>
              <v-btn icon="mdi-pencil" size="x-small" variant="tonal" color="primary"
                title="Переименовать чат (Enter — сохранить, Esc — отмена)"
                @click="startEditSubject" />
            </div>
            <div class="d-flex flex-wrap align-center ga-2 mt-1">
              <span class="text-caption text-medium-emphasis">{{ activeChat.customer_name }}</span>
              <v-chip size="x-small" :color="statusClr(activeChat.status)" variant="tonal"
                :prepend-icon="statusIcon(activeChat.status)">
                {{ statusTxt(activeChat.status) }}
              </v-chip>
              <v-chip v-if="activeChat.priority && activeChat.priority !== 'medium'"
                size="x-small" :color="prioClr(activeChat.priority)" variant="tonal"
                prepend-icon="mdi-flag">
                {{ prioLabel(activeChat.priority) }}
              </v-chip>
              <v-chip v-if="activeChat.recipient_name" size="x-small" variant="tonal"
                prepend-icon="mdi-arrow-right">
                {{ activeChat.recipient_name }}
              </v-chip>
              <v-chip v-if="slaLabel" size="x-small" variant="tonal"
                :color="slaClass === 'sla-overdue' ? 'error' : (slaClass === 'sla-warning' ? 'warning' : 'success')"
                prepend-icon="mdi-clock-outline">
                {{ slaLabel }}
              </v-chip>
            </div>
            <!-- Роли: постановщик / исполнитель / наблюдатели -->
            <div class="roles-grid mt-2">
              <!-- Постановщик -->
              <span class="role-label">Постановщик</span>
              <span class="role-value">
                <span v-if="activeChat.creator_name" class="role-person">
                  <v-icon size="13" color="secondary">mdi-account-edit</v-icon>
                  {{ activeChat.creator_name }}
                </span>
                <span v-else-if="activeChat.customer_name" class="role-person">
                  <v-icon size="13" color="secondary">mdi-account-edit</v-icon>
                  {{ activeChat.customer_name }}
                </span>
                <span v-else class="text-disabled">—</span>
              </span>

              <!-- Исполнитель -->
              <span class="role-label">Исполнитель</span>
              <span class="role-value">
                <v-chip v-if="chatAccessRow.find(p => p.kind === 'assignee')"
                  size="x-small" variant="tonal" color="primary"
                  @click="openParticipantsDialog">
                  <template #prepend>
                    <v-avatar size="16" color="primary" class="text-caption font-weight-bold">
                      {{ pInitials(chatAccessRow.find(p => p.kind === 'assignee').name) }}
                    </v-avatar>
                  </template>
                  {{ chatAccessRow.find(p => p.kind === 'assignee').name }}
                  <v-icon end size="11">mdi-star</v-icon>
                </v-chip>
                <span v-else class="text-disabled text-caption">Не назначен</span>
                <v-btn icon variant="text" size="x-small" title="Изменить исполнителя"
                  class="ml-1" @click="openParticipantsDialog">
                  <v-icon size="13">mdi-account-plus</v-icon>
                </v-btn>
              </span>

              <!-- Наблюдатели -->
              <template v-if="chatAccessRow.filter(p => p.kind !== 'assignee').length">
                <span class="role-label">Наблюдатели</span>
                <span class="role-value d-flex flex-wrap ga-1">
                  <v-chip v-for="p in chatAccessRow.filter(p => p.kind !== 'assignee')"
                    :key="'obs-' + p.userId"
                    size="x-small" variant="tonal"
                    @click="openParticipantsDialog">
                    <template #prepend>
                      <v-avatar size="16" color="grey-lighten-1" class="text-caption font-weight-bold">
                        {{ pInitials(p.name) }}
                      </v-avatar>
                    </template>
                    {{ p.name }}
                  </v-chip>
                </span>
              </template>
            </div>
          </div>
          <div class="chat-header-actions d-flex align-center ga-1">
            <!-- Быстрая кнопка «Решён» — один клик, без меню статусов.
                 Скрыта если тикет уже resolved/closed — там нечего закрывать.
                 Для тикетов-инцидентов закрытие доступно только админу
                 (решение 2026-05-28) — кнопку прячем у support/head. -->
            <v-btn
              v-if="!['resolved', 'closed'].includes(activeChat.status)
                && (!activeChat.is_incident || auth.isAdmin)"
              size="small" variant="tonal" color="success"
              prepend-icon="mdi-check-bold"
              title="Пометить тикет как решённый"
              @click="setStatus('resolved')">
              Решён
            </v-btn>
            <!-- Открыть повторно — для resolved/closed тикетов. По запросу
                 заказчика 2026-05-22: если оператор закрыл тикет, а у
                 партнёра возник дополнительный вопрос или решение оказалось
                 неполным, нужно вернуть тикет в работу одним кликом. -->
            <v-btn
              v-if="['resolved', 'closed'].includes(activeChat.status)"
              size="small" variant="tonal" color="warning"
              prepend-icon="mdi-lock-open-variant"
              title="Открыть тикет повторно"
              @click="setStatus('open')">
              Открыть повторно
            </v-btn>
            <!-- Кнопка смены приоритета — теперь в начале блока, рядом с «Решён»,
                 чтобы не зарываться в середину панели. Цвет/название отражают
                 текущий приоритет. -->
            <v-menu>
              <template #activator="{ props }">
                <v-btn v-bind="props" size="small" variant="tonal"
                  :color="prioClr(activeChat.priority) || 'grey'"
                  prepend-icon="mdi-flag"
                  :title="'Приоритет: ' + prioLabel(activeChat.priority || 'medium')">
                  {{ prioLabel(activeChat.priority || 'medium') }}
                </v-btn>
              </template>
              <v-list density="compact" min-width="160">
                <v-list-item v-for="p in priorities" :key="p.value" @click="setPriority(p.value)">
                  <template #prepend><v-icon size="14" :color="p.color">mdi-circle</v-icon></template>
                  <v-list-item-title class="text-body-2">{{ p.label }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
            <!-- 4 первичных действия видимые: Status, Assign, Priority, Context -->
            <v-menu>
              <template #activator="{ props }">
                <v-btn v-bind="props" icon variant="text" size="small"
                  :title="'Статус: ' + statusTxt(activeChat.status)">
                  <v-icon size="18">mdi-check-circle-outline</v-icon>
                </v-btn>
              </template>
              <v-list density="compact" min-width="180">
                <v-list-item v-for="s in statuses" :key="s.value" @click="setStatus(s.value)">
                  <template #prepend><v-icon size="14" :color="s.color">{{ s.icon }}</v-icon></template>
                  <v-list-item-title class="text-body-2">{{ s.label }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
            <v-menu>
              <template #activator="{ props }">
                <v-btn v-bind="props" icon variant="text" size="small" title="Назначить">
                  <v-icon size="18">mdi-account-plus-outline</v-icon>
                </v-btn>
              </template>
              <v-list density="compact" min-width="220">
                <v-list-item @click="assignTo(currentUserId, currentUserName)">
                  <template #prepend><v-icon size="14">mdi-account-check</v-icon></template>
                  <v-list-item-title class="text-body-2 font-weight-bold">Назначить на себя</v-list-item-title>
                </v-list-item>
                <v-divider />
                <v-list-item v-for="s in staffList" :key="s.id" @click="assignTo(s.id, s.name)">
                  <v-list-item-title class="text-body-2">{{ s.name }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
            <v-btn icon variant="text" size="small"
              :title="participants.length ? `Участники чата: ${participants.length + 1}` : 'Добавить участников в чат'"
              @click="openParticipantsDialog">
              <v-badge v-if="participants.length" :content="participants.length + 1" color="primary" floating>
                <v-icon size="18">mdi-account-multiple-plus-outline</v-icon>
              </v-badge>
              <v-icon v-else size="18">mdi-account-multiple-plus-outline</v-icon>
            </v-btn>
            <v-btn icon variant="text" size="small"
              :color="showContext ? 'primary' : undefined"
              title="Карточка партнёра" @click="showContext = !showContext">
              <v-icon size="18">mdi-card-account-details-outline</v-icon>
            </v-btn>

            <v-divider vertical class="mx-1" />

            <!-- Overflow меню: всё остальное -->
            <v-menu location="bottom end">
              <template #activator="{ props }">
                <v-btn v-bind="props" icon variant="text" size="small" title="Ещё">
                  <v-icon size="18">mdi-dots-horizontal</v-icon>
                </v-btn>
              </template>
              <v-list density="compact" min-width="240">
                <v-list-item prepend-icon="mdi-magnify" title="Поиск в чате (Ctrl+K)" @click="openMessageSearch" />
                <v-list-item prepend-icon="mdi-book-open-variant"
                  :title="showKb ? 'Скрыть базу знаний' : 'База знаний'"
                  @click="toggleKb" />
                <v-list-item prepend-icon="mdi-note-text-outline"
                  :title="showNotes ? 'Скрыть заметки' : 'Внутренние заметки'"
                  @click="toggleNotes" />
                <v-list-item v-if="activeChat.status === 'resolved'"
                  prepend-icon="mdi-content-save-outline"
                  title="Сохранить решение в базу знаний"
                  @click="openSaveFaq" />
                <v-divider />
                <v-list-item :prepend-icon="activeChat.pinned_at ? 'mdi-pin-off' : 'mdi-pin-outline'"
                  :title="activeChat.pinned_at ? 'Открепить' : 'Закрепить'"
                  @click="togglePin(activeChat, $event)" />
                <v-list-item :prepend-icon="notifyEnabled ? 'mdi-bell-off-outline' : 'mdi-bell-outline'"
                  :title="notifyEnabled ? 'Отключить уведомления' : 'Включить уведомления'"
                  @click="notifyEnabled = !notifyEnabled" />
                <v-divider />
                <v-list-item prepend-icon="mdi-keyboard-outline" title="Горячие клавиши (?)"
                  @click="showHotkeys = true" />
                <template v-if="canFullChat">
                  <v-divider />
                  <v-list-item prepend-icon="mdi-delete-outline"
                    base-color="error"
                    title="Удалить чат"
                    @click="deleteChat" />
                </template>
              </v-list>
            </v-menu>
          </div>
        </div>

        <!-- Tags (editable) -->
        <div class="chat-tags px-3 py-2 d-flex flex-wrap align-center ga-1">
          <v-chip v-for="tag in currentTags" :key="tag"
            size="x-small" variant="tonal" label closable
            @click:close="removeTag(tag)">
            {{ tag }}
          </v-chip>
          <v-btn v-if="!addingTag" size="x-small" variant="text"
            prepend-icon="mdi-plus" @click="addingTag = true">
            тег
          </v-btn>
          <v-text-field v-else v-model="newTag" ref="tagInput"
            placeholder="Имя тега…"
            variant="outlined" density="compact" hide-details
            class="tag-input-field"
            autofocus
            @keydown.enter.prevent="addTag"
            @keydown.esc="cancelAddTag"
            @blur="addTag" />
        </div>

        <!-- In-chat search bar -->
        <v-card v-if="messageSearch.open" flat class="msg-search-bar px-3 py-2 d-flex align-center ga-2">
          <v-text-field v-model="messageSearch.query"
            placeholder="Поиск по сообщениям этого чата…"
            prepend-inner-icon="mdi-magnify"
            variant="outlined" density="compact" hide-details clearable
            autofocus />
          <v-chip v-if="messageSearch.query" size="small" variant="tonal" label>
            Найдено: {{ messageSearchMatches.size }}
          </v-chip>
          <v-btn icon variant="text" size="small" @click="closeMessageSearch">
            <v-icon size="18">mdi-close</v-icon>
          </v-btn>
        </v-card>

        <!-- Knowledge base panel -->
        <v-card v-if="showKb" flat class="kb-panel mx-3 my-2" variant="tonal" color="primary">
          <v-card-title class="d-flex align-center ga-2 py-2 px-3 text-body-2 font-weight-bold">
            <v-icon size="16">mdi-book-open-variant</v-icon>
            <span>База знаний · предложения по теме</span>
            <v-spacer />
            <v-btn icon size="x-small" variant="text" title="Обновить" @click="loadKbSuggestions">
              <v-icon size="14">mdi-refresh</v-icon>
            </v-btn>
          </v-card-title>
          <v-divider />
          <div v-if="kbLoading" class="pa-3 text-body-2 text-medium-emphasis">
            <v-progress-circular indeterminate size="14" width="2" class="me-2" />
            Ищу…
          </div>
          <div v-else-if="!kbArticles.length" class="pa-4 text-center">
            <v-icon size="24" color="grey">mdi-book-off-outline</v-icon>
            <div class="text-body-2 text-medium-emphasis mt-1">Нет подходящих статей</div>
          </div>
          <v-list v-else density="compact" class="kb-list bg-transparent" style="max-height: 240px; overflow-y: auto">
            <v-list-item v-for="a in kbArticles" :key="a.id"
              :title="a.title"
              :subtitle="(a.content || '').slice(0, 180) + ((a.content || '').length > 180 ? '…' : '')"
              @click="insertKbArticle(a)">
              <template #append>
                <div class="d-flex align-center ga-2 text-caption text-medium-emphasis">
                  <span v-if="a.views"><v-icon size="11">mdi-eye</v-icon> {{ a.views }}</span>
                  <v-chip size="x-small" color="primary" variant="flat" label>Вставить ↵</v-chip>
                </div>
              </template>
            </v-list-item>
          </v-list>
        </v-card>

        <!-- Notes panel (collapsible) -->
        <v-card v-if="showNotes" flat class="notes-panel mx-3 my-2" variant="tonal" color="warning">
          <v-card-title class="d-flex align-center ga-2 py-2 px-3 text-body-2 font-weight-bold">
            <v-icon size="16">mdi-shield-account</v-icon>
            <span>Внутренние заметки · видны только сотрудникам</span>
          </v-card-title>
          <v-divider />
          <div class="notes-list pa-2" style="max-height: 200px; overflow-y: auto">
            <div v-for="n in notes" :key="n.id" class="note-item pa-2 mb-1 rounded">
              <div class="d-flex align-center ga-2 text-caption text-medium-emphasis">
                <strong class="text-body-2">{{ n.authorName || 'Staff' }}</strong>
                <span style="font-variant-numeric: tabular-nums">{{ fmtTime(n.createdAt) }}</span>
              </div>
              <div class="text-body-2 mt-1" style="white-space: pre-line; word-break: break-word">{{ n.content }}</div>
            </div>
            <div v-if="!notes.length" class="text-center text-body-2 text-medium-emphasis py-3">
              Заметок нет
            </div>
          </div>
          <v-divider />
          <div class="d-flex align-end ga-2 pa-2">
            <v-textarea v-model="noteText"
              placeholder="Добавить внутреннюю заметку… (Enter — отправить)"
              variant="outlined" density="compact" rows="2" auto-grow hide-details
              max-rows="6"
              @keydown.enter.exact.prevent="addNote" />
            <v-btn icon color="warning" size="small"
              :disabled="!noteText.trim()" @click="addNote">
              <v-icon>mdi-send</v-icon>
            </v-btn>
          </div>
        </v-card>

        <!-- Messages — поддержка drag-and-drop файла поверх области сообщений. -->
        <div ref="msgsRef" class="chat-messages" :class="{ 'drop-active': dropActive }"
          @scroll="onMessagesScroll"
          @dragenter.prevent="dropActive = true"
          @dragover.prevent="dropActive = true"
          @dragleave.prevent="dropActive = false"
          @drop.prevent="onDrop">
          <!-- Info-карточка сверху: номер тикета/инцидента и время создания.
               Видна всегда при открытом чате, не зависит от истории сообщений. -->
          <div v-if="activeChat" class="ticket-info-card">
            <v-icon size="14" class="me-1">
              {{ activeChat.is_incident ? 'mdi-alert-octagon-outline' : 'mdi-pound' }}
            </v-icon>
            <span class="ticket-info-num">
              {{ activeChat.is_incident && activeChat.incident_no
                ? `Инцидент ${activeChat.incident_no}`
                : `Тикет #${activeChat.id}` }}
            </span>
            <span v-if="activeChat.created_at" class="ticket-info-time">
              · создан {{ fmtTicketCreated(activeChat.created_at) }}
            </span>
          </div>
          <template v-for="item in groupedMessages" :key="item.key">
            <div v-if="item.type === 'divider'" class="date-divider">
              <span>{{ item.label }}</span>
            </div>
            <div v-else-if="item.msg.isSystem" class="msg-row system">
              <div class="msg-system">
                <v-icon size="12" class="me-1">mdi-information-outline</v-icon>
                <span>{{ item.msg.content }}</span>
                <span v-if="item.msg.createdAt" class="msg-system-time">· {{ ago(item.msg.createdAt) }}</span>
              </div>
            </div>
            <div v-else class="msg-row" :class="{ mine: isMine(item.msg), 'search-hit': messageSearch.open && messageSearchMatches.has(item.msg.id) }">
              <div class="msg-avatar" v-if="!isMine(item.msg)">
                <div class="avatar-circle partner">{{ initials(item.msg.senderName) }}</div>
              </div>
              <div class="msg-bubble" :class="isMine(item.msg) ? 'mine' : 'partner'">
                <div class="msg-sender">{{ item.msg.senderName }}</div>
                <div v-if="item.msg.replyTo" class="msg-reply-quote">
                  <v-icon size="12">mdi-reply</v-icon>
                  <div class="msg-reply-body">
                    <div class="msg-reply-sender">{{ item.msg.replyTo.senderName }}</div>
                    <div class="msg-reply-text">{{ item.msg.replyTo.content }}</div>
                  </div>
                </div>
                <template v-if="editing && editing.id === item.msg.id">
                  <textarea v-model="editing.content" class="msg-edit-area" rows="8"
                    @keydown.esc.prevent="cancelEdit"></textarea>
                  <div class="msg-edit-hint">Enter — перенос строки · Esc — отмена</div>
                  <div class="msg-edit-actions">
                    <button class="msg-edit-btn cancel" @click="cancelEdit">Отмена</button>
                    <button class="msg-edit-btn save" @click="saveEdit">Сохранить</button>
                  </div>
                </template>
                <template v-else>
                  <div v-if="item.msg.content" class="msg-text" v-html="linkify(item.msg.content)"></div>
                </template>
                <template v-if="item.msg.attachmentPath">
                  <a v-if="isImageAttachment(item.msg.attachmentName || item.msg.attachmentPath)"
                    href="#" class="msg-image-link"
                    @click.prevent="openLightbox(item.msg.attachmentPath, item.msg.attachmentName)">
                    <img :src="item.msg.attachmentPath" :alt="item.msg.attachmentName || 'Изображение'" class="msg-image" loading="lazy" />
                  </a>
                  <a v-else href="#" class="msg-attach"
                    @click.prevent="openLightbox(item.msg.attachmentPath, item.msg.attachmentName)">
                    <v-icon size="14">mdi-paperclip</v-icon> {{ item.msg.attachmentName || 'Файл' }}
                  </a>
                </template>
                <div class="msg-time">
                  {{ fmtTime(item.msg.createdAt) }}
                  <span v-if="item.msg.editedAt" class="msg-edited" title="Изменено">· изменено</span>
                  <v-icon v-if="isMine(item.msg) && isSeen(item.msg)" size="12" class="msg-check seen" title="Прочитано">mdi-check-all</v-icon>
                  <v-icon v-else-if="isMine(item.msg)" size="12" class="msg-check" title="Отправлено">mdi-check</v-icon>
                </div>
                <div v-if="item.msg.reactions && item.msg.reactions.length" class="msg-reactions">
                  <button v-for="r in item.msg.reactions" :key="r.emoji"
                    class="reaction-chip" :class="{ mine: r.mine }"
                    @click.stop="toggleReaction(item.msg, r.emoji)">
                    <span class="reaction-emoji">{{ r.emoji }}</span>
                    <span class="reaction-count">{{ r.count }}</span>
                  </button>
                </div>
                <div class="msg-actions">
                  <v-menu location="bottom end">
                    <template #activator="{ props }">
                      <button v-bind="props" class="msg-action" title="Реакция" @click.stop><v-icon size="14">mdi-emoticon-happy-outline</v-icon></button>
                    </template>
                    <div class="reaction-picker">
                      <button v-for="emoji in REACTION_PALETTE" :key="emoji"
                        class="reaction-picker-btn"
                        @click="toggleReaction(item.msg, emoji)">{{ emoji }}</button>
                    </div>
                  </v-menu>
                  <button class="msg-action" title="Ответить" @click="startReply(item.msg)"><v-icon size="14">mdi-reply</v-icon></button>
                  <button v-if="canEdit(item.msg)" class="msg-action" title="Изменить (5 мин)" @click="startEdit(item.msg)"><v-icon size="14">mdi-pencil</v-icon></button>
                </div>
              </div>
              <div class="msg-avatar" v-if="isMine(item.msg)">
                <div class="avatar-circle staff">{{ initials(item.msg.senderName) }}</div>
              </div>
            </div>
          </template>
          <div v-if="typingName" class="typing-indicator">
            <span class="typing-dots"><span></span><span></span><span></span></span>
            {{ typingName }} печатает…
          </div>
        </div>

        <v-btn v-if="showJumpToBottom" class="jump-to-bottom"
          icon size="small" color="primary" elevation="3"
          @click="scrollDown(true)">
          <v-icon size="18">mdi-arrow-down</v-icon>
          <v-badge v-if="pendingMessages > 0" :content="pendingMessages" color="error" floating />
        </v-btn>

        <!-- Reply preview — компактный одно-двухстрочный блок. Раньше тут
             был v-alert: на high-DPI его внутренний padding+icon растягивал
             блок почти на полэкрана при пустом активном чате. -->
        <div v-if="replyTo && activeChat.status !== 'closed'" class="reply-bar">
          <v-icon size="14" color="primary" class="me-1">mdi-reply</v-icon>
          <div class="reply-bar-body">
            <div class="reply-bar-sender">Ответ на: {{ replyTo.senderName }}</div>
            <div class="reply-bar-text text-truncate">{{ replyTo.content }}</div>
          </div>
          <v-btn icon="mdi-close" size="x-small" variant="text"
            title="Отменить ответ" @click="cancelReply" />
        </div>

        <!-- Input -->
        <div v-if="activeChat.status !== 'closed'" class="chat-input pa-2"
          :class="{ 'drag-over': dragOver }"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onFileDrop">
          <!-- multiple binding явным prop'ом и style="display:none" вместо
               hidden — на некоторых Chromium-сборках `hidden` срабатывает
               раньше, чем Vue прикладывает атрибут `multiple`, и диалог
               открывается в single-select. -->
          <input ref="fileRef" type="file" :multiple="true"
            style="display:none"
            @change="e => { addFiles(e.target.files); e.target.value = ''; }" />
          <v-btn icon variant="text" size="small"
            :title="files.length ? `Прикреплено: ${files.length} · добавить ещё` : 'Прикрепить файл (можно несколько)'"
            @click="$refs.fileRef.click()">
            <v-icon>mdi-paperclip</v-icon>
            <v-badge v-if="files.length > 0" :content="files.length" color="primary" floating />
          </v-btn>
          <!-- Quick replies -->
          <v-menu :close-on-content-click="false">
            <template #activator="{ props }">
              <v-btn v-bind="props" icon variant="text" size="small" title="Быстрые ответы">
                <v-icon>mdi-lightning-bolt-outline</v-icon>
              </v-btn>
            </template>
            <v-card width="380" max-width="380">
              <v-list density="compact" style="max-height: 400px; overflow-y: auto" class="pa-0">
                <!-- Кнопка «+ Добавить» — прямо в списке, первая строкой -->
                <v-list-item class="qr-add" @click="openQuickReplyEditor(null)">
                  <div class="d-flex align-center ga-2 w-100">
                    <v-icon color="primary">mdi-plus-circle</v-icon>
                    <span class="text-body-2 font-weight-medium">Добавить свой шаблон</span>
                  </div>
                </v-list-item>
                <v-divider />
                <v-list-item v-if="!quickReplies.length">
                  <v-list-item-subtitle class="text-caption">
                    Шаблонов пока нет — создайте первый.
                  </v-list-item-subtitle>
                </v-list-item>
                <v-list-item v-for="q in quickReplies" :key="q.id"
                  class="qr-row" @click="insertQuickReply(q)">
                  <div class="d-flex align-start ga-2 w-100">
                    <div class="flex-grow-1 min-w-0">
                      <div class="d-flex align-center ga-1">
                        <span class="text-body-2 font-weight-bold">{{ q.title }}</span>
                        <v-chip v-if="q.is_own" size="x-small" color="primary" variant="tonal">мой</v-chip>
                        <v-chip v-else-if="q.is_shared" size="x-small" color="grey" variant="tonal">общий</v-chip>
                      </div>
                      <div class="text-caption text-medium-emphasis" style="white-space: pre-wrap">{{ q.content }}</div>
                    </div>
                    <div class="d-flex flex-column ga-1">
                      <v-btn v-if="canEditQuickReply(q)" icon="mdi-pencil" size="x-small"
                        variant="text" title="Редактировать"
                        @click.stop="openQuickReplyEditor(q)" />
                      <v-btn v-if="canEditQuickReply(q)" icon="mdi-delete" size="x-small"
                        variant="text" color="error" title="Удалить"
                        @click.stop="deleteQuickReply(q)" />
                    </div>
                  </div>
                </v-list-item>
              </v-list>
            </v-card>
          </v-menu>
          <div class="input-area">
            <v-textarea ref="taRef" v-model="msgText"
              placeholder="Ответ… (Enter — отправить, Shift+Enter — перенос строки)"
              variant="outlined" density="compact" rows="1" auto-grow hide-details
              max-rows="6"
              @keydown.enter.exact.prevent="send"
              @input="onInput"
              @paste="onPaste" />
            <div v-if="files.length" class="input-files-list">
              <div v-for="(item, idx) in files" :key="idx" class="input-file-preview">
                <img v-if="item.previewUrl" :src="item.previewUrl" alt="preview" />
                <div v-else class="input-file-icon"><v-icon size="16">mdi-file</v-icon></div>
                <div class="input-file-info">
                  <div class="input-file-name">{{ item.file.name }}</div>
                  <div class="text-caption text-medium-emphasis">{{ fmtFileSize(item.file.size) }}</div>
                </div>
                <v-btn icon size="x-small" variant="text" title="Удалить" @click="removeFile(idx)">
                  <v-icon size="14">mdi-close</v-icon>
                </v-btn>
              </div>
            </div>
          </div>
          <v-btn icon color="primary"
            :disabled="sending || (!msgText.trim() && !files.length)"
            :loading="sending"
            :title="files.length > 1 ? `Отправить ${files.length} файла(ов)` : 'Отправить (Enter)'"
            @click="send">
            <v-icon>mdi-send</v-icon>
          </v-btn>
          <div v-if="dragOver" class="drop-overlay">
            <v-icon size="32">mdi-file-upload</v-icon>
            <span>Отпустите файл для прикрепления</span>
          </div>
        </div>
      </template>
      <div v-else class="chat-placeholder pa-8 text-center">
        <v-icon size="64" color="grey-lighten-2">mdi-forum-outline</v-icon>
        <div class="text-body-1 text-medium-emphasis mt-3">Выберите чат из списка</div>
      </div>
    </main>
    </Pane>

    <!-- Right pane: контекст-панель партнёра. Видна при выбранном тикете и
         включённом showContext. На mobile скрыта. -->
    <Pane :size="effectivePaneSizes[2]" :min-size="16" :max-size="38"
      v-if="!mobile && activeChat && showContext">
    <!-- Right: Partner context panel — единый блок (с/без partnerContext) -->
    <aside v-if="activeChat && showContext && !mobile" class="context-panel">
      <div class="context-head px-3 py-2 d-flex align-center ga-2">
        <v-icon size="16" color="primary">mdi-card-account-details-outline</v-icon>
        <span class="text-body-2 font-weight-bold">Карточка партнёра</span>
        <v-spacer />
        <v-btn icon variant="text" size="x-small" title="Скрыть" @click="showContext = false">
          <v-icon size="16">mdi-close</v-icon>
        </v-btn>
      </div>
      <v-divider />

      <!-- Empty state — нет партнёрских данных -->
      <div v-if="!partnerContext" class="pa-6 text-center">
        <v-icon size="40" color="grey-lighten-1" class="mb-2">mdi-account-question-outline</v-icon>
        <div class="text-body-2 mb-1">Нет партнёрских данных</div>
        <div class="text-caption text-medium-emphasis">
          Автор тикета не связан с активным консультантом или пока загружается.
        </div>
      </div>

      <!-- Полная карточка -->
      <div v-else class="context-body pa-3">
        <!-- User block -->
        <div class="d-flex align-center ga-3 mb-3">
          <v-avatar size="44" color="primary">
            <v-img v-if="partnerContext.user.avatarUrl" :src="partnerContext.user.avatarUrl" />
            <span v-else class="text-body-1 font-weight-bold text-white">
              {{ initials(`${partnerContext.user.lastName || ''} ${partnerContext.user.firstName || ''}`) }}
            </span>
          </v-avatar>
          <div style="min-width: 0; flex: 1">
            <div class="text-body-1 font-weight-bold text-truncate">
              {{ partnerContext.user.lastName }} {{ partnerContext.user.firstName }}
              {{ partnerContext.user.patronymic }}
            </div>
            <div class="d-flex flex-column ga-1 mt-1">
              <a v-if="partnerContext.user.email" :href="`mailto:${partnerContext.user.email}`"
                class="text-caption d-flex align-center ga-1 ctx-link">
                <v-icon size="12">mdi-email-outline</v-icon>
                <span class="text-truncate">{{ partnerContext.user.email }}</span>
              </a>
              <a v-if="partnerContext.user.phone" :href="`tel:${partnerContext.user.phone}`"
                class="text-caption d-flex align-center ga-1 ctx-link">
                <v-icon size="12">mdi-phone-outline</v-icon>
                <span class="text-truncate">{{ partnerContext.user.phone }}</span>
              </a>
            </div>
          </div>
        </div>

        <!-- Consultant block -->
        <template v-if="partnerContext.consultant">
          <div class="text-overline text-medium-emphasis mb-1">Партнёр</div>
          <v-list density="compact" class="ctx-list bg-transparent" lines="one">
            <v-list-item>
              <v-list-item-title class="text-caption text-medium-emphasis">Статус</v-list-item-title>
              <template #append>
                <v-chip size="x-small" variant="tonal"
                  :color="getActivityColorByName(partnerContext.consultant.activityName)" label>
                  {{ partnerContext.consultant.activityName || '—' }}
                </v-chip>
              </template>
            </v-list-item>
            <v-list-item v-if="partnerContext.consultant.qualificationName">
              <v-list-item-title class="text-caption text-medium-emphasis">Квалификация</v-list-item-title>
              <template #append>
                <strong class="text-body-2">{{ partnerContext.consultant.qualificationName }}</strong>
              </template>
            </v-list-item>
            <v-list-item>
              <v-list-item-title class="text-caption text-medium-emphasis">Реф-код</v-list-item-title>
              <template #append>
                <code class="text-caption">{{ partnerContext.consultant.participantCode || '—' }}</code>
              </template>
            </v-list-item>
            <v-list-item>
              <v-list-item-title class="text-caption text-medium-emphasis">ЛП</v-list-item-title>
              <template #append>
                <strong class="text-body-2 ctx-num">{{ formatVolume(partnerContext.consultant.personalVolume) }}</strong>
              </template>
            </v-list-item>
            <v-list-item>
              <v-list-item-title class="text-caption text-medium-emphasis">ГП</v-list-item-title>
              <template #append>
                <strong class="text-body-2 ctx-num">{{ formatVolume(partnerContext.consultant.groupVolumeCumulative) }}</strong>
              </template>
            </v-list-item>
            <v-list-item>
              <v-list-item-title class="text-caption text-medium-emphasis">Клиенты</v-list-item-title>
              <template #append>
                <strong class="text-body-2 ctx-num">{{ partnerContext.consultant.clientsCount }}</strong>
              </template>
            </v-list-item>
            <v-list-item>
              <v-list-item-title class="text-caption text-medium-emphasis">Контракты</v-list-item-title>
              <template #append>
                <strong class="text-body-2 ctx-num">{{ partnerContext.consultant.contractsCount }}</strong>
              </template>
            </v-list-item>
            <v-list-item v-if="partnerContext.consultant.dateActivity">
              <v-list-item-title class="text-caption text-medium-emphasis">Активен с</v-list-item-title>
              <template #append>
                <span class="text-body-2">{{ fmtDate(partnerContext.consultant.dateActivity) }}</span>
              </template>
            </v-list-item>
            <v-list-item v-if="partnerContext.consultant.yearPeriodEnd">
              <v-list-item-title class="text-caption text-medium-emphasis">Год до</v-list-item-title>
              <template #append>
                <span class="text-body-2">{{ fmtDate(partnerContext.consultant.yearPeriodEnd) }}</span>
              </template>
            </v-list-item>
            <v-list-item v-if="partnerContext.consultant.activationDeadline">
              <v-list-item-title class="text-caption text-medium-emphasis">Дедлайн активации</v-list-item-title>
              <template #append>
                <span class="text-body-2 text-warning">{{ fmtDate(partnerContext.consultant.activationDeadline) }}</span>
              </template>
            </v-list-item>
            <v-list-item v-if="(partnerContext.consultant.terminationCount || 0) > 0">
              <v-list-item-title class="text-caption text-medium-emphasis">Терминаций</v-list-item-title>
              <template #append>
                <span class="text-body-2 text-warning">{{ partnerContext.consultant.terminationCount }} / 3</span>
              </template>
            </v-list-item>
            <v-list-item v-if="partnerContext.consultant.inviterName">
              <v-list-item-title class="text-caption text-medium-emphasis">Пригласил</v-list-item-title>
              <template #append>
                <span class="text-body-2">{{ partnerContext.consultant.inviterName }}</span>
              </template>
            </v-list-item>
          </v-list>

          <v-btn block variant="tonal" size="small" prepend-icon="mdi-open-in-new" class="mt-2"
            :to="`/manage/partners?search=${encodeURIComponent(partnerContext.user.lastName || '')}`">
            Открыть в админке
          </v-btn>
        </template>
        <v-alert v-else type="info" variant="tonal" density="compact" class="mt-2">
          Пользователь не является партнёром.
        </v-alert>

        <!-- Recent contracts -->
        <template v-if="partnerContext.recentContracts && partnerContext.recentContracts.length">
          <div class="text-overline text-medium-emphasis mt-3 mb-1">Последние контракты</div>
          <v-card v-for="c in partnerContext.recentContracts" :key="c.id"
            variant="tonal" class="pa-2 mb-1" density="compact">
            <div class="d-flex justify-space-between align-center">
              <strong class="text-body-2">{{ c.number }}</strong>
              <span v-if="c.amount" class="text-body-2 ctx-num">{{ formatVolume(c.amount) }}</span>
            </div>
            <div class="text-caption text-medium-emphasis text-truncate">
              {{ c.clientName }} · {{ c.productName }}
            </div>
            <div v-if="c.openDate" class="text-caption text-medium-emphasis ctx-num">
              {{ fmtDate(c.openDate) }}
            </div>
          </v-card>
        </template>
      </div>
    </aside>
    </Pane>
    </Splitpanes>

    <!-- Save to FAQ dialog -->
    <v-dialog v-model="saveFaqDialog.open" max-width="640" :persistent="saveFaqDialog.saving">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>mdi-book-plus-outline</v-icon>
          Сохранить решение в базу знаний
        </v-card-title>
        <v-card-text>
          <v-text-field v-model="saveFaqDialog.title" label="Заголовок статьи" class="mb-2" density="comfortable" />
          <v-text-field v-model="saveFaqDialog.category" label="Категория" class="mb-2" density="comfortable" />
          <v-textarea v-model="saveFaqDialog.content" label="Содержимое"
            rows="10" auto-grow counter maxlength="8000" density="comfortable" />
          <div class="text-caption text-medium-emphasis mt-1">
            Поддерживается простой markdown. По умолчанию подтянуты все сообщения этого тикета — отредактируй перед сохранением.
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="saveFaqDialog.saving" @click="saveFaqDialog.open = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saveFaqDialog.saving"
            :disabled="!saveFaqDialog.title.trim() || !saveFaqDialog.content.trim()"
            @click="submitSaveFaq">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Hotkeys modal -->
    <v-dialog v-model="showHotkeys" max-width="500">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>mdi-keyboard</v-icon>
          Горячие клавиши
        </v-card-title>
        <v-card-text>
          <div class="text-overline mt-1 mb-1">Навигация</div>
          <div class="hotkey-row"><kbd>J</kbd> / <kbd>↓</kbd><span>Следующий тикет</span></div>
          <div class="hotkey-row"><kbd>K</kbd> / <kbd>↑</kbd><span>Предыдущий тикет</span></div>
          <div class="hotkey-row"><kbd>/</kbd><span>Поиск тикетов</span></div>
          <div class="hotkey-row"><kbd>Esc</kbd><span>Закрыть чат / поиск / отмена</span></div>
          <v-divider class="my-2" />
          <div class="text-overline mt-1 mb-1">Действия в открытом тикете</div>
          <div class="hotkey-row"><kbd>R</kbd><span>Фокус на ответ</span></div>
          <div class="hotkey-row"><kbd>E</kbd><span>Закрыть тикет (resolved)</span></div>
          <div class="hotkey-row"><kbd>Enter</kbd><span>Отправить ответ</span></div>
          <div class="hotkey-row"><kbd>Shift</kbd> + <kbd>Enter</kbd><span>Новая строка</span></div>
          <div class="hotkey-row"><kbd>Ctrl</kbd> + <kbd>K</kbd><span>Поиск по сообщениям в чате</span></div>
          <v-divider class="my-2" />
          <div class="text-overline mt-1 mb-1">Прочее</div>
          <div class="hotkey-row"><kbd>Ctrl</kbd> + <kbd>/</kbd><span>Показать / скрыть эту панель</span></div>
          <div class="hotkey-row"><kbd>?</kbd><span>То же (вне поля ввода)</span></div>
          <v-divider class="my-2" />
          <div class="text-caption text-medium-emphasis">
            Наведи курсор на сообщение — появятся кнопки «Ответить» и «Изменить» (редактирование в течение 5 мин).
            В шапке чата: смена приоритета, назначение, статус, заметки, база знаний, поиск.
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showHotkeys = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <ImageLightbox v-model="lightboxOpen" :src="lightboxSrc" :alt="lightboxAlt" />

    <!-- Диалог управления участниками чата -->
    <v-dialog v-model="participantsDialog" max-width="520">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-account-multiple-plus</v-icon>
          Участники чата
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="participantsDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-0">
          <v-list density="compact">
            <v-list-subheader class="text-caption">Текущие участники</v-list-subheader>
            <v-list-item v-for="p in participants" :key="'p-' + p.id">
              <template #prepend>
                <v-avatar size="32" color="primary" variant="tonal">
                  <span class="text-caption font-weight-bold">{{ pInitials(p.name) }}</span>
                </v-avatar>
              </template>
              <v-list-item-title class="text-body-2">{{ p.name }}</v-list-item-title>
              <v-list-item-subtitle class="text-caption">
                {{ pShortRole(p.role) }} · добавлен {{ pAddedAt(p.addedAt) }}
              </v-list-item-subtitle>
              <template #append>
                <v-btn icon="mdi-close" size="x-small" variant="text" color="error"
                  title="Убрать из чата" @click="removeParticipant(p)" />
              </template>
            </v-list-item>
            <v-list-item v-if="!participants.length" class="text-medium-emphasis">
              <v-list-item-subtitle class="text-caption">
                Никого ещё не добавили — выберите сотрудника ниже.
              </v-list-item-subtitle>
            </v-list-item>
            <v-divider />
            <v-list-subheader class="text-caption">Добавить сотрудника</v-list-subheader>
            <div class="px-3 pb-3">
              <v-autocomplete v-model="participantToAdd" :items="addableStaff"
                item-title="name" item-value="id"
                placeholder="Начните вводить ФИО…"
                variant="outlined" density="compact" hide-details clearable
                :loading="participantSaving" />
              <v-btn class="mt-2" color="primary" :loading="participantSaving"
                :disabled="!participantToAdd"
                prepend-icon="mdi-plus" @click="addParticipant" block>
                Добавить в чат
              </v-btn>
            </div>
            <v-divider />
            <v-list-subheader class="text-caption">Добавить ФК (партнёра)</v-list-subheader>
            <div class="px-3 pb-3">
              <v-autocomplete v-model="partnerToAdd" :items="partnerOptions"
                item-title="name" item-value="id"
                placeholder="Введите ФИО или код партнёра…"
                variant="outlined" density="compact" hide-details clearable
                no-filter :loading="partnerSearching"
                @update:search="searchPartners">
                <template #item="{ props, item }">
                  <v-list-item v-bind="props" :title="item.raw.name"
                    :subtitle="item.raw.code ? ('Код: ' + item.raw.code) : undefined" />
                </template>
                <template #no-data>
                  <div class="px-3 py-2 text-caption text-medium-emphasis">
                    {{ partnerSearching ? 'Поиск…' : 'Введите 2+ символа' }}
                  </div>
                </template>
              </v-autocomplete>
              <v-btn class="mt-2" color="primary" variant="tonal" :loading="participantSaving"
                :disabled="!partnerToAdd"
                prepend-icon="mdi-account-plus" @click="addPartner" block>
                Добавить ФК в чат
              </v-btn>
            </div>
          </v-list>
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Тикет в техподдержку — staff пишет о проблеме платформы. Department
         зашит в 'support': backend сразу делает инцидент (см. ChatController
         store() при $isSupport) и направляет в админскую категорию. -->
    <v-dialog v-model="showTechSupport" max-width="560">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-lifebuoy</v-icon>
          Тикет в техподдержку
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text"
            @click="showTechSupport = false" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pt-4">
          <div class="text-caption text-medium-emphasis mb-3">
            Опишите проблему — тикет создастся как инцидент и уйдёт администраторам платформы.
          </div>
          <v-text-field v-model="techSupportForm.subject"
            label="Тема" variant="outlined" density="comfortable"
            maxlength="255" counter
            class="mb-2" autofocus />
          <v-textarea v-model="techSupportForm.message"
            label="Описание проблемы" variant="outlined" density="comfortable"
            rows="5" auto-grow
            maxlength="10000" counter />
          <div v-if="techSupportError" class="text-caption text-error mt-2">
            {{ techSupportError }}
          </div>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn variant="text" @click="showTechSupport = false">Отмена</v-btn>
          <v-btn color="primary" :loading="creatingTechSupport"
            :disabled="!techSupportForm.subject.trim() || !techSupportForm.message.trim()"
            prepend-icon="mdi-send"
            @click="createTechSupport">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Редактирование/создание шаблона быстрых ответов -->
    <v-dialog v-model="qrDialog" max-width="560">
      <v-card>
        <v-card-title class="d-flex align-center ga-2">
          <v-icon color="primary">mdi-lightning-bolt</v-icon>
          {{ qrForm.id ? 'Редактирование шаблона' : 'Новый шаблон' }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="qrDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pt-4">
          <v-text-field v-model="qrForm.title" label="Заголовок" variant="outlined"
            density="comfortable" class="mb-2" autofocus />
          <v-textarea v-model="qrForm.content" label="Текст ответа"
            variant="outlined" density="comfortable" rows="6" auto-grow class="mb-2"
            hint="Доступны плейсхолдеры: {client_name}, {staff_name}, {agent_name}, {ticket_id}"
            persistent-hint />
          <div class="d-flex ga-2 mt-3">
            <v-text-field v-model="qrForm.category" label="Категория" variant="outlined"
              density="comfortable" hide-details style="max-width: 220px" />
            <v-text-field v-model="qrForm.shortcut" label="Шорткат (/hi)"
              variant="outlined" density="comfortable" hide-details style="max-width: 180px" />
          </div>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-3">
          <v-spacer />
          <v-btn variant="text" @click="qrDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="qrSaving" @click="saveQuickReply"
            prepend-icon="mdi-content-save">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay } from 'vuetify';
import { useRoute, useRouter } from 'vue-router';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { useConfirm } from '../../composables/useConfirm';
import { useSnackbar } from '../../composables/useSnackbar';
import { useAuthStore } from '../../stores/auth';
import { getActivityColorByName } from '../../composables/useDesign';
import ImageLightbox from '../../components/ImageLightbox.vue';
import { usePermissions } from '../../composables/usePermissions';
import { linkify } from '../../composables/useLinkify';
import { Splitpanes, Pane } from 'splitpanes';
import 'splitpanes/dist/splitpanes.css';

// destructured-rename — в файле уже есть локальный function canEdit(msg).
const { canFull: hasFullPermission } = usePermissions();
const canFullChat = computed(() => hasFullPermission('communication'));

const confirmDialog = useConfirm();
const { showError, showSuccess } = useSnackbar();
import {
  chatStatusColors,
  chatPriorityColors,
  getChatStatusColor,
  getChatPriorityColor,
  getChatCategoryColor,
  getChatActivityAccent,
} from '../../composables/chatPalette';

const { mobile } = useDisplay();

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const currentUserId = auth.userId;

// Хранилище ширин панелей. Модель — два независимых значения в % от общей
// ширины контейнера чата: sidebar и context. Main всегда занимает остаток.
// Это устраняет проблему «3-panes vs 2-panes»: в kanban-режиме мы не видим
// context, но sidebar остаётся в тех же % от всей ширины — нормализация
// больше не нужна. Используем @resized (drag-end), а не @resize.
//
// Per-user: ключ namespace'ится по userId, чтобы у каждого оператора был
// свой layout даже если они шарят браузер.
const PANE_STORAGE_KEY = `ds:chat-staff-widths:${currentUserId || 'anon'}`;
const LEGACY_PANE_KEY = 'ds:chat-staff-pane-sizes';
const paneWidths = ref(
  (() => {
    try {
      const stored = JSON.parse(localStorage.getItem(PANE_STORAGE_KEY) || 'null');
      if (stored && typeof stored.sidebar === 'number' && typeof stored.context === 'number') {
        return { sidebar: stored.sidebar, context: stored.context };
      }
      // Миграция из старого формата [a, b, c] (per-browser) — берём 0-й и
      // 2-й элементы как sidebar/context.
      const legacy = JSON.parse(localStorage.getItem(LEGACY_PANE_KEY) || 'null');
      if (Array.isArray(legacy) && legacy.length === 3) {
        return { sidebar: legacy[0], context: legacy[2] };
      }
    } catch (_) { /* fallthrough */ }
    return { sidebar: 24, context: 24 };
  })()
);
function persistPaneWidths() {
  try { localStorage.setItem(PANE_STORAGE_KEY, JSON.stringify(paneWidths.value)); } catch (_) { /* quota */ }
}
function onPaneResize(payload) {
  // Splitpanes 4.x шлёт @resized как объект { event, index, panes: [...] }.
  // До этого мы ожидали массив сразу и поэтому early-return'или — никаких
  // сохранений на drag-end не происходило вообще.
  const panes = Array.isArray(payload) ? payload : payload?.panes;
  if (!Array.isArray(panes) || !panes.length) return;
  // Размеры от splitpanes — в % от ВИДИМЫХ pane'ов (всегда сумма = 100).
  // Sidebar — всегда первый, его новый %% можно писать напрямую.
  const first = Number(panes[0]?.size);
  if (Number.isFinite(first) && first > 0) {
    paneWidths.value.sidebar = Math.round(first * 10) / 10;
  }
  // Если виден context (3 pane'а), он последний — пишем его %.
  if (panes.length === 3) {
    const last = Number(panes[2]?.size);
    if (Number.isFinite(last) && last > 0) {
      paneWidths.value.context = Math.round(last * 10) / 10;
    }
  }
  persistPaneWidths();
}
const currentUserName = computed(() => `${auth.user?.lastName || ''} ${auth.user?.firstName || ''}`.trim() || 'Staff');

// Кнопку «В техподдержку» скрываем у самих админов: они и так получат
// эти тикеты в общем списке (category=support видна ролям admin+support
// по TicketService::CATEGORIES) — писать самому себе бессмысленно.
const isAdminRole = computed(() => (auth.user?.role || '').toLowerCase().includes('admin'));
const showTechSupport = ref(false);
const creatingTechSupport = ref(false);
const techSupportError = ref('');
const techSupportForm = ref({ subject: '', message: '' });

function openTechSupport() {
  techSupportForm.value = { subject: '', message: '' };
  techSupportError.value = '';
  showTechSupport.value = true;
}

async function createTechSupport() {
  const subject = techSupportForm.value.subject.trim();
  const message = techSupportForm.value.message.trim();
  if (!subject || !message) {
    techSupportError.value = 'Заполните тему и описание';
    return;
  }
  creatingTechSupport.value = true;
  techSupportError.value = '';
  try {
    await api.post('/chat/tickets', {
      subject,
      message,
      department: 'support',
      priority: 'medium',
    });
    showTechSupport.value = false;
    showSuccess('Тикет отправлен в техподдержку');
    await loadChats();
  } catch (e) {
    techSupportError.value = e.response?.data?.message || 'Не удалось отправить';
  } finally {
    creatingTechSupport.value = false;
  }
}

const chats = ref([]);
const loading = ref(false);
// Бесконечный скролл sidebar'а: грузим страницами по 50 и догружаем при
// прокрутке вниз, пока page < lastPage (раньше показывались только 25).
const chatsPage = ref(1);
const chatsLastPage = ref(1);
const loadingMore = ref(false);
const CHATS_PER_PAGE = 50;
const activeChat = ref(null);
const messages = ref([]);

// Inline-редактирование названия. Поиск по списку идёт только по subject,
// поэтому staff подбирает удобный ключ — это критично для большой ленты.
const editingSubject = ref(false);
const editedSubject = ref('');
const savingSubject = ref(false);
function startEditSubject() {
  if (!activeChat.value) return;
  editedSubject.value = activeChat.value.subject || '';
  editingSubject.value = true;
}
function cancelEditSubject() {
  editingSubject.value = false;
  editedSubject.value = '';
}
async function saveSubject() {
  if (!editingSubject.value || !activeChat.value) return;
  const next = (editedSubject.value || '').trim();
  if (!next) { cancelEditSubject(); return; }
  if (next === activeChat.value.subject) { cancelEditSubject(); return; }
  savingSubject.value = true;
  try {
    const { data } = await api.post(`/chat/tickets/${activeChat.value.id}/subject`, { subject: next });
    activeChat.value.subject = data.subject;
    const inList = chats.value.find(c => c.id === activeChat.value.id);
    if (inList) inList.subject = data.subject;
    editingSubject.value = false;
    showSuccess('Название обновлено');
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось переименовать');
  } finally {
    savingSubject.value = false;
  }
}
const msgText = ref('');
// files: массив { file: File, previewUrl: string|null } — мульти-выбор.
// На отправке шлём по одному сообщению на файл (текст идёт с первым).
const files = ref([]);
const sending = ref(false);
const msgsRef = ref(null);
const fileRef = ref(null);
const dropActive = ref(false);
function onDrop(e) {
  dropActive.value = false;
  addFiles(e.dataTransfer?.files);
}
const taRef = ref(null);
const tagInput = ref(null);
const staffList = ref([]);
const quickReplies = ref([]);
const filter = ref({ status: '', priority: '', search: '' });
let poll = null;

// === Доп. участники чата ===
const participants = ref([]); // [{ id, userId, name, role, addedAt }]
const participantsDialog = ref(false);
const participantToAdd = ref(null);
const participantSaving = ref(false);

// Добавление ФК (партнёра) в участники: async-поиск по WebUser-логинам.
const partnerToAdd = ref(null);
const partnerOptions = ref([]); // [{ id: webUserId, name, code }]
const partnerSearching = ref(false);
const { debounced: searchPartners } = useDebounce(async (q) => {
  const term = (q || '').trim();
  if (term.length < 2) { partnerOptions.value = []; partnerSearching.value = false; return; }
  partnerSearching.value = true;
  try {
    const { data } = await api.get('/chat/partner-lookup', { params: { q: term } });
    partnerOptions.value = Array.isArray(data?.items) ? data.items : [];
  } catch { partnerOptions.value = []; }
  partnerSearching.value = false;
}, 350);

// Список сотрудников, которых можно добавить = staffList минус уже
// участвующие (включая created_by, recipient, assigned).
const addableStaff = computed(() => {
  const taken = new Set([
    ...participants.value.map(p => p.userId),
    Number(activeChat.value?.created_by) || 0,
    Number(activeChat.value?.recipient_id) || 0,
    Number(activeChat.value?.assigned_to) || 0,
  ]);
  return (staffList.value || []).filter(s => !taken.has(Number(s.id)));
});

// Аватарная строка «кто в работе» — рендерится в шапке чата. Состав:
//  1) assignee (звёздочка, primary-цвет) — единственный по claim & hide,
//     остальные staff отдела чат не видят;
//  2) явно приглашённые через chat_ticket_participants — серый.
// Дубли по userId режутся (если assigned_to случайно есть и в participants).
const chatAccessRow = computed(() => {
  const out = [];
  const seen = new Set();
  const assigneeId = Number(activeChat.value?.assigned_to) || 0;
  if (assigneeId) {
    out.push({
      userId: assigneeId,
      name: activeChat.value?.assigned_name || 'Сотрудник',
      kind: 'assignee',
      roleLabel: pShortRole(
        (staffList.value || []).find(s => Number(s.id) === assigneeId)?.role
      ),
    });
    seen.add(assigneeId);
  }
  for (const p of participants.value) {
    const uid = Number(p.userId);
    if (!uid || seen.has(uid)) continue;
    out.push({
      userId: uid,
      name: p.name || 'Сотрудник',
      kind: 'participant',
      roleLabel: pShortRole(p.role),
    });
    seen.add(uid);
  }
  return out;
});

function pInitials(name) {
  if (!name) return '?';
  const p = name.trim().split(/\s+/);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase() || '?';
}
function pShortRole(role) {
  // Партнёр (ФК) не имеет staff-роли — у него пустой/неизвестный role.
  if (!role) return 'ФК';
  const map = {
    admin: 'Админ', backoffice: 'Бэк-офис', support: 'Поддержка',
    head: 'Руководитель', finance: 'Финансы', calculations: 'Расчёты',
    corrections: 'Корректировки', education: 'Обучение',
    partner: 'ФК', consultant: 'ФК',
  };
  const first = String(role).split(',')[0].trim();
  return map[first] || first;
}
function pAddedAt(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

async function loadParticipants() {
  if (!activeChat.value?.id) { participants.value = []; return; }
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}/participants`);
    participants.value = Array.isArray(data) ? data : [];
  } catch {
    participants.value = [];
  }
}

function openParticipantsDialog() {
  participantToAdd.value = null;
  partnerToAdd.value = null;
  partnerOptions.value = [];
  participantsDialog.value = true;
  loadParticipants();
}

async function doAddParticipant(userId) {
  if (!userId || !activeChat.value?.id) return;
  participantSaving.value = true;
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/participants`, { user_id: userId });
    participantToAdd.value = null;
    partnerToAdd.value = null;
    partnerOptions.value = [];
    await loadParticipants();
    await refreshMessages();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось добавить');
  }
  participantSaving.value = false;
}
function addParticipant() { return doAddParticipant(participantToAdd.value); }
function addPartner() { return doAddParticipant(partnerToAdd.value); }

async function removeParticipant(p) {
  if (!confirm(`Убрать ${p.name} из чата?`)) return;
  try {
    await api.delete(`/chat/tickets/${activeChat.value.id}/participants/${p.userId}`);
    await loadParticipants();
    await refreshMessages();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось убрать');
  }
}

// Smart-views: быстрые фильтры по принадлежности тикета (как Intercom Inbox).
// Применяются на client-side после fetch — backend и так присылает <=25 строк.
const smartView = ref('all');
const countMine = computed(() =>
  chats.value.filter(t => String(t.assigned_to) === String(currentUserId)).length
);
const countUnassigned = computed(() =>
  chats.value.filter(t => !t.assigned_to).length
);
const countStale = computed(() =>
  chats.value.filter(t => isStale(t)).length
);
const filteredBySmartView = computed(() => {
  switch (smartView.value) {
    case 'mine': return chats.value.filter(t => String(t.assigned_to) === String(currentUserId));
    case 'unassigned': return chats.value.filter(t => !t.assigned_to);
    case 'stale': return chats.value.filter(t => isStale(t));
    default: return chats.value;
  }
});

// Socket
let socket = null;
const socketConnected = ref(true); // оптимистично; станет false на disconnect
const typingName = ref('');
let typingClearTimer = null;
let typingSendTimer = null;

// Scroll state
const showJumpToBottom = ref(false);
const pendingMessages = ref(0);
const BASE_TITLE = 'Чаты';

// Drag-drop
const dragOver = ref(false);

// Read receipts, reply, edit
const otherLastReadAt = ref(null);
const replyTo = ref(null);
const editing = ref(null);
const showHotkeys = ref(false);

// Tags (editable)
const currentTags = ref([]);
const addingTag = ref(false);
const newTag = ref('');

// Notes (internal)
const showNotes = ref(false);
const notes = ref([]);
const noteText = ref('');

// Partner context sidebar (right)
const partnerContext = ref(null);
const showContext = ref(localStorage.getItem('staff-chat-context') !== '0');
watch(showContext, v => localStorage.setItem('staff-chat-context', v ? '1' : '0'));

// Knowledge base
const showKb = ref(false);
const kbArticles = ref([]);
const kbLoading = ref(false);
async function loadKbSuggestions() {
  if (!activeChat.value) return;
  kbLoading.value = true;
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}/knowledge-suggest`);
    kbArticles.value = data.data || [];
  } catch { kbArticles.value = []; }
  kbLoading.value = false;
}
function toggleKb() {
  showKb.value = !showKb.value;
  if (showKb.value) loadKbSuggestions();
}
function insertKbArticle(a) {
  const content = a.content || '';
  msgText.value = msgText.value ? `${msgText.value}\n\n${content}` : content;
  nextTick(() => { taRef.value?.focus(); autoGrow(); });
  showKb.value = false;
}

// Save to FAQ
const saveFaqDialog = ref({ open: false, title: '', category: 'general', content: '', saving: false });
function openSaveFaq() {
  if (!activeChat.value) return;
  const content = messages.value
    .filter(m => !m.isSystem)
    .map(m => {
      const role = m.isAgent ? 'Сотрудник' : 'Клиент';
      return `**${role}** (${m.senderName}):\n${m.content || ''}`;
    })
    .join('\n\n');
  saveFaqDialog.value = {
    open: true,
    title: activeChat.value.subject || '',
    category: activeChat.value.department || activeChat.value.category || 'general',
    content,
    saving: false,
  };
}
async function submitSaveFaq() {
  if (!saveFaqDialog.value.title.trim() || !saveFaqDialog.value.content.trim()) return;
  saveFaqDialog.value.saving = true;
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/save-to-kb`, {
      title: saveFaqDialog.value.title,
      category: saveFaqDialog.value.category,
      content: saveFaqDialog.value.content,
    });
    saveFaqDialog.value.open = false;
    showSuccess('Статья добавлена в базу знаний');
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось сохранить');
  }
  saveFaqDialog.value.saving = false;
}

// In-chat message search
const messageSearch = ref({ open: false, query: '' });
const messageSearchMatches = computed(() => {
  const q = messageSearch.value.query.trim().toLowerCase();
  if (!q) return new Set();
  const hits = new Set();
  for (const m of messages.value) {
    if ((m.content || '').toLowerCase().includes(q)) hits.add(m.id);
  }
  return hits;
});
function openMessageSearch() {
  messageSearch.value.open = true;
  nextTick(() => document.querySelector('.msg-search-input')?.focus());
}
function closeMessageSearch() {
  messageSearch.value = { open: false, query: '' };
}

// Reactions
const REACTION_PALETTE = ['👍', '❤️', '😂', '🎉', '🙏', '✅'];

// Notifications (desktop + sound)
const notifyEnabled = ref(localStorage.getItem('staff-chat-notify') !== '0');
watch(notifyEnabled, v => localStorage.setItem('staff-chat-notify', v ? '1' : '0'));

// Двух-нотный «динь-дон» (E5 → A5, минорная терция вверх) с мягкой
// синусной огибающей и hi-pass envelope — звучит как у iOS-уведомлений,
// гораздо приятнее единичного beep'а 880→1320Hz.
function playPing() {
  if (!notifyEnabled.value) return;
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    const ctx = new Ctx();
    const now = ctx.currentTime;
    const master = ctx.createGain();
    master.gain.value = 0.18;
    master.connect(ctx.destination);

    const playNote = (freq, start, duration) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      // ADSR: быстрый attack, мягкий decay/release — без щелчка.
      gain.gain.setValueAtTime(0, now + start);
      gain.gain.linearRampToValueAtTime(1, now + start + 0.02);
      gain.gain.linearRampToValueAtTime(0.6, now + start + 0.08);
      gain.gain.exponentialRampToValueAtTime(0.001, now + start + duration);
      osc.connect(gain);
      gain.connect(master);
      osc.start(now + start);
      osc.stop(now + start + duration + 0.02);
    };

    playNote(659.25, 0, 0.18);   // E5
    playNote(880.00, 0.12, 0.32); // A5

    // Освободить AudioContext через секунду после звука.
    setTimeout(() => ctx.close().catch(() => {}), 700);
  } catch {}
}

function notifyDesktop(title, body) {
  if (!notifyEnabled.value) return;
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  try {
    const n = new Notification(title, { body, icon: '/favicon.ico', silent: false, tag: 'ds-chat' });
    n.onclick = () => { window.focus(); n.close(); };
  } catch {}
}

async function requestNotifPermission() {
  if (!('Notification' in window)) return;
  if (Notification.permission === 'default') {
    try { await Notification.requestPermission(); } catch {}
  }
}

// Pinning
async function togglePin(ticket, e) {
  e?.stopPropagation();
  const prev = ticket.pinned_at;
  ticket.pinned_at = prev ? null : new Date().toISOString();
  try {
    const { data } = await api.post(`/chat/tickets/${ticket.id}/pin`);
    ticket.pinned_at = data.pinnedAt;
    chats.value = [...chats.value].sort(sortChats);
  } catch {
    ticket.pinned_at = prev;
  }
}

// Удаление чата (admin-only). Полностью необратимо: backend сносит
// сообщения, заметки, реакции и вложения. Поэтому всегда подтверждаем.
async function deleteChat() {
  if (!activeChat.value) return;
  if (!canFullChat.value) return;
  const t = activeChat.value;
  if (!await confirmDialog.ask({
    title: 'Удалить чат?',
    message: `Чат «${t.subject}» и вся переписка будут удалены без возможности восстановления.`,
    confirmText: 'Удалить', confirmColor: 'error',
  })) return;
  try {
    await api.delete(`/chat/tickets/${t.id}`);
    chats.value = chats.value.filter(x => x.id !== t.id);
    activeChat.value = null;
    showSuccess('Чат удалён');
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось удалить чат');
  }
}
function sortChats(a, b) {
  // 1) Закреплённые в начало.
  const pa = a.pinned_at ? 1 : 0;
  const pb = b.pinned_at ? 1 : 0;
  if (pa !== pb) return pb - pa;
  // 2) Непрочитанные — выше прочитанных (внутри одной pinned-группы).
  //    Раньше сортировка была только по last_message_at, поэтому свежий
  //    «прочитанный» оттеснял непрочитанные вниз — оператор пропускал.
  const ua = (a.unread || 0) > 0 ? 1 : 0;
  const ub = (b.unread || 0) > 0 ? 1 : 0;
  if (ua !== ub) return ub - ua;
  // 3) В рамках своей группы — по свежести последнего сообщения.
  return new Date(b.last_message_at || 0) - new Date(a.last_message_at || 0);
}

// Размеры pane'ов под текущий набор видимых панелей. main всегда добирает
// остаток до 100% — пользовательский sidebar (и опционально context)
// сохраняют свою ширину независимо от того, открыта ли context-панель.
const effectivePaneSizes = computed(() => {
  const sidebarVisible = !mobile.value || !activeChat.value;
  const mainVisible = !mobile.value || activeChat.value;
  const contextVisible = !mobile.value && !!activeChat.value && showContext.value;

  const sidebar = sidebarVisible ? paneWidths.value.sidebar : 0;
  const context = contextVisible ? paneWidths.value.context : 0;
  const main = mainVisible ? Math.max(20, 100 - sidebar - context) : 0;
  return [sidebar, main, context];
});
const bulkMode = ref(false);
const selectedIds = ref(new Set());
watch(bulkMode, v => { if (!v) selectedIds.value = new Set(); });

function toggleBulk() {
  bulkMode.value = !bulkMode.value;
}
function toggleCardSelect(ticket, e) {
  e.stopPropagation();
  const ids = new Set(selectedIds.value);
  if (ids.has(ticket.id)) ids.delete(ticket.id);
  else ids.add(ticket.id);
  selectedIds.value = ids;
}
const anySelected = computed(() => selectedIds.value.size > 0);

async function bulkSetStatus(status) {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  if (!await confirmDialog.ask({
    title: 'Сменить статус тикетов?',
    message: `${ids.length} тикетов будут переведены в статус «${statuses.find(c => c.value === status)?.label}».`,
    confirmText: 'Сменить', confirmColor: 'primary',
  })) return;
  for (const id of ids) {
    const t = chats.value.find(x => x.id === id);
    if (!t || t.status === status) continue;
    const prev = t.status;
    t.status = status;
    try { await api.post(`/chat/tickets/${id}/status`, { status }); }
    catch { t.status = prev; }
  }
  selectedIds.value = new Set();
}

async function bulkAssign(userId, userName) {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  for (const id of ids) {
    const t = chats.value.find(x => x.id === id);
    if (!t) continue;
    try {
      await api.post(`/chat/tickets/${id}/assign`, { user_id: userId });
      t.assigned_to = userId;
      t.assigned_name = userName;
    } catch {}
  }
  selectedIds.value = new Set();
}

async function bulkSetPriority(priority) {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  for (const id of ids) {
    const t = chats.value.find(x => x.id === id);
    if (!t || t.priority === priority) continue;
    const prev = t.priority;
    t.priority = priority;
    try { await api.post(`/chat/tickets/${id}/status`, { status: t.status, priority }); }
    catch { t.priority = prev; }
  }
  selectedIds.value = new Set();
}

function shortName(n) {
  if (!n) return '';
  const parts = String(n).trim().split(/\s+/);
  if (parts.length >= 2) return `${parts[0]} ${(parts[1][0] || '').toUpperCase()}.`;
  return parts[0];
}

function isMine(msg) { return String(msg.senderId) === String(currentUserId); }

const priorities = [
  { label: 'Критический', value: 'critical', color: chatPriorityColors.critical },
  { label: 'Высокий', value: 'high', color: chatPriorityColors.high },
  { label: 'Средний', value: 'medium', color: chatPriorityColors.medium },
  { label: 'Низкий', value: 'low', color: chatPriorityColors.low },
];
const statuses = [
  { label: 'Новый', value: 'new', color: chatStatusColors.new, icon: 'mdi-circle-outline' },
  { label: 'В работе', value: 'open', color: chatStatusColors.open, icon: 'mdi-progress-clock' },
  { label: 'Ожидание', value: 'pending', color: chatStatusColors.pending, icon: 'mdi-pause-circle' },
  { label: 'Решён', value: 'resolved', color: chatStatusColors.resolved, icon: 'mdi-check-circle' },
  { label: 'Закрыт', value: 'closed', color: chatStatusColors.closed, icon: 'mdi-lock' },
];
const statusFilterPills = [{ label: 'Все', value: '' }, ...statuses.map(s => ({ label: s.label, value: s.value }))];
// «Все» — без color (Vuetify-default grey-token). Раньше был хардкод #888,
// который ломал тёмную тему.
const priorityFilterPills = [{ label: 'Все', value: '', color: 'grey' }, ...priorities];

const catColor = getChatCategoryColor;
function catIcon(c) {
  return ({
    backoffice: 'mdi-package-variant',
    accruals: 'mdi-credit-card-check',
    general: 'mdi-help-circle',
    // Legacy ключи — для старых тикетов в БД.
    support: 'mdi-headset', billing: 'mdi-credit-card-check',
    accounting: 'mdi-credit-card-check',
    legal: 'mdi-scale-balance',
    owner: 'mdi-shield-crown',
    technical: 'mdi-headset', sales: 'mdi-handshake',
  })[c] || 'mdi-chat';
}
const statusClr = getChatStatusColor;
function statusTxt(s) { return { new: 'Новый', assigned: 'Назначен', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function statusIcon(s) { return { new: 'mdi-circle-outline', assigned: 'mdi-account-arrow-right', open: 'mdi-progress-clock', pending: 'mdi-pause-circle', resolved: 'mdi-check-circle', closed: 'mdi-lock' }[s] || 'mdi-circle'; }
const prioClr = getChatPriorityColor;
function prioLabel(p) { return priorities.find(x => x.value === p)?.label || p; }
function initials(name) {
  if (!name) return '?';
  const parts = String(name).trim().split(/\s+/);
  return (parts[0]?.[0] || '').toUpperCase() + (parts[1]?.[0] || '').toUpperCase();
}
function ago(d) { if (!d) return ''; const s = Math.floor((Date.now() - new Date(d).getTime()) / 1000); if (s < 60) return 'сейчас'; if (s < 3600) return Math.floor(s/60) + 'м'; if (s < 86400) return Math.floor(s/3600) + 'ч'; return Math.floor(s/86400) + 'д'; }
function fmtTime(d) { if (!d) return ''; const dt = new Date(d); if (isNaN(dt)) return ''; return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }
function fmtDate(d) { if (!d) return ''; const dt = new Date(d); if (isNaN(dt)) return ''; return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }); }

/** Формат для info-карточки тикета: «12.05.2026 в 14:23». */
function fmtTicketCreated(d) {
  if (!d) return '';
  const dt = new Date(d);
  if (isNaN(dt)) return '';
  const date = dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const time = dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return `${date} в ${time}`;
}
function formatVolume(n) {
  const v = Number(n || 0);
  if (!v) return '0';
  if (v >= 1_000_000) return (v / 1_000_000).toFixed(1) + 'M';
  if (v >= 1_000) return (v / 1_000).toFixed(1) + 'K';
  return v.toFixed(2);
}
function activityChipBg(id) {
  const v = Number(id);
  if (v === 1) return 'rgba(52, 211, 153, 0.18)';   // Active
  if (v === 4) return 'rgba(96, 165, 250, 0.18)';   // Registered
  if (v === 3) return 'rgba(251, 191, 36, 0.20)';   // Terminated
  if (v === 5) return 'rgba(239, 68, 68, 0.15)';    // Excluded
  return 'rgba(107, 114, 128, 0.15)';
}
function activityChipFg(id) {
  return getChatActivityAccent(Number(id));
}
function dateLabel(date) {
  const d = new Date(date);
  const today = new Date();
  const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
  if (d.toDateString() === today.toDateString()) return 'Сегодня';
  if (d.toDateString() === yesterday.toDateString()) return 'Вчера';
  const diffDays = Math.abs((today - d) / 86400000);
  if (diffDays < 7) return d.toLocaleDateString('ru-RU', { weekday: 'long' });
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: today.getFullYear() === d.getFullYear() ? undefined : 'numeric' });
}

const IMAGE_EXT = /\.(jpe?g|png|gif|webp|bmp|svg)(\?|$)/i;
function isImageAttachment(path) { return !!path && IMAGE_EXT.test(path); }

const lightboxOpen = ref(false);
const lightboxSrc = ref('');
const lightboxAlt = ref('');
function openLightbox(src, alt) {
  lightboxSrc.value = src;
  lightboxAlt.value = alt || 'Изображение';
  lightboxOpen.value = true;
}
function fmtFileSize(bytes) { if (!bytes) return ''; if (bytes < 1024) return bytes + ' B'; if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'; return (bytes / 1024 / 1024).toFixed(1) + ' MB'; }
function parseTags(t) { if (!t) return []; if (Array.isArray(t)) return t; try { return JSON.parse(t); } catch { return []; } }

// SLA: per-priority пороги, как в Zendesk/Freshdesk SLA-policies.
// Раньше был единый 30 мин для всех — критичный «горел» так же как
// низкоприоритетный, и оператор не мог быстро понять что важно.
//   critical → 30 мин
//   high     → 2 ч
//   medium   → 8 ч
//   low      → 24 ч
const SLA_THRESHOLDS_MIN = { critical: 30, high: 120, medium: 480, low: 1440 };
function slaThresholdFor(t) {
  return SLA_THRESHOLDS_MIN[t?.priority || 'medium'] ?? SLA_THRESHOLDS_MIN.medium;
}
function isStale(t) {
  if (!t.last_message_at || ['resolved', 'closed'].includes(t.status)) return false;
  const mins = (Date.now() - new Date(t.last_message_at).getTime()) / 60000;
  return mins > slaThresholdFor(t);
}
const slaLabel = computed(() => {
  if (!activeChat.value?.last_message_at || ['resolved', 'closed'].includes(activeChat.value.status)) return '';
  const mins = Math.floor((Date.now() - new Date(activeChat.value.last_message_at).getTime()) / 60000);
  if (mins < 1) return 'только что';
  if (mins < 60) return `${mins} мин`;
  const hrs = Math.floor(mins / 60);
  return `${hrs} ч${mins % 60 ? ` ${mins % 60} мин` : ''}`;
});
const slaClass = computed(() => {
  if (!activeChat.value?.last_message_at) return '';
  const mins = (Date.now() - new Date(activeChat.value.last_message_at).getTime()) / 60000;
  const thr = slaThresholdFor(activeChat.value);
  if (mins > thr) return 'sla-danger';
  if (mins > thr / 2) return 'sla-warn';
  return '';
});

// Grouped messages with date dividers
const groupedMessages = computed(() => {
  const out = [];
  let prevDay = null;
  for (const msg of messages.value) {
    const day = msg.createdAt ? new Date(msg.createdAt).toDateString() : null;
    if (day && day !== prevDay) {
      out.push({ type: 'divider', key: `d-${day}`, label: dateLabel(msg.createdAt) });
      prevDay = day;
    }
    out.push({ type: 'msg', key: `m-${msg.id}`, msg });
  }
  return out;
});

function isAtBottom(threshold = 80) {
  const el = msgsRef.value;
  if (!el) return true;
  return el.scrollHeight - el.scrollTop - el.clientHeight < threshold;
}
function onMessagesScroll() {
  if (isAtBottom()) { showJumpToBottom.value = false; pendingMessages.value = 0; }
  else showJumpToBottom.value = true;
}
function scrollDown(force = false) {
  // Двойной nextTick + rAF: одного nextTick не хватает, когда в чате
  // есть картинки/файлы — высота контейнера ещё растёт после первого
  // тика и `scrollTop = scrollHeight` уезжает в середину переписки.
  // Повторно скроллим по событию `load` каждой картинки внутри
  // контейнера, чтобы догонять реальный scrollHeight.
  const doScroll = () => {
    const el = msgsRef.value;
    if (!el) return;
    if (force || isAtBottom()) {
      el.scrollTop = el.scrollHeight;
      pendingMessages.value = 0;
      showJumpToBottom.value = false;
    }
  };
  nextTick(() => {
    nextTick(() => {
      requestAnimationFrame(() => {
        doScroll();
        if (!force) return;
        const el = msgsRef.value;
        if (!el) return;
        // Догоняем после подгрузки картинок (≤ 3 сек, дольше — это уже
        // не «открытие чата», пусть пользователь сам прокрутит).
        const imgs = el.querySelectorAll('img');
        let pending = 0;
        const cleanup = setTimeout(() => { pending = 0; }, 3000);
        imgs.forEach(img => {
          if (img.complete) return;
          pending++;
          const onDone = () => {
            img.removeEventListener('load', onDone);
            img.removeEventListener('error', onDone);
            if (--pending <= 0) { clearTimeout(cleanup); doScroll(); }
          };
          img.addEventListener('load', onDone);
          img.addEventListener('error', onDone);
        });
      });
    });
  });
}

function autoGrow() {
  const t = taRef.value;
  if (!t) return;
  t.style.height = 'auto';
  t.style.height = Math.min(t.scrollHeight, 120) + 'px';
}
function onInput() { autoGrow(); }

// Draft autosave per ticket
function draftKey(ticketId) { return `staff-chat-draft-${ticketId}`; }
watch(msgText, (v) => {
  if (activeChat.value) {
    if (v) localStorage.setItem(draftKey(activeChat.value.id), v);
    else localStorage.removeItem(draftKey(activeChat.value.id));
  }
  nextTick(autoGrow);
  sendTyping();
});

function sendTyping() {
  if (!socket || !activeChat.value) return;
  if (typingSendTimer) return;
  socket.emit('ticket:typing', { ticketId: activeChat.value.id, isTyping: true });
  typingSendTimer = setTimeout(() => {
    socket?.emit('ticket:typing', { ticketId: activeChat.value?.id, isTyping: false });
    typingSendTimer = null;
  }, 2500);
}

function updateTitle() {
  const total = chats.value.reduce((s, t) => s + (t.unread || 0), 0);
  document.title = total > 0 ? `(${total}) ${BASE_TITLE}` : BASE_TITLE;
}
watch(chats, updateTitle, { deep: true });

// File handling
function addFiles(fileList) {
  if (!fileList) return;
  for (const f of Array.from(fileList)) {
    const previewUrl = f.type?.startsWith('image/') ? URL.createObjectURL(f) : null;
    files.value.push({ file: f, previewUrl });
  }
}
function removeFile(idx) {
  const item = files.value[idx];
  if (item?.previewUrl) URL.revokeObjectURL(item.previewUrl);
  files.value.splice(idx, 1);
}
function clearAllFiles() {
  for (const item of files.value) {
    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
  }
  files.value = [];
}
function onFileDrop(e) {
  dragOver.value = false;
  addFiles(e.dataTransfer?.files);
}
function onPaste(e) {
  const items = e.clipboardData?.items || [];
  const pasted = [];
  for (const it of items) {
    if (it.kind === 'file') {
      const f = it.getAsFile();
      if (f) pasted.push(f);
    }
  }
  if (pasted.length) {
    addFiles(pasted);
    e.preventDefault();
  }
}

// ВАЖНО: оборачиваем в стрелку без аргументов. Иначе значение из
// @update:model-value (строка поиска) прилетало в loadChats(append) как
// truthy → срабатывал режим дозагрузки (page не сбрасывался, результат
// мёржился в уже показанный полный список) → поиск «мелькал и пропадал».
const { debounced: debouncedLoad } = useDebounce(() => loadChats(), 400);

async function loadChats(append = false) {
  if (!append) {
    chatsPage.value = 1;
    loading.value = true;
  } else {
    loadingMore.value = true;
  }
  try {
    const params = { page: chatsPage.value, per_page: CHATS_PER_PAGE };
    if (filter.value.status) params.status = filter.value.status;
    if (filter.value.priority) params.priority = filter.value.priority;
    if (filter.value.search) params.search = filter.value.search;
    const { data } = await api.get('/chat/tickets', { params });
    chatsLastPage.value = data.last_page || 1;
    const incoming = data.data || [];
    // sortChats: pinned → непрочитанные → по дате. Бэкенд отдаёт
    // отсортированное по last_message_at, но «непрочитанные вверх»
    // — клиентское правило (unread считается per-user).
    if (append) {
      const seen = new Set(chats.value.map(c => c.id));
      const merged = chats.value.concat(incoming.filter(c => !seen.has(c.id)));
      chats.value = merged.sort(sortChats);
    } else {
      chats.value = incoming.slice().sort(sortChats);
    }
  } catch {}
  loading.value = false;
  loadingMore.value = false;
}

// Догрузка следующей страницы при прокрутке списка вниз.
async function loadMoreChats() {
  if (loadingMore.value || loading.value) return;
  if (chatsPage.value >= chatsLastPage.value) return;
  chatsPage.value += 1;
  await loadChats(true);
}

function onListScroll(e) {
  const el = e.target;
  if (el.scrollHeight - el.scrollTop - el.clientHeight < 240) {
    loadMoreChats();
  }
}

// Открытие конкретного тикета по id (используется для ?open=ID — переход
// из StartChatButton на странице Контракты/Клиенты/Транзакции и т.д.).
// Если тикет не попадает в текущий фильтр (например, фильтр «В работе»,
// а у нового тикета status=new), сначала сбрасываем status-фильтр и
// перезагружаем список — иначе пользователь увидит пустой правый блок.
async function openTicketById(id) {
  if (!id) return;
  let t = chats.value.find(c => Number(c.id) === Number(id));
  if (!t && filter.value.status) {
    filter.value.status = null;
    await loadChats();
    t = chats.value.find(c => Number(c.id) === Number(id));
  }
  if (t) {
    await openChat(t);
  } else {
    // Фильтр сняли, но тикет всё равно не нашёлся — открываем по stub'у
    // (openChat сам подгрузит /chat/tickets/{id} с messages и
    // partnerContext). Подписи вверху берутся из ticket-объекта,
    // поэтому подменим activeChat ответом сервера.
    try {
      const { data } = await api.get(`/chat/tickets/${id}`);
      if (data?.ticket) {
        await openChat({ ...data.ticket, unread: 0 });
      }
    } catch (e) {
      showError(e?.response?.data?.message || 'Не удалось открыть чат');
    }
  }
}

// При успешном переходе из ?open=ID убираем query, чтобы повторный
// клик по той же ссылке (или back/forward) не дёргал openTicketById
// без необходимости.
async function consumeOpenQuery() {
  const id = route.query.open;
  if (!id) return;
  await openTicketById(id);
  router.replace({ query: { ...route.query, open: undefined } });
}

async function openChat(t) {
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  activeChat.value = t;
  typingName.value = '';
  replyTo.value = null;
  editing.value = null;
  showNotes.value = false;
  t.unread = 0;
  try {
    const { data } = await api.get(`/chat/tickets/${t.id}`);
    messages.value = data.messages || [];
    otherLastReadAt.value = data.otherLastReadAt || null;
    partnerContext.value = data.partnerContext || null;
    currentTags.value = parseTags(t.tags);
    scrollDown(true);
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось загрузить чат');
  }

  msgText.value = localStorage.getItem(draftKey(t.id)) || '';
  nextTick(() => { taRef.value?.focus(); autoGrow(); });

  if (socket) socket.emit('ticket:join', t.id);
  startPoll();
  loadNotes();
  loadParticipants();
  if (showKb.value) loadKbSuggestions();
}

function closeActiveChat() {
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  activeChat.value = null;
  typingName.value = '';
  showNotes.value = false;
}

async function refreshMessages() {
  if (!activeChat.value) return;
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}`);
    const wasAtBottom = isAtBottom();
    const prevCount = messages.value.length;
    messages.value = data.messages || [];
    otherLastReadAt.value = data.otherLastReadAt || null;
    if (messages.value.length > prevCount) {
      if (wasAtBottom) scrollDown(true);
      else pendingMessages.value += messages.value.length - prevCount;
    }
  } catch {}
}

function isSeen(msg) {
  if (!otherLastReadAt.value || !msg.createdAt) return false;
  return new Date(otherLastReadAt.value) >= new Date(msg.createdAt);
}
function canEdit(msg) {
  if (!isMine(msg) || msg.isSystem) return false;
  if (!msg.createdAt) return false;
  return (Date.now() - new Date(msg.createdAt).getTime()) / 60000 <= 5;
}

function startReply(msg) {
  replyTo.value = { id: msg.id, senderName: msg.senderName, content: msg.content };
  nextTick(() => taRef.value?.focus());
}
function cancelReply() { replyTo.value = null; }
function startEdit(msg) { editing.value = { id: msg.id, content: msg.content }; }
function cancelEdit() { editing.value = null; }
async function saveEdit() {
  if (!editing.value) return;
  const newText = editing.value.content.trim();
  if (!newText) return;
  try {
    await api.put(`/chat/messages/${editing.value.id}`, { content: newText });
    const m = messages.value.find(x => String(x.id) === String(editing.value.id));
    if (m) { m.content = newText; m.editedAt = new Date().toISOString(); }
    editing.value = null;
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось изменить');
  }
}

async function toggleReaction(msg, emoji) {
  msg.reactions = msg.reactions || [];
  const existing = msg.reactions.find(r => r.emoji === emoji);
  if (existing) {
    if (existing.mine) {
      existing.count--;
      existing.mine = false;
      if (existing.count <= 0) msg.reactions = msg.reactions.filter(r => r.emoji !== emoji);
    } else {
      existing.count++;
      existing.mine = true;
    }
  } else {
    msg.reactions.push({ emoji, count: 1, mine: true });
  }
  try { await api.post(`/chat/messages/${msg.id}/reactions`, { emoji }); }
  catch { refreshMessages(); }
}

async function send() {
  // Vuetify v-textarea (auto-grow, v3.12) дублирует переносы строк при вводе:
  // один Shift+Enter сохраняется как «\n\n», и длинные сообщения превращаются
  // в «простыню». Схлопываем кратные переносы обратно — ceil(n/2): \n\n→\n,
  // \n\n\n\n→\n\n. При n=1 не меняем (safe, если баг исчезнет после апгрейда).
  const text = (msgText.value || '')
    .replace(/\r\n/g, '\n')
    .replace(/\n+/g, (m) => '\n'.repeat(Math.ceil(m.length / 2)))
    .trim();
  const fileItems = files.value.slice();
  if (!text && !fileItems.length) return;
  sending.value = true;
  const newClientId = () => (crypto?.randomUUID?.() ?? `cmid-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  const replyId = replyTo.value?.id ?? null;
  try {
    if (!fileItems.length) {
      const fd = new FormData();
      fd.append('message', text);
      fd.append('client_message_id', newClientId());
      if (replyId) fd.append('reply_to_id', String(replyId));
      await api.post(`/chat/tickets/${activeChat.value.id}/messages`, fd);
    } else {
      // Несколько файлов: текст идёт с первым, остальные — только файл.
      // Последовательно, чтобы порядок в БД совпадал с порядком выбора.
      for (let i = 0; i < fileItems.length; i++) {
        const fd = new FormData();
        fd.append('message', i === 0 ? text : '');
        fd.append('client_message_id', newClientId());
        fd.append('attachment', fileItems[i].file);
        if (replyId) fd.append('reply_to_id', String(replyId));
        await api.post(`/chat/tickets/${activeChat.value.id}/messages`, fd);
      }
    }
    localStorage.removeItem(draftKey(activeChat.value.id));
    msgText.value = '';
    clearAllFiles();
    replyTo.value = null;
    nextTick(autoGrow);
    await refreshMessages();
    scrollDown(true);
    activeChat.value.unread = 0;
    loadChats();
    taRef.value?.focus();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось отправить сообщение');
  }
  sending.value = false;
}

async function setStatus(status) {
  try {
    // Для тикетов-инцидентов (is_incident=true) при переводе в «Решён»
    // используем дедикейтед-эндпоинт /incident/resolve — он шлёт в чат
    // полноценное сообщение «✅ Инцидент #N решён. Исполнитель: …»
    // вместо короткой системки «Статус изменён → Решён».
    if (status === 'resolved' && activeChat.value?.is_incident) {
      await api.post(`/chat/tickets/${activeChat.value.id}/incident/resolve`);
      activeChat.value.status = 'resolved';
    } else {
      await api.post(`/chat/tickets/${activeChat.value.id}/status`, { status });
      activeChat.value.status = status;
    }
    await refreshMessages();
    loadChats();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось изменить статус');
  }
}

async function setPriority(priority) {
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/status`, { status: activeChat.value.status, priority });
    activeChat.value.priority = priority;
    // Бэкенд теперь пишет в чат системку «Приоритет изменён: X → Y» —
    // обновим ленту, чтобы оператор её увидел сразу.
    await refreshMessages();
    loadChats();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось изменить приоритет');
  }
}

async function assignTo(userId, name) {
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/assign`, { user_id: userId });
    activeChat.value.assigned_to = userId;
    activeChat.value.assigned_name = name;
    await refreshMessages();
  } catch (e) {
    showError(e?.response?.data?.message || 'Не удалось назначить ответственного');
  }
}

// Tags
function addTag() {
  const t = newTag.value.trim();
  if (t && !currentTags.value.includes(t)) {
    currentTags.value.push(t);
    syncTags();
  }
  newTag.value = '';
  addingTag.value = false;
}
function cancelAddTag() {
  newTag.value = '';
  addingTag.value = false;
}
function removeTag(tag) {
  currentTags.value = currentTags.value.filter(t => t !== tag);
  syncTags();
}
async function syncTags() {
  if (!activeChat.value) return;
  try {
    // Reuse status endpoint to send tags alongside (same validation)
    await api.post(`/chat/tickets/${activeChat.value.id}/status`, {
      status: activeChat.value.status,
      tags: currentTags.value,
    });
    activeChat.value.tags = JSON.stringify(currentTags.value);
  } catch (e) {
    showError('Не удалось сохранить теги');
  }
}
watch(addingTag, (v) => { if (v) nextTick(() => tagInput.value?.focus()); });

// Notes
function toggleNotes() {
  showNotes.value = !showNotes.value;
  if (showNotes.value) loadNotes();
}
async function loadNotes() {
  if (!activeChat.value) return;
  try {
    const { data } = await api.get(`/chat/tickets/${activeChat.value.id}/notes`);
    notes.value = data.data || data || [];
  } catch {}
}
async function addNote() {
  const text = noteText.value.trim();
  if (!text || !activeChat.value) return;
  try {
    await api.post(`/chat/tickets/${activeChat.value.id}/notes`, { content: text });
    noteText.value = '';
    await loadNotes();
  } catch (e) {
    showError('Не удалось добавить заметку');
  }
}

// Quick replies
function insertQuickReply(q) {
  const content = (q.content || '')
    .replace(/\{client_name\}/g, activeChat.value?.customer_name || '')
    .replace(/\{ticket_id\}/g, activeChat.value?.id || '')
    .replace(/\{staff_name\}/g, currentUserName.value)
    .replace(/\{agent_name\}/g, currentUserName.value);
  msgText.value = msgText.value ? `${msgText.value}\n${content}` : content;
  nextTick(() => { taRef.value?.focus(); autoGrow(); });
}

// --- CRUD шаблонов быстрых ответов ---
// is_own — личный шаблон, всегда можно править; is_shared — общий,
// править/удалять может любой staff (этот файл — staff-чат, сюда
// партнёры всё равно не попадают, но проверку оставим явной).
const STAFF_ROLES_RE = /admin|backoffice|support|head|finance|calculations|corrections|education/i;
const isStaffUser = computed(() => STAFF_ROLES_RE.test(auth.user?.role || ''));
function canEditQuickReply(q) {
  return !!q.is_own || (q.is_shared && isStaffUser.value);
}

const qrDialog = ref(false);
const qrSaving = ref(false);
const qrForm = ref({ id: null, title: '', content: '', category: 'Личные', shortcut: '' });

function openQuickReplyEditor(q) {
  qrForm.value = q
    ? { id: q.id, title: q.title || '', content: q.content || '', category: q.category || 'Личные', shortcut: q.shortcut || '' }
    : { id: null, title: '', content: '', category: 'Личные', shortcut: '' };
  qrDialog.value = true;
}

async function saveQuickReply() {
  if (!qrForm.value.title.trim() || !qrForm.value.content.trim()) {
    showError('Заголовок и текст обязательны');
    return;
  }
  qrSaving.value = true;
  try {
    const payload = {
      title: qrForm.value.title.trim(),
      content: qrForm.value.content,
      category: qrForm.value.category || 'Личные',
      shortcut: qrForm.value.shortcut || null,
    };
    if (qrForm.value.id) {
      await api.put(`/chat/quick-replies/${qrForm.value.id}`, payload);
    } else {
      await api.post('/chat/quick-replies', payload);
    }
    await reloadQuickReplies();
    qrDialog.value = false;
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось сохранить шаблон');
  }
  qrSaving.value = false;
}

async function deleteQuickReply(q) {
  if (!confirm(`Удалить шаблон «${q.title}»?`)) return;
  try {
    await api.delete(`/chat/quick-replies/${q.id}`);
    await reloadQuickReplies();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить шаблон');
  }
}

async function reloadQuickReplies() {
  try {
    const { data } = await api.get('/chat/quick-replies');
    quickReplies.value = data.data || data || [];
  } catch {}
}

// Polling работает только пока вкладка активна. Скрытая вкладка
// получит обновления через socket-events, отдельный setInterval
// просто жжёт батарею и нагружает API.
function startPoll() {
  stopPoll();
  if (document.hidden) return;
  poll = setInterval(() => { refreshMessages(); loadChats(); }, 15000);
}
function stopPoll() { if (poll) { clearInterval(poll); poll = null; } }
function onVisibilityChange() {
  if (document.hidden) {
    stopPoll();
  } else if (activeChat.value) {
    refreshMessages();
    loadChats();
    startPoll();
  }
}

// Socket
async function connectSocket() {
  const token = auth.token;
  if (!token) return;
  try {
    const { io } = await import('socket.io-client');
    // Priority: explicit override -> local dev on :3001 -> same-origin (nginx proxy on prod)
    const isLocal = ['localhost', '127.0.0.1'].includes(location.hostname);
    const defaultHost = isLocal
      ? `ws://${location.hostname}:3001`
      : `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}`;
    const host = window.__SOCKET_URL__ || defaultHost;
    socket = io(host, { auth: { token }, transports: ['websocket', 'polling'], reconnection: true });

    // Connection status — раньше падение socket'а было невидимым, оператор
    // не понимал что real-time ушёл и приходится ждать polling.
    socket.on('connect', () => { socketConnected.value = true; });
    socket.on('disconnect', () => { socketConnected.value = false; });
    socket.on('connect_error', () => { socketConnected.value = false; });

    socket.on('chat:new-message', (m) => {
      const isOwn = String(m.senderId) === String(currentUserId);
      const isActive = activeChat.value && Number(m.ticketId) === Number(activeChat.value.id);

      if (isActive && !messages.value.some(x => String(x.id) === String(m.id))) {
        const wasAtBottom = isAtBottom();
        messages.value.push({
          id: m.id, senderId: m.senderId, senderName: m.senderName,
          content: m.content, isSystem: false, createdAt: m.createdAt,
        });
        if (wasAtBottom) scrollDown(true); else pendingMessages.value++;
      }

      // Звук на любое не-своё сообщение (активный чат тоже). Desktop-
      // нотификация — только когда вкладка скрыта или это другой чат,
      // иначе всплывало бы лишнее у оператора, который и так смотрит.
      if (!isOwn) {
        playPing();
        if (document.hidden || !isActive) {
          notifyDesktop(m.senderName || 'Новое сообщение',
            (m.content || '').slice(0, 120) || 'Прислали сообщение');
        }
      }

      loadChats();
    });
    socket.on('ticket:typing', (e) => {
      if (!activeChat.value || String(e.userId) === String(currentUserId)) return;
      const name = (e.userName || '').trim();
      typingName.value = (name && name !== 'Unknown') ? name : 'Собеседник';
      if (typingClearTimer) clearTimeout(typingClearTimer);
      typingClearTimer = setTimeout(() => { typingName.value = ''; }, 3500);
    });
    socket.on('chat:new-ticket', () => loadChats());
    socket.on('chat:message-edited', (e) => {
      if (!activeChat.value || Number(e.ticketId) !== Number(activeChat.value.id)) return;
      const m = messages.value.find(x => String(x.id) === String(e.id));
      if (m) { m.content = e.content; m.editedAt = e.editedAt; }
    });

    socket.on('chat:reaction-toggled', (e) => {
      if (!activeChat.value || Number(e.ticketId) !== Number(activeChat.value.id)) return;
      if (String(e.userId) === String(currentUserId)) return;
      const msg = messages.value.find(m => String(m.id) === String(e.messageId));
      if (!msg) return;
      msg.reactions = msg.reactions || [];
      const r = msg.reactions.find(x => x.emoji === e.emoji);
      if (e.action === 'added') {
        if (r) r.count++;
        else msg.reactions.push({ emoji: e.emoji, count: 1, mine: false });
      } else if (e.action === 'removed') {
        if (r) { r.count--; if (r.count <= 0) msg.reactions = msg.reactions.filter(x => x.emoji !== e.emoji); }
      }
    });

    // Удаление тикета другим админом — убираем карточку из списка
    // и закрываем активный чат, если открыт именно он.
    socket.on('chat:ticket-deleted', (e) => {
      const id = Number(e.ticketId);
      chats.value = chats.value.filter(x => Number(x.id) !== id);
      if (activeChat.value && Number(activeChat.value.id) === id) {
        activeChat.value = null;
        showError('Этот чат был удалён администратором');
      }
    });

    // Ticket updates from other staff (status / priority / assignee / pin)
    socket.on('chat:ticket-updated', (e) => {
      const t = chats.value.find(x => Number(x.id) === Number(e.ticketId));
      if (!t) { loadChats(); return; }
      if (e.status !== undefined) t.status = e.status;
      if (e.priority !== undefined) t.priority = e.priority;
      if (e.assignedTo !== undefined) t.assigned_to = e.assignedTo;
      if (e.assignedName !== undefined) t.assigned_name = e.assignedName;
      if (e.tags !== undefined) t.tags = e.tags;
      if (e.subject !== undefined) t.subject = e.subject;
      if (e.pinnedAt !== undefined) { t.pinned_at = e.pinnedAt; chats.value = [...chats.value].sort(sortChats); }
      if (activeChat.value && Number(activeChat.value.id) === Number(e.ticketId)) {
        if (e.status !== undefined) activeChat.value.status = e.status;
        if (e.priority !== undefined) activeChat.value.priority = e.priority;
        if (e.assignedTo !== undefined) activeChat.value.assigned_to = e.assignedTo;
        if (e.assignedName !== undefined) activeChat.value.assigned_name = e.assignedName;
        if (e.subject !== undefined && !editingSubject.value) activeChat.value.subject = e.subject;
        if (e.pinnedAt !== undefined) activeChat.value.pinned_at = e.pinnedAt;
        // Если поменялся assignee — состав «В работе» в шапке тоже может
        // измениться (новый отображается со звёздочкой, старый — пропадает
        // из строки, если не был приглашён участником). Тянем заново.
        if (e.assignedTo !== undefined) loadParticipants();
      }
      // Claim & hide: если ассайн изменился НЕ на меня — у меня мог пропасть
      // (или появиться) этот тикет в списке. Перетягиваем chats с backend'а,
      // он отфильтрует авторитетно по правилу «assigned_to IS NULL OR me OR
      // created_by/recipient/participant». Случаи: (а) ticket назначили
      // другому → у меня уходит из списка; (б) ticket освободили (null) →
      // возвращается для всех staff отдела. Случай «назначили мне» — list
      // уже корректен (e.ticketId был в моём списке как unassigned).
      if (e.assignedTo !== undefined
          && Number(e.assignedTo) !== Number(currentUserId)) {
        loadChats();
      }
    });
  } catch (e) {
    if (import.meta.env.DEV) console.warn('Chat socket unavailable, falling back to polling:', e?.message);
  }
}

// Global keyboard shortcuts (Intercom Inbox / Linear-style — оператор
// работает 80% действий с клавиатуры).
//   J / ↓     следующий тикет
//   K / ↑     предыдущий тикет
//   R         фокус на ответ
//   A         меню «Назначить»
//   E         закрыть тикет (resolve)
//   #         меню «Статус»
//   /         фокус поиска
//   Ctrl+K    поиск по сообщениям
//   ?         показать справку
//   Esc       закрыть тикет / поиск
function jumpToTicket(delta) {
  const list = filteredBySmartView.value;
  if (!list.length) return;
  const idx = activeChat.value ? list.findIndex(t => t.id === activeChat.value.id) : -1;
  const next = idx === -1 ? 0 : Math.max(0, Math.min(list.length - 1, idx + delta));
  if (list[next] && (idx === -1 || list[next].id !== activeChat.value?.id)) {
    openChat(list[next]);
  }
}

function onGlobalKey(e) {
  const tag = e.target?.tagName;
  const inField = tag === 'INPUT' || tag === 'TEXTAREA' || e.target?.isContentEditable;

  // Ctrl/Cmd combos работают всегда
  if ((e.ctrlKey || e.metaKey) && e.key === '/') { e.preventDefault(); showHotkeys.value = !showHotkeys.value; return; }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && activeChat.value) {
    e.preventDefault();
    openMessageSearch();
    return;
  }

  if (e.key === 'Escape') {
    if (messageSearch.value.open) { closeMessageSearch(); return; }
    if (showHotkeys.value) { showHotkeys.value = false; return; }
    if (editing.value) { cancelEdit(); return; }
    if (replyTo.value) { cancelReply(); return; }
    if (addingTag.value) { cancelAddTag(); return; }
    if (activeChat.value && !inField) { closeActiveChat(); return; }
  }

  // Single-key shortcuts работают только когда курсор НЕ в input/textarea.
  if (inField || e.ctrlKey || e.metaKey || e.altKey) return;

  switch (e.key) {
    case '?':
      e.preventDefault(); showHotkeys.value = !showHotkeys.value; return;
    case 'j':
    case 'ArrowDown':
      e.preventDefault(); jumpToTicket(+1); return;
    case 'k':
    case 'ArrowUp':
      e.preventDefault(); jumpToTicket(-1); return;
    case 'r':
      if (activeChat.value && taRef.value) {
        e.preventDefault();
        taRef.value.focus();
      }
      return;
    case 'e':
      if (activeChat.value && activeChat.value.status !== 'closed') {
        e.preventDefault();
        setStatus('resolved');
      }
      return;
    case '/':
      // Фокус глобального поиска по тикетам
      e.preventDefault();
      const searchInput = document.querySelector('.sidebar-search-row input');
      if (searchInput) searchInput.focus();
      return;
  }
}

onMounted(async () => {
  await loadChats();
  await consumeOpenQuery();
  connectSocket();
  window.addEventListener('keydown', onGlobalKey);
  document.addEventListener('visibilitychange', onVisibilityChange);
  requestNotifPermission();
  try { const { data } = await api.get('/chat/tickets/staff'); staffList.value = data || []; } catch {}
  try { const { data } = await api.get('/chat/quick-replies'); quickReplies.value = data.data || data || []; } catch {}
});

// Если пользователь уже на /manage/chat и кликнул StartChatButton ещё раз
// (роутер не размонтирует компонент, onMounted не повторится), реагируем
// на изменение query.open.
watch(() => route.query.open, async (id) => {
  if (id) await consumeOpenQuery();
});
onUnmounted(() => {
  stopPoll();
  if (socket && activeChat.value) socket.emit('ticket:leave', activeChat.value.id);
  socket?.disconnect();
  document.title = BASE_TITLE;
  window.removeEventListener('keydown', onGlobalKey);
  document.removeEventListener('visibilitychange', onVisibilityChange);
});
</script>

<style scoped>
.chat-wrap { display: flex; height: 100%; min-height: 0; overflow: hidden; position: relative; }

/* Splitpanes — drag-resizable layout (2 или 3 pane'а). Apple-style:
   тонкие сепараторы, нежный hover, без жёстких рамок. */
.chat-splitpanes { flex: 1; min-width: 0; }
.chat-splitpanes :deep(.splitpanes__pane) {
  background: rgb(var(--v-theme-surface));
  transition: none;
  overflow: hidden;
}
.chat-splitpanes :deep(.splitpanes__splitter) {
  position: relative;
  flex: 0 0 1px;
  background: rgba(var(--v-border-color), 0.08);
  cursor: col-resize;
  transition: background 0.18s ease;
}
.chat-splitpanes :deep(.splitpanes__splitter)::before {
  content: '';
  position: absolute;
  top: 0; bottom: 0;
  left: -4px; right: -4px;
  z-index: 1;
}
.chat-splitpanes :deep(.splitpanes__splitter)::after {
  content: '';
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 2px;
  height: 32px;
  border-radius: 1px;
  background: rgba(var(--v-theme-on-surface), 0.16);
  opacity: 0;
  transition: opacity 0.2s ease, background 0.18s ease, height 0.2s ease;
}
.chat-splitpanes :deep(.splitpanes__splitter:hover),
.chat-splitpanes :deep(.splitpanes__splitter.splitpanes__splitter--dragging) {
  background: rgba(var(--v-theme-primary), 0.45);
}
.chat-splitpanes :deep(.splitpanes__splitter:hover)::after,
.chat-splitpanes :deep(.splitpanes__splitter.splitpanes__splitter--dragging)::after {
  opacity: 1;
  background: rgb(var(--v-theme-primary));
}
.chat-splitpanes :deep(.splitpanes__splitter.splitpanes__splitter--dragging)::after { height: 48px; }
/* В kanban-режиме splitter остаётся активным — оператор может тянуть
   ширину sidebar, и она сохраняется (общая с list-режимом, см.
   paneSizes/PANE_STORAGE_KEY). */
/* Sidebar — Linear-style: 320px, тонкие dividers, компактная плотность */
/* Sidebar — ширина управляется splitpanes-pane'ом снаружи. */
.chat-sidebar {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  background: rgb(var(--v-theme-surface));
  position: relative;
}
.sidebar-head {
  position: sticky; top: 0; z-index: 3;
  display: flex; align-items: center;
  background: rgba(var(--v-theme-surface), 0.82);
  backdrop-filter: saturate(180%) blur(20px);
  -webkit-backdrop-filter: saturate(180%) blur(20px);
  border-bottom: 1px solid rgba(var(--v-border-color), 0.06);
  min-height: 44px;
}
.sidebar-list { flex: 1; overflow-y: auto; padding: 4px 6px 8px; }

/* Apple-style thin scrollbar — tone-on-tone, ничего не отвлекает. */
.sidebar-list,
.chat-messages,
.context-body { scrollbar-width: thin; scrollbar-color: rgba(var(--v-theme-on-surface), 0.18) transparent; }
.sidebar-list::-webkit-scrollbar,
.chat-messages::-webkit-scrollbar,
.context-body::-webkit-scrollbar { width: 8px; height: 8px; }
.sidebar-list::-webkit-scrollbar-thumb,
.chat-messages::-webkit-scrollbar-thumb,
.context-body::-webkit-scrollbar-thumb {
  background: rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 4px;
  border: 2px solid transparent;
  background-clip: padding-box;
}
.sidebar-list::-webkit-scrollbar-thumb:hover,
.chat-messages::-webkit-scrollbar-thumb:hover,
.context-body::-webkit-scrollbar-thumb:hover { background: rgba(var(--v-theme-on-surface), 0.28); background-clip: padding-box; }

/* Chat item — единый стиль: компактный, тонкие границы, плавный hover */
.chat-item {
  display: flex; align-items: flex-start; gap: 11px;
  padding: 11px 12px; cursor: pointer; position: relative;
  border-radius: 12px;
  margin: 1px 0;
  transition: background 0.2s ease, transform 0.18s ease;
}
.chat-item:hover { background: rgba(var(--v-theme-on-surface), 0.05); }
.chat-item:active { transform: scale(0.985); }
.chat-item.active { background: rgba(var(--v-theme-primary), 0.1); }
.chat-item.active .chat-item-subject { color: rgb(var(--v-theme-primary)); font-weight: 600; }
.chat-item.stale { background: rgba(var(--v-theme-error), 0.05); }
.chat-item-avatar {
  width: 38px; height: 38px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 1px;
  box-shadow:
    0 1px 2px rgba(0, 0, 0, 0.06),
    inset 0 0 0 1px rgba(255, 255, 255, 0.12);
  transition: transform 0.2s ease;
}
.chat-item:hover .chat-item-avatar { transform: scale(1.04); }
.chat-item-avatar { overflow: hidden; }
.chat-item-avatar-img { width: 100%; height: 100%; }
.chat-item-avatar-initials { color: #fff; font-size: 13px; font-weight: 700; letter-spacing: -0.3px; }
.priority-bar { position: absolute; top: 8px; bottom: 8px; left: 4px; width: 2px; border-radius: 1px; }
.chat-item-body { flex: 1; min-width: 0; }
.chat-item-top { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; min-width: 0; }
.chat-item-subject {
  flex: 1 1 auto;
  min-width: 0;
  display: block;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}
.chat-item-time { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.45); flex-shrink: 0; font-variant-numeric: tabular-nums; }
.chat-item-time.stale { color: rgb(var(--v-theme-error)); font-weight: 600; }
/* Одна строка, обрезаем многоточием — иначе при двух ФИО + чипе статуса
   wrap переносил статус на вторую строку, она наезжала на следующую
   карточку и текст «→ Лаврова Ирина» визуально обрезался сверху. */
.chat-item-bottom { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.55); margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; min-width: 0; overflow: hidden; line-height: 1.5; }
.chat-item-bottom .customer,
.chat-item-bottom .recipient { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.chat-item-bottom .chat-item-status-chip { flex-shrink: 0; }
.roles-grid { display: grid; grid-template-columns: 90px 1fr; align-items: center; gap: 5px 8px; }
.role-label { font-size: 11px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.45); text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
.role-value { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; min-width: 0; }
.role-person { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.85); }
.chat-item-roles { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.role-chip { display: inline-flex; align-items: center; gap: 2px; font-size: 10px; padding: 1px 5px; border-radius: 4px; white-space: nowrap; }
.role-assignee { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary)); }
.role-creator  { background: rgba(var(--v-theme-secondary), 0.12); color: rgb(var(--v-theme-secondary)); }
.role-observer { background: rgba(var(--v-theme-on-surface), 0.07); color: rgba(var(--v-theme-on-surface), 0.55); }
/* «Новый для вас»: акцентируем через theme.primary (раньше был
   inline-хардкод #a78bfa, не реагировал на тёмную тему). */
.chip-new-for-me {
  background: rgba(var(--v-theme-primary), 0.15);
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}
.chat-item-preview { display: flex; gap: 4px; margin-top: 2px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; line-height: 1.3; }
.chat-item-preview-prefix { color: rgba(var(--v-theme-on-surface), 0.4); flex-shrink: 0; }
.chat-item-preview-text { overflow: hidden; text-overflow: ellipsis; }
.chat-item.has-unread .chat-item-subject { font-weight: 600; color: rgb(var(--v-theme-on-surface)); }
.chat-item.has-unread .chat-item-preview { color: rgba(var(--v-theme-on-surface), 0.85); }
.conn-banner { position: absolute; top: 0; left: 0; right: 0; z-index: 100; padding: 6px 12px; background: rgba(var(--v-theme-warning), 0.15); color: rgb(var(--v-theme-warning)); font-size: 12px; display: flex; align-items: center; gap: 6px; }
.customer { font-weight: 500; }
.recipient { color: rgba(var(--v-theme-on-surface), 0.6); }
.unread-badge { position: absolute; right: 12px; top: 10px; background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; min-width: 18px; text-align: center; line-height: 1.4; }
.csat-badge { position: absolute; right: 12px; top: 10px; background: rgba(245, 165, 36, 0.15); color: #f5a524; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; line-height: 1.4; }
.chat-item.pinned { background: rgba(var(--v-theme-primary), 0.03); }
.chat-item-pin { position: absolute; right: 8px; bottom: 8px; background: none; border: none; padding: 2px; border-radius: 4px; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.3); opacity: 0; transition: opacity 0.15s, color 0.15s; }
.chat-item:hover .chat-item-pin { opacity: 1; }
.chat-item-pin.active { color: rgb(var(--v-theme-primary)); opacity: 1; }

.chat-main {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  min-width: 0;
  position: relative;
  background: rgb(var(--v-theme-surface));
}
.chat-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; }

/* Apple-style sticky header с blur. */
.chat-header {
  position: sticky; top: 0; z-index: 3;
  background: rgba(var(--v-theme-surface), 0.82);
  backdrop-filter: saturate(180%) blur(20px);
  -webkit-backdrop-filter: saturate(180%) blur(20px);
  border-bottom: 1px solid rgba(var(--v-border-color), 0.06);
  display: flex; align-items: flex-start; gap: 10px;
}
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-actions { flex-shrink: 0; }
/* Карандаш переименования — variant=tonal даёт зелёный чип-фон, видно
   на любой теме (важно для AdminLayout с forced dark). Без opacity-
   скрытия — пользователь должен сразу понимать, что чат можно
   переименовать (это критичный UX: поиск работает только по subject). */
.chat-subject-row :deep(.v-btn) { transition: transform 0.15s; }
.chat-subject-row :deep(.v-btn:hover) { transform: scale(1.08); }
.btn-back { background: none; border: none; cursor: pointer; color: inherit; padding: 4px; }
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-subject { font-size: 14px; font-weight: 700; }
.chat-header-meta { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.5); margin-top: 2px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.customer-name { font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.8); }
.meta-status-chip, .meta-priority-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 10px; font-weight: 600; font-size: 11px; }
.recipient-tag { background: rgba(249,115,22,0.15); color: #f97316; padding: 1px 8px; border-radius: 4px; font-size: 11px; display: flex; align-items: center; gap: 3px; }
.sla-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), 0.06); font-size: 11px; font-weight: 600; }
.sla-chip.sla-warn { background: rgba(251,191,36,0.18); color: #c27803; }
.sla-chip.sla-danger { background: rgba(239,68,68,0.15); color: #dc2626; }
.chat-header-actions { display: flex; gap: 4px; }
.action-btn { background: none; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 6px; cursor: pointer; color: inherit; transition: all 0.15s; }
.action-btn:hover { background: rgba(var(--v-theme-primary), 0.08); }
.action-btn.active { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary)); border-color: rgba(var(--v-theme-primary), 0.4); }

.chat-tags { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; padding: 6px 16px; border-bottom: 1px solid rgba(var(--v-border-color), 0.2); }
.tag-input-field { max-width: 180px; }

/* Notes panel */
/* notes-panel — Vuetify v-card variant="tonal" color="warning" */
.notes-panel { max-height: 360px; display: flex; flex-direction: column; }
.note-item { background: rgba(var(--v-theme-on-surface), 0.04); }

/* In-chat search bar */
/* msg-search-bar — Vuetify v-card flat */
.msg-search-bar { border-bottom: 1px solid rgba(var(--v-border-color), 0.12); }
.msg-row.search-hit .msg-bubble { box-shadow: 0 0 0 2px #fbbf24; background: rgba(251,191,36,0.15); }

/* KB suggestions panel */
/* kb-panel — Vuetify v-card variant="tonal" color="primary" */
.kb-panel { max-height: 360px; display: flex; flex-direction: column; }
.kb-list { flex: 1; }

/* Messages — Telegram-style bubbles, asymmetric tail */
.chat-messages { flex: 1; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 8px; scroll-behavior: smooth; position: relative; }
.chat-messages.drop-active::before {
  content: 'Отпустите файл, чтобы прикрепить';
  position: absolute; inset: 12px;
  border: 2px dashed rgba(var(--v-theme-primary), 0.7);
  border-radius: 12px;
  background: rgba(var(--v-theme-primary), 0.08);
  color: rgb(var(--v-theme-primary));
  display: flex; align-items: center; justify-content: center;
  font-weight: 600; pointer-events: none; z-index: 10;
}
.date-divider { display: flex; align-items: center; justify-content: center; margin: 12px 0 4px; position: relative; }
.date-divider span { padding: 3px 10px; font-size: 11px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: capitalize; background: rgba(var(--v-theme-on-surface), 0.06); border-radius: 12px; }
.msg-row { display: flex; align-items: flex-end; gap: 8px; }
.msg-row.mine { flex-direction: row-reverse; }
.msg-row.system { justify-content: center; }
.msg-system { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.55); padding: 3px 10px; background: rgba(var(--v-theme-on-surface), 0.04); border-radius: 8px; display: inline-flex; align-items: center; gap: 4px; }
.msg-system-time { color: rgba(var(--v-theme-on-surface), 0.4); margin-left: 4px; font-size: 11px; font-variant-numeric: tabular-nums; }
/* Info-карточка тикета поверх ленты: показывает номер и время создания
   независимо от наличия сообщений. Стилизована как тонкая sticky-плашка. */
.ticket-info-card {
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 4px auto 12px;
  padding: 6px 14px;
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.65);
  background: rgba(var(--v-theme-on-surface), 0.05);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 14px;
  width: max-content;
  max-width: 90%;
  font-variant-numeric: tabular-nums;
}
.ticket-info-num { font-weight: 600; }
.ticket-info-time { color: rgba(var(--v-theme-on-surface), 0.45); margin-left: 4px; }
.msg-avatar { flex-shrink: 0; }
.avatar-circle { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: #fff; letter-spacing: -0.3px; }
.avatar-circle.partner { background: #f97316; }
.avatar-circle.staff { background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); }
/* Apple iMessage-style bubble. */
.msg-bubble {
  max-width: 72%;
  padding: 8px 14px 9px;
  border-radius: 20px;
  position: relative;
  line-height: 1.42;
  box-shadow: 0 1px 1.5px rgba(0, 0, 0, 0.04);
  transition: box-shadow 0.2s ease;
}
.msg-bubble:hover { box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06); }
/* При редактировании пузырь расширяем под большое окно правки. */
.msg-bubble:has(.msg-edit-area) { max-width: 90%; }
/* iMessage-стиль: фиксированный тёмно-зелёный для mine bubble в обеих
   темах (в dark-theme primary-token переключается на brand mint и
   делает bubble слишком светлым — поэтому жёсткий hex). */
.msg-bubble.partner {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgb(var(--v-theme-on-surface));
  border-bottom-left-radius: 6px;
}
.msg-bubble.mine {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  border-bottom-right-radius: 6px;
}
/* Тёмная тема: ярко-зелёный primary бьёт по глазам на чёрном фоне.
   Приглушаем — Telegram/iMessage стайл: полупрозрачный тёмно-зелёный
   фон + почти-белый текст. */
.v-theme--dark .msg-bubble.mine {
  background: rgba(46, 125, 50, 0.42);
  color: rgba(255, 255, 255, 0.94);
}
.msg-sender { font-size: 11px; font-weight: 600; margin-bottom: 2px; color: #f97316; }
.msg-bubble.mine .msg-sender { color: rgba(255,255,255,0.9); }
.msg-text { font-size: 14px; line-height: 1.45; white-space: pre-line; word-break: break-word; }
.msg-text a { color: inherit; text-decoration: underline; word-break: break-all; }
.msg-text a:hover { opacity: 0.8; }
.msg-bubble.mine .msg-text a { color: #fff; text-decoration-color: rgba(255,255,255,0.6); }
.msg-attach { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; margin-top: 6px; }
.msg-bubble.mine .msg-attach { color: rgba(255,255,255,0.9); }
.msg-image-link { display: block; margin-top: 6px; border-radius: 10px; overflow: hidden; max-width: 320px; }
.msg-image { display: block; width: 100%; height: auto; max-height: 280px; object-fit: cover; border-radius: 10px; background: rgba(0,0,0,0.05); }
.msg-time { font-size: 10px; margin-top: 4px; opacity: 0.55; display: inline-flex; align-items: center; gap: 4px; font-variant-numeric: tabular-nums; }
.msg-bubble.mine .msg-time { justify-content: flex-end; width: 100%; }
.msg-edited { font-style: italic; }
.msg-check { opacity: 0.6; }
.msg-check.seen { color: #4fc3f7 !important; opacity: 1; }

.msg-reply-quote { display: flex; gap: 6px; padding: 6px 10px; margin-bottom: 6px; background: rgba(0,0,0,0.08); border-left: 3px solid rgba(var(--v-theme-primary), 0.5); border-radius: 6px; font-size: 11px; }
.msg-bubble.mine .msg-reply-quote { background: rgba(255,255,255,0.1); border-left-color: rgba(255,255,255,0.5); }
.msg-reply-body { flex: 1; min-width: 0; }
.msg-reply-sender { font-weight: 700; opacity: 0.9; }
.msg-reply-text { opacity: 0.7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.msg-actions { position: absolute; top: -12px; right: 8px; display: none; gap: 2px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 2px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.msg-row.mine .msg-actions { right: auto; left: 8px; }
.msg-bubble:hover .msg-actions { display: flex; }
.msg-action { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.6); padding: 4px; border-radius: 6px; }
.msg-action:hover { background: rgba(var(--v-theme-primary), 0.1); color: rgb(var(--v-theme-primary)); }

.msg-edit-area { width: min(70vw, 640px); max-width: 85vw; min-height: 200px; border: 1px solid rgba(var(--v-theme-primary), 0.5); border-radius: 8px; padding: 10px 12px; font-size: 14px; line-height: 1.5; background: rgba(var(--v-theme-surface), 1); color: rgb(var(--v-theme-on-surface)); resize: vertical; font-family: inherit; outline: none; }
.msg-edit-hint { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.55); margin-top: 3px; }

/* Reactions */
.msg-reactions { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
.reaction-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 12px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.5); font-size: 11px; cursor: pointer; transition: all 0.15s; }
.reaction-chip:hover { background: rgba(var(--v-theme-primary), 0.1); border-color: rgba(var(--v-theme-primary), 0.5); }
.reaction-chip.mine { background: rgba(var(--v-theme-primary), 0.15); border-color: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-primary)); font-weight: 700; }
.reaction-emoji { font-size: 13px; line-height: 1; }
.reaction-count { font-size: 10px; font-weight: 600; }
.msg-bubble.mine .reaction-chip { background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.3); color: #fff; }
.msg-bubble.mine .reaction-chip.mine { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.5); }
.reaction-picker { display: flex; gap: 2px; padding: 4px; background: rgb(var(--v-theme-surface)); border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.reaction-picker-btn { background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 6px; font-size: 16px; line-height: 1; transition: background 0.1s; }
.reaction-picker-btn:hover { background: rgba(var(--v-theme-primary), 0.1); }
.msg-edit-actions { display: flex; gap: 6px; justify-content: flex-end; margin-top: 6px; }
.msg-edit-btn { padding: 3px 10px; border-radius: 6px; border: none; cursor: pointer; font-size: 11px; font-weight: 600; }
.msg-edit-btn.cancel { background: transparent; color: rgba(var(--v-theme-on-surface), 0.6); }
.msg-edit-btn.save { background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); }

.typing-indicator { display: flex; align-items: center; gap: 8px; padding: 6px 14px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.5); font-style: italic; }
.typing-dots { display: inline-flex; gap: 3px; }
.typing-dots span { width: 5px; height: 5px; border-radius: 50%; background: rgba(var(--v-theme-on-surface), 0.4); animation: typing-blink 1.2s infinite ease-in-out; }
.typing-dots span:nth-child(2) { animation-delay: 0.15s; }
.typing-dots span:nth-child(3) { animation-delay: 0.3s; }
@keyframes typing-blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }

.jump-to-bottom { position: absolute; right: 24px; bottom: 90px; display: flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 16px; background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 12px; font-weight: 600; z-index: 5; }

.reply-bar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  background: rgba(var(--v-theme-primary), 0.08);
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-left: 3px solid rgb(var(--v-theme-primary));
  min-height: 0;
  max-height: 48px;
  overflow: hidden;
}
.reply-bar-body { flex: 1 1 auto; min-width: 0; line-height: 1.25; }
.reply-bar-sender { font-size: 11px; font-weight: 600; color: rgb(var(--v-theme-primary)); }
.reply-bar-text { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.75); }
.reply-bar-body { flex: 1; min-width: 0; font-size: 12px; }
.reply-bar-sender { font-weight: 700; color: rgb(var(--v-theme-primary)); }
.reply-bar-text { color: rgba(var(--v-theme-on-surface), 0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.reply-bar-close { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }

.qr-row { cursor: pointer; }
.qr-row:hover { background: rgba(var(--v-theme-primary), 0.06); }
.qr-add {
  cursor: pointer;
  background: rgba(var(--v-theme-primary), 0.10);
  border-bottom: 1px solid rgba(var(--v-theme-primary), 0.20);
}
.qr-add:hover { background: rgba(var(--v-theme-primary), 0.18); }
/* Apple composer — blur + soft separator. */
.chat-input {
  display: flex; align-items: flex-end; gap: 8px;
  padding: 10px 14px;
  border-top: 1px solid rgba(var(--v-border-color), 0.06);
  background: rgba(var(--v-theme-surface), 0.82);
  backdrop-filter: saturate(180%) blur(20px);
  -webkit-backdrop-filter: saturate(180%) blur(20px);
  position: relative;
  transition: background 0.18s ease;
}
.chat-input.drag-over { background: rgba(var(--v-theme-primary), 0.08); }
.input-btn { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 6px; border-radius: 8px; }
.input-btn:hover { background: rgba(var(--v-theme-primary), 0.1); }
.input-area { flex: 1; }
.input-area textarea { width: 100%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 8px 12px; font-size: 13px; resize: none; background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; outline: none; font-family: inherit; }
.input-area textarea:focus { border-color: rgb(var(--v-theme-primary)); }
.input-files-list { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; max-height: 180px; overflow-y: auto; }
.input-files-list .input-file-preview { margin-top: 0; }
.input-file-preview { display: flex; align-items: center; gap: 8px; margin-top: 6px; padding: 6px 8px; border-radius: 10px; background: rgba(var(--v-theme-primary), 0.08); border: 1px solid rgba(var(--v-theme-primary), 0.2); }
.input-file-preview img { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; }
.input-file-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: rgba(var(--v-theme-primary), 0.15); color: rgb(var(--v-theme-primary)); }
.input-file-info { flex: 1; min-width: 0; }
.input-file-name { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.input-file-size { font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.5); }
.input-file-remove { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }
.drop-overlay { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; background: rgba(var(--v-theme-primary), 0.15); border: 2px dashed rgb(var(--v-theme-primary)); border-radius: 8px; color: rgb(var(--v-theme-primary)); font-weight: 600; font-size: 13px; pointer-events: none; z-index: 10; }
.input-send { background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary)); border: none; border-radius: 10px; padding: 8px 12px; cursor: pointer; }
.input-send:disabled { opacity: 0.4; cursor: not-allowed; }

.hotkey-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px dashed rgba(var(--v-border-color), 0.3); font-size: 13px; }
.hotkey-row:last-of-type { border-bottom: none; }
.hotkey-row kbd { display: inline-block; padding: 2px 8px; border-radius: 6px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.5); font-family: ui-monospace, monospace; font-size: 11px; font-weight: 600; min-width: 24px; text-align: center; }
.hotkey-row span { flex: 1; color: rgba(var(--v-theme-on-surface), 0.8); }

/* Bulk action bar */
/* Bulk bar (kanban + list) — Vuetify-first, остался лишь sticky-layout */
.bulk-bar { position: sticky; bottom: 0; background: rgb(var(--v-theme-surface)); border-top: 1px solid rgba(var(--v-border-color), 0.12); box-shadow: 0 -4px 16px rgba(0,0,0,0.08); }
.chat-item.bulk-mode { padding-left: 36px; }
.chat-item.selected { background: rgba(var(--v-theme-primary), 0.08); }
.bulk-slide-enter-active, .bulk-slide-leave-active { transition: transform 0.2s ease, opacity 0.2s ease; }
.bulk-slide-enter-from, .bulk-slide-leave-to { transform: translateY(100%); opacity: 0; }

/* Partner context panel (right sidebar in list mode) */
/* Right partner-context panel — Vuetify-first, остался лишь layout */
.context-panel {
  width: 100%; height: 100%;
  display: flex; flex-direction: column;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
}
.context-body { flex: 1; overflow-y: auto; }
.ctx-link { color: rgba(var(--v-theme-on-surface), 0.7); text-decoration: none; }
.ctx-link:hover { color: rgb(var(--v-theme-primary)); }
.ctx-num { font-variant-numeric: tabular-nums; }
.ctx-list :deep(.v-list-item) { min-height: 32px; padding-inline: 0 !important; }

@media (max-width: 959px) {
  /* На mobile splitter скрыт — pane'ы переключаются v-if'ом по activeChat. */
  .chat-splitpanes :deep(.splitpanes__splitter) { display: none; }
  .chat-splitpanes :deep(.splitpanes__pane) { width: 100% !important; max-width: 100%; }
  .chat-sidebar { width: 100% !important; }
  .mobile-hidden { display: none !important; }
}
</style>
