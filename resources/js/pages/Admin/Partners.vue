<template>
  <div>
    <!-- Крупной зелёной кнопки в шапке больше нет: по макету шапка — это имя
         раздела и счётчик, а не место для призыва к действию. Добавление
         партнёра осталось доступным — оно переехало в панель управления
         списком, спокойной кнопкой рядом с фильтрами. -->
    <PageHeader title="Партнёры" icon="mdi-account-search" :count="total" />

    <!-- Одна строка поиска вместо семи полей. Раньше семь инпутов стояли в ряд
         с подсказкой вместо подписи: подсказка исчезает при вводе, и через
         минуту не отличить «ИД» от «Кода». Тип ввода теперь определяется по
         форме, редкие фильтры уехали в отдельное окно. -->
    <v-card class="mb-3 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <v-text-field v-model="q" placeholder="Имя, ID, email, телефон или код…"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-magnify"
          class="p-search" style="min-width: 280px; flex: 1 1 420px"
          @update:model-value="debouncedLoad">
          <template #append-inner>
            <span v-if="qKindLabel" class="p-qhint">ищу по: {{ qKindLabel }}</span>
            <kbd v-else class="p-kbd" title="Нажмите «/», чтобы встать в поиск">/</kbd>
          </template>
        </v-text-field>

        <v-btn variant="outlined" prepend-icon="mdi-tune-variant"
          :color="filterCount ? 'primary' : undefined" @click="openFilters">
          Фильтры
          <v-chip v-if="filterCount" size="x-small" color="primary" variant="flat" class="ml-2">
            {{ filterCount }}
          </v-chip>
        </v-btn>

        <!-- Применение и сохранение сегмента — в одном меню. Раньше рядом
             стояли селект «Сегмент» и кнопка «Сегмент»: одно слово, два
             разных действия. -->
        <v-menu>
          <template #activator="{ props: segProps }">
            <v-btn v-bind="segProps" variant="outlined" append-icon="mdi-chevron-down">
              Сегменты
            </v-btn>
          </template>
          <v-list density="compact" min-width="240">
            <v-list-item v-for="s in segments" :key="s.id" :title="s.name"
              prepend-icon="mdi-filter-outline" @click="applySegment(s.id)">
              <template #append>
                <v-btn icon="mdi-delete" size="x-small" variant="text" color="error"
                  aria-label="Удалить сегмент" @click.stop="deleteSegment(s.id)" />
              </template>
            </v-list-item>
            <v-list-item v-if="!segments.length" disabled
              title="Сохранённых сегментов нет" class="text-medium-emphasis" />
            <v-divider class="my-1" />
            <v-list-item prepend-icon="mdi-content-save-outline"
              title="Сохранить текущий фильтр" @click="saveSegment" />
          </v-list>
        </v-menu>

        <ColumnVisibilityMenu
          :headers="toggleableColumns"
          v-model:visible="columnVisible"
          storage-key="partners-cols" />

        <v-spacer />

        <v-btn v-if="canEdit('partners')" variant="outlined" prepend-icon="mdi-plus"
          @click="openAddPartner">Добавить партнёра</v-btn>
      </div>

      <!-- «Найдено N из M» и активные фильтры чипами: раньше число в шапке при
           включённом фильтре не отвечало, сколько именно нашлось. -->
      <div class="d-flex align-center flex-wrap ga-2 mt-3">
        <span class="text-caption text-medium-emphasis">
          Найдено <strong class="text-high-emphasis">{{ total }}</strong>
          <template v-if="grandTotal && total !== grandTotal"> из {{ grandTotal }}</template>
        </span>
        <v-chip v-for="c in activeChips" :key="c.key" size="small" variant="tonal"
          closable @click:close="clearChip(c.key)">{{ c.label }}</v-chip>
        <v-btn v-if="activeChips.length" size="x-small" variant="text" color="secondary"
          @click="resetFilters">Сбросить всё</v-btn>
      </div>
    </v-card>

    <!-- ===== Окно фильтров =====
         Не выпадашка: её сносит краем экрана, и календарь на ноутбуке уже не
         помещается. В окне влезают и счётчики, и месячная сетка. Меняем копию
         состояния — «Отмена» откатывает всё, список не дёргается по пути. -->
    <DialogShell
      v-model="filtersOpen"
      title="Фильтры"
      :max-width="660"
      @confirm="applyFilters"
    >
      <section class="p-fsec">
        <h4 class="p-fsec__title">Активность</h4>
        <div class="d-flex flex-wrap ga-2">
          <v-chip v-for="o in activityOptions" :key="o.value"
            :variant="draft.activity.includes(o.value) ? 'flat' : 'outlined'"
            :color="draft.activity.includes(o.value) ? 'primary' : undefined"
            size="small" @click="toggleActivity(o.value)">
            <i class="p-status__dot mr-2" :class="`p-status--${o.value}`" />
            {{ o.title }}
            <span v-if="activityCounts[o.value] != null" class="p-fsec__cnt">
              {{ activityCounts[o.value] }}
            </span>
          </v-chip>
        </div>
      </section>

      <section class="p-fsec">
        <h4 class="p-fsec__title">Пригласивший</h4>
        <v-autocomplete v-model="draft.inviterName" :items="invFilterItems"
          :loading="invFilterLoading" v-model:search="invFilterQuery"
          placeholder="Начните вводить фамилию…" variant="outlined" density="compact"
          hide-details clearable no-filter hide-no-data />
        <p class="p-fsec__hint">Ищет по вхождению — можно ввести только фамилию.</p>
      </section>

      <section class="p-fsec">
        <h4 class="p-fsec__title">Дата регистрации</h4>
        <v-btn-toggle v-model="draft.dateMode" mandatory density="compact"
          variant="outlined" divided color="primary" class="mb-4 p-datemode">
          <v-btn value="quick" size="small">Быстро</v-btn>
          <v-btn value="year" size="small">Год</v-btn>
          <v-btn value="month" size="small">Месяц</v-btn>
          <v-btn value="range" size="small">Период</v-btn>
        </v-btn-toggle>

        <div v-if="draft.dateMode === 'quick'" class="d-flex flex-wrap ga-2">
          <v-chip v-for="p in QUICK_RANGES" :key="p.key" size="small"
            :variant="draft.datePreset === p.key ? 'flat' : 'outlined'"
            :color="draft.datePreset === p.key ? 'primary' : undefined"
            @click="pickQuick(p)">{{ p.title }}</v-chip>
        </div>

        <div v-else-if="draft.dateMode === 'year'" class="d-flex flex-wrap ga-2">
          <v-chip v-for="y in yearOptions" :key="y" size="small"
            :variant="draft.datePreset === String(y) ? 'flat' : 'outlined'"
            :color="draft.datePreset === String(y) ? 'primary' : undefined"
            @click="pickYear(y)">{{ y }}</v-chip>
        </div>

        <div v-else-if="draft.dateMode === 'month'">
          <div class="d-flex align-center ga-2 mb-3">
            <v-btn icon="mdi-chevron-left" size="x-small" variant="text"
              aria-label="Предыдущий год" @click="draft.monthYear--" />
            <strong>{{ draft.monthYear }}</strong>
            <v-btn icon="mdi-chevron-right" size="x-small" variant="text"
              aria-label="Следующий год" @click="draft.monthYear++" />
            <span class="text-caption text-medium-emphasis">— выберите месяц</span>
          </div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip v-for="(m, i) in MONTHS" :key="m" size="small"
              :variant="draft.datePreset === monthKey(i) ? 'flat' : 'outlined'"
              :color="draft.datePreset === monthKey(i) ? 'primary' : undefined"
              @click="pickMonth(i)">{{ m }}</v-chip>
          </div>
        </div>

        <div v-else class="d-flex align-center ga-2">
          <v-text-field v-model="draft.from" type="date" label="с"
            variant="outlined" density="compact" hide-details
            @update:model-value="draft.datePreset = ''" />
          <span class="text-medium-emphasis">—</span>
          <v-text-field v-model="draft.to" type="date" label="по"
            variant="outlined" density="compact" hide-details
            @update:model-value="draft.datePreset = ''" />
        </div>

        <p class="p-fsec__hint">{{ dateSummary }}</p>
      </section>

      <section class="p-fsec">
        <h4 class="p-fsec__title">Признаки</h4>
        <v-checkbox v-model="draft.onlyClient" density="compact" hide-details
          color="primary" label="Только клиенты" />
        <v-checkbox v-model="draft.onlyBlocked" density="compact" hide-details
          color="primary" label="Только заблокированные" />
        <p class="p-fsec__hint">
          Заблокированные — те, у кого закрыт вход в кабинет. Партнёры без логина
          сюда не попадают: закрывать у них нечего.
        </p>
      </section>

      <template #actions>
        <v-btn variant="text" @click="resetDraft">Сбросить всё</v-btn>
        <v-spacer />
        <v-btn variant="text" @click="filtersOpen = false">Отмена</v-btn>
        <!-- «Показать N» считается на сервере по черновику: видно, сколько
             останется, ещё до применения — и не бывает «нажал, а пусто». -->
        <v-btn color="primary" variant="flat" :loading="previewLoading"
          @click="applyFilters">
          Показать<span v-if="previewCount !== null" class="ml-1">{{ previewCount }}</span>
        </v-btn>
      </template>
    </DialogShell>

    <!-- ===== Карточка партнёра =====
         Шторка справа, а не модальное окно на весь экран: список остаётся
         виден, и людей можно просматривать подряд. Редактирование открывает
         СУЩЕСТВУЮЩУЮ форму — вторую такую же не заводим. -->
    <v-overlay v-model="cardOpen" scroll-strategy="block"
      class="d-flex align-stretch justify-end" content-class="p-card">
      <v-card v-if="cardItem" class="p-card__inner d-flex flex-column" rounded="0">
        <div class="p-card__head">
          <div class="d-flex align-start ga-3">
            <v-avatar :color="activityColor(cardItem)" variant="tonal" size="46">
              <span class="text-subtitle-2">{{ getInitials(cardItem.personName) }}</span>
            </v-avatar>
            <div class="flex-grow-1" style="min-width: 0">
              <div class="p-card__name">{{ cardItem.personName }}</div>
              <div class="d-flex align-center ga-2 mt-1 flex-wrap">
                <span class="p-status" :class="`p-status--${cardItem.activityId || 0}`">
                  <i class="p-status__dot" />{{ activityLabel(cardItem) }}
                </span>
                <span class="text-caption text-medium-emphasis">ID {{ cardItem.id }}</span>
                <v-btn icon="mdi-content-copy" size="x-small" variant="text"
                  title="Скопировать ID" @click="copyToClipboard(cardItem.id)" />
              </div>
            </div>
            <v-btn icon="mdi-close" variant="text" size="small"
              aria-label="Закрыть" @click="cardOpen = false" />
          </div>
          <div class="d-flex align-center ga-2 mt-3">
            <v-btn color="primary" variant="flat" size="small" prepend-icon="mdi-pencil"
              @click="openEditFromCard">Редактировать</v-btn>
            <StartChatButton :partner-id="cardItem.id" :partner-name="cardItem.personName" silent />
          </div>
        </div>

        <!-- Вкладки ведут в существующие разделы, а не в заглушки: карточка
             должна быть входом в них, а не их копией. -->
        <v-tabs v-model="cardTab" density="compact" class="p-card__tabs">
          <v-tab value="main">Обзор</v-tab>
          <v-tab value="log">История</v-tab>
        </v-tabs>

        <div v-if="cardTab === 'log'" class="p-card__body">
          <div v-if="cardEvents.length" class="p-tl">
            <div v-for="(e, i) in cardEvents" :key="i" class="p-tl__row">
              <i class="p-tl__dot" :style="{ background: e.color }" />
              <time>{{ e.date }}</time>
              <span>{{ e.text }}</span>
            </div>
          </div>
          <p v-else class="p-fsec__hint">Событий по этому партнёру не записано.</p>
          <p class="p-fsec__hint">
            Хронология собрана из полей карточки. Полный журнал правок — в форме
            редактирования, вкладка «История».
          </p>
        </div>

        <div v-else class="p-card__body">
          <!-- Три числа, как в макете. В списке их нет: они приходят отдельным
               запросом карточки (/admin/partners/{id} → snapshot), иначе их
               пришлось бы считать на все 1968 строк ради открытой одной. -->
          <div class="p-card__stats">
            <div class="p-card__stat">
              <div class="p-card__stat-l">Квалификация</div>
              <div class="p-card__stat-v">
                {{ cardSnapshot?.level ? cardSnapshot.level + ' ур.' : '—' }}
              </div>
            </div>
            <div class="p-card__stat">
              <div class="p-card__stat-l">ГП за месяц</div>
              <div class="p-card__stat-v">
                {{ cardSnapshot?.groupVolume != null ? fmtNum(Math.round(cardSnapshot.groupVolume)) : '—' }}
              </div>
            </div>
            <div class="p-card__stat">
              <div class="p-card__stat-l">Остаток</div>
              <div class="p-card__stat-v"
                :class="(cardSnapshot?.remaining ?? 0) < 0 ? 'p-card__stat-v--neg' : ''">
                {{ cardSnapshot?.remaining != null
                  ? fmtNum(Math.round(cardSnapshot.remaining)) + ' ₽' : '—' }}
              </div>
            </div>
          </div>

          <h5 class="p-card__sec">Контакты</h5>
          <div class="p-card__row">
            <span class="p-card__k">Email</span>
            <span class="p-card__v">{{ cardItem.email || '—' }}</span>
            <v-btn v-if="cardItem.email" class="p-card__act" icon="mdi-content-copy"
              size="x-small" variant="text" title="Скопировать"
              @click="copyToClipboard(cardItem.email)" />
          </div>
          <div class="p-card__row">
            <span class="p-card__k">Телефон</span>
            <span class="p-card__v">{{ cardItem.phone || '—' }}</span>
            <v-btn v-if="cardItem.phone" class="p-card__act" icon="mdi-content-copy"
              size="x-small" variant="text" title="Скопировать"
              @click="copyToClipboard(cardItem.phone)" />
          </div>
          <div class="p-card__row">
            <span class="p-card__k">Дата рождения</span>
            <span class="p-card__v">{{ fmtDate(cardItem.birthDate) || '—' }}</span>
          </div>

          <h5 class="p-card__sec">Партнёрство</h5>
          <div class="p-card__row">
            <span class="p-card__k">Реф. код</span>
            <span class="p-card__v">
              {{ cardItem.participantCode || '' }}
              <span v-if="!cardItem.participantCode" class="text-medium-emphasis">не выдан</span>
            </span>
          </div>
          <div class="p-card__row" :class="cardItem.inviterName ? 'p-card__row--link' : ''"
            @click="cardItem.inviterName && filterByInviter(cardItem.inviterName)">
            <span class="p-card__k">Пригласивший</span>
            <span class="p-card__v">
              {{ cardItem.inviterName || '' }}
              <span v-if="!cardItem.inviterName" class="text-medium-emphasis">нет</span>
            </span>
            <v-icon v-if="cardItem.inviterName" class="p-card__act" size="14"
              icon="mdi-filter-outline" title="Показать всех, кого он пригласил" />
          </div>
          <div class="p-card__row">
            <span class="p-card__k">Личный объём</span>
            <span class="p-card__v">{{ fmtNum(cardItem.personalVolume) }}</span>
          </div>
          <div class="p-card__row">
            <span class="p-card__k">НГП</span>
            <span class="p-card__v">{{ fmtNum(cardItem.groupVolumeCumulative) }}</span>
          </div>
          <div class="p-card__row">
            <span class="p-card__k">Терминаций</span>
            <span class="p-card__v">{{ cardItem.terminationCount || 0 }}</span>
          </div>
          <div class="p-card__row">
            <span class="p-card__k">Регистрация</span>
            <span class="p-card__v">{{ fmtDate(cardItem.createdAt) || '—' }}</span>
          </div>
          <div class="p-card__row">
            <span class="p-card__k">Смена статуса</span>
            <span class="p-card__v" :class="isStatusChangeSoon(cardItem) ? 'text-error' : ''">
              {{ fmtDate(cardItem.statusChangeDate) || '—' }}
            </span>
          </div>

          <h5 class="p-card__sec">Доступ</h5>
          <div class="p-card__row">
            <span class="p-card__k">Кабинет</span>
            <span class="p-card__v">
              <span v-if="cardItem.isBlocked" class="text-error">заблокирован</span>
              <span v-else-if="cardItem.platformAccess" class="text-success">открыт</span>
              <span v-else class="text-medium-emphasis">логина нет</span>
            </span>
          </div>
          <div class="p-card__row">
            <span class="p-card__k">Клиент</span>
            <span class="p-card__v">
              <span v-if="cardItem.isClient">да, есть контракты</span>
              <span v-else class="text-medium-emphasis">нет</span>
            </span>
          </div>
        </div>
      </v-card>
    </v-overlay>

    <!-- Bulk action bar: two primary actions + destructive + overflow menu -->
    <v-slide-y-transition>
      <v-card v-if="selected.length" class="mb-3 pa-3" color="primary" variant="tonal">
        <div class="d-flex align-center flex-wrap ga-2">
          <v-chip color="primary" variant="flat">
            <v-icon start size="16">mdi-checkbox-multiple-marked</v-icon>
            Выбрано: {{ selected.length }}
          </v-chip>
          <v-btn size="small" variant="tonal" color="success"
            prepend-icon="mdi-account-check" @click="bulkRun('activate')">Активировать</v-btn>
          <v-btn size="small" variant="tonal" color="warning"
            prepend-icon="mdi-account-cancel" @click="bulkRun('terminate')">Терминировать</v-btn>
          <v-btn size="small" variant="tonal" color="error"
            prepend-icon="mdi-account-remove" @click="bulkRun('exclude')">Исключить</v-btn>
          <v-menu>
            <template #activator="{ props: menuProps }">
              <v-btn size="small" variant="text" append-icon="mdi-chevron-down" v-bind="menuProps">Ещё</v-btn>
            </template>
            <v-list density="compact">
              <v-list-item prepend-icon="mdi-account-reactivate" title="Перерегистрировать" @click="bulkRun('re-register')" />
              <v-list-item prepend-icon="mdi-lock" title="Заблокировать" @click="bulkRun('block')" />
              <v-list-item prepend-icon="mdi-lock-open" title="Разблокировать" @click="bulkRun('unblock')" />
              <v-list-item prepend-icon="mdi-account-supervisor" title="Сменить наставника" @click="bulkSetInviter" />
            </v-list>
          </v-menu>
          <v-spacer />
          <v-btn size="small" variant="text" prepend-icon="mdi-close" @click="selected = []">Снять выбор</v-btn>
        </div>
        <v-alert v-if="bulkMsg" :type="bulkMsgType" density="compact" class="mt-2" closable @click:close="bulkMsg = ''">
          {{ bulkMsg }}
        </v-alert>
      </v-card>
    </v-slide-y-transition>

    <!-- ===== Таблица =====
         Разметка своя, а не v-data-table. Страницу трижды подгоняли под
         согласованный макет, и каждый раз ломались внутренности таблицы
         Vuetify: липкие колонки через nth-child, отступы ячеек, типографика
         заголовков, невидимый статус. Здесь разметка делает ровно то, что
         нарисовано; Vuetify остаётся на меню, диалогах и кнопках. -->
    <v-card class="p-tablecard">
      <div class="p-scroll">
        <table class="p-table">
          <thead>
            <tr>
              <th class="p-th p-th--check p-sticky" :style="stickyLeft(0)">
                <input type="checkbox" class="p-check" :checked="allOnPage"
                  :indeterminate.prop="someOnPage" aria-label="Выбрать всю страницу"
                  @change="togglePage($event.target.checked)">
              </th>
              <th v-for="(c, i) in visibleHeaders" :key="c.key"
                :class="['p-th', {
                  'p-sticky': isSticky(i),
                  'p-edge': isLastSticky(i),
                  'p-th--sortable': isSortable(c),
                }]"
                :style="[stickyLeft(i + 1), c.width ? { width: c.width + 'px' } : null]"
                @click="isSortable(c) && toggleSort(c)">
                {{ c.title }}
                <span v-if="sortBy === sortKeyOf(c)" class="p-sort">
                  {{ sortDir === 'asc' ? '↑' : '↓' }}
                </span>
              </th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="item in items" :key="item.id"
              :class="['p-tr', `row-activity-${item.activityId || 0}`, { 'p-tr--sel': isSelected(item) }]"
              @click="openCard(item)">
              <td class="p-td p-td--check p-sticky" :style="stickyLeft(0)" @click.stop>
                <input type="checkbox" class="p-check" :checked="isSelected(item)"
                  :aria-label="`Выбрать ${item.personName}`"
                  @change="toggleOne(item, $event.target.checked)">
              </td>

              <td v-for="(c, i) in visibleHeaders" :key="c.key"
                :class="['p-td', { 'p-sticky': isSticky(i), 'p-edge': isLastSticky(i) }]"
                :style="stickyLeft(i + 1)">

                <!-- ID: копирование появляется по наведению на строку -->
                <template v-if="c.key === 'id'">
                  <span class="p-id">
                    <span class="p-id__num">{{ item.id }}</span>
                    <v-btn class="p-id__copy" icon="mdi-content-copy" size="x-small"
                      variant="text" title="Скопировать ID"
                      @click.stop="copyToClipboard(item.id)" />
                  </span>
                </template>

                <!-- Имя одной строкой, контакт вторым этажом. Колонки Email,
                     Телефон, «Клиент» и «Доступ» убраны: они переехали сюда. -->
                <template v-else-if="c.key === 'personName'">
                  <div class="p-name__row">
                    <span class="p-name__text" :title="item.personName">
                      {{ shortName(item.personName) }}
                    </span>
                    <v-icon v-if="item.isClient" size="14" color="secondary"
                      title="Партнёр является клиентом">mdi-account-tie</v-icon>
                    <!-- Именно isBlocked, а не !platformAccess: последний false
                         и у партнёров без логина — замок висел бы на каждом
                         импортированном. -->
                    <v-icon v-if="item.isBlocked" size="14" color="error"
                      title="Доступ в кабинет заблокирован">mdi-lock</v-icon>
                  </div>
                  <div v-if="contactLine(item)" class="p-name__contact">
                    {{ contactLine(item) }}
                  </div>
                </template>

                <!-- Статус цветной точкой: одноцветные чипы не различались -->
                <template v-else-if="c.key === 'activityName'">
                  <span v-if="item.activityName" class="p-status"
                    :class="`p-status--${item.activityId || 0}`">
                    <i class="p-status__dot" />{{ activityLabel(item) }}
                  </span>
                  <span v-else class="p-muted">—</span>
                </template>

                <template v-else-if="c.key === 'statusChangeDate'">
                  <span v-if="item.statusChangeDate"
                    :class="isStatusChangeSoon(item) ? 'p-soon' : ''">
                    {{ fmtDate(item.statusChangeDate) }}
                  </span>
                  <span v-else class="p-muted">—</span>
                </template>

                <!-- Код — приглушённым: он служебный, а не то, что читают -->
                <template v-else-if="c.key === 'participantCode'">
                  <span v-if="item.participantCode" class="p-muted p-nowrap">
                    {{ item.participantCode }}
                  </span>
                  <span v-else class="p-muted">—</span>
                </template>

                <template v-else-if="c.key === 'birthDate' || c.key === 'createdAt'">
                  <span v-if="item[c.key]">{{ fmtDate(item[c.key]) }}</span>
                  <span v-else class="p-muted">—</span>
                </template>

                <!-- Действия: чат снаружи (им пользуются чаще всего),
                     остальное под «⋯». Три иконки в каждой строке на списке
                     в две тысячи строк превращаются в рябь. -->
                <template v-else-if="c.key === 'actions'">
                  <div class="p-actions" @click.stop>
                    <StartChatButton :partner-id="item.id"
                      :partner-name="item.personName" silent />
                    <v-menu location="bottom end">
                      <template #activator="{ props: menuProps }">
                        <v-btn v-bind="menuProps" icon="mdi-dots-horizontal"
                          size="x-small" variant="text" aria-label="Действия" />
                      </template>
                      <v-list density="compact" min-width="212">
                        <v-list-item prepend-icon="mdi-account-outline"
                          title="Открыть карточку" @click="openCard(item)" />
                        <v-list-item prepend-icon="mdi-pencil"
                          title="Редактировать" @click="openEdit(item)" />
                        <v-list-item prepend-icon="mdi-content-copy"
                          title="Скопировать ID" @click="copyToClipboard(item.id)" />
                        <template v-if="canEdit('partners')">
                          <v-divider class="my-1" />
                          <v-list-item prepend-icon="mdi-delete" title="Удалить"
                            base-color="error" @click="confirmDeletePartner(item)" />
                        </template>
                      </v-list>
                    </v-menu>
                  </div>
                </template>

                <!-- Пригласивший — «Фамилия Имя»: полное ФИО переносилось на
                     две строки и растягивало всю строку таблицы. Полное имя
                     остаётся в подсказке и в карточке. -->
                <template v-else-if="c.key === 'inviterName'">
                  <span v-if="item.inviterName" class="p-nowrap" :title="item.inviterName">
                    {{ shortInviter(item.inviterName) }}
                  </span>
                  <span v-else class="p-muted">—</span>
                </template>

                <template v-else>
                  <span v-if="item[c.key]" class="p-cellwrap">{{ item[c.key] }}</span>
                  <span v-else class="p-muted">—</span>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="loading" class="p-state">
        <v-progress-circular indeterminate size="26" width="3" color="primary" />
      </div>
      <div v-else-if="!items.length" class="p-state">
        <v-icon size="34" class="mb-2" color="disabled">mdi-account-search-outline</v-icon>
        <div>Партнёры не найдены</div>
        <div class="p-muted mt-1">Попробуйте снять часть фильтров.</div>
      </div>

      <!-- Постраничность своя: серверная выдача отдаёт страницу и total. -->
      <div v-if="items.length" class="p-foot">
        <span class="p-muted">Строки {{ rowsFrom }}–{{ rowsTo }} из {{ total }}</span>
        <v-spacer />
        <span class="p-muted">на странице</span>
        <v-select v-model="perPage" :items="[25, 50, 100, 200]" density="compact"
          variant="outlined" hide-details style="max-width: 92px"
          @update:model-value="() => { page = 1; loadData(); }" />
        <v-btn icon="mdi-chevron-left" size="small" variant="text" :disabled="page <= 1"
          aria-label="Предыдущая страница" @click="goPage(page - 1)" />
        <span class="p-foot__page">{{ page }} / {{ pageCount }}</span>
        <v-btn icon="mdi-chevron-right" size="small" variant="text"
          :disabled="page >= pageCount" aria-label="Следующая страница"
          @click="goPage(page + 1)" />
      </div>
    </v-card>

    <!-- Delete dialog -->
    <DialogShell
      v-model="deleteDialogOpen"
      title="Удалить партнёра?"
      :max-width="500"
      :loading="deleting"
      confirm-text="Удалить"
      confirm-color="error"
      @confirm="performDeletePartner"
    >
      <p class="mb-2">
        <strong>{{ deleteTarget?.personName }}</strong>
        (ID {{ deleteTarget?.id }})
      </p>
      <p class="text-body-2 text-medium-emphasis mb-3">
        Удаление — soft-delete (выставит <code>dateDeleted</code>). FK из
        контрактов/комиссий/транзакций сохраняются. Если у партнёра есть
        активные дети в структуре — сервер отклонит запрос.
      </p>
      <v-textarea v-model="deleteReason" label="Причина (для аудита)"
        variant="outlined" density="comfortable" rows="2" />
    </DialogShell>

    <!-- Edit dialog -->
    <v-dialog v-model="editDialog" max-width="920" persistent scrollable>
      <v-card v-if="editForm" class="pf-dialog">
        <!-- Шапка: кто редактируется и в каком он состоянии. Аватар + чипы
             статуса/реквизитов отвечают на «того ли я открыл» без прокрутки. -->
        <div class="pf-head">
          <v-avatar size="44" color="primary" variant="tonal">
            <span class="text-subtitle-2 font-weight-bold">{{ getInitials(editForm.personName) }}</span>
          </v-avatar>
          <div class="pf-head__main">
            <div class="pf-head__name text-truncate">
              {{ editForm.personName || 'Партнёр' }}
            </div>
            <div class="pf-head__meta">
              <v-chip size="x-small" variant="tonal" :color="editActivityColor">
                {{ editForm.activityName || '—' }}
              </v-chip>
              <v-chip size="x-small" variant="tonal"
                :color="editForm.requisitesVerified ? 'success' : 'warning'"
                :prepend-icon="editForm.requisitesVerified ? 'mdi-shield-check' : 'mdi-shield-alert-outline'">
                {{ editForm.requisitesVerified ? 'Реквизиты подтверждены' : 'Реквизиты не подтверждены' }}
              </v-chip>
              <span class="pf-head__id">ID {{ editForm.id }}</span>
              <v-btn icon="mdi-content-copy" size="x-small" variant="text"
                aria-label="Скопировать ID" @click="copyValue(editForm.id, 'ID партнёра')" />
            </div>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small"
            aria-label="Закрыть" @click="editDialog = false" />
        </div>
        <v-divider />

        <v-card-text class="pf-body">
          <div v-if="editLoading" class="text-center pa-6">
            <v-progress-circular indeterminate />
          </div>
          <template v-else>
            <v-form v-model="editFormValid">
            <!-- Спека «Верификация реквизитов Партнёра», Контур 3: ИП должно
                 быть оформлено на то же имя, что в профиле, поэтому смена ФИО
                 снимает верификацию. Предупреждаем ДО сохранения — иначе
                 сотрудник узнаёт о закрытом гейте выплат от партнёра. -->
            <v-alert v-if="editForm.requisitesVerified"
              :type="editNameChanged ? 'warning' : 'info'" density="compact"
              variant="tonal" class="mb-4"
              :icon="editNameChanged ? 'mdi-shield-off-outline' : 'mdi-shield-check-outline'">
              <div class="text-body-2">
                <strong>Реквизиты партнёра верифицированы.</strong>
                {{ editNameChanged
                  ? 'Сохранение снимет верификацию: партнёру откроется повторный ввод и отправка реквизитов, выплаты и «Продукты» — до новой проверки.'
                  : 'Изменение ФИО снимет верификацию — партнёру придётся отправить реквизиты повторно.' }}
              </div>
            </v-alert>

            <section class="pf-sec">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-account-outline</v-icon>
                <span class="pf-sec__title">ФИО</span>
                <span class="pf-sec__hint">Новое имя разойдётся по контрактам, клиентам и структуре</span>
              </header>
              <v-row dense>
                <v-col cols="12" sm="4"><v-text-field v-model="editForm.lastName" :rules="cyrillicOptionalRules" label="Фамилия" variant="outlined" density="compact" :error-messages="editErrors.lastName" /></v-col>
                <v-col cols="12" sm="4"><v-text-field v-model="editForm.firstName" :rules="cyrillicOptionalRules" label="Имя" variant="outlined" density="compact" :error-messages="editErrors.firstName" /></v-col>
                <v-col cols="12" sm="4"><v-text-field v-model="editForm.patronymic" :rules="cyrillicOptionalRules" label="Отчество" variant="outlined" density="compact" :error-messages="editErrors.patronymic" /></v-col>
              </v-row>
            </section>

            <section class="pf-sec">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-card-account-mail-outline</v-icon>
                <span class="pf-sec__title">Контакты</span>
                <span class="pf-sec__hint">Email — это логин в кабинет</span>
              </header>
              <v-row dense>
                <v-col cols="12" md="4"><v-text-field v-model="editForm.email" :rules="emailRules" label="Email" type="email" variant="outlined" density="compact" prepend-inner-icon="mdi-email-outline" :error-messages="editErrors.email" /></v-col>
                <v-col cols="12" md="4">
                  <!-- PhoneInput: статичный префикс «🇷🇺 +7» + маска (XXX) XXX-XX-XX.
                       v-model хранит «+79991234567» — формат сохранения не изменился. -->
                  <PhoneInput v-model="editForm.phone" label="Телефон"
                    :error-messages="editPhoneShowError ? 'Неверный номер телефона' : (editErrors.phone || [])"
                    @validate="onEditPhoneValidate" />
                </v-col>
                <v-col cols="12" md="4"><v-text-field v-model="editForm.nicTG" label="Telegram" variant="outlined" density="compact" prepend-inner-icon="mdi-send-outline" placeholder="@username" :error-messages="editErrors.nicTG" /></v-col>
              </v-row>
            </section>

            <section class="pf-sec">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-badge-account-horizontal-outline</v-icon>
                <span class="pf-sec__title">Персональные данные</span>
              </header>
              <v-row dense>
                <v-col cols="12" sm="4"><v-select v-model="editForm.gender" :items="genderOptions" label="Пол" variant="outlined" density="compact" clearable :error-messages="editErrors.gender" /></v-col>
                <v-col cols="12" sm="4"><v-text-field v-model="editBirthDate" type="date" label="Дата рождения" variant="outlined" density="compact" :error-messages="editErrors.birthDate" /></v-col>
                <v-col cols="12" sm="4">
                  <!-- Роли в БД — CSV-строка; в UI — массив. Список ролей —
                       зеркало Admin/Users.vue (источник: config/cabinetPermissions.js). -->
                  <v-select v-model="editFormRoles" :items="roleOptions"
                    label="Роль(и)" variant="outlined" density="compact"
                    multiple chips closable-chips clearable
                    hint="Определяет доступные разделы" persistent-hint
                    :error-messages="editErrors.role" />
                </v-col>
              </v-row>
            </section>

            <section class="pf-sec">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-file-tree-outline</v-icon>
                <span class="pf-sec__title">Сеть</span>
                <span class="pf-sec__hint">Смена наставника = перестановка: попадёт в историю и пересчитает комиссии</span>
              </header>
              <v-row dense>
                <v-col cols="12" sm="5">
                  <v-text-field v-model="editForm.participantCode" label="Реф. код"
                    variant="outlined" density="compact" prepend-inner-icon="mdi-pound"
                    hint="Уникальный код в ссылке-приглашении" persistent-hint
                    :error-messages="editErrors.participantCode" />
                </v-col>
                <v-col cols="12" sm="7">
                  <!-- Автокомплит по ФИО: ищем по personName / participantCode,
                       отдаём наверх consultant.id. В подсказке — ID и реф. код
                       текущего выбора, чтобы оператор видел всё нужное. -->
                  <v-autocomplete
                    v-model="editForm.inviter"
                    :items="inviterOptions"
                    item-title="personName"
                    item-value="id"
                    label="Пригласивший"
                    placeholder="Поиск по ФИО или коду"
                    prepend-inner-icon="mdi-account-search"
                    variant="outlined" density="compact"
                    clearable hide-no-data
                    :loading="inviterLoading"
                    :hint="inviterHint" persistent-hint
                    :error-messages="editErrors.inviter"
                    @update:search="onInviterSearch"
                  >
                    <template #item="{ props: itemProps, item }">
                      <v-list-item v-bind="itemProps">
                        <template #subtitle>
                          ID {{ item.raw.id }}<span v-if="item.raw.participantCode"> · код {{ item.raw.participantCode }}</span>
                        </template>
                      </v-list-item>
                    </template>
                  </v-autocomplete>
                </v-col>
              </v-row>
            </section>

            <!-- Доступ в кабинет: блокировка и пароль — про вход, а не про
                 структуру. Раньше «Заблокирован» стоял чекбоксом в «Сети»
                 рядом с наставником и читался как свойство дерева. -->
            <section class="pf-sec">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-lock-outline</v-icon>
                <span class="pf-sec__title">Доступ в кабинет</span>
              </header>
              <v-row dense align="center">
                <v-col cols="12" :sm="auth.isAdmin ? 5 : 12">
                  <v-switch v-model="editForm.isBlocked" color="error"
                    density="compact" hide-details inset
                    :label="editForm.isBlocked ? 'Заблокирован' : 'Доступ открыт'" />
                  <div class="pf-hint">
                    <v-icon size="13">mdi-information-outline</v-icon>
                    Блокировка отзывает активные сессии — партнёра выкинет из кабинета сразу.
                  </div>
                </v-col>
                <v-col v-if="auth.isAdmin" cols="12" sm="7">
                  <v-text-field v-model="editForm.newPassword"
                    :type="showNewPassword ? 'text' : 'password'"
                    label="Новый пароль"
                    placeholder="Пусто — не менять"
                    variant="outlined" density="compact"
                    prepend-inner-icon="mdi-key-variant"
                    :append-inner-icon="showNewPassword ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                    hint="Минимум 8 символов, буквы и цифры" persistent-hint
                    autocomplete="new-password"
                    :error-messages="editErrors.newPassword"
                    @click:append-inner="showNewPassword = !showNewPassword" />
                </v-col>
              </v-row>
            </section>
            </v-form>

            <!-- Кастомные поля пользователя (определяются в /admin/custom-fields). -->
            <section v-if="pcfFields.length" class="pf-sec">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-form-select</v-icon>
                <span class="pf-sec__title">Дополнительные сведения</span>
                <span class="pf-sec__hint">Поля из раздела «Кастомные поля»</span>
              </header>
              <v-row dense>
                <v-col v-for="pf in pcfFields" :key="pf.id" cols="12" sm="6">
                  <v-text-field v-if="pf.type === 'text' || pf.type === 'number'"
                    v-model="pcfValues[pf.id]" :type="pf.type === 'number' ? 'number' : 'text'"
                    :label="pf.label + (pf.required ? ' *' : '')" density="compact" hide-details />
                  <v-textarea v-else-if="pf.type === 'textarea'" v-model="pcfValues[pf.id]"
                    :label="pf.label + (pf.required ? ' *' : '')" rows="2" auto-grow density="compact" hide-details />
                  <v-text-field v-else-if="pf.type === 'date'" v-model="pcfValues[pf.id]" type="date"
                    :label="pf.label + (pf.required ? ' *' : '')" density="compact" hide-details />
                  <v-select v-else-if="pf.type === 'select'" v-model="pcfValues[pf.id]" :items="pf.options || []"
                    :label="pf.label + (pf.required ? ' *' : '')" density="compact" hide-details clearable />
                  <v-checkbox v-else-if="pf.type === 'checkbox'" v-model="pcfValues[pf.id]"
                    :label="pf.label + (pf.required ? ' *' : '')" density="compact" hide-details />
                </v-col>
              </v-row>
            </section>

            <!-- Блок «Смена статуса» — по праву statuses=full (admin получает
                 full на все секции). Раньше был жёстко auth.isAdmin, из-за чего
                 руководитель по расчётам блок не видел. Бэкенд гейтит тем же
                 правом: permission:statuses,full на /admin/partners/{id}/status. -->
            <section v-if="canFull('statuses')" class="pf-sec pf-sec--action">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-account-switch-outline</v-icon>
                <span class="pf-sec__title">Смена статуса</span>
                <span class="pf-sec__hint">Применяется сразу и требует причины</span>
                <v-spacer />
                <!-- История смены статуса: иконка с попапом по наведению.
                     Источник — Spatie Activitylog, поле activity у Consultant. -->
                <v-menu open-on-hover open-on-focus :close-on-content-click="false"
                  location="bottom end" offset="6">
                  <template #activator="{ props: tipProps }">
                    <v-btn v-bind="tipProps" size="x-small" variant="text" color="info"
                      prepend-icon="mdi-history" aria-label="История смены статуса">
                      История статусов
                    </v-btn>
                  </template>
                  <v-card min-width="320" max-width="460" class="pa-2">
                    <div class="text-subtitle-2 px-2 py-1">История смены статуса</div>
                    <v-divider />
                    <div v-if="statusHistoryLoading" class="pa-3 text-center">
                      <v-progress-circular indeterminate size="20" />
                    </div>
                    <div v-else-if="!statusHistory.length"
                      class="pa-3 text-caption text-medium-emphasis text-center">
                      Изменений пока нет
                    </div>
                    <v-list v-else density="compact" lines="two" class="py-0">
                      <v-list-item v-for="h in statusHistory" :key="h.id">
                        <template #prepend>
                          <v-icon size="18" color="info">mdi-circle-small</v-icon>
                        </template>
                        <v-list-item-title class="text-body-2">
                          <span class="text-medium-emphasis">{{ h.oldStatus || '—' }}</span>
                          <v-icon size="14" class="mx-1">mdi-arrow-right</v-icon>
                          <strong>{{ h.newStatus || '—' }}</strong>
                        </v-list-item-title>
                        <v-list-item-subtitle class="text-caption">
                          {{ fmtDateTime(h.createdAt) }} · {{ h.author }}
                          <span v-if="h.comment"> · {{ h.comment }}</span>
                        </v-list-item-subtitle>
                      </v-list-item>
                    </v-list>
                  </v-card>
                </v-menu>
              </header>

              <!-- Набор действий собирается в скрипте (statusActions): у каждой
                   кнопки своя подсказка о последствиях — «терминировать» и
                   «исключить» двигают портфель и деньги, и оператор должен
                   понимать разницу до клика, а не из истории после. -->
              <div class="d-flex ga-2 flex-wrap">
                <v-tooltip v-for="a in statusActions" :key="a.action"
                  :text="a.tip" location="top" max-width="320">
                  <template #activator="{ props: tipProps }">
                    <div v-bind="tipProps">
                      <v-btn size="small" variant="tonal" :color="a.color"
                        :prepend-icon="a.icon" :disabled="a.disabled"
                        @click="changeStatus(a.action)">{{ a.label }}</v-btn>
                    </div>
                  </template>
                </v-tooltip>
              </div>

              <!-- Самовосстановление: партнёр возвращается сам из окна при
                   входе. Лимит показываем шкалой — «2 из 3» текстом терялось. -->
              <div class="pf-reinstate">
                <span class="text-caption text-medium-emphasis">Самовосстановления</span>
                <v-progress-linear :model-value="reinstatePercent" height="6" rounded
                  :color="reinstatePercent >= 100 ? 'error' : 'warning'"
                  bg-opacity="0.15" class="pf-reinstate__bar" />
                <span class="text-caption font-weight-medium">
                  {{ editForm.reinstatementCount ?? 0 }} из {{ editForm.reinstateLimit ?? 3 }}
                </span>
                <v-chip v-if="editForm.reinstateBlocked" size="x-small" color="warning"
                  variant="tonal" prepend-icon="mdi-lock">
                  запрещено администратором
                </v-chip>
              </div>

              <v-alert v-if="statusMsg" :type="statusMsgType" density="compact" class="mt-3" closable @click:close="statusMsg = ''">
                {{ statusMsg }}
              </v-alert>
            </section>

            <!-- История изменений: объединённый поток
                 activity_log (Spatie, изменения Consultant) + audit_log
                 (partner_update с diff'ом полей WebUser).
                 Показываем кто, когда и что менял с указанием поля и
                 значений «было → стало». -->
            <section v-if="canFull('statuses')" class="pf-sec">
              <header class="pf-sec__head">
                <v-icon size="18" class="pf-sec__icon">mdi-history</v-icon>
                <span class="pf-sec__title">История изменений</span>
                <v-chip v-if="changeLog.length" size="x-small" variant="tonal">
                  {{ changeLog.length }}
                </v-chip>
                <v-spacer />
                <v-btn size="x-small" variant="text" prepend-icon="mdi-refresh"
                  :loading="changeLogLoading" @click="loadChangeLog(editForm.id)">
                  Обновить
                </v-btn>
              </header>

              <div v-if="changeLogLoading && !changeLog.length" class="text-center pa-4">
                <v-progress-circular indeterminate size="22" />
              </div>
              <div v-else-if="!changeLog.length" class="pf-empty">
                <v-icon size="28" class="pf-empty__icon">mdi-clipboard-text-clock-outline</v-icon>
                <div>Изменений пока нет</div>
                <div class="text-caption">Здесь появятся правки карточки с автором и основанием</div>
              </div>
              <!-- Лента-таймлайн: вертикальная направляющая слева и точка на
                   каждом событии. Плоский v-list не показывал, что записи
                   идут одна за другой во времени. -->
              <div v-else class="pf-log">
                <div v-for="entry in changeLog" :key="entry.id" class="pf-log__item">
                  <span class="pf-log__dot" :class="`text-${changeIconColor(entry)}`">
                    <v-icon size="12">{{ changeIcon(entry) }}</v-icon>
                  </span>
                  <div class="pf-log__title">
                    <strong>{{ entry.author }}</strong>
                    <span class="text-medium-emphasis"> · {{ fmtDateTime(entry.createdAt) }}</span>
                  </div>
                  <!-- Основание правки: сотрудник указывает его при
                       сохранении карточки, отдельной строкой — по нему
                       через полгода и отвечают «почему поменяли». -->
                  <div v-if="entry.comment" class="pf-log__reason">
                    <v-icon size="13">mdi-comment-quote-outline</v-icon>
                    {{ entry.comment }}
                  </div>
                  <div v-if="entry.changes && entry.changes.length" class="pf-log__changes">
                    <div v-for="(c, i) in entry.changes" :key="i" class="pf-log__change">
                      <span class="pf-log__field">{{ c.fieldLabel }}</span>
                      <span class="pf-log__from">{{ c.from || '—' }}</span>
                      <v-icon size="12" class="mx-1">mdi-arrow-right</v-icon>
                      <span class="pf-log__to">{{ c.to || '—' }}</span>
                    </div>
                  </div>
                  <div v-else class="text-caption text-medium-emphasis">{{ entry.action }}</div>
                </div>
              </div>
            </section>
          </template>
        </v-card-text>

        <v-divider />
        <v-card-actions class="pf-foot">
          <!-- Индикатор несохранённого: диалог длинный, и без него неясно,
               осталось ли что-то нажать после правки поля вверху формы. -->
          <div class="pf-foot__state">
            <template v-if="editHasAnyChanges">
              <v-icon size="16" color="warning">mdi-circle-medium</v-icon>
              <span class="text-caption">Есть несохранённые изменения</span>
            </template>
            <span v-else class="text-caption text-medium-emphasis">Изменений нет</span>
          </div>
          <v-spacer />
          <v-btn variant="text" @click="editDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline"
            :loading="saving"
            :disabled="!editHasAnyChanges || editFormValid === false || (!phoneIsEmpty(editForm.phone) && !editPhoneValid)"
            @click="saveEdit">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Двухшаг «Добавить партнёра» per spec ✅Партнёры §2 -->
    <v-dialog v-model="addOpen" max-width="640" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="me-2">mdi-account-plus</v-icon>
          {{ addStep === 1 ? 'Шаг 1: проверка на дубли' : 'Шаг 2: новая персона' }}
          <v-spacer />
          <v-btn icon="mdi-close" size="small" variant="text" @click="addOpen = false" />
        </v-card-title>

        <v-card-text v-if="addStep === 1">
          <div class="text-body-2 mb-3">
            Выберите существующую персону или добавьте новую, если не удаётся найти её в списке.
          </div>
          <v-text-field v-model="addSearch" label="Начните вводить фамилию"
            variant="outlined" density="comfortable"
            prepend-inner-icon="mdi-magnify" autofocus
            @update:model-value="searchAddCandidates" />
          <v-progress-linear v-if="addSearching" indeterminate class="mt-2" />
          <v-list v-if="addCandidates.length" density="compact" class="mt-2">
            <v-list-item v-for="p in addCandidates" :key="p.id"
              :title="p.personName" :subtitle="`${p.email || '—'} · ID ${p.id}`"
              @click="pickExisting(p)">
              <template #prepend><v-icon>mdi-account</v-icon></template>
            </v-list-item>
          </v-list>
          <v-alert v-else-if="addSearch.length >= 2 && !addSearching"
            type="info" variant="tonal" density="compact" class="mt-2">
            Совпадений не найдено.
          </v-alert>
        </v-card-text>

        <v-card-text v-else>
          <v-form v-model="addFormValid">
          <v-row dense>
            <v-col cols="12" sm="4"><v-text-field v-model="addForm.lastName"
              :rules="cyrillicRequiredRules"
              label="Фамилия *" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="4"><v-text-field v-model="addForm.firstName"
              :rules="cyrillicRequiredRules"
              label="Имя *" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="4"><v-text-field v-model="addForm.patronymic"
              :rules="cyrillicOptionalRules"
              label="Отчество" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.email"
              :rules="emailRules"
              label="Email" type="email" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6">
              <PhoneInput v-model="addForm.phone" label="Телефон"
                density="comfortable"
                :error-messages="(addForm.phone && !addPhoneValid && addPhoneTouched) ? 'Неверный номер телефона' : []"
                @validate="onAddPhoneValidate" />
            </v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.birthDate"
              label="Дата рождения" type="date" variant="outlined" density="comfortable" /></v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.participantCode"
              label="Партнёрский код" variant="outlined" density="comfortable"
              hint="Сгенерируется автоматически при активации" persistent-hint /></v-col>
            <v-col cols="12" sm="6">
              <v-select v-model="addForm.activity"
                :items="[{title:'Зарегистрирован',value:4},{title:'Активный',value:1},
                         {title:'Терминирован',value:3},{title:'Исключён',value:5}]"
                label="Статус активности *" variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12" sm="6"><v-text-field v-model="addForm.inviter"
              label="Пригласитель (ID)" type="number" variant="outlined" density="comfortable"
              hint="ID существующего партнёра-наставника" persistent-hint /></v-col>
          </v-row>
          <v-alert v-if="addError" type="error" density="compact" class="mt-2">{{ addError }}</v-alert>
          </v-form>
        </v-card-text>

        <v-card-actions>
          <v-btn v-if="addStep === 2" variant="text" prepend-icon="mdi-arrow-left"
            @click="addStep = 1">Назад</v-btn>
          <v-spacer />
          <v-btn v-if="addStep === 1" variant="text" @click="addOpen = false">Отмена</v-btn>
          <v-btn v-if="addStep === 1" color="success" prepend-icon="mdi-plus"
            :disabled="!addSearch || addSearch.length < 2" @click="gotoNewPersonStep">
            + Добавить новую персону
          </v-btn>
          <v-btn v-else color="success" prepend-icon="mdi-content-save"
            :loading="addSaving"
            :disabled="!addFormValid || (!!addForm.phone && !addPhoneValid)"
            @click="saveNewPartner">
            Создать партнёра
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { useTableSort } from '../../composables/useTableSort';
import PageHeader from '../../components/PageHeader.vue';
import DialogShell from '../../components/DialogShell.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import PhoneInput from '../../components/PhoneInput.vue';
import { useSnackbar } from '../../composables/useSnackbar';
import { useAuthStore } from '../../stores/auth';
import { usePermissions } from '../../composables/usePermissions';
import {
  cyrillicRequiredRules,
  cyrillicOptionalRules,
  emailRules,
} from '../../composables/useFormRules';

const auth = useAuthStore();
const { canEdit, canFull } = usePermissions();

// Валидация форм редактирования/добавления партнёра (строгий формат
// по запросу заказчика 2026-05-13). Edit-форма мягче — поля sometimes,
// поэтому правила optional. Add-форма — required.
const editFormValid = ref(true);
const editPhoneValid = ref(true);
const editPhoneTouched = ref(false);
// PhoneInput v-model хранит «+7XXXXXXXXXX» (пустой инпут → ''). На всякий
// случай считаем «пусто» и любой набор ≤3 цифр (legacy от vue-tel-input,
// который сам подставлял dial-code) — чтобы не было ложного «Неверный
// номер» сразу после открытия диалога.
function phoneIsEmpty(v) {
  const digits = String(v || '').replace(/\D/g, '');
  return digits.length <= 3;
}
function onEditPhoneValidate(obj) {
  if (phoneIsEmpty(editForm.value?.phone)) {
    editPhoneValid.value = true;
    editPhoneTouched.value = false;
    return;
  }
  editPhoneTouched.value = true;
  editPhoneValid.value = !!obj?.valid;
}
const editPhoneShowError = computed(() =>
  !phoneIsEmpty(editForm.value?.phone) && !editPhoneValid.value && editPhoneTouched.value
);

const addFormValid = ref(false);
const addPhoneValid = ref(true);
const addPhoneTouched = ref(false);
function onAddPhoneValidate(obj) {
  addPhoneTouched.value = true;
  addPhoneValid.value = !addForm.value?.phone ? true : !!obj?.valid;
}
import { useConfirm } from '../../composables/useConfirm';
import { fmtDate, fmtDateTime, getInitials } from '../../composables/useDesign';

const confirm = useConfirm();

const { showSuccess, showError } = useSnackbar();
const deleteDialogOpen = ref(false);
const deleteTarget = ref(null);
const deleteReason = ref('');
const deleting = ref(false);

function confirmDeletePartner(item) {
  deleteTarget.value = item;
  deleteReason.value = '';
  deleteDialogOpen.value = true;
}

async function performDeletePartner() {
  if (!deleteTarget.value?.id) return;
  deleting.value = true;
  try {
    await api.delete(`/admin/partners/${deleteTarget.value.id}`, {
      data: { reason: deleteReason.value },
    });
    showSuccess('Партнёр удалён');
    deleteDialogOpen.value = false;
    loadData();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось удалить');
  }
  deleting.value = false;
}

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const q = ref('');
const page = ref(1);
const perPage = ref(25);

/** Пустой набор фильтров окна. */
function emptyFilters() {
  return {
    activity: [],
    inviterName: null,
    dateMode: 'quick',
    datePreset: '',
    from: '',
    to: '',
    monthYear: new Date().getFullYear(),
    onlyClient: false,
    onlyBlocked: false,
  };
}

// Применённое и черновик разделены намеренно: окно правит копию, поэтому
// «Отмена» откатывает всё разом, а список не дёргается на каждый щелчок.
const applied = ref(emptyFilters());
const draft = ref(emptyFilters());
const filtersOpen = ref(false);

// ===== Единая строка поиска =====
// Тип ввода определяется по форме, а не отдельным полем: цифры → ID,
// «@» → email, «+7…» → телефон, латиница с цифрами → код, остальное → ФИО.
const QUERY_KINDS = { id: 'ID', email: 'email', phone: 'телефон', code: 'код', name: 'ФИО' };

function parseQuery(raw) {
  const t = String(raw ?? '').trim();
  if (!t) return null;
  if (/^\d+$/.test(t)) return { kind: 'id', value: t };
  if (t.includes('@')) return { kind: 'email', value: t };
  if (/^[+\d][\d\s()-]{4,}$/.test(t)) return { kind: 'phone', value: t };
  if (/^[A-Za-z]{2,4}-?\d{2,4}$/.test(t)) return { kind: 'code', value: t };
  return { kind: 'name', value: t };
}

const qKindLabel = computed(() => {
  const p = parseQuery(q.value);
  return p ? QUERY_KINDS[p.kind] : '';
});

/**
 * Фильтры → параметры запроса. Одна функция и на список, и на предпросмотр
 * «Показать N»: иначе счётчик в окне и выдача в таблице разъезжаются.
 */
function filterParams(f, query) {
  const params = {};
  const p = parseQuery(query);
  if (p) {
    if (p.kind === 'id') params.partner_id = p.value;
    else if (p.kind === 'email') params.email = p.value;
    else if (p.kind === 'phone') params.phone = p.value;
    else if (p.kind === 'code') params.code = p.value;
    else params.search = p.value;
  }
  if (f.activity.length) params.activity = f.activity.join(',');
  if (f.inviterName) params.inviter_name = f.inviterName;
  if (f.from) params.registered_from = f.from;
  if (f.to) params.registered_to = f.to;
  if (f.onlyClient) params.is_client = 1;
  if (f.onlyBlocked) params.is_blocked = 1;
  return params;
}

/** Сколько строк даст такой набор фильтров. Берём total, страницу не тянем. */
async function countWith(params) {
  const { data } = await api.get('/admin/partners', { params: { ...params, page: 1, per_page: 1 } });
  return data.total;
}

// Bulk selection
const selected = ref([]);
const bulkMsg = ref('');
const bulkMsgType = ref('success');

// ===== Даты регистрации =====
const MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
const MONTHS_FULL = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь',
  'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];

const pad2 = n => String(n).padStart(2, '0');
const isoDate = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const monthEdges = (y, m) => [new Date(y, m, 1), new Date(y, m + 1, 0)];

const QUICK_RANGES = [
  { key: 'today', title: 'Сегодня', range: () => [new Date(), new Date()] },
  { key: 'd7', title: '7 дней', range: () => [addDays(new Date(), -6), new Date()] },
  { key: 'd30', title: '30 дней', range: () => [addDays(new Date(), -29), new Date()] },
  { key: 'm', title: 'Этот месяц', range: () => monthEdges(new Date().getFullYear(), new Date().getMonth()) },
  { key: 'mPrev', title: 'Прошлый месяц', range: () => monthEdges(new Date().getFullYear(), new Date().getMonth() - 1) },
  { key: 'y', title: 'Этот год', range: () => [new Date(new Date().getFullYear(), 0, 1), new Date(new Date().getFullYear(), 11, 31)] },
  { key: 'yPrev', title: 'Прошлый год', range: () => [new Date(new Date().getFullYear() - 1, 0, 1), new Date(new Date().getFullYear() - 1, 11, 31)] },
];

// С 2024-го: раньше данных о регистрациях в платформе нет (выгрузка Directual).
const yearOptions = computed(() => {
  const now = new Date().getFullYear();
  return Array.from({ length: now - 2023 }, (_, i) => 2024 + i);
});

const monthKey = i => `${draft.value.monthYear}-${pad2(i + 1)}`;

function pickQuick(p) {
  if (draft.value.datePreset === p.key) return clearDraftDates();
  const [a, z] = p.range();
  Object.assign(draft.value, { datePreset: p.key, from: isoDate(a), to: isoDate(z) });
}
function pickYear(y) {
  if (draft.value.datePreset === String(y)) return clearDraftDates();
  Object.assign(draft.value, { datePreset: String(y), from: `${y}-01-01`, to: `${y}-12-31` });
}
function pickMonth(i) {
  const key = monthKey(i);
  if (draft.value.datePreset === key) return clearDraftDates();
  const [a, z] = monthEdges(draft.value.monthYear, i);
  Object.assign(draft.value, { datePreset: key, from: isoDate(a), to: isoDate(z) });
}
function clearDraftDates() {
  Object.assign(draft.value, { datePreset: '', from: '', to: '' });
}

const ruDate = s => (s ? s.split('-').reverse().join('.') : '');

/** Человеческая подпись периода: «2026», «июнь 2026», «12.03.2025 — 30.08.2026». */
function dateChipLabel(f) {
  if (!f.from && !f.to) return '';
  if (f.from && f.to && f.from.slice(0, 4) === f.to.slice(0, 4)) {
    const y = f.from.slice(0, 4);
    if (f.from.endsWith('-01-01') && f.to.endsWith('-12-31')) return `Регистрация: ${y}`;
    if (f.from.slice(8) === '01' && f.from.slice(0, 7) === f.to.slice(0, 7)) {
      return `Регистрация: ${MONTHS_FULL[+f.from.slice(5, 7) - 1]} ${y}`;
    }
  }
  if (f.from && f.to) return `Регистрация: ${ruDate(f.from)} — ${ruDate(f.to)}`;
  return f.from ? `Регистрация с ${ruDate(f.from)}` : `Регистрация по ${ruDate(f.to)}`;
}

const dateSummary = computed(() => {
  const l = dateChipLabel(draft.value);
  return l ? l.replace(/^Регистрация:?\s*/, 'Выбрано: ') : 'Период не выбран — показываем все даты.';
});

// ===== Активные фильтры чипами =====
const activeChips = computed(() => {
  const out = [];
  const p = parseQuery(q.value);
  if (p) out.push({ key: 'q', label: `${QUERY_KINDS[p.kind]}: ${p.value}` });

  for (const a of applied.value.activity) {
    const o = activityOptions.find(x => x.value === a);
    out.push({ key: `activity:${a}`, label: `Активность: ${o?.title ?? a}` });
  }
  if (applied.value.inviterName) {
    out.push({ key: 'inviter', label: `Пригласил: ${applied.value.inviterName}` });
  }
  const d = dateChipLabel(applied.value);
  if (d) out.push({ key: 'date', label: d });
  if (applied.value.onlyClient) out.push({ key: 'client', label: 'Только клиенты' });
  if (applied.value.onlyBlocked) out.push({ key: 'blocked', label: 'Только заблокированные' });
  return out;
});

// Поиск в счётчик кнопки «Фильтры» не входит: он живёт своей строкой.
const filterCount = computed(() => activeChips.value.length - (parseQuery(q.value) ? 1 : 0));

function clearChip(key) {
  if (key === 'q') q.value = '';
  else if (key.startsWith('activity:')) {
    applied.value.activity = applied.value.activity.filter(v => v !== key.slice(9));
  } else if (key === 'inviter') applied.value.inviterName = null;
  else if (key === 'date') Object.assign(applied.value, { datePreset: '', from: '', to: '' });
  else if (key === 'client') applied.value.onlyClient = false;
  else if (key === 'blocked') applied.value.onlyBlocked = false;
  loadData();
}

function resetFilters() {
  q.value = '';
  applied.value = emptyFilters();
  loadData();
}

// ===== Окно фильтров =====
// Счётчики у активностей и «Показать N» считает сервер: локально их взять
// неоткуда — на странице лежит 25 строк из 1968.
const activityCounts = ref({});
const previewCount = ref(null);
const previewLoading = ref(false);
const grandTotal = ref(0);
let previewTimer;

async function loadActivityCounts() {
  const pairs = await Promise.all(activityOptions.map(async o => {
    try { return [o.value, await countWith({ activity: o.value })]; } catch { return [o.value, null]; }
  }));
  activityCounts.value = Object.fromEntries(pairs);
}

watch([draft, q], () => {
  if (!filtersOpen.value) return;
  clearTimeout(previewTimer);
  previewLoading.value = true;
  previewTimer = setTimeout(async () => {
    try { previewCount.value = await countWith(filterParams(draft.value, q.value)); } catch {}
    previewLoading.value = false;
  }, 350);
}, { deep: true });

function openFilters() {
  draft.value = JSON.parse(JSON.stringify(applied.value));
  filtersOpen.value = true;
  previewCount.value = total.value;
  if (!Object.keys(activityCounts.value).length) loadActivityCounts();
}
function toggleActivity(v) {
  const list = draft.value.activity;
  const i = list.indexOf(v);
  if (i >= 0) list.splice(i, 1); else list.push(v);
}
function resetDraft() {
  draft.value = emptyFilters();
}
function applyFilters() {
  applied.value = JSON.parse(JSON.stringify(draft.value));
  filtersOpen.value = false;
  page.value = 1;
  loadData();
}

// Подсказки пригласивших берём из того же lookup, что и форма партнёра.
const invFilterQuery = ref('');
const invFilterItems = ref([]);
const invFilterLoading = ref(false);
let invFilterTimer;
watch(invFilterQuery, val => {
  clearTimeout(invFilterTimer);
  const term = String(val ?? '').trim();
  if (term.length < 2) { invFilterItems.value = []; return; }
  invFilterTimer = setTimeout(async () => {
    invFilterLoading.value = true;
    try {
      const { data } = await api.get('/admin/partners/lookup', { params: { q: term } });
      invFilterItems.value = [...new Set((data.items || []).map(i => i.personName).filter(Boolean))];
    } catch {}
    invFilterLoading.value = false;
  }, 300);
});

// ===== Сегменты (сохранённые фильтры) =====
const segments = ref([]);
const selectedSegment = ref(null);

function currentCriteria() {
  return { q: q.value || '', ...applied.value };
}

async function loadSegments() {
  try { const { data } = await api.get('/admin/user-segments'); segments.value = data.segments || []; } catch {}
}

function applySegment(id) {
  const seg = segments.value.find(s => s.id === id);
  if (!seg) return;
  const c = seg.criteria || {};

  // Сегменты, сохранённые ДО редизайна, лежат в старой форме — семь отдельных
  // полей вместо одной строки поиска. Читаем обе, иначе всё, что бэкофис
  // насохранял раньше, перестанет применяться.
  const legacy = !('q' in c);
  q.value = legacy
    ? (c.search || c.partnerId || c.email || c.phone || '')
    : (c.q || '');

  applied.value = {
    ...emptyFilters(),
    activity: legacy
      ? (c.activity ? [String(c.activity)] : [])
      : (Array.isArray(c.activity) ? c.activity : []),
    inviterName: c.inviterName || null,
    dateMode: c.dateMode || 'quick',
    datePreset: c.datePreset || '',
    from: c.from || '',
    to: c.to || '',
    monthYear: c.monthYear || new Date().getFullYear(),
    onlyClient: !!c.onlyClient,
    onlyBlocked: !!c.onlyBlocked,
  };
  page.value = 1;
  loadData();
}

async function saveSegment() {
  const name = (prompt('Название сегмента:') || '').trim();
  if (!name) return;
  try {
    await api.post('/admin/user-segments', { name, criteria: currentCriteria() });
    await loadSegments();
  } catch {}
}

async function deleteSegment(id) {
  if (!confirm('Удалить сегмент?')) return;
  try { await api.delete(`/admin/user-segments/${id}`); selectedSegment.value = null; await loadSegments(); } catch {}
}

const activityOptions = [
  { title: 'Активен', value: '1' },
  { title: 'Терминирован', value: '3' },
  // Подпись короткая: значение уходит на сервер, а «-Партнёр» — жаргон Directual.
  { title: 'Зарегистрирован', value: '4' },
  { title: 'Исключён', value: '5' },
];

// Column metadata: `always` = never hideable (ФИО / Активность / Действия);
// `default` = shown out of the box; others are opt-in via the «Колонки» menu.
const allColumns = [
  { title: 'ID',               key: 'id',             width: 92,  default: true },
  { title: 'Партнёр',          key: 'personName',     width: 320, always: true },
  { title: 'Статус',           key: 'activityName',   width: 160, always: true },
  { title: 'Код',              key: 'participantCode', width: 100, default: true },
  // Без width: эта колонка забирает свободный простор, чтобы пустота не
  // копилась в «Партнёре» — там имя короткое и растягивать его нечем.
  { title: 'Пригласивший',     key: 'inviterName',    default: true },
  { title: 'Дата рождения',    key: 'birthDate',      width: 130 },
  // Колонки «Куратор» здесь больше нет: поле curatorName никогда не приходило
  // с сервера (в PartnerListingService::present его нет), и ячейка была пустой
  // у всех 1968 партнёров.
  { title: 'Регистрация',      key: 'createdAt',      width: 130, default: true },
  { title: 'Смена статуса',    key: 'statusChangeDate', width: 140, default: true },
  { title: '',                 key: 'actions',        sortable: false, width: 92, always: true },
];

// Which columns show in the menu (everything except always-on).
const toggleableColumns = computed(() => allColumns.filter(c => !c.always && c.title));

// Reactive visibility state, persisted per-user in localStorage so their
// column choice survives refreshes.
const COL_STORAGE_KEY = 'admin.partners.visibleColumns';
const columnVisible = ref((() => {
  try {
    const saved = JSON.parse(localStorage.getItem(COL_STORAGE_KEY) || 'null');
    if (saved) return saved;
  } catch {}
  const initial = {};
  for (const c of allColumns) if (!c.always) initial[c.key] = !!c.default;
  return initial;
})());

// Persist on change.
watch(columnVisible, v => localStorage.setItem(COL_STORAGE_KEY, JSON.stringify(v)), { deep: true });

const visibleHeaders = computed(() =>
  allColumns.filter(c => c.always || columnVisible.value[c.key])
);

/**
 * «Потемкин Артем Сергеевич» → «Потемкин А. С.».
 * Полное ФИО остаётся в подсказке: колонка узкая, и полное имя переносилось
 * на три строки, растягивая строку таблицы втрое.
 */
function shortName(full) {
  const parts = String(full ?? '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '—';
  const [last, first, mid] = parts;
  return [last, first ? `${first[0]}.` : '', mid ? `${mid[0]}.` : ''].filter(Boolean).join(' ');
}

/** «Полозова Людмила Александровна» → «Полозова Людмила». */
function shortInviter(full) {
  return String(full ?? '').trim().split(/\s+/).slice(0, 2).join(' ');
}

/** Второй этаж строки — вместо отдельных колонок Email и Телефон. */
function contactLine(item) {
  return [item?.email, item?.phone].filter(Boolean).join(' · ');
}

// Короткие подписи статусов: «Зарегистрирован-Партнёр» — жаргон Directual,
// он не помещался в ячейку и переносился на две строки.
const ACTIVITY_LABEL = { 1: 'Активен', 3: 'Терминирован', 4: 'Зарегистрирован', 5: 'Исключён' };
function activityLabel(item) {
  return ACTIVITY_LABEL[item?.activityId] || item?.activityName || '—';
}

// ===== Карточка партнёра =====
// Открывается по клику на строку. Данные берём из уже загруженной строки
// списка — отдельного запроса не делаем: всё нужное присутствует в ответе
// /admin/partners, а лишний round-trip на каждый клик по списку из двух
// тысяч человек не нужен.
const cardOpen = ref(false);
const cardItem = ref(null);
const cardTab = ref('main');

// Сводка (квалификация, ГП за месяц, остаток) приходит отдельным запросом:
// в списке этих полей нет намеренно — считать их на 1968 строк ради трёх
// чисел в открытой карточке незачем.
const cardSnapshot = ref(null);

async function openCard(item) {
  cardItem.value = item;
  cardTab.value = 'main';
  cardSnapshot.value = null;
  cardOpen.value = true;
  try {
    const { data } = await api.get(`/admin/partners/${item.id}`);
    // Пока запрос идёт, карточку могли переключить на другого партнёра.
    if (cardItem.value?.id === item.id) cardSnapshot.value = data.snapshot || null;
  } catch {}
}

// Хронология выводится из полей самой строки: отдельного журнала событий в
// списке нет, а запрос за ним на каждый клик по списку из 1968 человек не
// оправдан. Полный журнал живёт в форме редактирования.
const cardEvents = computed(() => {
  const i = cardItem.value;
  if (!i) return [];
  const out = [];
  if (i.createdAt) {
    out.push({ date: fmtDate(i.createdAt), text: 'Зарегистрирован в системе', color: 'var(--ds-on-surface-muted)' });
  }
  if (i.activityId && i.activityId !== 4) {
    out.push({ date: '', text: `Текущая активность: ${activityLabel(i)}`, color: `var(--ds-${ACTIVITY_TOKEN[i.activityId] || 'on-surface-muted'})` });
  }
  if (i.terminationCount) {
    out.push({ date: '', text: `Терминаций всего: ${i.terminationCount}`, color: 'var(--ds-warning)' });
  }
  if (i.statusChangeDate) {
    out.push({ date: fmtDate(i.statusChangeDate), text: 'Ближайшая смена статуса', color: 'var(--ds-tertiary)' });
  }
  if (i.isBlocked) {
    out.push({ date: '', text: 'Вход в кабинет закрыт', color: 'var(--ds-error)' });
  }
  return out;
});
const ACTIVITY_TOKEN = { 1: 'success', 3: 'warning', 4: 'on-surface-muted', 5: 'error' };
function openEditFromCard() {
  const item = cardItem.value;
  cardOpen.value = false;
  openEdit(item);
}
function filterByInviter(name) {
  cardOpen.value = false;
  applied.value.inviterName = name;
  page.value = 1;
  loadData();
}

const ACTIVITY_COLOR = { 1: 'success', 3: 'warning', 4: 'grey', 5: 'error' };
const activityColor = item => ACTIVITY_COLOR[item?.activityId] || 'grey';
const fmtNum = v => Number(v ?? 0).toLocaleString('ru-RU');

const { debounced: debouncedLoad } = useDebounce(loadData, 400);
const { sortBy, sortDir, applyParams } = useTableSort('id', 'desc');

// ===== Таблица: закрепление, сортировка, выделение, страницы =====
// Липкими делаем чекбокс, ID и «Партнёр». Смещения считаем по ключам колонок,
// а не по nth-child: ID выключается в «Колонках», и позиционный расчёт после
// этого разъезжался.
const STICKY_KEYS = ['id', 'personName'];
const CHECK_W = 44;
const ID_W = 92;

const isSticky = i => STICKY_KEYS.includes(visibleHeaders.value[i]?.key);
const isLastSticky = i => visibleHeaders.value[i]?.key === 'personName';

/** idx: 0 — колонка чекбокса, дальше индекс видимой колонки + 1. */
function stickyLeft(idx) {
  if (idx === 0) return { left: '0px' };
  const c = visibleHeaders.value[idx - 1];
  if (!c || !STICKY_KEYS.includes(c.key)) return null;
  if (c.key === 'id') return { left: `${CHECK_W}px` };
  return { left: `${CHECK_W + (columnVisible.value.id ? ID_W : 0)}px` };
}

// Сервер сортирует по своему списку колонок (AdminDataController::partners).
// Чего в нём нет — не даём щёлкать, чтобы клик не оборачивался пустотой.
const SORT_KEYS = {
  id: 'id',
  personName: 'personName',
  activityName: 'activityName',
  participantCode: 'participantCode',
  inviterName: 'inviterName',
  createdAt: 'dateCreated',
};
const sortKeyOf = c => SORT_KEYS[c.key] || null;
const isSortable = c => !!sortKeyOf(c);

function toggleSort(c) {
  const key = sortKeyOf(c);
  if (!key) return;
  if (sortBy.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortBy.value = key;
    sortDir.value = 'asc';
  }
  page.value = 1;
  loadData();
}

const isSelected = item => selected.value.some(x => x.id === item.id);
const allOnPage = computed(() => items.value.length > 0 && items.value.every(isSelected));
const someOnPage = computed(() => items.value.some(isSelected) && !allOnPage.value);

function toggleOne(item, on) {
  if (on) {
    if (!isSelected(item)) selected.value.push(item);
  } else {
    selected.value = selected.value.filter(x => x.id !== item.id);
  }
}
function togglePage(on) {
  if (on) {
    items.value.forEach(i => { if (!isSelected(i)) selected.value.push(i); });
  } else {
    const ids = new Set(items.value.map(i => i.id));
    selected.value = selected.value.filter(x => !ids.has(x.id));
  }
}

const pageCount = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));
const rowsFrom = computed(() => (page.value - 1) * perPage.value + 1);
const rowsTo = computed(() => Math.min(page.value * perPage.value, total.value));

function goPage(n) {
  if (n < 1 || n > pageCount.value) return;
  page.value = n;
  loadData();
}

// === per spec ✅Партнеры.md §1.2 helpers ===
function copyToClipboard(text) {
  if (!text) return;
  navigator.clipboard?.writeText(String(text));
}

function isStatusChangeSoon(item) {
  if (!item.statusChangeDate) return false;
  const days = (new Date(item.statusChangeDate) - new Date()) / 86400000;
  return days >= 0 && days <= 30;
}

async function loadData() {
  loading.value = true;
  try {
    // Единая строка раскладывается в тот же серверный фильтр, который раньше
    // заполняло отдельное поле, — контракт API не менялся.
    const params = {
      page: page.value,
      per_page: perPage.value,
      ...filterParams(applied.value, q.value),
    };
    applyParams(params);

    const { data } = await api.get('/admin/partners', { params });
    items.value = data.data;
    total.value = data.total;
  } catch {}
  loading.value = false;
}

// Двухшаг «Добавить партнёра» per spec ✅Партнёры §2:
// шаг 1 — поиск по существующим (антидубль), шаг 2 — заполнение профиля
// если совпадений нет.
const addOpen = ref(false);
const addStep = ref(1);
const addSearch = ref('');
const addCandidates = ref([]);
const addSearching = ref(false);
const addSaving = ref(false);
const addError = ref('');
const addForm = ref({
  email: '', phone: '', firstName: '', lastName: '', patronymic: '',
  birthDate: '', activity: 1, inviter: null, participantCode: '',
});
let addSearchTimer;

function openAddPartner() {
  addOpen.value = true;
  addStep.value = 1;
  addSearch.value = '';
  addCandidates.value = [];
  addError.value = '';
  addForm.value = {
    email: '', phone: '', firstName: '', lastName: '', patronymic: '',
    birthDate: '', activity: 1, inviter: null, participantCode: '',
  };
}

function searchAddCandidates(q) {
  clearTimeout(addSearchTimer);
  if (!q || q.length < 2) {
    addCandidates.value = [];
    return;
  }
  addSearchTimer = setTimeout(async () => {
    addSearching.value = true;
    try {
      const { data } = await api.get('/admin/partners', { params: { search: q, per_page: 10 } });
      addCandidates.value = data.data || [];
    } catch {}
    addSearching.value = false;
  }, 300);
}

function pickExisting(person) {
  addOpen.value = false;
  // Открываем существующий профиль для редактирования.
  openEdit?.(person);
}

function gotoNewPersonStep() {
  // Заполняем фамилию из последнего поиска, чтобы не вводить дважды.
  const parts = addSearch.value.trim().split(/\s+/);
  if (parts[0]) addForm.value.lastName = parts[0];
  if (parts[1]) addForm.value.firstName = parts[1];
  if (parts[2]) addForm.value.patronymic = parts[2];
  addStep.value = 2;
  addError.value = '';
}

async function saveNewPartner() {
  addSaving.value = true;
  addError.value = '';
  try {
    await api.post('/admin/partners', addForm.value);
    addOpen.value = false;
    await loadData();
  } catch (e) {
    addError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  addSaving.value = false;
}

const editDialog = ref(false);
const editLoading = ref(false);
const editForm = ref(null);
// Кастомные поля редактируемого пользователя (по WebUser.id).
const pcfFields = ref([]);
const pcfValues = ref({});
const editErrors = ref({});
const saving = ref(false);
const statusMsg = ref('');
const statusMsgType = ref('success');
const statusHistory = ref([]);
const statusHistoryLoading = ref(false);
const changeLog = ref([]);
const changeLogLoading = ref(false);
// ФИО на момент открытия карточки + флаг «реквизиты подтверждены»: вместе
// дают предупреждение и подтверждение перед сбросом верификации.
const editNameBefore = ref({ lastName: '', firstName: '', patronymic: '' });
// Слепок карточки «как открыли» — по нему понимаем, есть ли что сохранять
// (и надо ли спрашивать основание правки).
const editSnapshot = ref('');

const editNameChanged = computed(() => {
  const f = editForm.value;
  if (!f) return false;
  const b = editNameBefore.value;
  return (f.lastName || '') !== b.lastName
    || (f.firstName || '') !== b.firstName
    || (f.patronymic || '') !== b.patronymic;
});

// Полезная нагрузка карточки: одна функция и для слепка, и для запроса —
// иначе сравнение «изменилось ли» и то, что реально уходит, разъедутся.
function editPayload(f) {
  return {
    participantCode: f.participantCode || null,
    inviter: f.inviter || null,
    firstName: f.firstName || null,
    lastName: f.lastName || null,
    patronymic: f.patronymic || null,
    email: f.email || null,
    phone: phoneIsEmpty(f.phone) ? null : f.phone,
    nicTG: f.nicTG || null,
    gender: f.gender || null,
    birthDate: f.birthDate || null,
    role: f.role || null,
    isBlocked: !!f.isBlocked,
    newPassword: f.newPassword || null,
  };
}

const editHasChanges = computed(() =>
  !!editForm.value && !!editSnapshot.value
  && JSON.stringify(editPayload(editForm.value)) !== editSnapshot.value);

// Кастомные поля уходят отдельным запросом, но кнопкой «Сохранить» той же
// формы — в «есть что сохранять» они обязаны учитываться, иначе правка
// только доп. поля упирается в заблокированную кнопку.
const editPcfSnapshot = ref('');
// Слепок не снялся (карточка не догрузилась) — «изменений нет» утверждать
// нельзя, иначе кнопка «Сохранить» останется заблокированной навсегда.
const editSnapshotReady = computed(() => !!editSnapshot.value);
const editHasAnyChanges = computed(() =>
  !editSnapshotReady.value
  || editHasChanges.value
  || JSON.stringify(pcfValues.value) !== editPcfSnapshot.value);

const showNewPassword = ref(false);

// Доля израсходованных самовосстановлений — для шкалы в блоке статуса.
const reinstatePercent = computed(() => {
  const limit = editForm.value?.reinstateLimit || 3;
  const used = editForm.value?.reinstatementCount || 0;
  return Math.min(100, Math.round((used / limit) * 100));
});

/**
 * Кнопки смены статуса. Держим списком, а не разметкой: у каждого действия
 * своя подсказка о последствиях, а состав зависит от текущего статуса —
 * «Отменить терминацию» показываем только терминированному/исключённому.
 */
const statusActions = computed(() => {
  const f = editForm.value || {};
  const list = [
    { action: 'activate', label: 'Активировать', icon: 'mdi-account-check', color: 'success',
      disabled: f.activityId === 1,
      tip: 'Ручная активация без проверки порога ЛП. Причина уйдёт в историю статусов.' },
    { action: 'terminate', label: 'Терминировать', icon: 'mdi-account-cancel', color: 'warning',
      tip: 'Баллы обнуляются, клиенты и контракты уходят вверх по структуре.' },
    { action: 'exclude', label: 'Исключить', icon: 'mdi-account-remove', color: 'error',
      tip: 'Карточка помечается удалённой, партнёр выпадает из структуры и отчётов.' },
    { action: 're-register', label: 'Перерегистрировать', icon: 'mdi-account-reactivate', color: 'info',
      tip: 'Новый стартовый период с нуля. Счётчик перерегистраций растёт.' },
  ];
  // Отмена ошибочной терминации: в отличие от «Активировать» возвращает и
  // портфель (контракты/клиенты), уехавший вверх в момент терминации.
  if (f.activityId === 3 || f.activityId === 5) {
    list.push({ action: 'restore-termination', label: 'Отменить терминацию',
      icon: 'mdi-undo-variant', color: 'primary',
      tip: 'Возвращает и статус, и портфель — контракты с клиентами, уехавшие вверх при терминации.' });
  }
  // Самовосстановление: партнёр возвращается сам из окна при входе.
  // Запрет статус не меняет — только закрывает эту дверь.
  list.push(f.reinstateBlocked
    ? { action: 'unblock-reinstate', label: 'Разрешить самовосстановление',
      icon: 'mdi-lock-open-variant', color: 'success',
      tip: 'Партнёр снова сможет вернуться сам из окна при входе, пока не исчерпан лимит.' }
    : { action: 'block-reinstate', label: 'Запретить самовосстановление',
      icon: 'mdi-lock', color: 'warning',
      tip: 'Закрывает партнёру возможность вернуться самому. Текущий статус не меняется.' });
  return list;
});

/** Копирование в буфер: ID из шапки карточки. */
async function copyValue(value, label) {
  try {
    await navigator.clipboard.writeText(String(value ?? ''));
    showSuccess(`${label} скопирован`);
  } catch {
    showError('Буфер обмена недоступен');
  }
}

// Иконка по типу события — статус-смены отдельно от обычных правок,
// чтобы оператор сразу видел «крупные» изменения в потоке.
function changeIcon(entry) {
  if (entry?.action === 'manual-status-override') return 'mdi-shield-edit';
  if (entry?.changes?.some(c => c.field === 'activity')) return 'mdi-account-switch';
  if (entry?.changes?.some(c => c.field === 'role')) return 'mdi-account-key';
  if (entry?.changes?.some(c => c.field === 'password')) return 'mdi-lock-reset';
  if (entry?.changes?.some(c => c.field === 'requisitesVerified')) return 'mdi-credit-card-off';
  if (entry?.action === 'partner_update') return 'mdi-pencil';
  return 'mdi-circle-small';
}
function changeIconColor(entry) {
  if (entry?.changes?.some(c => c.field === 'activity')) return 'warning';
  if (entry?.changes?.some(c => c.field === 'requisitesVerified')) return 'warning';
  if (entry?.changes?.some(c => c.field === 'role')) return 'info';
  return 'primary';
}

async function loadChangeLog(id) {
  if (!id) return;
  changeLogLoading.value = true;
  try {
    const { data } = await api.get(`/admin/partners/${id}/change-log`);
    changeLog.value = data?.data || [];
  } catch {
    changeLog.value = [];
  }
  changeLogLoading.value = false;
}

// === Inviter autocomplete state ===
// items для v-autocomplete (ФИО + ID + код) и подсказка под полем.
const inviterOptions = ref([]);
const inviterLoading = ref(false);
const inviterHint = computed(() => {
  const id = editForm.value?.inviter;
  if (!id) return 'Начните вводить ФИО или код пригласителя';
  const opt = inviterOptions.value.find(o => o.id === id);
  if (!opt) return `ID ${id}`;
  const code = opt.participantCode ? `, код ${opt.participantCode}` : '';
  return `ID ${opt.id}${code}`;
});

let inviterSearchTimer = null;
async function fetchInviterOptions(q, ids = []) {
  inviterLoading.value = true;
  try {
    const params = {};
    if (q && q.length >= 1) params.q = q;
    if (ids.length) params.ids = ids;
    const { data } = await api.get('/admin/partners/lookup', { params });
    const items = data?.items || [];
    // Сохраняем уже выбранного пригласителя, чтобы он не пропал из items
    // когда пользователь начнёт искать другого — иначе v-autocomplete
    // отрисует пустой title.
    const currentId = editForm.value?.inviter;
    const currentInOptions = currentId
      ? items.find(i => i.id === currentId) || inviterOptions.value.find(i => i.id === currentId)
      : null;
    const merged = currentInOptions
      ? [currentInOptions, ...items.filter(i => i.id !== currentId)]
      : items;
    inviterOptions.value = merged;
  } catch {
    /* тишина — сеть упадёт, оставим прежние options */
  } finally {
    inviterLoading.value = false;
  }
}

function onInviterSearch(q) {
  clearTimeout(inviterSearchTimer);
  inviterSearchTimer = setTimeout(() => fetchInviterOptions(q || ''), 300);
}

async function loadStatusHistory(id) {
  if (!id) return;
  statusHistoryLoading.value = true;
  try {
    const { data } = await api.get(`/admin/partners/${id}/status-history`);
    statusHistory.value = data?.data || [];
  } catch {
    statusHistory.value = [];
  }
  statusHistoryLoading.value = false;
}

const genderOptions = [
  { title: 'Мужской', value: 'male' },
  { title: 'Женский', value: 'female' },
];

// Легаси-партнёры из Directual хранят пол по-русски («Мужской»/«Женский»),
// новые — «male»/«female». Приводим к канону, иначе v-select не находит
// значение и сохранение падает на валидации in:male,female.
function normalizeGender(v) {
  const s = String(v || '').trim().toLowerCase();
  if (!s) return null;
  if (['male', 'm', 'м', 'муж', 'мужской'].includes(s)) return 'male';
  if (['female', 'f', 'ж', 'жен', 'женский'].includes(s)) return 'female';
  return null;
}

// Роли — единый перечень с Admin/Users.vue. WebUser.role хранится как
// CSV (например "registered,consultant"), поэтому работаем через прокси
// editFormRoles: array ↔ string.
const roleOptions = [
  { title: 'Администратор', value: 'admin' },
  { title: 'Бэкофис (БЭК)', value: 'backoffice' },
  { title: 'Техподдержка', value: 'support' },
  { title: 'Руководитель', value: 'head' },
  { title: 'Фин. менеджер', value: 'finance' },
  { title: 'Расчёты (Богданова)', value: 'calculations' },
  { title: 'Правки', value: 'corrections' },
  { title: 'Отдел обучения', value: 'education' },
  { title: 'Консультант', value: 'consultant' },
  { title: 'Зарегистрирован-Партнёр', value: 'registered' },
];

const editFormRoles = computed({
  get: () => {
    const raw = editForm.value?.role;
    if (!raw) return [];
    return String(raw).split(',').map(s => s.trim()).filter(Boolean);
  },
  set: (arr) => {
    if (editForm.value) editForm.value.role = (arr || []).join(',');
  },
});

const editBirthDate = computed({
  get: () => editForm.value?.birthDate ? editForm.value.birthDate.split('T')[0] : '',
  set: (v) => { if (editForm.value) editForm.value.birthDate = v || null; },
});

const editActivityColor = computed(() => {
  const id = editForm.value?.activityId;
  if (id === 1) return 'success';   // Активен
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'error';     // Терминирован — per spec ✅Статусы партнеров §2 col.2
  if (id === 5) return 'error';     // Исключен
  return 'grey';
});

async function openEdit(item) {
  editDialog.value = true;
  editLoading.value = true;
  editErrors.value = {};
  statusMsg.value = '';
  statusHistory.value = [];
  changeLog.value = [];
  editPhoneTouched.value = false;
  editForm.value = { id: item.id, personName: item.personName };
  // История параллельно — нужна только админу, но грузим всегда:
  // ACL на бэке, а тут логика проще без условного fetch.
  if (auth.isAdmin) {
    loadStatusHistory(item.id);
    loadChangeLog(item.id);
  }
  try {
    const { data } = await api.get(`/admin/partners/${item.id}`);
    const c = data.consultant || {};
    const u = data.webUser || {};
    // Сразу подкладываем текущего пригласителя в options автокомплита,
    // чтобы он отрисовал ФИО а не пустой title (items пустые до поиска).
    inviterOptions.value = (c.inviter && c.inviterName)
      ? [{ id: c.inviter, personName: c.inviterName, participantCode: null }]
      : [];
    editForm.value = {
      id: c.id,
      personName: c.personName,
      participantCode: c.participantCode || '',
      inviter: c.inviter ?? null,
      inviterName: c.inviterName,
      activityId: c.activityId,
      activityName: c.activityName,
      terminationCount: c.terminationCount ?? 0,
      reinstatementCount: c.reinstatementCount ?? 0,
      reinstateLimit: c.reinstateLimit ?? 3,
      reinstateBlocked: !!c.reinstateBlocked,
      firstName: u.firstName || '',
      lastName: u.lastName || '',
      patronymic: u.patronymic || '',
      email: u.email || '',
      phone: u.phone || '',
      nicTG: u.nicTG || '',
      gender: normalizeGender(u.gender),
      birthDate: u.birthDate || null,
      role: u.role || '',
      isBlocked: !!u.isBlocked,
      newPassword: '',
      webUserId: u.id || null,
      requisitesVerified: !!c.requisitesVerified,
    };
    // Слепок ФИО на момент открытия: по нему перед сохранением решаем,
    // спрашивать ли подтверждение сброса верификации реквизитов.
    editNameBefore.value = {
      lastName: u.lastName || '', firstName: u.firstName || '', patronymic: u.patronymic || '',
    };
    editSnapshot.value = JSON.stringify(editPayload(editForm.value));
    // Кастомные поля пользователя (по WebUser.id).
    pcfFields.value = [];
    pcfValues.value = {};
    if (u.id) {
      try {
        const { data: cf } = await api.get(`/admin/users/${u.id}/custom-fields`);
        pcfFields.value = cf.fields || [];
        const vals = {};
        for (const fld of pcfFields.value) vals[fld.id] = fld.value;
        pcfValues.value = vals;
      } catch {}
    }
    // Слепок доп. полей — как и по основной форме, чтобы кнопка «Сохранить»
    // знала, что менялось. Ставим ПОСЛЕ загрузки, иначе слепок пустой.
    editPcfSnapshot.value = JSON.stringify(pcfValues.value);
  } catch {}
  editLoading.value = false;
}

async function saveEdit() {
  const f = editForm.value;
  if (!f) return;

  // Правка карточки обязана быть объяснена: основание уходит в «Историю
  // изменений» и в аудит (бэк без него сохранять откажется). Спрашиваем
  // только когда есть что сохранять — «нажал Сохранить, ничего не поменяв»
  // не должно требовать комментария.
  // Смена ФИО у партнёра с подтверждёнными реквизитами тут же сбрасывает
  // верификацию — предупреждаем об этом в том же окне, а не отдельным.
  let comment = '';
  if (editHasChanges.value || !editSnapshotReady.value) {
    const resetsVerification = editNameChanged.value && f.requisitesVerified;
    const res = await confirm.ask({
      title: resetsVerification ? 'Сменить ФИО и сбросить верификацию?' : 'Сохранить изменения?',
      message: (resetsVerification
        ? 'Реквизиты партнёра верифицированы. После сохранения статус будет снят: '
          + 'партнёр получит уведомление, сможет заново ввести и отправить реквизиты, '
          + 'а выплаты и раздел «Продукты» будут закрыты до повторной проверки. '
        : '')
        + 'Укажите основание — оно попадёт в «Историю изменений» карточки и в аудит.',
      confirmText: 'Сохранить',
      confirmColor: resetsVerification ? 'warning' : 'primary',
      maxWidth: 520,
      input: {
        label: 'Основание изменения',
        placeholder: 'Например: заявление партнёра от 03.09.2026',
        required: true, rows: 3,
      },
    });
    if (!res?.confirmed) return;
    comment = res.value;
  }

  saving.value = true;
  editErrors.value = {};
  try {
    const { data } = await api.put(`/admin/partners/${f.id}`, {
      ...editPayload(f),
      comment: comment || null,
    });
    // Кастомные поля пользователя — отдельным запросом (если есть WebUser).
    if (f.webUserId && pcfFields.value.length) {
      try {
        await api.put(`/admin/users/${f.webUserId}/custom-fields`, { values: { ...pcfValues.value } });
      } catch { /* не критично для основного сохранения */ }
    }
    if (data?.requisitesReset) {
      showSuccess(data.message);
    }
    editDialog.value = false;
    loadData();
  } catch (e) {
    if (e.response?.status === 422) {
      const raw = e.response.data?.errors || {};
      const mapped = {};
      for (const k of Object.keys(raw)) mapped[k] = raw[k][0];
      editErrors.value = mapped;
      // У основания нет своего поля в форме (оно спрашивается в диалоге) —
      // показываем ошибку снекбаром, иначе она осталась бы невидимой.
      if (mapped.comment) showError(mapped.comment);
    }
  }
  saving.value = false;
}

// ============ BULK ACTIONS ============
function selectedIds() {
  return selected.value.map(x => (typeof x === 'object' ? x.id : x));
}

async function bulkRun(action) {
  const ids = selectedIds();
  if (!ids.length) return;
  const labels = {
    activate: 'активировать', terminate: 'терминировать', exclude: 'исключить',
    're-register': 'перерегистрировать', block: 'заблокировать', unblock: 'разблокировать',
  };
  const colors = { activate: 'success', terminate: 'warning', exclude: 'error',
    're-register': 'primary', block: 'error', unblock: 'success' };
  // Терминация/исключение пачкой — причина обязательна (та же логика, что в
  // карточке: она уходит в историю статусов каждому партнёру).
  const needsReason = action === 'terminate' || action === 'exclude';
  const res = await confirm.ask({
    title: `Массовое действие: ${labels[action]}`,
    message: `${ids.length} партнёр(ов) будут переведены в статус "${labels[action]}". Действие применится сразу.`,
    confirmText: labels[action], confirmColor: colors[action] || 'primary',
    maxWidth: needsReason ? 520 : 420,
    ...(needsReason ? { input: { label: 'Причина *', required: true, rows: 3,
      placeholder: 'Попадёт в историю статусов каждого партнёра' } } : {}),
  });
  if (needsReason ? !res?.confirmed : !res) return;
  const reason = needsReason ? res.value : '';

  try {
    const { data } = await api.post('/admin/partners/bulk', { ids, action, reason });
    bulkMsg.value = data.message;
    bulkMsgType.value = data.fail > 0 ? 'warning' : 'success';
    selected.value = [];
    loadData();
  } catch (e) {
    bulkMsg.value = e.response?.data?.message || 'Ошибка массового действия';
    bulkMsgType.value = 'error';
  }
}

async function bulkSetInviter() {
  const ids = selectedIds();
  if (!ids.length) return;
  const inviterId = window.prompt('Введите ID нового наставника:', '');
  if (!inviterId) return;
  const n = parseInt(inviterId, 10);
  if (!Number.isFinite(n) || n <= 0) {
    bulkMsg.value = 'Некорректный ID';
    bulkMsgType.value = 'error';
    return;
  }
  if (!await confirm.ask({
    title: 'Сменить наставника?',
    message: `${ids.length} партнёр(ов) будут перепривязаны к наставнику с ID ${n}.`,
    confirmText: 'Сменить', confirmColor: 'warning',
  })) return;
  try {
    const { data } = await api.post('/admin/partners/bulk', {
      ids, action: 'set-inviter', inviter: n,
    });
    bulkMsg.value = data.message;
    bulkMsgType.value = data.fail > 0 ? 'warning' : 'success';
    selected.value = [];
    loadData();
  } catch (e) {
    bulkMsg.value = e.response?.data?.message || 'Ошибка массового действия';
    bulkMsgType.value = 'error';
  }
}

// Действия, где причина ОБЯЗАТЕЛЬНА: они меняют деньги и портфель партнёра,
// и через полгода «почему его терминировали» отвечает только этот комментарий.
// Он уходит в chageConsultanStatusLog + activity_log и виден в попапе
// «История смены статуса» рядом с кнопками.
const STATUS_ACTION_PROMPTS = {
  terminate: {
    title: 'Терминировать партнёра?',
    message: 'Контракты и клиенты автоматически перейдут ближайшему активному вышестоящему, '
      + 'счётчик терминаций увеличится на 1. Причина попадёт в историю статусов.',
    confirmText: 'Терминировать', confirmColor: 'warning',
    label: 'Причина терминации *',
    placeholder: 'Например: не выполнен план периода (ЛП 320 из 500)',
    required: true,
  },
  exclude: {
    title: 'Исключить партнёра?',
    message: 'Партнёр теряет доступ в кабинет, контракты и клиенты перейдут вышестоящему. '
      + 'Причина попадёт в историю статусов.',
    confirmText: 'Исключить', confirmColor: 'error',
    label: 'Причина исключения *',
    placeholder: 'Например: нарушение правил (п. 4.2), решение комитета от 01.08',
    required: true,
  },
  'restore-termination': {
    title: 'Отменить терминацию?',
    message: 'Статус вернётся к тому, что был до терминации, а контракты и клиенты — этому партнёру '
      + '(только те, что уехали автоматически при терминации и с тех пор не переводились дальше). '
      + 'Счётчик терминаций уменьшится на 1. Комиссии по открытым периодам пересчитаются.',
    confirmText: 'Отменить терминацию', confirmColor: 'primary',
    label: 'Причина отмены *',
    placeholder: 'Например: терминирован ошибочно — баллы за июль не были учтены',
    required: true,
  },
  'block-reinstate': {
    title: 'Запретить самовосстановление?',
    message: 'Партнёр больше не сможет вернуться в работу сам из окна при входе. Текущий статус '
      + 'не меняется. Следующая терминация переведёт его в «Исключён».',
    confirmText: 'Запретить', confirmColor: 'warning',
    label: 'Причина запрета *',
    placeholder: 'Например: разбирательство по жалобе от 05.08',
    required: true,
  },
  'unblock-reinstate': {
    title: 'Разрешить самовосстановление?',
    message: 'Партнёр снова сможет восстановиться сам, если попытки не исчерпаны.',
    confirmText: 'Разрешить', confirmColor: 'success',
    label: 'Комментарий',
    placeholder: 'Например: разбирательство закрыто',
    required: false,
  },
  activate: {
    title: 'Активировать партнёра?',
    message: 'Ручная активация в обход порога ЛП. Причина попадёт в историю статусов.',
    confirmText: 'Активировать', confirmColor: 'success',
    label: 'Причина активации *',
    placeholder: 'Например: активация по решению руководителя, договорённость от 01.08',
    required: true,
  },
};

async function changeStatus(action) {
  if (!editForm.value) return;
  let reason = '';
  const p = STATUS_ACTION_PROMPTS[action];
  if (p) {
    const res = await confirm.ask({
      title: p.title, message: p.message,
      confirmText: p.confirmText, confirmColor: p.confirmColor,
      maxWidth: 520,
      input: { label: p.label, placeholder: p.placeholder, required: p.required, rows: 3 },
    });
    if (!res?.confirmed) return;
    reason = res.value;
  }
  try {
    const { data } = await api.post(`/admin/partners/${editForm.value.id}/status`, { action, reason });
    statusMsg.value = data.message || 'Статус обновлён';
    statusMsgType.value = 'success';
    // Reload partner + list + history (новая запись попадёт в попап).
    const { data: fresh } = await api.get(`/admin/partners/${editForm.value.id}`);
    editForm.value.activityId = fresh.consultant.activityId;
    editForm.value.activityName = fresh.consultant.activityName;
    editForm.value.reinstatementCount = fresh.consultant.reinstatementCount;
    editForm.value.reinstateLimit = fresh.consultant.reinstateLimit;
    editForm.value.reinstateBlocked = fresh.consultant.reinstateBlocked;
    loadStatusHistory(editForm.value.id);
    loadChangeLog(editForm.value.id);
    loadData();
  } catch (e) {
    statusMsg.value = e.response?.data?.message || 'Ошибка смены статуса';
    statusMsgType.value = 'error';
  }
}

// «/» из любого места ставит курсор в поиск — как в макете. Внутри полей и
// редакторов клавиша работает как обычный символ.
function focusSearch(e) {
  if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
  const t = e.target;
  if (t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return;
  e.preventDefault();
  document.querySelector('.p-search input')?.focus();
}
onMounted(() => window.addEventListener('keydown', focusSearch));
onBeforeUnmount(() => window.removeEventListener('keydown', focusSearch));

onMounted(() => {
  loadData();
  loadSegments();
  // Знаменатель для «Найдено N из M» — общее число партнёров без фильтров.
  countWith({}).then(n => { grandTotal.value = n; }).catch(() => {});
});
</script>

<style scoped>
/* Row accent: a 3px left border tinted by activity. Keeps wide tables
   scannable without adding a whole colored cell. */
/* ============ Таблица списка ============
   Перенесена из согласованного прототипа (.design/partners-list.html).
   Разметка своя, поэтому все размеры здесь и работают предсказуемо — раньше
   те же правила приходилось продавливать через внутренности v-data-table
   с !important и nth-child, и статус в ячейке в итоге не отрисовывался.
   Цвета — токенами, поэтому светлая тема получает то же самое. */
.p-tablecard { overflow: hidden; }
.p-scroll { overflow-x: auto; }

.p-table {
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
  font-size: 0.8125rem;
}

.p-th {
  text-align: left;
  font-size: 0.66rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ds-on-surface-muted);
  padding: 12px 14px;
  white-space: nowrap;
  border-bottom: 1px solid var(--ds-outline-variant);
  background: var(--ds-surface);
  vertical-align: middle;
}
.p-th--check { width: 44px; padding-right: 0; }
.p-th--sortable { cursor: pointer; user-select: none; }
.p-th--sortable:hover { color: var(--ds-on-surface); }
.p-sort { color: rgb(var(--v-theme-primary)); margin-left: 4px; }

.p-td {
  padding: 9px 14px;
  border-bottom: 1px solid var(--ds-outline-soft);
  vertical-align: middle;
  background: var(--ds-surface);
}
.p-td--check { width: 44px; padding-right: 0; }
.p-tr:last-child .p-td { border-bottom: 0; }
.p-tr { cursor: pointer; }
.p-tr:hover .p-td { background: var(--ds-surface-container-low); }
.p-tr--sel .p-td { background: var(--ds-primary-soft); }

/* Закреплённые слева колонки. Фон непрозрачный — иначе под ними просвечивают
   уезжающие ячейки; подсветку строки красим отдельно по той же причине. */
.p-sticky { position: sticky; z-index: 2; }
.p-th.p-sticky { z-index: 3; }
.p-edge { box-shadow: 1px 0 0 var(--ds-outline-variant); }

/* Цветного торца строки нет намеренно: в макете его не было, а вместе с
   точкой статуса он давал два разных сигнала об одном и том же — и на
   «Зарегистрирован» превращался в еле заметную полоску-артефакт. */

.p-nowrap { white-space: nowrap; }
.p-check { width: 15px; height: 15px; cursor: pointer; accent-color: rgb(var(--v-theme-primary)); }
.p-muted { color: var(--ds-on-surface-muted); }
.p-soon { color: rgb(var(--v-theme-error)); font-weight: 600; }
.p-cellwrap { display: block; overflow: hidden; text-overflow: ellipsis; }

/* ID: копирование не мозолит глаза, появляется по наведению на строку. */
.p-id { display: inline-flex; align-items: center; gap: 2px; white-space: nowrap; }
.p-id__num { font-variant-numeric: tabular-nums; color: var(--ds-on-surface-variant); }
.p-id__copy { opacity: 0; transition: opacity 0.12s; }
.p-tr:hover .p-id__copy, .p-id__copy:focus-visible { opacity: 1; }

/* Имя одной строкой, контакт вторым этажом: раньше при узкой колонке имя
   ломалось на три этажа, и на экран влезало четыре партнёра из двух тысяч. */
.p-name__row { display: flex; align-items: center; gap: 6px; white-space: nowrap; }
.p-name__text { font-weight: 600; }
.p-name__contact {
  font-size: 0.72rem;
  line-height: 1.25;
  color: var(--ds-on-surface-muted);
  white-space: nowrap;
  margin-top: 1px;
}

/* Статус: цветная точка + слово. Читается периферийным зрением при
   прокрутке — именно так бэкофис и просматривает список. */
.p-status { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; }
.p-status__dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex: 0 0 auto; }
.p-status--1 { color: rgb(var(--v-theme-success)); }
.p-status--3 { color: rgb(var(--v-theme-warning)); }
.p-status--4 { color: var(--ds-on-surface-variant); }
.p-status--5 { color: rgb(var(--v-theme-error)); }

.p-actions { display: flex; align-items: center; justify-content: flex-end; gap: 2px; white-space: nowrap; }

.p-state {
  padding: 48px 16px;
  text-align: center;
  color: var(--ds-on-surface-muted);
  font-size: 0.85rem;
}
.p-foot {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 10px 14px;
  border-top: 1px solid var(--ds-outline-variant);
  font-size: 0.78rem;
}
.p-foot__page { font-variant-numeric: tabular-nums; }

/* ============ Карточка партнёра (шторка) ============
   Справа во всю высоту: список остаётся виден, людей можно просматривать
   подряд. Модальное окно на весь экран этого не давало. */
:global(.p-card) {
  width: 448px;
  max-width: 96vw;
  height: 100%;
}
.p-card__inner { height: 100%; }
.p-card__head {
  padding: 16px 18px 14px;
  border-bottom: 1px solid var(--ds-outline-variant);
}
.p-card__name { font-size: 1rem; font-weight: 700; line-height: 1.3; }
.p-card__body { padding: 16px 18px 26px; overflow: auto; flex: 1 1 auto; }

.p-card__stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.p-card__stat {
  background: var(--ds-surface-container-low);
  border: 1px solid var(--ds-outline-variant);
  border-radius: 11px;
  padding: 10px 12px;
}
.p-card__stat-l {
  font-size: 0.63rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--ds-on-surface-muted);
}
.p-card__stat-v--neg { color: rgb(var(--v-theme-error)); }
.p-card__stat-v {
  font-size: 1.05rem;
  font-weight: 700;
  margin-top: 3px;
  font-variant-numeric: tabular-nums;
}
.p-card__sec {
  margin: 22px 0 4px;
  font-size: 0.68rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ds-on-surface-muted);
  font-weight: 700;
}
.p-card__row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 8px;
  margin: 0 -8px;
  border-radius: 9px;
  font-size: 0.82rem;
}
.p-card__row:hover { background: var(--ds-surface-container-low); }
.p-card__row--link { cursor: pointer; }
.p-card__k { color: var(--ds-on-surface-muted); flex: 0 0 118px; }
.p-card__v { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.p-card__act { opacity: 0; transition: opacity 0.12s; }
.p-card__row:hover .p-card__act { opacity: 1; }

/* ============ Секции окна фильтров ============
   Плоские блоки с мелким прописным заголовком и разделительной линией —
   как в согласованном макете, без рамок-«карточек» вокруг каждой группы. */
.p-fsec { padding: 16px 0; border-bottom: 1px solid var(--ds-outline-variant); }
.p-fsec:first-child { padding-top: 4px; }
.p-fsec:last-child { border-bottom: 0; }
.p-fsec__title {
  margin: 0 0 11px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ds-on-surface-muted);
}
.p-fsec__hint {
  margin: 11px 0 0;
  font-size: 0.76rem;
  line-height: 1.35;
  color: var(--ds-on-surface-muted);
}
/* Выбранный способ выбора даты — залитый фирменным, как в макете: серая
   заливка Vuetify по умолчанию не читалась как «выбрано». */
.p-datemode :deep(.v-btn--active) {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.p-fsec__cnt {
  margin-left: 7px;
  font-size: 0.7rem;
  opacity: 0.65;
  font-variant-numeric: tabular-nums;
}

/* Хронология в карточке: направляющая точка + дата + событие. */
.p-tl__row {
  display: grid;
  grid-template-columns: 10px 84px 1fr;
  gap: 10px;
  align-items: start;
  padding: 7px 0;
  font-size: 0.8rem;
  color: var(--ds-on-surface-variant);
}
.p-tl__dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; }
.p-tl__row time { color: var(--ds-on-surface-muted); font-variant-numeric: tabular-nums; }

.p-card__tabs { border-bottom: 1px solid var(--ds-outline-variant); }

/* Подсказка про «/» в пустом поиске — как в макете. */
.p-kbd {
  border: 1px solid var(--ds-outline);
  border-radius: 5px;
  padding: 0 6px;
  font-size: 0.68rem;
  line-height: 1.6;
  color: var(--ds-on-surface-muted);
  align-self: center;
}

/* Подпись «ищу по: телефон» прямо в строке поиска: одно поле на пять типов
   ввода, и человек должен видеть, как его поняли. */
.p-qhint {
  font-size: 0.7rem;
  white-space: nowrap;
  color: rgb(var(--v-theme-secondary));
  align-self: center;
}

/* Статус: цветная точка + слово. Читается периферийным зрением при
   прокрутке — именно так бэкофис и просматривает список. */
.p-status {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  white-space: nowrap;
  font-size: 0.82rem;
}
.p-status__dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex: 0 0 auto; }
.p-status--1 { color: rgb(var(--v-theme-success)); }
.p-status--3 { color: rgb(var(--v-theme-warning)); }
.p-status--4 { color: var(--ds-on-surface-variant); }
.p-status--5 { color: rgb(var(--v-theme-error)); }

/* ============ Карточка партнёра ============
   Цвета берём из ds-токенов (resources/js/styles/ds-tokens.css) — они уже
   переопределены для тёмной темы, поэтому отдельных .v-theme--dark правил
   здесь не нужно. */

/* Шапка и подвал липнут к краям: форма длинная, и «кто это» с «Сохранить»
   должны оставаться на виду при прокрутке. */
.pf-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--ds-surface);
}
.pf-head__main { min-width: 0; flex: 1 1 auto; }
.pf-head__name { font-size: 1.05rem; font-weight: 600; line-height: 1.3; }
.pf-head__meta {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 4px;
}
.pf-head__id { font-size: 0.72rem; color: var(--ds-on-surface-muted); }

.pf-body { max-height: 68vh; padding: 16px; }

/* Секция = блок полей на своей поверхности. Раньше разделами служили
   голые жирные подписи, и границы групп читались только по отступам. */
.pf-sec {
  border: 1px solid var(--ds-outline-variant);
  border-radius: 12px;
  background: var(--ds-surface-container-low);
  padding: 12px 14px 4px;
  margin-bottom: 14px;
}
.pf-sec--action { padding-bottom: 14px; }
.pf-sec__head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.pf-sec__icon { color: var(--ds-on-surface-variant); }
.pf-sec__title { font-size: 0.85rem; font-weight: 700; letter-spacing: 0.01em; }
.pf-sec__hint {
  font-size: 0.72rem;
  color: var(--ds-on-surface-muted);
  min-width: 0;
}
.pf-hint {
  display: flex;
  align-items: flex-start;
  gap: 5px;
  font-size: 0.72rem;
  line-height: 1.35;
  color: var(--ds-on-surface-muted);
  margin: 6px 0 10px;
}

.pf-reinstate {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
  flex-wrap: wrap;
}
.pf-reinstate__bar { flex: 0 1 160px; }

/* Пустое состояние истории — вместо одинокой серой строки. */
.pf-empty {
  text-align: center;
  padding: 18px 8px;
  color: var(--ds-on-surface-muted);
  font-size: 0.82rem;
}
.pf-empty__icon { color: var(--ds-on-surface-faint); margin-bottom: 6px; }

/* Лента изменений: направляющая слева + точка на каждом событии. */
.pf-log {
  position: relative;
  max-height: 320px;
  overflow: auto;
  padding-left: 22px;
}
.pf-log::before {
  content: '';
  position: absolute;
  left: 7px;
  top: 6px;
  bottom: 6px;
  width: 2px;
  border-radius: 2px;
  background: var(--ds-outline-variant);
}
.pf-log__item { position: relative; padding: 8px 0 10px; }
.pf-log__item + .pf-log__item { border-top: 1px dashed var(--ds-outline-soft); }
.pf-log__dot {
  position: absolute;
  left: -22px;
  top: 10px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--ds-surface);
  border: 1px solid var(--ds-outline-variant);
}
.pf-log__title { font-size: 0.82rem; }
.pf-log__reason {
  display: flex;
  align-items: flex-start;
  gap: 5px;
  margin: 4px 0;
  font-size: 0.76rem;
  font-style: italic;
  color: var(--ds-on-surface-variant);
}
.pf-log__changes { display: flex; flex-direction: column; gap: 2px; margin-top: 2px; }
.pf-log__change { font-size: 0.75rem; }
.pf-log__field { font-weight: 600; margin-right: 6px; }
.pf-log__from { color: var(--ds-on-surface-muted); text-decoration: line-through; }
.pf-log__to { font-weight: 500; }

.pf-foot {
  position: sticky;
  bottom: 0;
  z-index: 2;
  background: var(--ds-surface);
  padding: 10px 14px;
  gap: 6px;
}
.pf-foot__state { display: flex; align-items: center; gap: 2px; }

@media (max-width: 600px) {
  .pf-sec__hint { display: none; }
  .pf-body { max-height: none; }
}
</style>
