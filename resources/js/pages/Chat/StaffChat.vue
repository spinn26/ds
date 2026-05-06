<template>
  <div class="chat-wrap" :class="{ 'kanban-mode': viewMode === 'kanban' }">
    <!-- Connection-status banner: показываем только если socket упал, чтобы
         оператор не думал что чат «завис». Polling-fallback всё равно работает. -->
    <div v-if="!socketConnected" class="conn-banner">
      <v-icon size="14">mdi-wifi-off</v-icon>
      Real-time соединение потеряно. Сообщения придут с задержкой ~15 сек.
    </div>
    <!-- Left: ticket list (hidden in Kanban mode on mobile / collapsed on desktop) -->
    <aside class="chat-sidebar" :class="{ 'mobile-hidden': mobile && (activeChat || viewMode === 'kanban'), 'compact': viewMode === 'kanban' && !mobile }">
      <div class="sidebar-head px-3 py-2">
        <div class="d-flex align-center justify-space-between">
          <div class="text-body-1 font-weight-bold">Обращения</div>
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
      </div>

      <!-- Search -->
      <div class="px-3 pb-2">
        <v-text-field v-model="filter.search"
          placeholder="Поиск…"
          prepend-inner-icon="mdi-magnify"
          variant="outlined" density="compact" hide-details clearable
          @input="debouncedLoad"
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
      <div class="sidebar-list">
        <div v-for="t in filteredBySmartView" :key="t.id"
          class="chat-item"
          :class="{ active: activeChat?.id === t.id, 'has-unread': t.unread > 0, stale: isStale(t), pinned: t.pinned_at, 'bulk-mode': bulkMode, selected: selectedIds.has(t.id) }"
          @click="bulkMode ? toggleCardSelect(t, $event) : openChat(t)">
          <input v-if="bulkMode" type="checkbox" class="chat-item-cb"
            :checked="selectedIds.has(t.id)"
            @click.stop="toggleCardSelect(t, $event)" />
          <div class="chat-item-avatar" :style="{ background: catColor(t.category || t.department) }">
            <v-icon size="18" color="white">{{ catIcon(t.category || t.department) }}</v-icon>
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
              <span class="chat-item-status-chip" :style="{ background: statusClr(t.status) + '22', color: statusClr(t.status) }">{{ statusTxt(t.status) }}</span>
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
      </div>

      <!-- Bulk action bar (list-mode). В Канбане свой — это для list-view. -->
      <transition name="bulk-slide">
        <div v-if="bulkMode && anySelected && viewMode === 'list'" class="bulk-bar list-bulk-bar pa-2 d-flex flex-wrap align-center ga-2">
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

    <!-- View toggle: floating vertical rail on the right edge -->
    <div class="view-toggle">
      <v-btn class="view-toggle-btn"
        :variant="viewMode === 'list' ? 'flat' : 'text'"
        :color="viewMode === 'list' ? 'primary' : undefined"
        size="small" stacked
        @click="viewMode = 'list'" title="Список">
        <v-icon size="20">mdi-format-list-bulleted</v-icon>
        <span class="text-caption">Список</span>
      </v-btn>
      <v-btn class="view-toggle-btn"
        :variant="viewMode === 'kanban' ? 'flat' : 'text'"
        :color="viewMode === 'kanban' ? 'primary' : undefined"
        size="small" stacked
        @click="viewMode = 'kanban'" title="Канбан">
        <v-icon size="20">mdi-view-column-outline</v-icon>
        <span class="text-caption">Доска</span>
      </v-btn>
    </div>

    <!-- Kanban mode container -->
    <div v-if="viewMode === 'kanban'" class="kanban-wrap">
      <!-- Kanban toolbar -->
      <div class="kanban-toolbar pa-2 d-flex flex-wrap align-center ga-3">
        <v-checkbox v-model="myBoardOnly" hide-details density="compact"
          prepend-icon="mdi-account-star" label="Только мои" />
        <div class="d-flex align-center ga-1">
          <span class="text-caption text-medium-emphasis">Сортировка:</span>
          <v-chip size="small"
            :color="kanbanSort === 'time' ? 'primary' : undefined"
            :variant="kanbanSort === 'time' ? 'flat' : 'tonal'"
            @click="kanbanSort = 'time'">Время</v-chip>
          <v-chip size="small"
            :color="kanbanSort === 'priority' ? 'primary' : undefined"
            :variant="kanbanSort === 'priority' ? 'flat' : 'tonal'"
            @click="kanbanSort = 'priority'">Приоритет</v-chip>
          <v-chip size="small"
            :color="kanbanSort === 'assignee' ? 'primary' : undefined"
            :variant="kanbanSort === 'assignee' ? 'flat' : 'tonal'"
            @click="kanbanSort = 'assignee'">Исполнитель</v-chip>
        </div>
        <div class="d-flex align-center ga-1">
          <span class="text-caption text-medium-emphasis">Ряды:</span>
          <v-chip size="small"
            :color="swimlaneMode === 'none' ? 'primary' : undefined"
            :variant="swimlaneMode === 'none' ? 'flat' : 'tonal'"
            @click="swimlaneMode = 'none'">Нет</v-chip>
          <v-chip size="small"
            :color="swimlaneMode === 'priority' ? 'primary' : undefined"
            :variant="swimlaneMode === 'priority' ? 'flat' : 'tonal'"
            @click="swimlaneMode = 'priority'">По приоритету</v-chip>
          <v-chip size="small"
            :color="swimlaneMode === 'assignee' ? 'primary' : undefined"
            :variant="swimlaneMode === 'assignee' ? 'flat' : 'tonal'"
            @click="swimlaneMode = 'assignee'">По исполнителю</v-chip>
        </div>
        <v-btn size="x-small" :variant="bulkMode ? 'flat' : 'tonal'"
          :color="bulkMode ? 'primary' : undefined"
          :prepend-icon="bulkMode ? 'mdi-checkbox-marked' : 'mdi-checkbox-blank-outline'"
          @click="toggleBulk">
          Выбор
        </v-btn>
      </div>

      <!-- Kanban board -->
      <div class="kanban-board">
        <div v-for="col in kanbanColumns" :key="col.value"
          class="kanban-column"
          :style="{ '--col-color': col.color }">
          <div class="kanban-col-head">
            <v-icon size="14" :color="col.color">{{ col.icon }}</v-icon>
            <span class="kanban-col-title">{{ col.label }}</span>
            <span class="kanban-col-count">{{ kanbanGrouped[col.value]?.length || 0 }}</span>
          </div>
          <div class="kanban-col-body">
            <template v-for="lane in swimlanes" :key="col.value + '-' + lane.key">
              <div v-if="swimlaneMode !== 'none'" class="swimlane-head" :style="lane.color ? { borderColor: lane.color, color: lane.color } : {}">
                {{ lane.label }}
                <span class="swimlane-count">{{ cardsInCell(col.value, lane.key).length }}</span>
              </div>
              <div class="swimlane-drop"
                :class="{ 'drop-target': dragOverCol === cellKey(col.value, lane.key) }"
                @dragover.prevent="dragOverCol = cellKey(col.value, lane.key)"
                @dragleave="dragOverCol === cellKey(col.value, lane.key) && (dragOverCol = null)"
                @drop.prevent="onKanbanDrop(col.value, lane.key)">
                <v-card v-for="t in cardsInCell(col.value, lane.key)" :key="t.id"
                  class="kanban-card"
                  :class="{ 'is-dragging': draggingId === t.id, stale: isStale(t), selected: selectedIds.has(t.id), 'bulk-mode': bulkMode }"
                  :style="t.priority && t.priority !== 'medium' ? { borderLeftColor: prioClr(t.priority), borderLeftWidth: '3px', borderLeftStyle: 'solid' } : {}"
                  variant="outlined"
                  :draggable="!bulkMode"
                  @dragstart="onKanbanDragStart(t, $event)"
                  @dragend="onKanbanDragEnd"
                  @click="openFromKanban(t, $event)">
                  <div class="kanban-card-head d-flex align-center ga-2 px-2 pt-2">
                    <v-checkbox-btn v-if="bulkMode"
                      :model-value="selectedIds.has(t.id)"
                      density="compact" hide-details
                      @click.stop="toggleCardSelect(t, $event)" />
                    <div class="kanban-card-avatar" :style="{ background: catColor(t.category || t.department) }">
                      <v-icon size="12" color="white">{{ catIcon(t.category || t.department) }}</v-icon>
                    </div>
                    <v-icon v-if="t.pinned_at" size="12" color="primary" title="Закреплён">mdi-pin</v-icon>
                    <div class="kanban-card-subject text-body-2 font-weight-medium text-truncate flex-grow-1">{{ t.subject }}</div>
                    <v-chip v-if="t.unread > 0" size="x-small" color="primary" variant="flat" label density="comfortable">
                      {{ t.unread }}
                    </v-chip>
                  </div>
                  <div class="text-caption text-medium-emphasis text-truncate px-2 mt-1">
                    {{ t.customer_name }}
                  </div>
                  <div class="d-flex flex-wrap align-center ga-2 px-2 pt-1 text-caption text-medium-emphasis">
                    <span :class="{ 'text-error font-weight-bold': isStale(t) }" class="d-flex align-center ga-1">
                      <v-icon size="11">mdi-clock-outline</v-icon>
                      <span class="ctx-num">{{ ago(t.last_message_at) }}</span>
                    </span>
                    <span v-if="t.assigned_name" class="d-flex align-center ga-1" :title="'Назначен: ' + t.assigned_name">
                      <v-icon size="11">mdi-account</v-icon>
                      {{ shortName(t.assigned_name) }}
                    </span>
                    <v-icon v-if="t.priority && t.priority !== 'medium'" size="11" :color="prioClr(t.priority)">
                      mdi-flag
                    </v-icon>
                  </div>
                  <div v-if="parseTags(t.tags).length" class="d-flex flex-wrap ga-1 px-2 pt-1 pb-2">
                    <v-chip v-for="tag in parseTags(t.tags).slice(0, 3)" :key="tag"
                      size="x-small" variant="tonal" label density="comfortable">
                      #{{ tag }}
                    </v-chip>
                  </div>
                  <div v-else class="pb-2" />
                  <!-- Quick actions on hover -->
                  <div v-if="!bulkMode" class="kanban-quick-actions">
                    <v-btn icon variant="text" size="x-small"
                      :color="t.pinned_at ? 'primary' : undefined"
                      :title="t.pinned_at ? 'Открепить' : 'Закрепить'"
                      @click="togglePin(t, $event)">
                      <v-icon size="14">{{ t.pinned_at ? 'mdi-pin' : 'mdi-pin-outline' }}</v-icon>
                    </v-btn>
                    <v-btn v-if="String(t.assigned_to) !== String(currentUserId)"
                      icon variant="text" size="x-small"
                      title="Взять себе" @click="quickAssignToMe(t, $event)">
                      <v-icon size="14">mdi-account-arrow-left</v-icon>
                    </v-btn>
                    <v-menu location="bottom end">
                      <template #activator="{ props }">
                        <v-btn v-bind="props" icon variant="text" size="x-small"
                          title="Приоритет" @click.stop>
                          <v-icon size="14">mdi-flag-variant</v-icon>
                        </v-btn>
                      </template>
                      <v-list density="compact" min-width="160">
                        <v-list-item v-for="p in priorities" :key="p.value" @click="quickSetPriority(t, p.value, $event)">
                          <template #prepend><v-icon size="12" :color="p.color">mdi-circle</v-icon></template>
                          <v-list-item-title class="text-caption">{{ p.label }}</v-list-item-title>
                        </v-list-item>
                      </v-list>
                    </v-menu>
                  </div>
                </v-card>
                <div v-if="!cardsInCell(col.value, lane.key).length" class="kanban-col-empty text-caption text-medium-emphasis text-center py-4">—</div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- Bulk action bar (kanban-mode) -->
      <transition name="bulk-slide">
        <div v-if="bulkMode && anySelected" class="bulk-bar pa-2 d-flex flex-wrap align-center ga-2">
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
    </div>

    <!-- Center: messages (list mode) -->
    <main v-else class="chat-main" :class="{ 'mobile-hidden': mobile && !activeChat }">
      <template v-if="activeChat">
        <!-- Header with actions -->
        <div class="chat-header pa-3">
          <v-btn v-if="mobile" icon variant="text" size="small" @click="closeActiveChat">
            <v-icon>mdi-arrow-left</v-icon>
          </v-btn>
          <div class="chat-header-info">
            <div class="text-subtitle-1 font-weight-bold">{{ activeChat.subject }}</div>
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
          </div>
          <div class="chat-header-actions d-flex align-center ga-1">
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
            <v-menu>
              <template #activator="{ props }">
                <v-btn v-bind="props" icon variant="text" size="small"
                  :title="'Приоритет: ' + prioLabel(activeChat.priority || 'medium')">
                  <v-icon size="18" :color="prioClr(activeChat.priority)">mdi-flag-outline</v-icon>
                </v-btn>
              </template>
              <v-list density="compact" min-width="160">
                <v-list-item v-for="p in priorities" :key="p.value" @click="setPriority(p.value)">
                  <template #prepend><v-icon size="14" :color="p.color">mdi-circle</v-icon></template>
                  <v-list-item-title class="text-body-2">{{ p.label }}</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
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

        <!-- Messages -->
        <div ref="msgsRef" class="chat-messages" @scroll="onMessagesScroll">
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
                  <textarea v-model="editing.content" class="msg-edit-area" rows="2"
                    @keydown.enter.exact.prevent="saveEdit"
                    @keydown.esc.prevent="cancelEdit"></textarea>
                  <div class="msg-edit-actions">
                    <button class="msg-edit-btn cancel" @click="cancelEdit">Отмена</button>
                    <button class="msg-edit-btn save" @click="saveEdit">Сохранить</button>
                  </div>
                </template>
                <template v-else>
                  <div v-if="item.msg.content" class="msg-text">{{ item.msg.content }}</div>
                </template>
                <template v-if="item.msg.attachmentPath">
                  <a v-if="isImageAttachment(item.msg.attachmentName || item.msg.attachmentPath)"
                    :href="item.msg.attachmentPath" target="_blank" rel="noopener noreferrer" class="msg-image-link">
                    <img :src="item.msg.attachmentPath" :alt="item.msg.attachmentName || 'Изображение'" class="msg-image" loading="lazy" />
                  </a>
                  <a v-else :href="item.msg.attachmentPath" target="_blank" rel="noopener noreferrer" class="msg-attach">
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

        <!-- Reply preview -->
        <v-alert v-if="replyTo && activeChat.status !== 'closed'"
          density="compact" variant="tonal" color="primary"
          icon="mdi-reply" closable class="reply-bar"
          @click:close="cancelReply">
          <div class="text-caption font-weight-medium">Ответ на: {{ replyTo.senderName }}</div>
          <div class="text-body-2 text-truncate">{{ replyTo.content }}</div>
        </v-alert>

        <!-- Input -->
        <div v-if="activeChat.status !== 'closed'" class="chat-input pa-2"
          :class="{ 'drag-over': dragOver }"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onFileDrop">
          <input ref="fileRef" type="file" hidden @change="e => setFile(e.target.files?.[0])" />
          <v-btn icon variant="text" size="small" title="Прикрепить файл" @click="$refs.fileRef.click()">
            <v-icon>mdi-paperclip</v-icon>
          </v-btn>
          <!-- Quick replies -->
          <v-menu v-if="quickReplies.length">
            <template #activator="{ props }">
              <v-btn v-bind="props" icon variant="text" size="small" title="Быстрые ответы">
                <v-icon>mdi-lightning-bolt-outline</v-icon>
              </v-btn>
            </template>
            <v-list density="compact" style="max-width: 360px; max-height: 400px; overflow-y: auto">
              <v-list-item v-for="q in quickReplies" :key="q.id" @click="insertQuickReply(q)">
                <v-list-item-title class="text-body-2 font-weight-bold">{{ q.title }}</v-list-item-title>
                <v-list-item-subtitle class="text-caption" style="white-space: normal">{{ q.content }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-menu>
          <div class="input-area">
            <v-textarea ref="taRef" v-model="msgText"
              placeholder="Ответ… (Enter — отправить, Shift+Enter — перенос строки)"
              variant="outlined" density="compact" rows="1" auto-grow hide-details
              max-rows="6"
              @keydown.enter.exact.prevent="send"
              @input="onInput"
              @paste="onPaste" />
            <div v-if="file" class="input-file-preview">
              <img v-if="filePreviewUrl" :src="filePreviewUrl" alt="preview" />
              <div v-else class="input-file-icon"><v-icon size="16">mdi-file</v-icon></div>
              <div class="input-file-info">
                <div class="input-file-name">{{ file.name }}</div>
                <div class="text-caption text-medium-emphasis">{{ fmtFileSize(file.size) }}</div>
              </div>
              <v-btn icon size="x-small" variant="text" @click="clearFile">
                <v-icon size="14">mdi-close</v-icon>
              </v-btn>
            </div>
          </div>
          <v-btn icon color="primary"
            :disabled="sending || (!msgText.trim() && !file)"
            :loading="sending"
            title="Отправить (Enter)" @click="send">
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

    <!-- Right: Partner context panel — единый блок (с/без partnerContext) -->
    <aside v-if="viewMode === 'list' && activeChat && showContext && !mobile" class="context-panel">
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
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useDisplay } from 'vuetify';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { useConfirm } from '../../composables/useConfirm';
import { useSnackbar } from '../../composables/useSnackbar';
import { useAuthStore } from '../../stores/auth';
import { getActivityColorByName } from '../../composables/useDesign';

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
const auth = useAuthStore();
const currentUserId = auth.userId;
const currentUserName = computed(() => `${auth.user?.lastName || ''} ${auth.user?.firstName || ''}`.trim() || 'Staff');

const chats = ref([]);
const loading = ref(false);
const activeChat = ref(null);
const messages = ref([]);
const msgText = ref('');
const file = ref(null);
const sending = ref(false);
const msgsRef = ref(null);
const fileRef = ref(null);
const taRef = ref(null);
const tagInput = ref(null);
const staffList = ref([]);
const quickReplies = ref([]);
const filter = ref({ status: '', priority: '', search: '' });
let poll = null;

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

// Drag-drop + preview
const dragOver = ref(false);
const filePreviewUrl = ref(null);

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

function playPing() {
  if (!notifyEnabled.value) return;
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.1);
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.25);
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
function sortChats(a, b) {
  const pa = a.pinned_at ? 1 : 0;
  const pb = b.pinned_at ? 1 : 0;
  if (pa !== pb) return pb - pa;
  return new Date(b.last_message_at || 0) - new Date(a.last_message_at || 0);
}

// View mode (list / kanban)
const viewMode = ref(localStorage.getItem('staff-chat-view') || 'list');
watch(viewMode, v => localStorage.setItem('staff-chat-view', v));
const draggingId = ref(null);
const dragOverCol = ref(null); // { col, lane } or col value for backward-compat
const kanbanColumns = [
  { value: 'new', label: 'Новые', color: chatStatusColors.new, icon: 'mdi-circle-outline' },
  { value: 'open', label: 'В работе', color: chatStatusColors.open, icon: 'mdi-progress-clock' },
  { value: 'pending', label: 'Ожидание', color: chatStatusColors.pending, icon: 'mdi-pause-circle' },
  { value: 'resolved', label: 'Решён', color: chatStatusColors.resolved, icon: 'mdi-check-circle' },
  { value: 'closed', label: 'Закрыт', color: chatStatusColors.closed, icon: 'mdi-lock' },
];

// Kanban extensions
const kanbanSort = ref(localStorage.getItem('kanban-sort') || 'time');
watch(kanbanSort, v => localStorage.setItem('kanban-sort', v));
const myBoardOnly = ref(localStorage.getItem('kanban-my-only') === '1');
watch(myBoardOnly, v => localStorage.setItem('kanban-my-only', v ? '1' : '0'));
const swimlaneMode = ref(localStorage.getItem('kanban-swimlane') || 'none');
watch(swimlaneMode, v => localStorage.setItem('kanban-swimlane', v));
const bulkMode = ref(false);
const selectedIds = ref(new Set());
watch(bulkMode, v => { if (!v) selectedIds.value = new Set(); });

const PRIORITY_ORDER = { critical: 0, high: 1, medium: 2, low: 3, undefined: 4, null: 4 };

function cardsMatchingFilters() {
  let list = chats.value;
  if (myBoardOnly.value) {
    list = list.filter(t => String(t.assigned_to) === String(currentUserId));
  }
  return list;
}

function sortCards(cards) {
  const sorted = [...cards];
  switch (kanbanSort.value) {
    case 'priority':
      sorted.sort((a, b) => (PRIORITY_ORDER[a.priority] ?? 4) - (PRIORITY_ORDER[b.priority] ?? 4));
      break;
    case 'assignee':
      sorted.sort((a, b) => (a.assigned_name || 'я').localeCompare(b.assigned_name || 'я', 'ru'));
      break;
    case 'time':
    default:
      sorted.sort((a, b) => new Date(b.last_message_at || 0) - new Date(a.last_message_at || 0));
  }
  return sorted;
}

const kanbanGrouped = computed(() => {
  const groups = {};
  for (const col of kanbanColumns) groups[col.value] = [];
  for (const t of cardsMatchingFilters()) {
    if (groups[t.status]) groups[t.status].push(t);
  }
  for (const k of Object.keys(groups)) groups[k] = sortCards(groups[k]);
  return groups;
});

// Swimlanes: when enabled, split each column by lane key
const swimlanes = computed(() => {
  if (swimlaneMode.value === 'none') return [{ key: 'all', label: '', color: null }];

  if (swimlaneMode.value === 'priority') {
    return [
      { key: 'critical', label: 'Критический', color: chatPriorityColors.critical },
      { key: 'high', label: 'Высокий', color: chatPriorityColors.high },
      { key: 'medium', label: 'Средний', color: chatPriorityColors.medium },
      { key: 'low', label: 'Низкий', color: chatPriorityColors.low },
    ];
  }

  // assignee: distinct assigned_name values across visible cards + Не назначено
  const names = new Set();
  for (const t of cardsMatchingFilters()) {
    names.add(t.assigned_name || '__unassigned');
  }
  const sorted = [...names].sort((a, b) => {
    if (a === '__unassigned') return 1;
    if (b === '__unassigned') return -1;
    return a.localeCompare(b, 'ru');
  });
  return sorted.map(n => ({
    key: n,
    label: n === '__unassigned' ? 'Не назначено' : n,
    color: n === '__unassigned' ? chatStatusColors.closed : null,
  }));
});

function laneKeyFor(ticket) {
  if (swimlaneMode.value === 'priority') return ticket.priority || 'medium';
  if (swimlaneMode.value === 'assignee') return ticket.assigned_name || '__unassigned';
  return 'all';
}

function cardsInCell(colValue, laneKey) {
  const colCards = kanbanGrouped.value[colValue] || [];
  if (swimlaneMode.value === 'none') return colCards;
  return colCards.filter(t => laneKeyFor(t) === laneKey);
}

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
    message: `${ids.length} тикетов будут переведены в статус «${kanbanColumns.find(c => c.value === status)?.label}».`,
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

async function quickAssignToMe(ticket, e) {
  e?.stopPropagation();
  try {
    await api.post(`/chat/tickets/${ticket.id}/assign`, { user_id: currentUserId });
    ticket.assigned_to = currentUserId;
    ticket.assigned_name = currentUserName.value;
  } catch {}
}

async function quickSetPriority(ticket, priority, e) {
  e?.stopPropagation();
  if (ticket.priority === priority) return;
  const prev = ticket.priority;
  ticket.priority = priority;
  try { await api.post(`/chat/tickets/${ticket.id}/status`, { status: ticket.status, priority }); }
  catch { ticket.priority = prev; }
}
function shortName(n) {
  if (!n) return '';
  const parts = String(n).trim().split(/\s+/);
  if (parts.length >= 2) return `${parts[0]} ${(parts[1][0] || '').toUpperCase()}.`;
  return parts[0];
}

function onKanbanDragStart(ticket, e) {
  if (bulkMode.value) { e.preventDefault(); return; }
  draggingId.value = ticket.id;
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', String(ticket.id));
}
function onKanbanDragEnd() {
  draggingId.value = null;
  dragOverCol.value = null;
}
function cellKey(colValue, laneKey) { return `${colValue}::${laneKey}`; }
async function onKanbanDrop(targetStatus, laneKey) {
  dragOverCol.value = null;
  const id = draggingId.value;
  draggingId.value = null;
  if (!id) return;
  const ticket = chats.value.find(t => t.id === id);
  if (!ticket) return;

  const patches = {};
  // Column change: status
  if (ticket.status !== targetStatus) patches.status = targetStatus;
  // Swimlane change: apply the relevant attribute
  if (swimlaneMode.value === 'priority' && laneKey && ticket.priority !== laneKey) {
    patches.priority = laneKey;
  } else if (swimlaneMode.value === 'assignee' && laneKey && laneKey !== '__unassigned'
             && ticket.assigned_name !== laneKey) {
    // Find staff id by display name
    const staff = staffList.value.find(s => s.name === laneKey);
    if (staff) patches.assigneeId = staff.id;
  }

  if (!Object.keys(patches).length) return;

  // Optimistic update
  const prev = { status: ticket.status, priority: ticket.priority, assigned_to: ticket.assigned_to, assigned_name: ticket.assigned_name };
  if (patches.status) ticket.status = patches.status;
  if (patches.priority) ticket.priority = patches.priority;
  if (patches.assigneeId) {
    const staff = staffList.value.find(s => s.id === patches.assigneeId);
    if (staff) { ticket.assigned_to = staff.id; ticket.assigned_name = staff.name; }
  }

  try {
    if (patches.status !== undefined || patches.priority !== undefined) {
      await api.post(`/chat/tickets/${id}/status`, {
        status: ticket.status,
        priority: ticket.priority || 'medium',
      });
    }
    if (patches.assigneeId !== undefined) {
      await api.post(`/chat/tickets/${id}/assign`, { user_id: patches.assigneeId });
    }
  } catch {
    Object.assign(ticket, prev);
    showError('Не удалось обновить тикет');
  }
}
function openFromKanban(t, e) {
  if (bulkMode.value) { toggleCardSelect(t, e); return; }
  viewMode.value = 'list';
  openChat(t);
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
const priorityFilterPills = [{ label: 'Все', value: '', color: '#888' }, ...priorities];

const catColor = getChatCategoryColor;
function catIcon(c) { return { support: 'mdi-headset', backoffice: 'mdi-briefcase', billing: 'mdi-cash', legal: 'mdi-scale-balance', general: 'mdi-help-circle', technical: 'mdi-headset', sales: 'mdi-handshake' }[c] || 'mdi-chat'; }
const statusClr = getChatStatusColor;
function statusTxt(s) { return { new: 'Новый', open: 'В работе', pending: 'Ожидание', resolved: 'Решён', closed: 'Закрыт' }[s] || s; }
function statusIcon(s) { return { new: 'mdi-circle-outline', open: 'mdi-progress-clock', pending: 'mdi-pause-circle', resolved: 'mdi-check-circle', closed: 'mdi-lock' }[s] || 'mdi-circle'; }
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
  nextTick(() => {
    const el = msgsRef.value;
    if (!el) return;
    if (force || isAtBottom()) {
      el.scrollTop = el.scrollHeight;
      pendingMessages.value = 0;
      showJumpToBottom.value = false;
    }
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
function setFile(f) {
  if (!f) return;
  file.value = f;
  if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value);
  filePreviewUrl.value = f.type?.startsWith('image/') ? URL.createObjectURL(f) : null;
}
function clearFile() {
  if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value);
  filePreviewUrl.value = null;
  file.value = null;
}
function onFileDrop(e) {
  dragOver.value = false;
  const f = e.dataTransfer?.files?.[0];
  if (f) setFile(f);
}
function onPaste(e) {
  const items = e.clipboardData?.items || [];
  for (const it of items) {
    if (it.kind === 'file') {
      const f = it.getAsFile();
      if (f) { setFile(f); e.preventDefault(); return; }
    }
  }
}

const { debounced: debouncedLoad } = useDebounce(loadChats, 400);

async function loadChats() {
  loading.value = true;
  try {
    const params = {};
    if (filter.value.status) params.status = filter.value.status;
    if (filter.value.priority) params.priority = filter.value.priority;
    if (filter.value.search) params.search = filter.value.search;
    const { data } = await api.get('/chat/tickets', { params });
    chats.value = data.data || [];
  } catch {}
  loading.value = false;
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
  if (!msgText.value?.trim() && !file.value) return;
  sending.value = true;
  // Идемпотентный токен — backend дедуплицирует, фронт игнорирует
  // socket-emit с этим же id (см. recvNewMessage).
  const clientMessageId = (crypto?.randomUUID?.() ?? `cmid-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  try {
    const fd = new FormData();
    fd.append('message', msgText.value || '');
    fd.append('client_message_id', clientMessageId);
    if (file.value) fd.append('attachment', file.value);
    if (replyTo.value) fd.append('reply_to_id', String(replyTo.value.id));
    await api.post(`/chat/tickets/${activeChat.value.id}/messages`, fd);
    localStorage.removeItem(draftKey(activeChat.value.id));
    msgText.value = '';
    clearFile();
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
    await api.post(`/chat/tickets/${activeChat.value.id}/status`, { status });
    activeChat.value.status = status;
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
    .replace(/\{staff_name\}/g, currentUserName.value);
  msgText.value = msgText.value ? `${msgText.value}\n${content}` : content;
  nextTick(() => { taRef.value?.focus(); autoGrow(); });
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

      if (!isOwn && (document.hidden || !isActive)) {
        playPing();
        notifyDesktop(m.senderName || 'Новое сообщение',
          (m.content || '').slice(0, 120) || 'Прислали сообщение');
      }

      loadChats();
    });
    socket.on('ticket:typing', (e) => {
      if (!activeChat.value || String(e.userId) === String(currentUserId)) return;
      typingName.value = e.userName || 'Собеседник';
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

    // Ticket updates from other staff (status / priority / assignee / pin)
    socket.on('chat:ticket-updated', (e) => {
      const t = chats.value.find(x => Number(x.id) === Number(e.ticketId));
      if (!t) { loadChats(); return; }
      if (e.status !== undefined) t.status = e.status;
      if (e.priority !== undefined) t.priority = e.priority;
      if (e.assignedTo !== undefined) t.assigned_to = e.assignedTo;
      if (e.assignedName !== undefined) t.assigned_name = e.assignedName;
      if (e.tags !== undefined) t.tags = e.tags;
      if (e.pinnedAt !== undefined) { t.pinned_at = e.pinnedAt; chats.value = [...chats.value].sort(sortChats); }
      if (activeChat.value && Number(activeChat.value.id) === Number(e.ticketId)) {
        if (e.status !== undefined) activeChat.value.status = e.status;
        if (e.priority !== undefined) activeChat.value.priority = e.priority;
        if (e.assignedTo !== undefined) activeChat.value.assigned_to = e.assignedTo;
        if (e.assignedName !== undefined) activeChat.value.assigned_name = e.assignedName;
        if (e.pinnedAt !== undefined) activeChat.value.pinned_at = e.pinnedAt;
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
  loadChats();
  connectSocket();
  window.addEventListener('keydown', onGlobalKey);
  document.addEventListener('visibilitychange', onVisibilityChange);
  requestNotifPermission();
  try { const { data } = await api.get('/chat/tickets/staff'); staffList.value = data || []; } catch {}
  try { const { data } = await api.get('/chat/quick-replies'); quickReplies.value = data.data || data || []; } catch {}
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
.chat-wrap { display: flex; height: calc(100vh - 64px); overflow: hidden; position: relative; }
/* Sidebar — Linear-style: 320px, тонкие dividers, компактная плотность */
.chat-sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid rgba(var(--v-border-color), 0.12); display: flex; flex-direction: column; background: rgba(var(--v-theme-surface), 1); }
.sidebar-head { display: flex; align-items: center; }
.sidebar-list { flex: 1; overflow-y: auto; }

/* Chat item — единый стиль: компактный, тонкие границы, плавный hover */
.chat-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; cursor: pointer; transition: background 0.1s; position: relative; }
.chat-item:not(:last-child)::after { content: ''; position: absolute; left: 12px; right: 12px; bottom: 0; border-bottom: 1px solid rgba(var(--v-border-color), 0.08); }
.chat-item:hover { background: rgba(var(--v-theme-on-surface), 0.04); }
.chat-item.active { background: rgba(var(--v-theme-primary), 0.08); }
.chat-item.active::before { content: ''; position: absolute; left: 0; top: 8px; bottom: 8px; width: 2px; background: rgb(var(--v-theme-primary)); border-radius: 0 2px 2px 0; }
.chat-item.stale { background: rgba(var(--v-theme-error), 0.05); }
.chat-item-avatar { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
.priority-bar { position: absolute; top: 8px; bottom: 8px; left: 4px; width: 2px; border-radius: 1px; }
.chat-item-body { flex: 1; min-width: 0; }
.chat-item-top { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
.chat-item-subject { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
.chat-item-time { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.45); flex-shrink: 0; font-variant-numeric: tabular-nums; }
.chat-item-time.stale { color: rgb(var(--v-theme-error)); font-weight: 600; }
.chat-item-bottom { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.55); margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.chat-item-preview { display: flex; gap: 4px; margin-top: 2px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; line-height: 1.3; }
.chat-item-preview-prefix { color: rgba(var(--v-theme-on-surface), 0.4); flex-shrink: 0; }
.chat-item-preview-text { overflow: hidden; text-overflow: ellipsis; }
.chat-item.has-unread .chat-item-subject { font-weight: 600; color: rgb(var(--v-theme-on-surface)); }
.chat-item.has-unread .chat-item-preview { color: rgba(var(--v-theme-on-surface), 0.85); }
.conn-banner { position: absolute; top: 0; left: 0; right: 0; z-index: 100; padding: 6px 12px; background: rgba(var(--v-theme-warning), 0.15); color: rgb(var(--v-theme-warning)); font-size: 12px; display: flex; align-items: center; gap: 6px; }
.customer { font-weight: 500; }
.recipient { color: rgba(var(--v-theme-on-surface), 0.6); }
.unread-badge { position: absolute; right: 12px; top: 10px; background: rgb(var(--v-theme-primary)); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; min-width: 18px; text-align: center; line-height: 1.4; }
.csat-badge { position: absolute; right: 12px; top: 10px; background: rgba(245, 165, 36, 0.15); color: #f5a524; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; line-height: 1.4; }
.chat-item.pinned { background: rgba(var(--v-theme-primary), 0.03); }
.chat-item-pin { position: absolute; right: 8px; bottom: 8px; background: none; border: none; padding: 2px; border-radius: 4px; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.3); opacity: 0; transition: opacity 0.15s, color 0.15s; }
.chat-item:hover .chat-item-pin { opacity: 1; }
.chat-item-pin.active { color: rgb(var(--v-theme-primary)); opacity: 1; }
.kanban-qa-btn.active { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.12); }

.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; position: relative; }
.chat-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; }

.chat-header { border-bottom: 1px solid rgba(var(--v-border-color), 0.12); display: flex; align-items: flex-start; gap: 8px; }
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-actions { flex-shrink: 0; }
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
.chat-messages { flex: 1; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 8px; scroll-behavior: smooth; }
.date-divider { display: flex; align-items: center; justify-content: center; margin: 12px 0 4px; position: relative; }
.date-divider span { padding: 3px 10px; font-size: 11px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: capitalize; background: rgba(var(--v-theme-on-surface), 0.06); border-radius: 12px; }
.msg-row { display: flex; align-items: flex-end; gap: 8px; }
.msg-row.mine { flex-direction: row-reverse; }
.msg-row.system { justify-content: center; }
.msg-system { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.55); padding: 3px 10px; background: rgba(var(--v-theme-on-surface), 0.04); border-radius: 8px; display: inline-flex; align-items: center; gap: 4px; }
.msg-system-time { color: rgba(var(--v-theme-on-surface), 0.4); margin-left: 4px; font-size: 11px; font-variant-numeric: tabular-nums; }
.msg-avatar { flex-shrink: 0; }
.avatar-circle { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: #fff; letter-spacing: -0.3px; }
.avatar-circle.partner { background: #f97316; }
.avatar-circle.staff { background: rgb(var(--v-theme-primary)); }
.msg-bubble { max-width: 65%; padding: 8px 12px; border-radius: 14px; position: relative; line-height: 1.4; }
.msg-bubble.partner { background: rgba(var(--v-theme-on-surface), 0.06); border-bottom-left-radius: 4px; }
.msg-bubble.mine { background: rgb(var(--v-theme-primary)); color: #fff; border-bottom-right-radius: 4px; }
.msg-sender { font-size: 11px; font-weight: 600; margin-bottom: 2px; color: #f97316; }
.msg-bubble.mine .msg-sender { color: rgba(255,255,255,0.85); }
.msg-text { font-size: 14px; line-height: 1.45; white-space: pre-line; word-break: break-word; }
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

.msg-edit-area { width: 100%; border: 1px solid rgba(var(--v-theme-primary), 0.5); border-radius: 8px; padding: 6px 10px; font-size: 13px; background: rgba(var(--v-theme-surface), 1); color: rgb(var(--v-theme-on-surface)); resize: vertical; font-family: inherit; outline: none; }

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
.msg-edit-btn.save { background: rgb(var(--v-theme-primary)); color: #fff; }

.typing-indicator { display: flex; align-items: center; gap: 8px; padding: 6px 14px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.5); font-style: italic; }
.typing-dots { display: inline-flex; gap: 3px; }
.typing-dots span { width: 5px; height: 5px; border-radius: 50%; background: rgba(var(--v-theme-on-surface), 0.4); animation: typing-blink 1.2s infinite ease-in-out; }
.typing-dots span:nth-child(2) { animation-delay: 0.15s; }
.typing-dots span:nth-child(3) { animation-delay: 0.3s; }
@keyframes typing-blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }

.jump-to-bottom { position: absolute; right: 24px; bottom: 90px; display: flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 16px; background: rgb(var(--v-theme-primary)); color: #fff; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 12px; font-weight: 600; z-index: 5; }

.reply-bar { display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: rgba(var(--v-theme-primary), 0.06); border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-left: 3px solid rgb(var(--v-theme-primary)); }
.reply-bar-body { flex: 1; min-width: 0; font-size: 12px; }
.reply-bar-sender { font-weight: 700; color: rgb(var(--v-theme-primary)); }
.reply-bar-text { color: rgba(var(--v-theme-on-surface), 0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.reply-bar-close { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }

.chat-input { display: flex; align-items: flex-end; gap: 8px; padding: 10px 16px; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); position: relative; transition: background 0.15s; }
.chat-input.drag-over { background: rgba(var(--v-theme-primary), 0.08); }
.input-btn { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 6px; border-radius: 8px; }
.input-btn:hover { background: rgba(var(--v-theme-primary), 0.1); }
.input-area { flex: 1; }
.input-area textarea { width: 100%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 8px 12px; font-size: 13px; resize: none; background: rgba(var(--v-theme-surface-variant), 0.3); color: inherit; outline: none; font-family: inherit; }
.input-area textarea:focus { border-color: rgb(var(--v-theme-primary)); }
.input-file-preview { display: flex; align-items: center; gap: 8px; margin-top: 6px; padding: 6px 8px; border-radius: 10px; background: rgba(var(--v-theme-primary), 0.08); border: 1px solid rgba(var(--v-theme-primary), 0.2); }
.input-file-preview img { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; }
.input-file-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: rgba(var(--v-theme-primary), 0.15); color: rgb(var(--v-theme-primary)); }
.input-file-info { flex: 1; min-width: 0; }
.input-file-name { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.input-file-size { font-size: 10px; color: rgba(var(--v-theme-on-surface), 0.5); }
.input-file-remove { background: none; border: none; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.5); padding: 4px; border-radius: 6px; }
.drop-overlay { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; background: rgba(var(--v-theme-primary), 0.15); border: 2px dashed rgb(var(--v-theme-primary)); border-radius: 8px; color: rgb(var(--v-theme-primary)); font-weight: 600; font-size: 13px; pointer-events: none; z-index: 10; }
.input-send { background: rgb(var(--v-theme-primary)); color: #fff; border: none; border-radius: 10px; padding: 8px 12px; cursor: pointer; }
.input-send:disabled { opacity: 0.4; cursor: not-allowed; }

.hotkey-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px dashed rgba(var(--v-border-color), 0.3); font-size: 13px; }
.hotkey-row:last-of-type { border-bottom: none; }
.hotkey-row kbd { display: inline-block; padding: 2px 8px; border-radius: 6px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-surface-variant), 0.5); font-family: ui-monospace, monospace; font-size: 11px; font-weight: 600; min-width: 24px; text-align: center; }
.hotkey-row span { flex: 1; color: rgba(var(--v-theme-on-surface), 0.8); }

/* ================== VIEW TOGGLE ================== */
.view-toggle {
  position: fixed;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  z-index: 6;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 6px;
  border-radius: 16px;
  background: rgba(var(--v-theme-surface), 0.72);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}
.view-toggle-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  width: 64px;
  padding: 10px 6px;
  border-radius: 12px;
  border: none;
  background: transparent;
  color: rgba(var(--v-theme-on-surface), 0.55);
  cursor: pointer;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.2px;
  transition: all 0.18s ease;
}
.view-toggle-btn:hover {
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-on-surface), 0.06);
}
.view-toggle-btn.active {
  background: rgb(var(--v-theme-primary));
  color: #fff;
  box-shadow: 0 4px 12px rgba(var(--v-theme-primary), 0.35);
  transform: scale(1.02);
}
.view-toggle-btn.active:hover {
  background: rgb(var(--v-theme-primary));
}
.view-toggle-label {
  display: inline;
  line-height: 1;
}

/* Sidebar compact variant in Kanban mode — list acts like quick filter preview */
.chat-sidebar.compact { width: 260px; }
.chat-sidebar.compact .chat-item-bottom .chat-item-status-chip { display: none; }

/* ================== KANBAN ================== */
.kanban-wrap { flex: 1; display: flex; flex-direction: column; min-width: 0; background: rgba(var(--v-theme-surface-variant), 0.2); }

/* Toolbar */
.kanban-toolbar { display: flex; align-items: center; gap: 12px; padding: 56px 16px 10px; flex-wrap: wrap; border-bottom: 1px solid rgba(var(--v-border-color), 0.25); }
.toolbar-toggle { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 8px; font-size: 11px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.7); cursor: pointer; user-select: none; }
.toolbar-toggle input { cursor: pointer; }
.toolbar-toggle:has(input:checked) { background: rgba(var(--v-theme-primary), 0.12); color: rgb(var(--v-theme-primary)); }
.toolbar-group { display: inline-flex; align-items: center; gap: 4px; }
.toolbar-label { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.5); margin-right: 4px; }
.toolbar-chip { padding: 3px 9px; border-radius: 12px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: transparent; color: rgba(var(--v-theme-on-surface), 0.7); font-size: 11px; cursor: pointer; white-space: nowrap; transition: all 0.15s; display: inline-flex; align-items: center; gap: 4px; }
.toolbar-chip:hover { background: rgba(var(--v-theme-primary), 0.06); }
.toolbar-chip.active { background: rgb(var(--v-theme-primary)); color: #fff; border-color: rgb(var(--v-theme-primary)); }
.bulk-toggle { margin-left: auto; }

.kanban-board { flex: 1; display: flex; gap: 12px; padding: 12px 16px; overflow-x: auto; align-items: flex-start; }
.kanban-column { flex: 1; min-width: 260px; max-width: 320px; display: flex; flex-direction: column; background: rgba(var(--v-theme-surface), 0.9); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-top: 3px solid var(--col-color); border-radius: 12px; overflow: hidden; transition: all 0.15s; }
.kanban-column.drop-target { background: rgba(var(--v-theme-primary), 0.08); border-color: rgb(var(--v-theme-primary)); border-top-color: rgb(var(--v-theme-primary)); box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.3); }
.kanban-col-head { display: flex; align-items: center; gap: 6px; padding: 10px 14px; border-bottom: 1px solid rgba(var(--v-border-color), 0.3); background: rgba(var(--v-theme-surface-variant), 0.3); }
.kanban-col-title { flex: 1; font-size: 13px; font-weight: 700; color: rgba(var(--v-theme-on-surface), 0.8); }
.kanban-col-count { font-size: 11px; padding: 2px 8px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), 0.08); color: rgba(var(--v-theme-on-surface), 0.6); font-weight: 600; }
.kanban-col-body { flex: 1; overflow-y: auto; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.kanban-col-empty { text-align: center; padding: 20px 0; font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.3); }

/* Swimlanes */
.swimlane-head { display: flex; align-items: center; gap: 6px; padding: 3px 8px; margin: 8px 0 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: rgba(var(--v-theme-on-surface), 0.5); border-left: 3px solid rgba(var(--v-theme-on-surface), 0.2); background: rgba(var(--v-theme-surface-variant), 0.3); border-radius: 0 4px 4px 0; }
.swimlane-head:first-child { margin-top: 0; }
.swimlane-count { margin-left: auto; padding: 1px 6px; border-radius: 8px; background: rgba(var(--v-theme-on-surface), 0.08); color: rgba(var(--v-theme-on-surface), 0.6); font-weight: 600; font-size: 9px; letter-spacing: 0; }
.swimlane-drop { display: flex; flex-direction: column; gap: 6px; min-height: 30px; border-radius: 8px; transition: background 0.15s; padding: 2px; }
.swimlane-drop.drop-target { background: rgba(var(--v-theme-primary), 0.1); outline: 2px dashed rgba(var(--v-theme-primary), 0.4); outline-offset: -2px; }

/* Kanban card — Vuetify v-card variant="outlined" + лёгкий hover-эффект */
.kanban-card { cursor: grab; transition: all 0.15s; user-select: none; position: relative; }
.kanban-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); transform: translateY(-1px); }
.kanban-card:hover .kanban-quick-actions { opacity: 1; pointer-events: auto; }
.kanban-card.is-dragging { opacity: 0.4; cursor: grabbing; }
.kanban-card.stale { background: rgba(var(--v-theme-error), 0.04); }
.kanban-card.bulk-mode { cursor: pointer; }
.kanban-card.selected { border-color: rgb(var(--v-theme-primary)) !important; background: rgba(var(--v-theme-primary), 0.08); box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.3); }
.kanban-card-avatar { width: 22px; height: 22px; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kanban-card-subject { line-height: 1.3; }

/* Quick actions on card hover */
.kanban-quick-actions { position: absolute; top: 4px; right: 4px; display: flex; gap: 1px; padding: 2px; border-radius: 6px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), 0.2); box-shadow: 0 2px 6px rgba(0,0,0,0.08); opacity: 0; pointer-events: none; transition: opacity 0.15s; }

/* Bulk action bar */
/* Bulk bar (kanban + list) — Vuetify-first, остался лишь sticky-layout */
.bulk-bar { position: sticky; bottom: 0; background: rgb(var(--v-theme-surface)); border-top: 1px solid rgba(var(--v-border-color), 0.12); box-shadow: 0 -4px 16px rgba(0,0,0,0.08); }
.chat-item.bulk-mode { padding-left: 36px; }
.chat-item.selected { background: rgba(var(--v-theme-primary), 0.08); }
.bulk-slide-enter-active, .bulk-slide-leave-active { transition: transform 0.2s ease, opacity 0.2s ease; }
.bulk-slide-enter-from, .bulk-slide-leave-to { transform: translateY(100%); opacity: 0; }

/* Partner context panel (right sidebar in list mode) */
/* Right partner-context panel — Vuetify-first, остался лишь layout */
.context-panel { width: 320px; flex-shrink: 0; border-left: 1px solid rgba(var(--v-border-color), 0.12); display: flex; flex-direction: column; background: rgba(var(--v-theme-surface), 1); overflow: hidden; }
.context-body { flex: 1; overflow-y: auto; }
.ctx-link { color: rgba(var(--v-theme-on-surface), 0.7); text-decoration: none; }
.ctx-link:hover { color: rgb(var(--v-theme-primary)); }
.ctx-num { font-variant-numeric: tabular-nums; }
.ctx-list :deep(.v-list-item) { min-height: 32px; padding-inline: 0 !important; }

/* Kanban mode: suppress chat-main, sidebar acts as filter strip */
.chat-wrap.kanban-mode .chat-main { display: none; }
.chat-wrap.kanban-mode .context-panel { display: none; }

@media (max-width: 959px) {
  .chat-sidebar { width: 100%; }
  .mobile-hidden { display: none !important; }
  .view-toggle-label { display: none; }
  .view-toggle { right: 8px; padding: 4px; }
  .view-toggle-btn { width: 44px; padding: 8px 4px; }
  .kanban-board { padding: 56px 8px 8px; }
  .kanban-column { min-width: 220px; }
  .chat-sidebar.compact { display: none !important; }
}
</style>
