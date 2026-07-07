<template>
  <div>
    <PageHeader title="Транзакции" icon="mdi-swap-horizontal">
      <template #actions>
        <!-- Перерасчёт штрафов §5 (Отрыв + ОП) за текущий месяц.
             Видна только admin + calculations (reports-access FULL) —
             эта же ролёвая гарда стоит на /admin/finalize/apply. -->
        <v-btn v-if="canManagePeriod" size="small" color="error" variant="flat"
          prepend-icon="mdi-calculator-variant"
          :loading="recalcing"
          :disabled="recalcing"
          @click="recalcCurrentPeriod">
          Пересчитать текущий период
        </v-btn>
      </template>
    </PageHeader>

    <v-tabs v-model="tab" color="primary" class="mb-3" density="compact">
      <v-tab value="manual" prepend-icon="mdi-cash-plus">Ручной ввод</v-tab>
      <v-tab value="log" prepend-icon="mdi-history">Журнал</v-tab>
    </v-tabs>

    <v-window v-model="tab">

      <!-- ВКЛАДКА 1: РУЧНОЙ ВВОД -->
      <v-window-item value="manual">
        <!-- Поиск контрактов -->
        <v-card class="mb-3">
          <v-card-title class="text-subtitle-1 d-flex align-center ga-2">
            <v-icon size="20">mdi-file-document-multiple</v-icon>
            Контракты
            <v-chip v-if="contractTotal" size="x-small" color="primary" variant="tonal">{{ contractTotal }}</v-chip>
            <v-spacer />
            <ColumnVisibilityMenu :headers="contractHeaders"
              v-model:visible="contractColsVisible"
              storage-key="manual-tx-contracts-cols"
              :always-visible="['add']" />
          </v-card-title>

          <v-card-text class="pt-0">
            <!-- Компактный layout: 7 фильтров в одной flex-строке вместо v-row md="2-3"
                 (на Mac Air ≤1470px перенос на 2-3 строки). -->
            <div class="d-flex flex-wrap ga-2 align-center">
              <v-text-field v-model="filters.consultantName" placeholder="ФИО консультанта"
                density="compact" hide-details rounded clearable variant="outlined"
                style="max-width: 220px; flex: 1 1 180px"
                @update:model-value="debouncedSearch" />
              <v-text-field v-model="filters.clientName" placeholder="ФИО клиента"
                density="compact" hide-details rounded clearable variant="outlined"
                style="max-width: 200px; flex: 1 1 160px"
                @update:model-value="debouncedSearch" />
              <v-text-field v-model="filters.number" placeholder="№ контракта"
                density="compact" hide-details rounded clearable variant="outlined"
                style="max-width: 160px; flex: 1 1 120px"
                @update:model-value="debouncedSearch" />
              <v-autocomplete v-model="filters.supplier" :items="lookupSuppliers"
                placeholder="Поставщик" density="compact" hide-details rounded clearable variant="outlined"
                style="max-width: 180px; flex: 1 1 140px"
                @update:model-value="loadContracts" />
              <!-- «Провайдер» убран — на платформе понятие не используется,
                   только «Поставщик». Запрошено правками 2026-05-05. -->
              <v-autocomplete v-model="filters.product" :items="productList" item-title="name" item-value="id"
                placeholder="Продукт" density="compact" hide-details rounded clearable variant="outlined"
                style="max-width: 200px; flex: 1 1 160px"
                @update:model-value="onProductChange" />
              <v-autocomplete v-model="filters.program" :items="programsForSelectedProduct"
                item-title="name" item-value="id"
                placeholder="Программа" density="compact" hide-details rounded clearable variant="outlined"
                style="max-width: 200px; flex: 1 1 160px"
                :no-data-text="filters.product ? 'У этого продукта нет программ' : 'Выберите продукт'"
                @update:model-value="loadContracts" />
              <v-autocomplete v-model="filters.currency" :items="currencyOptions" item-title="name" item-value="id"
                placeholder="Валюта" density="compact" hide-details rounded clearable variant="outlined"
                style="max-width: 140px; flex: 1 1 100px"
                @update:model-value="loadContracts" />

              <v-spacer />

              <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="resetContractFilters">
                Очистить
              </v-btn>
            </div>
          </v-card-text>

          <v-data-table-server
            :items="contracts" :items-length="contractTotal" :loading="loadingContracts"
            :headers="visibleContractHeaders" :items-per-page="15" item-value="id"
            density="compact"
            @update:options="onContractOpts">
            <template #item.add="{ item }">
              <v-btn v-if="canCalc" icon="mdi-plus-circle" size="small" variant="text" color="primary"
                title="Добавить в черновики"
                @click="addContractToDrafts(item.id)" />
            </template>
            <template #item.number="{ item }">
              {{ item.number || ('Контракт #' + item.id) }}
            </template>
            <template #item.amount="{ item }">{{ fmt2(item.amount) }} {{ item.currencySymbol || '' }}</template>
            <template #item.openDate="{ value }">{{ fmtDate(value) }}</template>
            <template #no-data><EmptyState message="Контракты не найдены" /></template>
          </v-data-table-server>
        </v-card>

        <!-- Рабочая зона черновиков -->
        <v-card class="mb-3">
          <v-card-title class="d-flex align-center ga-3 flex-wrap">
            <span class="text-subtitle-1">
              <v-icon size="20" class="mr-1">mdi-pencil</v-icon>
              Черновики транзакций
              <v-chip size="x-small" color="warning" variant="tonal" class="ml-1">{{ drafts.length }}</v-chip>
            </span>
            <v-spacer />
            <ColumnVisibilityMenu :headers="draftHeaders"
              v-model:visible="draftColsVisible"
              storage-key="manual-tx-drafts-cols"
              :always-visible="['select', 'icon', 'actions']" />
          </v-card-title>

          <!-- Чёткое предупреждение: черновики — это «корзина», они не
               участвуют ни в одном расчёте до нажатия «Зафиксировать».
               Без этой плашки финменеджеры теряли черновики и не понимали,
               почему ЛП/ГП/пул не двигаются. -->
          <v-card-text v-if="drafts.length" class="pt-0 pb-2">
            <v-alert
              type="warning"
              variant="tonal"
              density="compact"
              icon="mdi-alert-circle-outline"
              border="start"
            >
              <div class="text-body-2">
                <strong>Эти {{ drafts.length }} {{ drafts.length === 1 ? 'черновик ждёт' : 'черновика(ов) ждут' }} фиксации.</strong>
                Пока черновик не зафиксирован, он <strong>не участвует</strong> в расчёте ЛП, ГП, НГП, квалификаций и пула.
                После проверки сумм нажмите кнопку <strong>«Зафиксировать транзакции»</strong> внизу секции.
              </div>
            </v-alert>
          </v-card-text>

          <v-card-text v-if="!drafts.length" class="text-center text-medium-emphasis py-4">
            Выберите контракты сверху и нажмите «Добавить в черновики»
          </v-card-text>

          <!-- Обёртка с горизонтальным скроллом — таблица не помещается
               на стандартном вьюпорте, без overflow часть правых колонок
               (Доход ДС / Без НДС / Партнёр / ЛП / Σ / Прибыль ДС)
               уходила за правый край. -->
          <div v-else class="manual-tx-scroll" style="overflow-x: auto">
          <v-table density="compact" class="manual-tx-table">
            <thead>
              <tr>
                <th v-for="h in visibleDraftHeaders" :key="h.key" :class="h.thClass" :style="h.style">
                  {{ h.title }}
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="d in drafts" :key="d.id">
                <tr :class="{ 'tx-row-ready': d.preview?.ready }">
                  <td v-for="h in visibleDraftHeaders" :key="h.key + '-' + d.id" :class="h.tdClass">
                    <template v-if="h.key === 'select'">
                      <v-checkbox v-model="selectedDraftIds" :value="d.id" hide-details density="compact" />
                    </template>
                    <template v-else-if="h.key === 'icon'">
                      <v-icon size="20" color="primary">mdi-calculator</v-icon>
                    </template>
                    <template v-else-if="h.key === 'number'">
                      <span class="text-no-wrap">{{ contractNum(d) }}</span>
                    </template>
                    <template v-else-if="h.key === 'client'">
                      <span class="text-no-wrap">{{ d.clientName || '—' }}</span>
                    </template>
                    <template v-else-if="h.key === 'product'">
                      <span class="text-no-wrap">{{ d.productName || '—' }}</span>
                    </template>
                    <template v-else-if="h.key === 'program'">
                      <span class="text-no-wrap">{{ d.programName || '—' }}</span>
                    </template>
                    <template v-else-if="h.key === 'supplier'">
                      <span class="text-no-wrap">{{ d.supplierName || '—' }}</span>
                    </template>
                    <template v-else-if="h.key === 'date'">
                      <v-menu :close-on-content-click="false" location="bottom start">
                        <template #activator="{ props: dprops }">
                          <v-text-field v-bind="dprops" :model-value="fmtDate(d.date) || ''"
                            placeholder="дд.мм.гггг" readonly density="compact" hide-details variant="plain"
                            append-inner-icon="mdi-calendar" />
                        </template>
                        <v-date-picker :model-value="parseDate(d.date)" hide-header
                          @update:model-value="v => patchField(d, 'date', formatYmd(v))" />
                      </v-menu>
                    </template>
                    <template v-else-if="h.key === 'comment'">
                      <v-text-field :model-value="d.comment" placeholder="Введите" density="compact" hide-details variant="plain"
                        @update:model-value="v => patchField(d, 'comment', v)" />
                    </template>
                    <template v-else-if="h.key === 'parameter'">
                      <!-- Для has_year_kv продуктов (EVO, Medlife, Manhattan Trust)
                           свойство определяется yearKV автоматически — не показываем. -->
                      <span v-if="d.productHasProperty === false || d.productHasYearKv === true"
                        class="text-medium-emphasis">—</span>
                      <template v-else-if="(d.availableParameters?.length || 0) > 1">
                        <v-select :model-value="d.parameter !== null ? Number(d.parameter) : null"
                          :items="d.availableParameters"
                          item-title="title" item-value="id"
                          density="compact" hide-details variant="plain" placeholder="Выберите"
                          @update:model-value="v => patchField(d, 'parameter', v)" />
                      </template>
                      <template v-else-if="d.availableParameters?.length === 1">
                        <span class="text-medium-emphasis">{{ d.availableParameters[0].title }}</span>
                      </template>
                      <template v-else>
                        <span class="text-medium-emphasis">—</span>
                      </template>
                    </template>
                    <template v-else-if="h.key === 'contractTerm'">
                      <!-- Год контракта = c.term из контракта (срок в годах).
                           Read-only — изменяется на стороне самого контракта,
                           не в черновике транзакции. -->
                      <span v-if="d.contractTerm != null">{{ d.contractTerm }}</span>
                      <span v-else class="text-medium-emphasis">—</span>
                    </template>
                    <template v-else-if="h.key === 'yearKV'">
                      <!-- Аналогично «Свойству»: у продуктов без has_year_kv
                           ввод года КВ скрыт. -->
                      <span v-if="d.productHasYearKv === false" class="text-medium-emphasis">—</span>
                      <v-select v-else :model-value="d.yearKV" :items="yearOptionsFor(d)"
                        density="compact" hide-details variant="plain" clearable
                        @update:model-value="v => patchField(d, 'yearKV', v)" />
                    </template>
                    <template v-else-if="h.key === 'amount'">
                      <v-text-field :model-value="fmtAmt(d.amount)" inputmode="decimal"
                        density="compact" hide-details variant="plain"
                        reverse @update:model-value="v => patchField(d, 'amount', parseAmt(v))" />
                    </template>
                    <template v-else-if="h.key === 'currency'">
                      <v-select :model-value="d.currencyId" :items="currencyOptions" item-title="symbol" item-value="id"
                        density="compact" hide-details variant="plain"
                        @update:model-value="v => patchField(d, 'currency', v)" />
                    </template>
                    <template v-else-if="h.key === 'amountRub'">
                      <span v-if="d.preview?.ready"
                        :title="d.currencyRate ? `Курс платформы: ${fmt2(d.currencyRate)}` : ''">
                        {{ fmt2(d.preview.amountRUB) }} RUB
                      </span>
                      <span v-else class="text-medium-emphasis">—</span>
                    </template>
                    <template v-else-if="h.key === 'dsPercent'">
                      <span v-if="d.preview?.ready">{{ fmt2(d.preview.dsCommissionPercentage) }}%</span>
                      <span v-else class="text-medium-emphasis">—</span>
                    </template>
                    <template v-else-if="h.key === 'customCommission'">
                      <v-checkbox :model-value="d.customCommission"
                        :title="'Введите Доход ДС вручную, %ДС посчитается обратно'"
                        hide-details density="compact" color="warning"
                        class="d-inline-flex"
                        @update:model-value="v => onCustomCommissionToggle(d, v)" />
                    </template>
                    <template v-else-if="h.key === 'zeroDsIncome'">
                      <v-checkbox :model-value="d.zeroDsIncome"
                        title="Не начислять Доход ДС по этой транзакции"
                        hide-details density="compact" color="warning"
                        class="d-inline-flex"
                        @update:model-value="v => (d.zeroDsIncome = v)" />
                    </template>
                    <template v-else-if="h.key === 'change'">
                      <v-btn icon="mdi-pencil-outline" size="x-small" variant="text"
                        :disabled="!isRateChangeable(d)"
                        :title="isRateChangeable(d) ? 'Изменить % ДС' : 'Доступно только для Investors Trust и Medlife'"
                        @click="openRateModal(d)" />
                    </template>
                    <template v-else-if="h.key === 'incomeDS'">
                      <!-- «Своя комиссия»: пользователь вводит Доход ДС С НДС
                           (gross) — так удобнее (сумма из счёта поставщика). В
                           БД dsCommissionAbsolute хранится БЕЗ НДС (бэкенд так
                           трактует) — конвертим в setGrossCommission. -->
                      <template v-if="d.customCommission">
                        <v-text-field :model-value="grossCommission(d)" type="number"
                          density="compact" hide-details variant="underlined"
                          placeholder="0.00"
                          style="max-width:120px; display:inline-block"
                          reverse @update:model-value="v => setGrossCommission(d, v)" />
                        RUB
                      </template>
                      <template v-else>
                        <span v-if="d.preview?.ready"
                          :title="`Сумма комиссии с НДС ${d.preview.vatPercent || 0}%`">
                          {{ fmt2(Number(d.preview.incomeDS || 0) * (1 + Number(d.preview.vatPercent || 0) / 100)) }} RUB
                        </span>
                        <span v-else class="text-medium-emphasis">—</span>
                      </template>
                    </template>
                    <template v-else-if="h.key === 'incomeDsNoVat'">
                      <!-- Без НДС — производное от введённого gross (только показ). -->
                      <template v-if="d.customCommission">
                        <span class="text-medium-emphasis">{{ d.dsCommissionAbsolute != null && d.dsCommissionAbsolute !== '' ? fmt2(Number(d.dsCommissionAbsolute)) : '—' }} RUB</span>
                      </template>
                      <template v-else>
                        <span v-if="d.preview?.ready">{{ fmt2(d.preview.incomeDS) }} RUB</span>
                        <span v-else class="text-medium-emphasis">—</span>
                      </template>
                    </template>
                    <template v-else-if="h.key === 'partner'">
                      <v-menu open-on-hover open-delay="150" close-delay="100" location="bottom start">
                        <template #activator="{ props: pprops }">
                          <span v-bind="pprops" class="text-no-wrap"
                            :class="d.preview?.chain?.length ? 'text-primary tx-partner-hover' : 'text-medium-emphasis'">
                            {{ partnerSurname(d.consultantName) || '—' }}
                            <v-icon v-if="d.preview?.chain?.length" size="14" class="ms-1">mdi-account-tree</v-icon>
                          </span>
                        </template>
                        <v-card v-if="d.preview?.chain?.length" min-width="640" class="pa-3">
                          <div class="text-caption text-medium-emphasis mb-2">
                            Цепочка партнёров (от верхнего наставника вниз):
                          </div>
                          <v-table density="compact">
                            <thead>
                              <tr>
                                <th class="text-left">Партнёр</th>
                                <th class="text-end" style="white-space:nowrap">% кв.</th>
                                <th class="text-end">ЛП</th>
                                <th class="text-end">ГП</th>
                                <th class="text-end">Баллы</th>
                                <th class="text-end" style="white-space:nowrap">Σ, RUB</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr v-for="row in chainTopDown(d.preview.chain)" :key="row.consultantId"
                                :class="{ 'font-weight-bold tx-direct-row': row.isDirect }">
                                <td>{{ row.name }}</td>
                                <td class="text-end">{{ row.percent }}%</td>
                                <td class="text-end">{{ fmt2(row.lp) }}</td>
                                <td class="text-end">{{ fmt2(row.gp || 0) }}</td>
                                <td class="text-end">{{ fmt2(row.points) }}</td>
                                <td class="text-end">{{ fmt2(row.sum) }} RUB</td>
                              </tr>
                            </tbody>
                          </v-table>
                          <div class="text-caption text-medium-emphasis mt-2">
                            Полужирным — текущий партнёр (получатель транзакции).
                            ЛП — личные продажи, ГП — групповой объём, поднявшийся снизу.
                          </div>
                        </v-card>
                      </v-menu>
                    </template>
                    <template v-else-if="h.key === 'lpPoints'">
                      <span v-if="d.preview?.ready">{{ fmt2(d.preview.personalVolume || 0) }}</span>
                      <span v-else class="text-medium-emphasis">—</span>
                    </template>
                    <template v-else-if="h.key === 'pointsCount'">
                      <span v-if="d.preview?.ready">{{ fmt2(d.preview.chain?.[0]?.points || 0) }}</span>
                      <span v-else class="text-medium-emphasis">—</span>
                    </template>
                    <template v-else-if="h.key === 'partnersTotal'">
                      <span v-if="d.preview?.ready">{{ fmt2(d.preview.partnersTotal || 0) }} RUB</span>
                      <span v-else class="text-medium-emphasis">—</span>
                    </template>
                    <template v-else-if="h.key === 'profit'">
                      <span v-if="d.preview?.ready" class="font-weight-bold">{{ fmt2(d.preview.profitDS) }} RUB</span>
                      <span v-else class="text-medium-emphasis">—</span>
                    </template>
                    <template v-else-if="h.key === 'actions'">
                      <!-- «Дублировать» — для случая нескольких одинаковых
                           взносов по одному контракту (напр. два 10 000 RUB
                           на одну дату). Копируем contract+amount+date+
                           currency+comment+parameter+yearKV; commission-флаги
                           сбрасываются — пусть оператор решает заново. -->
                      <v-btn v-if="canCalc" icon="mdi-content-duplicate" size="x-small" variant="text" color="primary"
                        title="Дублировать черновик"
                        @click="duplicateDraft(d)" />
                      <v-btn v-if="canCalc" icon="mdi-trash-can-outline" size="x-small" variant="text" color="error"
                        title="Удалить черновик"
                        @click="removeDraft(d)" />
                    </template>
                  </td>
                </tr>

              </template>

              <!-- Строка-итог снизу таблицы -->
              <tr class="tx-totals-row">
                <td v-for="h in visibleDraftHeaders" :key="'tot-' + h.key" :class="h.tdClass">
                  <template v-if="h.key === 'icon'">
                    <v-icon size="20" color="success">mdi-sigma</v-icon>
                  </template>
                  <template v-else-if="h.key === 'number'">
                    <span class="text-success font-weight-bold">ИТОГО</span>
                  </template>
                  <template v-else-if="h.key === 'date'">
                    <span class="text-success">{{ totals.maxDate || '—' }}</span>
                  </template>
                  <template v-else-if="h.key === 'amount'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.amount) }}</span>
                  </template>
                  <template v-else-if="h.key === 'currency'">
                    <span class="text-success">{{ totals.currencySymbol || 'RUB' }}</span>
                  </template>
                  <template v-else-if="h.key === 'amountRub'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.amountRub) }} RUB</span>
                  </template>
                  <template v-else-if="h.key === 'incomeDS'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.incomeDS) }} RUB</span>
                  </template>
                  <template v-else-if="h.key === 'incomeDsNoVat'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.incomeDsNoVat) }} RUB</span>
                  </template>
                  <template v-else-if="h.key === 'lpPoints'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.lpPoints) }}</span>
                  </template>
                  <template v-else-if="h.key === 'pointsCount'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.pointsCount) }}</span>
                  </template>
                  <template v-else-if="h.key === 'partnersTotal'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.partnersTotal) }} RUB</span>
                  </template>
                  <template v-else-if="h.key === 'profit'">
                    <span class="text-end text-success font-weight-bold d-block">{{ fmt2(totals.profit) }} RUB</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </v-table>
          </div><!-- /manual-tx-scroll -->

          <v-card-actions class="d-flex flex-wrap ga-2">
            <v-btn v-if="canCalc" color="primary" :disabled="!calculableIds.length || calculating" prepend-icon="mdi-calculator"
              :loading="calculating" @click="calcAll" size="large">
              Рассчитать транзакции
              <v-chip v-if="dirtyCount" size="x-small" color="white" variant="elevated" class="ms-2">
                {{ dirtyCount }}
              </v-chip>
            </v-btn>
            <v-btn v-if="canCalc" color="success" :disabled="!fixableIds.length || fixing" prepend-icon="mdi-content-save"
              :loading="fixing" @click="fixAll" size="large" variant="outlined">
              Зафиксировать транзакции
              <v-chip v-if="fixableIds.length" size="x-small" color="success" variant="elevated" class="ms-2">
                {{ fixableIds.length }}
              </v-chip>
            </v-btn>
            <v-spacer />
            <v-btn v-if="canCalc && drafts.length" color="error" variant="text" prepend-icon="mdi-trash-can-outline" @click="clearAll">
              Очистить все транзакции
            </v-btn>
          </v-card-actions>

          <v-card-text v-if="drafts.length" class="pt-0">
            <div class="text-caption text-medium-emphasis mb-1">Готовность:</div>
            <div class="d-flex flex-wrap ga-2">
              <v-chip size="small" :color="cl.amounts ? 'success' : 'default'"
                :prepend-icon="cl.amounts ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'">
                Введены суммы транзакций
              </v-chip>
              <v-chip size="small" :color="cl.dates ? 'success' : 'default'"
                :prepend-icon="cl.dates ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'">
                Введены даты транзакций
              </v-chip>
              <v-chip size="small" :color="!calculating ? 'success' : 'default'"
                :prepend-icon="!calculating ? 'mdi-check-circle' : 'mdi-loading'">
                Расчёты не ведутся
              </v-chip>
              <v-chip size="small" :color="cl.calculated ? 'success' : 'warning'"
                :prepend-icon="cl.calculated ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'">
                Рассчитаны комиссии по всем транзакциям
              </v-chip>
            </div>
          </v-card-text>
        </v-card>

        <v-dialog v-model="rateModal" max-width="540">
          <v-card v-if="rateContext">
            <v-card-title>Изменить комиссию ДС в контракте {{ rateContext.contractNumber || '' }}</v-card-title>
            <v-card-text>
              <!-- Контекст программы/срока, по которым отобраны тарифы. -->
              <div class="text-caption text-medium-emphasis mb-2">
                Программа: <strong>{{ rateContext.programName || '—' }}</strong>
                <template v-if="rateContext.contractTerm != null">
                  · срок контракта: <strong>{{ rateContext.contractTerm }}</strong>
                </template>
              </div>
              <!-- Срок контракта не совпал ни с одним тарифом — фильтр по
                   сроку снят, показаны все ставки программы. -->
              <v-alert v-if="rateRelaxedTerm" type="warning" variant="tonal" density="compact" class="mb-2">
                Для срока контракта ({{ rateContext.contractTerm }}) тарифов не найдено —
                показаны все ставки программы. Проверьте срок контракта.
              </v-alert>
              <!-- На дату транзакции действующих тарифов нет — показаны все
                   версии (включая исторические). Возможны дубли по году. -->
              <v-alert v-else-if="rateRelaxedDate" type="warning" variant="tonal" density="compact" class="mb-2">
                На дату транзакции действующих тарифов не найдено —
                показаны все версии ставок (включая исторические).
              </v-alert>
              <v-alert v-if="!productRates.length" type="info" variant="tonal" density="compact">
                Для программы контракта нет настроенных тарифов в справочнике dsCommission.
              </v-alert>
              <v-radio-group v-else v-model="rateChoice">
                <v-radio v-for="r in productRates" :key="r.id" :value="r.id">
                  <template #label>
                    <div>
                      <div class="font-weight-medium">{{ r.comission }}%</div>
                      <div class="text-caption text-medium-emphasis">
                        {{ r.propertyTitle || r.programName || '—' }}
                        <template v-if="r.termContract"> · срок {{ r.termContract }} лет</template>
                      </div>
                    </div>
                  </template>
                </v-radio>
              </v-radio-group>
            </v-card-text>
            <v-card-actions>
              <v-spacer />
              <v-btn variant="text" @click="rateModal = false">Отмена</v-btn>
              <v-btn color="primary" :disabled="!rateChoice" @click="applyRate">Сохранить комиссии ДС</v-btn>
            </v-card-actions>
          </v-card>
        </v-dialog>
      </v-window-item>

      <!-- ВКЛАДКА 2: ЖУРНАЛ ЗАФИКСИРОВАННЫХ ТРАНЗАКЦИЙ -->
      <v-window-item value="log">
        <v-card class="mb-3 pa-3">
          <div class="d-flex ga-2 flex-wrap align-center">
            <v-text-field v-model="logSearch" placeholder="Поиск по ID..."
              rounded prepend-inner-icon="mdi-magnify" clearable hide-details style="max-width:240px"
              @update:model-value="debouncedLogLoad" />
            <v-text-field v-model="logChainPartner" placeholder="Партнёр в цепочке"
              rounded prepend-inner-icon="mdi-account-tree" clearable hide-details
              style="max-width:240px"
              @update:model-value="debouncedLogLoad" />
            <v-text-field v-model="logMonth" type="month" label="Месяц"
              hide-details style="max-width:200px" @update:model-value="loadLog" />
            <v-checkbox v-model="logHideZero" label="Скрыть нулевые"
              density="compact" hide-details color="primary"
              title="Скрыть строки уплайн-наставников с margin=0 (та же квалификация, что у нижестоящего)"
              @update:model-value="loadLog" />
            <v-chip v-if="logActiveFilters > 0" size="small" color="info" variant="tonal" class="ml-1">
              {{ logActiveFilters }} {{ logActiveFilters === 1 ? 'фильтр' : 'фильтра' }}
            </v-chip>
            <v-btn v-if="logActiveFilters > 0" size="small" variant="text" color="secondary"
              prepend-icon="mdi-filter-remove" @click="resetLogFilters">Сбросить</v-btn>
            <v-spacer />
            <ColumnVisibilityMenu :headers="logHeaders" v-model:visible="logColumnVisible" storage-key="transactions-cols" />
          </div>
        </v-card>

        <DataTableWrapper
          :items="logItems"
          :items-length="logTotal"
          :loading="logLoading"
          :headers="logVisibleHeaders"
          :items-per-page="logPerPage"
          :items-per-page-options="[25, 50, 100, 200]"
          server-side
          empty-icon="mdi-swap-horizontal-variant"
          empty-message="Транзакции не найдены"
          @update:options="onLogOptions">
          <template #item.period="{ item }">
            <v-icon :color="item.periodFrozen ? 'grey' : 'info'" size="14"
              :title="item.periodFrozen ? 'Период закрыт' : 'Период открыт'">
              mdi-square
            </v-icon>
          </template>
          <template #item.contractNumber="{ item }">
            {{ item.contractNumber || ('Контракт #' + item.contract) }}
          </template>
          <template #item.contractOpenDate="{ value }">{{ value ? fmtDate(value) : '—' }}</template>
          <template #item.amount="{ item }">{{ fmt2(item.amount) }} {{ item.currencySymbol || '' }}</template>
          <template #item.amountRUB="{ value }">{{ fmt2(value) }} ₽</template>
          <template #item.amountUSD="{ value }">{{ fmt2(value) }} $</template>
          <template #item.date="{ value }">{{ fmtDate(value) }}</template>
          <template #item.dsCommissionPercentage="{ value }">
            <span v-if="value != null">{{ value }}%</span>
            <span v-else class="text-medium-emphasis">—</span>
          </template>
          <template #item.commissionsAmountRUB="{ value }">{{ fmt2(value) }} ₽</template>
          <template #item.commissionsAmountUSD="{ value }">{{ fmt2(value) }} $</template>
          <template #item.netRevenueRUB="{ value }">{{ fmt2(value) }} ₽</template>
          <template #item.netRevenueUSD="{ value }">{{ fmt2(value) }} $</template>
          <template #item.contractTerm="{ value }">{{ value || '—' }}</template>
          <template #item.yearKV="{ value }">{{ value || '—' }}</template>
          <template #item.propertyTitle="{ value }">{{ value || '—' }}</template>
          <template #item.comment="{ value }">{{ value || '—' }}</template>
          <template #item.chat="{ item }">
            <StartChatButton :partner-id="item.consultantId || item.consultant" :partner-name="item.consultantName"
              context-type="Транзакция" :context-id="item.id" :context-label="'#' + item.id" />
          </template>
          <template #item.edit="{ item }">
            <v-btn icon="mdi-pencil" size="x-small" variant="text" color="primary"
              :title="item.periodFrozen ? 'Период закрыт — нельзя править' : 'Редактировать транзакцию'"
              :disabled="item.periodFrozen" @click="openEditTx(item)" />
            <v-btn v-if="canCalc" icon="mdi-trash-can-outline" size="x-small"
              variant="text" color="error"
              :title="item.periodFrozen ? 'Период закрыт — нельзя удалить' : 'Удалить транзакцию (с пересчётом цепочки)'"
              :disabled="item.periodFrozen || deletingTxId === item.id"
              :loading="deletingTxId === item.id"
              @click="confirmDeleteTx(item)" />
          </template>
        </DataTableWrapper>
      </v-window-item>

    </v-window>

    <!-- Редактирование транзакции -->
    <v-dialog v-model="editDialog" max-width="560">
      <v-card v-if="editTx">
        <v-card-title class="d-flex align-center ga-2">
          <v-icon>mdi-pencil</v-icon>
          Редактировать транзакцию #{{ editTx.id }}
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" @click="editDialog = false" />
        </v-card-title>
        <v-card-text>
          <v-text-field v-model="editTx.amount" type="number" step="0.01" label="Сумма"
            density="comfortable" variant="outlined" class="mb-2" />
          <v-text-field v-model="editTx.dsCommissionPercentage" type="number" step="0.01"
            label="% ДС" density="comfortable" variant="outlined" class="mb-2"
            hint="Если задан — пересчитает комиссию" persistent-hint />
          <v-text-field v-model="editTx.date" type="date" label="Дата транзакции"
            density="comfortable" variant="outlined" class="mb-2" />
          <v-textarea v-model="editTx.comment" label="Комментарий" rows="2" auto-grow
            density="comfortable" variant="outlined" />
        </v-card-text>
        <v-card-actions>
          <v-btn variant="text" @click="editDialog = false">Отмена</v-btn>
          <v-spacer />
          <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save"
            :loading="savingTx" @click="saveTx">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snack.open" :color="snack.color" :timeout="snack.action ? 12000 : 4000">
      {{ snack.text }}
      <template v-if="snack.action" #actions>
        <v-btn variant="text" size="small" :to="snack.action.to" @click="snack.open = false">
          {{ snack.action.label }}
        </v-btn>
      </template>
    </v-snackbar>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import { useConfirm } from '../../composables/useConfirm';
import PageHeader from '../../components/PageHeader.vue';

const confirmDialog = useConfirm();
import EmptyState from '../../components/EmptyState.vue';
import DataTableWrapper from '../../components/DataTableWrapper.vue';
import StartChatButton from '../../components/StartChatButton.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { fmt2, fmtDate } from '../../composables/useDesign';

function fmtAmt(v) {
  if (v == null || v === '') return '';
  const n = Number(v);
  if (isNaN(n)) return String(v);
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}
function parseAmt(v) {
  if (v == null || v === '') return null;
  const n = parseFloat(String(v).replace(/[\s ]/g, '').replace(',', '.'));
  return isNaN(n) ? null : n;
}
import { usePermissions } from '../../composables/usePermissions';

// Все кнопки расчётов/финализации — только у руководителя расчётов (canCalc).
const { canCalc } = usePermissions();

// Перерасчёт штрафов §5 — только admin + calculations. Та же гарда в backend
// (role:admin,calculations), кнопка просто скрывает её у тех, кто получит 403.
const canManagePeriod = canCalc;
const recalcing = ref(false);

function recalcErrorMessage(e, fallback) {
  if (e?.response?.status === 429) {
    return 'Перерасчёт уже запускался недавно. Подождите минуту.';
  }
  return e?.response?.data?.message || fallback;
}

async function recalcCurrentPeriod() {
  if (recalcing.value) return;
  recalcing.value = true;
  try {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth() + 1;
    const monthLabel = now.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });

    let preview = null;
    try {
      const { data } = await api.post('/admin/finalize/preview', { year, month });
      preview = data;
    } catch (e) {
      notify(recalcErrorMessage(e, 'Не удалось получить превью'), 'error');
      return;
    }

    if (preview?.frozen) {
      notify(`Период ${monthLabel} закрыт — пересчёт недоступен`, 'error');
      return;
    }

    const ok = await confirmDialog.ask({
      title: `Пересчитать штрафы за ${monthLabel}?`,
      message:
        `Будет затронуто ${preview?.affected ?? 0} комиссий ` +
        `у ${preview?.processed ?? 0} партнёров. ` +
        `Расчёт включает Отрыв (×0.5) и ОП (×0.8) по §5. ` +
        `Изменения будут записаны в комиссии.`,
      confirmText: 'Пересчитать',
      confirmColor: 'error',
    });
    if (!ok) return;

    try {
      const { data } = await api.post('/admin/finalize/apply', { year, month });
      const ym = `${year}-${String(month).padStart(2, '0')}`;
      notify(
        data?.message || `Пересчёт за ${monthLabel} выполнен`,
        'success',
        { label: 'Открыть период', to: `/manage/periods/${ym}` },
      );
      if (tab.value === 'log') await loadLog();
    } catch (e) {
      notify(recalcErrorMessage(e, 'Не удалось применить пересчёт'), 'error');
    }
  } finally {
    recalcing.value = false;
  }
}

const tab = ref('manual');
const snack = ref({ open: false, color: 'success', text: '', action: null });
function notify(text, color = 'success', action = null) {
  snack.value = { open: true, color, text, action };
}

// === Manual entry: contracts top zone ===
const contracts = ref([]);
const contractTotal = ref(0);
const loadingContracts = ref(false);
const contractPage = ref(1);
const contractPerPage = ref(15);
const filters = ref({
  consultantName: '', clientName: '', number: '',
  supplier: null, provider: null, product: null, program: null, currency: null,
});
const productList = ref([]);
const programList = ref([]);
const lookupSuppliers = ref([]);
const lookupProviders = ref([]);
const currencyOptions = ref([]);
const productRates = ref([]);

function resetContractFilters() {
  filters.value = { consultantName: '', clientName: '', number: '', supplier: null, provider: null, product: null, program: null, currency: null };
  loadContracts();
}

// Программы фильтра зависят от выбранного продукта. Если продукт не
// выбран — показываем все программы. Иначе — только программы этого
// продукта (по program.productId из form-data).
const programsForSelectedProduct = computed(() => {
  if (!filters.value.product) return programList.value;
  return programList.value.filter(p => p.productId === filters.value.product);
});

// При смене продукта — сбрасываем выбранную программу, если она не
// принадлежит новому продукту, и подгружаем список контрактов.
function onProductChange() {
  if (filters.value.program) {
    const stillValid = programsForSelectedProduct.value
      .some(p => p.id === filters.value.program);
    if (!stillValid) filters.value.program = null;
  }
  loadContracts();
}

const contractHeaders = [
  { title: '', key: 'add', sortable: false, width: 50 },
  { title: 'Номер', key: 'number', width: 130 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Открыт', key: 'openDate', width: 110 },
  { title: 'Срок', key: 'term', width: 70 },
  { title: 'Поставщик', key: 'supplierName' },
  { title: 'Продукт', key: 'productName' },
  { title: 'Программа', key: 'programName' },
  { title: 'Сумма', key: 'amount', align: 'end', width: 140 },
];
const contractColsVisible = ref({});
const visibleContractHeaders = computed(() =>
  contractHeaders.filter(h => contractColsVisible.value[h.key] !== false)
);

// Колонки таблицы черновиков. select/icon/actions — always-visible.
// Видимость остальных колонок управляется через ColumnVisibilityMenu.
const draftHeaders = [
  { title: '', key: 'select', style: 'width:36px' },
  { title: '', key: 'icon', style: 'width:32px' },
  { title: '№', key: 'number' },
  { title: 'Клиент', key: 'client' },
  { title: 'Продукт', key: 'product' },
  { title: 'Программа', key: 'program' },
  { title: 'Поставщик', key: 'supplier' },
  { title: 'Дата', key: 'date', style: 'min-width:140px' },
  { title: 'Комментарий', key: 'comment', style: 'min-width:160px' },
  { title: 'Свойство', key: 'parameter', style: 'min-width:130px' },
  { title: 'Год контракта', key: 'contractTerm', thClass: 'text-end', tdClass: 'text-end text-no-wrap', style: 'min-width:110px' },
  { title: 'Год КВ', key: 'yearKV', style: 'min-width:110px' },
  { title: 'Транзакция', key: 'amount', thClass: 'text-end', tdClass: 'text-end', style: 'min-width:140px' },
  { title: 'Валюта', key: 'currency', style: 'min-width:90px' },
  { title: 'Сумма, RUB', key: 'amountRub', thClass: 'text-end', tdClass: 'text-end text-no-wrap', style: 'min-width:140px' },
  { title: '% ДС', key: 'dsPercent', thClass: 'text-end', tdClass: 'text-end', style: 'min-width:80px' },
  { title: 'Своя комиссия', key: 'customCommission', thClass: 'text-center', tdClass: 'text-center', style: 'width:110px' },
  { title: 'Нулевой доход ДС', key: 'zeroDsIncome', thClass: 'text-center', tdClass: 'text-center', style: 'width:130px' },
  { title: 'Изменить', key: 'change', thClass: 'text-center', tdClass: 'text-center', style: 'width:50px' },
  { title: 'Доход ДС', key: 'incomeDS', thClass: 'text-end', tdClass: 'text-end text-no-wrap' },
  { title: 'Доход ДС без НДС', key: 'incomeDsNoVat', thClass: 'text-end', tdClass: 'text-end text-no-wrap', style: 'min-width:140px' },
  { title: 'Партнёр', key: 'partner' },
  { title: 'ЛП', key: 'lpPoints', thClass: 'text-end', tdClass: 'text-end text-no-wrap', style: 'min-width:70px' },
  { title: 'Баллы', key: 'pointsCount', thClass: 'text-end', tdClass: 'text-end text-no-wrap', style: 'min-width:80px' },
  { title: 'Σ по партнёрам', key: 'partnersTotal', thClass: 'text-end', tdClass: 'text-end text-no-wrap', style: 'min-width:130px' },
  { title: 'Прибыль ДС', key: 'profit', thClass: 'text-end', tdClass: 'text-end text-no-wrap' },
  { title: '', key: 'actions', style: 'width:88px; min-width:88px' },
];

const draftColsVisible = ref({});
const visibleDraftHeaders = computed(() =>
  draftHeaders.filter(h => draftColsVisible.value[h.key] !== false)
);

const { debounced: debouncedSearch } = useDebounce(loadContracts, 400);

function onContractOpts(opts) {
  contractPage.value = opts.page;
  if (opts.itemsPerPage) contractPerPage.value = opts.itemsPerPage;
  loadContracts();
}

async function loadContracts() {
  loadingContracts.value = true;
  try {
    const params = { page: contractPage.value, per_page: contractPerPage.value };
    if (filters.value.consultantName) params.consultantName = filters.value.consultantName;
    if (filters.value.clientName) params.clientName = filters.value.clientName;
    if (filters.value.number) params.number = filters.value.number;
    if (filters.value.product) params.product = filters.value.product;
    if (filters.value.program) params.program = filters.value.program;
    if (filters.value.supplier) params.supplier = filters.value.supplier;
    if (filters.value.provider) params.provider = filters.value.provider;
    const { data } = await api.get('/admin/manual-tx/contracts', { params });
    contracts.value = data.data;
    contractTotal.value = data.total;
  } catch {
    notify('Ошибка загрузки контрактов', 'error');
  }
  loadingContracts.value = false;
}

// === Manual entry: drafts ===
const drafts = ref([]);
const adding = ref(false);
const fixing = ref(false);
const selectedDraftIds = ref([]);

// Тогглы убраны (per user request) — все колонки видимы по умолчанию.

function isRateChangeable(d) {
  if (!d.productId || !d.productName) return false;
  const n = d.productName.toLowerCase();
  return n.includes('investor') || n.includes('trust') || n.includes('medlife') || n.includes('медлайф') || n.includes('инвестор');
}

function contractNum(d) {
  return d.contractNumber || (d.contractId ? `Контракт #${d.contractId}` : '—');
}

function partnerSurname(name) {
  if (!name) return '';
  return name.trim().split(/\s+/)[0];
}

/** В hover-попап выводим цепочку сверху-вниз: верхний наставник → текущий партнёр (bold). */
function chainTopDown(chain) {
  return [...chain].reverse();
}

function parseDate(v) {
  if (!v) return null;
  const d = new Date(v);
  return isNaN(d) ? null : d;
}

// При включении «Своя комиссия» предзаполняем dsCommissionAbsolute текущим
// рассчитанным incomeDS (без НДС) — иначе поле появлялось пустым и
// пользователь не понимал, что именно вводить. При выключении трогать
// dsCommissionAbsolute не нужно: бэкенд игнорирует его, если customCommission=false.
function onCustomCommissionToggle(d, v) {
  patchField(d, 'customCommission', !!v);
  if (v && (d.dsCommissionAbsolute == null || d.dsCommissionAbsolute === '') && d.preview?.incomeDS != null) {
    patchField(d, 'dsCommissionAbsolute', Math.round(Number(d.preview.incomeDS) * 100) / 100);
  }
}

// «Своя комиссия»: в UI пользователь оперирует Доходом ДС С НДС (gross), а в
// БД dsCommissionAbsolute хранится БЕЗ НДС (net) — бэкенд считает %ДС обратно
// от amountNoVat. Конвертируем на границе UI, не трогая бэкенд/расчёты.
function vatMul(d) { return 1 + Number(d?.preview?.vatPercent || 0) / 100; }
function grossCommission(d) {
  if (d.dsCommissionAbsolute == null || d.dsCommissionAbsolute === '') return null;
  return Math.round(Number(d.dsCommissionAbsolute) * vatMul(d) * 100) / 100;
}
function setGrossCommission(d, gross) {
  if (gross == null || gross === '') { patchField(d, 'dsCommissionAbsolute', null); return; }
  const m = vatMul(d);
  patchField(d, 'dsCommissionAbsolute', m > 0 ? Math.round(Number(gross) / m * 100) / 100 : Number(gross));
}

function formatYmd(d) {
  if (!d) return null;
  const date = d instanceof Date ? d : new Date(d);
  if (isNaN(date)) return null;
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const dd = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}

const totals = computed(() => {
  const ready = drafts.value.filter(d => d.preview?.ready);
  const maxDate = drafts.value.map(d => d.date).filter(Boolean).sort().pop();
  const sym = drafts.value[0]?.currencySymbol || 'RUB';
  return {
    maxDate: maxDate ? fmtDate(maxDate) : null,
    currencySymbol: sym,
    amount: drafts.value.reduce((s, d) => s + Number(d.amount || 0), 0),
    amountRub: ready.reduce((s, d) => s + Number(d.preview.amountRUB || 0), 0),
    incomeDS: ready.reduce((s, d) => s + Number(d.preview.incomeDS || 0) * (1 + Number(d.preview.vatPercent || 0) / 100), 0),
    incomeDsNoVat: ready.reduce((s, d) => s + Number(d.preview.incomeDS || 0), 0),
    lpPoints: ready.reduce((s, d) => s + Number(d.preview.personalVolume || 0), 0),
    pointsCount: ready.reduce((s, d) => s + Number(d.preview.chain?.[0]?.points || 0), 0),
    partnersTotal: ready.reduce((s, d) => s + Number(d.preview.partnersTotal || 0), 0),
    profit: ready.reduce((s, d) => s + Number(d.preview.profitDS || 0), 0),
  };
});

// Фолбэк, если у черновика нет программы/тарифов (legacy без productId).
const yearKVOptions = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

// Годы КВ конкретного продукта берём из его тарифов (availableParameters —
// строки commissionCalcProperty программы контракта). Для has_year_kv
// продуктов их title = год: «1 год», …, «5 год», «3, 4, 5 год» → вытаскиваем
// все числа. Medlife → 1–5, EVO → свой набор, без хардкода per-product.
function yearOptionsFor(d) {
  const years = new Set();
  (d?.availableParameters || []).forEach((p) => {
    const nums = String(p?.title ?? '').match(/\d+/g);
    if (nums) nums.forEach((n) => years.add(Number(n)));
  });
  const arr = [...years].filter((n) => n >= 1 && n <= 20).sort((a, b) => a - b);
  return arr.length ? arr : yearKVOptions;
}

async function loadDrafts() {
  try {
    const { data } = await api.get('/admin/manual-tx/drafts');
    drafts.value = data.data;
  } catch {}
}

async function addContractToDrafts(contractId) {
  adding.value = true;
  try {
    await api.post('/admin/manual-tx/drafts', { contractIds: [contractId] });
    await loadDrafts();
    notify('Добавлено в черновики');
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка', 'error');
  }
  adding.value = false;
}

const debouncedPatch = useDebounce((draft, payload) => doPatch(draft, payload), 500).debounced;

function patchField(draft, field, value) {
  draft[field] = value;
  debouncedPatch(draft, { [field]: value });
}

async function doPatch(draft, payload) {
  try {
    const { data } = await api.patch('/admin/manual-tx/drafts/' + draft.id, payload);
    Object.assign(draft, data);
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
  }
}

async function removeDraft(draft) {
  await api.delete('/admin/manual-tx/drafts/' + draft.id);
  drafts.value = drafts.value.filter(d => d.id !== draft.id);
}

// Дублировать черновик — для случая «два одинаковых взноса по одному
// контракту на одну дату» без перелистывания списка контрактов.
async function duplicateDraft(draft) {
  try {
    await api.post('/admin/manual-tx/drafts/' + draft.id + '/duplicate');
    await loadDrafts();
  } catch {
    notify('Не удалось дублировать черновик', 'error');
  }
}

async function clearAll() {
  if (!await confirmDialog.ask({
    title: 'Очистить все черновики?',
    message: `Будет удалено ${drafts.value.length} черновиков. Действие необратимо.`,
    confirmText: 'Очистить', confirmColor: 'error', icon: 'mdi-trash-can',
  })) return;
  await api.delete('/admin/manual-tx/drafts');
  drafts.value = [];
}

// Если есть выбранные чекбоксами — работаем по выбору, иначе по всем строкам.
function targetDrafts() {
  if (selectedDraftIds.value.length) {
    return drafts.value.filter(d => selectedDraftIds.value.includes(d.id));
  }
  return drafts.value;
}
const calculableIds = computed(() =>
  targetDrafts().filter(d => d.amount && d.date).map(d => d.id)
);
const fixableIds = computed(() =>
  targetDrafts().filter(d => d.amount && d.date && d.preview?.ready).map(d => d.id)
);
const dirtyCount = computed(() =>
  targetDrafts().filter(d => d.amount && d.date && !d.preview?.ready).length
);

const calculating = ref(false);
async function calcAll() {
  calculating.value = true;
  try {
    const { data } = await api.post('/admin/manual-tx/calc', {});
    if (data.calculated) notify(`Рассчитано: ${data.calculated}`);
    if (data.skipped) notify(`Пропущено (нет суммы/даты): ${data.skipped}`, 'warning');
    await loadDrafts();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка расчёта', 'error');
  }
  calculating.value = false;
}

async function fixAll() {
  if (!fixableIds.value.length) return;
  fixing.value = true;
  // Запоминаем диапазон дат фиксируемых черновиков — после успеха ведём
  // юзера прямо в Комиссии с этим фильтром. Иначе на /manage/commissions
  // свежая транзакция теряется среди десятков тысяч записей с такой же
  // (или более поздней) датой — сортировка по date DESC.
  const dates = drafts.value
    .filter(d => fixableIds.value.includes(d.id) && d.date)
    .map(d => String(d.date).slice(0, 10))
    .sort();
  const dateFrom = dates[0];
  const dateTo = dates[dates.length - 1];
  try {
    const { data } = await api.post('/admin/manual-tx/fix', { ids: fixableIds.value });
    if (data.fixed?.length) {
      const action = dateFrom
        ? { label: 'Открыть в Комиссиях', to: { path: '/manage/commissions', query: { date_from: dateFrom, date_to: dateTo } } }
        : null;
      notify(`Зафиксировано: ${data.fixed.length}`, 'success', action);
    }
    if (data.errors?.length) notify(`Ошибки: ${data.errors.length}`, 'warning');
    await loadDrafts();
    if (data.fixed?.length) loadLog();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка фиксации', 'error');
  }
  fixing.value = false;
}

const cl = computed(() => ({
  amounts: drafts.value.length > 0 && drafts.value.every(d => Number(d.amount) > 0),
  dates: drafts.value.length > 0 && drafts.value.every(d => !!d.date),
  calculated: drafts.value.length > 0 && drafts.value.every(d => d.preview?.ready),
}));

const rateModal = ref(false);
const rateContext = ref(null);
const rateChoice = ref(null); // выбранная строка тарифа по id (не по %, т.к.
                              // при снятом фильтре срока % могут повторяться).
const rateRelaxedTerm = ref(false);
const rateRelaxedDate = ref(false);

async function openRateModal(d) {
  if (!d.productId) return;
  rateContext.value = d;
  rateChoice.value = null;
  productRates.value = [];
  rateRelaxedTerm.value = false;
  rateRelaxedDate.value = false;
  try {
    // Тарифы выпадают согласно программе, сроку и дате транзакции черновика
    // (дата отсекает просроченные версии тарифа).
    const params = {};
    if (d.programId) params.program = d.programId;
    if (d.contractTerm != null) params.term = d.contractTerm;
    if (d.date) params.date = String(d.date).slice(0, 10);
    const { data } = await api.get(`/admin/manual-tx/products/${d.productId}/rates`, { params });
    productRates.value = data.rates || [];
    rateRelaxedTerm.value = !!data.relaxedTerm;
    rateRelaxedDate.value = !!data.relaxedDate;
    // Предвыбор текущей ставки черновика, если она присутствует в списке.
    if (d.dsCommissionPercentage != null) {
      const cur = productRates.value.find(r => Number(r.comission) === Number(d.dsCommissionPercentage));
      if (cur) rateChoice.value = cur.id;
    }
  } catch { productRates.value = []; }
  rateModal.value = true;
}

async function applyRate() {
  const row = productRates.value.find(r => r.id === rateChoice.value);
  if (!rateContext.value || !row) return;
  await doPatch(rateContext.value, {
    dsCommissionPercentage: Number(row.comission),
    commissionOverride: true,
  });
  rateModal.value = false;
}

// === Журнал зафиксированных ===
const logItems = ref([]);
const logTotal = ref(0);
const logLoading = ref(false);
const logSearch = ref('');
const logChainPartner = ref('');
const logMonth = ref(new Date().toISOString().slice(0, 7));
// Скрывать строки уплайн-наставников с amountRUB=0 (margin=0) — по умолчанию
// включено, чтобы не было «шума» из тысяч нулевых строк.
const logHideZero = ref(true);
const logPage = ref(1);
const logPerPage = ref(25);
const logSortBy = ref('');
const logSortDir = ref('desc');
const defaultMonth = new Date().toISOString().slice(0, 7);

const logHeaders = [
  { title: '', key: 'period', sortable: false, width: 30 },
  { title: 'ID', key: 'id', width: 70 },
  { title: '№ контракта', key: 'contractNumber', width: 140 },
  { title: 'Открыт', key: 'contractOpenDate', width: 110 },
  { title: 'Клиент', key: 'clientName' },
  { title: 'Партнёр', key: 'consultantName' },
  { title: 'Дата тр.', key: 'date', width: 110 },
  { title: 'Комментарий', key: 'comment' },
  { title: 'Свойство', key: 'propertyTitle', width: 130 },
  { title: 'Срок контр.', key: 'contractTerm', width: 100, align: 'end' },
  { title: 'Год КВ', key: 'yearKV', width: 90, align: 'end' },
  { title: 'Транзакция', key: 'amount', align: 'end', width: 130 },
  { title: 'В РУБ', key: 'amountRUB', align: 'end', width: 130 },
  { title: '%ДС', key: 'dsCommissionPercentage', align: 'end', width: 80 },
  { title: 'Доход DS RUB', key: 'commissionsAmountRUB', align: 'end', width: 140 },
  { title: 'Доход DS USD', key: 'commissionsAmountUSD', align: 'end', width: 140 },
  { title: 'Без НДС RUB', key: 'netRevenueRUB', align: 'end', width: 130 },
  { title: 'Без НДС USD', key: 'netRevenueUSD', align: 'end', width: 130 },
  { title: '', key: 'chat', sortable: false, width: 50 },
  { title: '', key: 'edit', sortable: false, width: 90 },
];

const logColumnVisible = ref({});

// Авто-скрытие колонок «Свойство»/«Срок контр.»/«Год КВ» если ни у одной
// строки текущей страницы продукт не объявил соответствующий флаг.
// Раньше эти колонки показывали '—' для всех строк продуктов где они не
// релевантны — занимало место без информации.
const logAutoHide = computed(() => {
  const items = logItems.value || [];
  if (! items.length) return new Set();
  const hide = new Set(['propertyTitle', 'contractTerm', 'yearKV']);
  for (const it of items) {
    if (it.productHasProperty) hide.delete('propertyTitle');
    if (it.productHasTerm) hide.delete('contractTerm');
    if (it.productHasYearKv) hide.delete('yearKV');
    if (hide.size === 0) break;
  }
  return hide;
});

const logVisibleHeaders = computed(() => logHeaders.filter(h =>
  logColumnVisible.value[h.key] !== false
  && ! logAutoHide.value.has(h.key)
));

// Редактирование одной транзакции через PUT /admin/transactions/{id}.
const editDialog = ref(false);
const editTx = ref(null);
const savingTx = ref(false);

function openEditTx(item) {
  if (item.periodFrozen) return;
  editTx.value = {
    id: item.id,
    amount: item.amount ?? 0,
    dsCommissionPercentage: item.dsCommissionPercentage ?? null,
    date: item.date ? String(item.date).slice(0, 10) : '',
    comment: item.comment ?? '',
  };
  editDialog.value = true;
}

async function saveTx() {
  if (!editTx.value) return;
  savingTx.value = true;
  try {
    const payload = {
      amount: editTx.value.amount === '' ? null : Number(editTx.value.amount),
      dsCommissionPercentage: editTx.value.dsCommissionPercentage === '' || editTx.value.dsCommissionPercentage == null
        ? null : Number(editTx.value.dsCommissionPercentage),
      date: editTx.value.date || null,
      comment: editTx.value.comment ?? null,
    };
    await api.put(`/admin/transactions/${editTx.value.id}`, payload);
    notify('Транзакция обновлена. Комиссии пересчитаны.', 'success');
    editDialog.value = false;
    await loadLog();
  } catch (e) {
    notify(e.response?.data?.message || 'Ошибка сохранения', 'error');
  }
  savingTx.value = false;
}

// Удаление одной зафиксированной транзакции через DELETE /admin/transactions/{id}.
// Доступно только admin / calculations (reports-access). Бэк сам блокирует
// закрытый период (422). Каскадно soft-delete'ит commission всей цепочки
// наставников и пересчитывает consultantBalance — поэтому в confirm-диалоге
// явно предупреждаем, что цифры партнёров-наставников изменятся.
const deletingTxId = ref(null);

async function confirmDeleteTx(item) {
  if (item.periodFrozen) return;
  const ok = await confirmDialog.ask({
    title: `Удалить транзакцию #${item.id}?`,
    message:
      `Сумма ${item.amount ?? '—'} ${item.currencySymbol || ''} от ${item.date ?? '—'}. ` +
      `Партнёр: ${item.consultantName || '—'}.\n\n` +
      `Будут отменены все комиссии по этой транзакции у партнёра и всех его наставников. ` +
      `Балансы пересчитаются автоматически. Действие обратимо только восстановлением вручную в БД.`,
    confirmText: 'Удалить',
    confirmColor: 'error',
  });
  if (!ok) return;
  deletingTxId.value = item.id;
  try {
    const { data } = await api.delete(`/admin/transactions/${item.id}`);
    // Если за месяц транзакции пул уже был применён — выплаты у партнёров
    // посчитаны по старой сумме commission, нужно перезапустить пул вручную
    // через карточку периода. Показываем оранжевый snackbar с action-кнопкой,
    // ведущей сразу туда.
    if (data?.poolWasApplied && data?.poolPeriod) {
      notify(
        data.message || `Транзакция #${item.id} удалена. Пересчитайте пул за ${data.poolPeriod} вручную.`,
        'warning',
        { label: 'Открыть период', to: `/manage/periods/${data.poolPeriod}` },
      );
    } else {
      notify(data?.message || `Транзакция #${item.id} удалена`, 'success');
    }
    await loadLog();
  } catch (e) {
    notify(e.response?.data?.message || 'Не удалось удалить транзакцию', 'error');
  }
  deletingTxId.value = null;
}

const logActiveFilters = computed(() => {
  let c = 0;
  if (logSearch.value) c++;
  if (logChainPartner.value) c++;
  if (logMonth.value && logMonth.value !== defaultMonth) c++;
  if (!logHideZero.value) c++; // если ВЫКЛЮЧИЛ дефолтный фильтр — это активный фильтр
  return c;
});

function resetLogFilters() {
  logSearch.value = '';
  logChainPartner.value = '';
  logMonth.value = defaultMonth;
  logHideZero.value = true;
  loadLog();
}

const { debounced: debouncedLogLoad } = useDebounce(loadLog, 400);

function onLogOptions(opts) {
  logPage.value = opts.page;
  if (opts.itemsPerPage) logPerPage.value = opts.itemsPerPage;
  // Sort: opts.sortBy = [{ key, order: 'asc'|'desc' }]
  if (Array.isArray(opts.sortBy) && opts.sortBy.length) {
    logSortBy.value = opts.sortBy[0].key;
    logSortDir.value = opts.sortBy[0].order || 'desc';
  } else {
    logSortBy.value = '';
    logSortDir.value = 'desc';
  }
  loadLog();
}

async function loadLog() {
  logLoading.value = true;
  try {
    const params = { page: logPage.value, per_page: logPerPage.value };
    if (logSearch.value) params.search = logSearch.value;
    if (logChainPartner.value) params.chain_partner = logChainPartner.value;
    if (logMonth.value) params.month = logMonth.value;
    if (logHideZero.value) params.hide_zero = 1;
    if (logSortBy.value) {
      params.sort_by = logSortBy.value;
      params.sort_dir = logSortDir.value;
    }
    const { data } = await api.get('/admin/transactions', { params });
    logItems.value = data.data;
    logTotal.value = data.total;
  } catch {}
  logLoading.value = false;
}

onMounted(async () => {
  await Promise.all([loadContracts(), loadDrafts(), loadLog()]);
  try {
    const [products, formData, lookups] = await Promise.all([
      api.get('/admin/products-catalog', { params: { per_page: 1000, active: true } }).catch(() => ({ data: { data: [] } })),
      api.get('/admin/transaction-import/form-data').catch(() => ({ data: { currencies: [] } })),
      api.get('/admin/manual-tx/lookups').catch(() => ({ data: { suppliers: [], providers: [] } })),
    ]);
    productList.value = (products.data?.data || []).filter(p => p.legacyProductId).map(p => ({ id: p.legacyProductId, name: p.name }));
    currencyOptions.value = (formData.data?.currencies || []).map(c => ({
      id: c.id, symbol: c.symbol || c.name, name: c.name,
    }));
    lookupSuppliers.value = lookups.data?.suppliers || [];
    lookupProviders.value = lookups.data?.providers || [];

    // Programs не были загружены — фильтр «Программа» оставался пустым.
    // Берём из общего contract form-data — там programs уже сгруппированы
    // по продуктам.
    //
    // Дедуп: legacy `program` хранит одну и ту же программу («Жизнь+»)
    // несколькими строками (разные vendorName/term/provider). Для
    // фильтра-выбора это шум — отбираем по одному представителю на
    // (name, productId). Фильтр на бэке матчит по contract.programName,
    // так что выбор любого id из группы поднимает контракты по всем
    // вариантам этой программы.
    try {
      const fd = await api.get('/admin/contracts/form-data');
      const seen = new Set();
      programList.value = (fd.data?.programs || [])
        .filter(p => {
          const key = `${p.name}|${p.productId ?? ''}`;
          if (seen.has(key)) return false;
          seen.add(key);
          return true;
        })
        .map(p => ({ id: p.id, name: p.name, productId: p.productId ?? null }));
    } catch {}
  } catch {}
});
</script>

<style scoped>
/* Убираем spin-кнопки у всех number-input в таблице — при случайном наведении меняли сумму */
.manual-tx-table :deep(input[type="number"]::-webkit-inner-spin-button),
.manual-tx-table :deep(input[type="number"]::-webkit-outer-spin-button) { -webkit-appearance: none; margin: 0; }
.manual-tx-table :deep(input[type="number"]) { -moz-appearance: textfield; }
.manual-tx-table :deep(table) { border-collapse: separate; border-spacing: 0; }
.manual-tx-table :deep(td) { vertical-align: middle; }
.manual-tx-table :deep(th) {
  white-space: nowrap;
  font-weight: 600;
  background: rgba(var(--v-theme-surface-variant), 0.5);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgba(var(--v-theme-on-surface), 0.7);
}
.manual-tx-table :deep(tbody tr:hover td) {
  background: rgba(var(--v-theme-primary), 0.04);
}
.tx-row-ready :deep(td),
.tx-row-ready td { background: rgba(var(--v-theme-success), 0.08); }
.tx-totals-row td {
  background: rgba(var(--v-theme-success), 0.14) !important;
  font-size: 14px;
  border-top: 2px solid rgba(var(--v-theme-success), 0.45) !important;
  padding-top: 12px !important;
  padding-bottom: 12px !important;
}
.tx-partner-hover { cursor: pointer; }
.tx-partner-hover:hover { text-decoration: underline; }
.tx-direct-row td { background: rgba(var(--v-theme-primary), 0.08); }
</style>
