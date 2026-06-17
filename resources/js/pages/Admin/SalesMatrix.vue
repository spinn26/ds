<template>
  <div>
    <!-- Header + mode toggle -->
    <div class="d-flex align-center mb-4 ga-3 flex-wrap">
      <PageHeader :title="reportType === 'revenue' ? 'Начисление выручки по продуктам' : 'Продажи по продуктам'"
        icon="mdi-table-large" class="flex-grow-1 mb-0" />
      <v-btn size="small" variant="tonal" prepend-icon="mdi-currency-rub"
        to="/manage/management-currencies" title="Курсы валют для отчётов">
        Курсы
      </v-btn>
      <!-- Тип отчёта (Part 3, Lena): Продажи / Начисление выручки -->
      <v-btn-toggle v-model="reportType" density="compact" variant="outlined" mandatory color="secondary">
        <v-btn value="sales" size="small">Продажи</v-btn>
        <v-btn value="revenue" size="small">Начисление выручки</v-btn>
      </v-btn-toggle>
      <v-btn-toggle v-model="reportMode" density="compact" variant="outlined" mandatory color="primary">
        <v-btn value="inwork" size="small" prepend-icon="mdi-progress-clock">
          В работе
          <v-tooltip activator="parent" location="bottom" text="Контракты в работе (прогноз активации)" />
        </v-btn>
        <v-btn value="forecast" size="small" prepend-icon="mdi-chart-timeline-variant">
          Активировано
          <v-tooltip activator="parent" location="bottom" text="Активированные контракты" />
        </v-btn>
        <v-btn value="fact" size="small" prepend-icon="mdi-check-circle-outline">
          Факт
          <v-tooltip activator="parent" location="bottom" text="Финансовый факт: Транзакции и пополнения" />
        </v-btn>
        <v-btn value="total" size="small" prepend-icon="mdi-sigma">
          Итого
          <v-tooltip activator="parent" location="bottom" text="Итого (в разработке)" />
        </v-btn>
      </v-btn-toggle>
    </div>

    <!-- Заглушка для неопределённых разрезов: Начисление выручки (весь отчёт)
         и Итого (в любом отчёте). По указанию Лены — «оставить пустыми», пока
         не описана логика. -->
    <v-card v-if="isStub" class="ds-card" elevation="0">
      <v-card-text class="text-center py-12">
        <v-icon size="48" color="medium-emphasis" class="mb-3">mdi-hammer-wrench</v-icon>
        <div class="text-h6 mb-1">Отчёт в разработке</div>
        <div class="text-body-2 text-medium-emphasis" style="max-width:520px;margin:0 auto">
          Структура согласована: <b>Продажи</b> и <b>Начисление выручки</b>, каждый в
          разрезах <b>В работе / Активировано / Факт / Итого</b>.
          Логика этого разреза будет описана и добавлена отдельно.
        </div>
      </v-card-text>
    </v-card>

    <!-- (устар.) pipeline по прогнозу активации — заменён на /inwork по дате создания -->
    <template v-if="reportType === 'sales' && reportMode === 'pipeline'">
      <!-- Filter bar -->
      <v-card class="ds-card mb-3" elevation="0">
        <v-card-text class="pa-2">
          <div class="d-flex ga-1 flex-wrap align-center">

            <!-- Period mode (по прогнозной дате активации; «Всё» = без ограничения) -->
            <v-btn-toggle v-model="fcPeriodMode" mandatory density="compact" variant="outlined" color="primary"
              @update:model-value="loadForecast">
              <v-btn value="all"     size="x-small">Всё</v-btn>
              <v-btn value="year"    size="x-small">Год</v-btn>
              <v-btn value="quarter" size="x-small">Квартал</v-btn>
              <v-btn value="month"   size="x-small">Месяц</v-btn>
              <v-btn value="range"   size="x-small">Диапазон</v-btn>
            </v-btn-toggle>

            <v-select v-if="fcPeriodMode !== 'all' && fcPeriodMode !== 'range'" v-model="fcPeriodYear" :items="fcYearOptions"
              density="compact" variant="outlined" hide-details style="width:92px; flex:0 0 92px"
              @update:model-value="loadForecast" />

            <v-btn-toggle v-if="fcPeriodMode === 'quarter'" v-model="fcPeriodQuarter" mandatory
              density="compact" variant="outlined" @update:model-value="loadForecast">
              <v-btn v-for="q in ['Q1','Q2','Q3','Q4']" :key="q" :value="q" size="x-small">{{ q }}</v-btn>
            </v-btn-toggle>

            <v-select v-if="fcPeriodMode === 'month'" v-model="fcPeriodMonth" :items="monthOpts"
              item-title="t" item-value="v" density="compact" variant="outlined"
              hide-details style="width:128px; flex:0 0 128px" @update:model-value="loadForecast" />

            <template v-if="fcPeriodMode === 'range'">
              <v-select v-model="fcRangeFromYear" :items="fcYearOptions" density="compact" variant="outlined"
                hide-details style="width:86px;flex:0 0 86px" @update:model-value="loadForecast" />
              <v-select v-model="fcRangeFromMonth" :items="monthOpts" item-title="t" item-value="v"
                density="compact" variant="outlined" hide-details style="width:120px;flex:0 0 120px"
                @update:model-value="loadForecast" />
              <span class="text-medium-emphasis" style="flex-shrink:0">—</span>
              <v-select v-model="fcRangeToYear" :items="fcYearOptions" density="compact" variant="outlined"
                hide-details style="width:86px;flex:0 0 86px" @update:model-value="loadForecast" />
              <v-select v-model="fcRangeToMonth" :items="monthOpts" item-title="t" item-value="v"
                density="compact" variant="outlined" hide-details style="width:120px;flex:0 0 120px"
                @update:model-value="loadForecast" />
            </template>

            <v-divider vertical class="mx-1" style="height:24px;align-self:center" />

            <!-- Status filter (стадия pipeline) -->
            <v-btn-toggle v-model="fcStatuses" multiple density="compact" variant="outlined" color="primary"
              @update:model-value="onFcStatusChange">
              <v-btn :value="2" size="x-small">Сбор док.</v-btn>
              <v-btn :value="3" size="x-small">Комплайнс</v-btn>
            </v-btn-toggle>

            <v-divider vertical class="mx-1" style="height:24px;align-self:center" />

            <v-autocomplete v-model="fcFilterSuppliers" :items="fcSupplierOptions"
              placeholder="Поставщик" prepend-inner-icon="mdi-domain"
              multiple chips closable-chips density="compact" variant="outlined"
              hide-details style="width:190px; flex:0 0 190px"
              @update:model-value="onFcSupplierFilter" />
            <v-autocomplete v-model="fcFilterProducts" :items="fcProductOptions"
              item-title="name" item-value="id" placeholder="Продукт"
              prepend-inner-icon="mdi-magnify"
              multiple chips closable-chips density="compact" variant="outlined"
              hide-details style="width:220px; flex:0 0 220px"
              @update:model-value="onFcProductFilter" />
            <v-btn v-if="fcFilterProducts.length || fcFilterSuppliers.length"
              icon="mdi-filter-remove" size="x-small" variant="text" @click="resetFcFilters" />

            <v-spacer />

            <v-btn size="x-small" variant="text" prepend-icon="mdi-expand-all-outline"   @click="fcExpandAll">Все</v-btn>
            <v-btn size="x-small" variant="text" prepend-icon="mdi-collapse-all-outline" @click="fcCollapseAll">Свернуть</v-btn>

            <!-- Metrics selector -->
            <v-menu :close-on-content-click="false" location="bottom end">
              <template #activator="{ props }">
                <v-btn v-bind="props" size="x-small" variant="tonal" color="primary" prepend-icon="mdi-tune">
                  Метрики · {{ fcSelectedMetricKeys.length }}
                </v-btn>
              </template>
              <v-card min-width="210" elevation="4">
                <v-card-title class="text-body-2 pa-3 pb-1 font-weight-medium">Метрики</v-card-title>
                <v-divider />
                <v-list density="compact" class="pa-1">
                  <v-list-item v-for="m in fcAllMetrics" :key="m.key" :title="m.label"
                    rounded="lg" style="cursor:pointer" @click="fcToggleMetric(m.key)">
                    <template #prepend>
                      <v-checkbox-btn :model-value="fcSelectedMetricKeys.includes(m.key)"
                        color="primary" density="compact"
                        @click.stop="fcToggleMetric(m.key)" />
                    </template>
                  </v-list-item>
                </v-list>
              </v-card>
            </v-menu>
          </div>
        </v-card-text>
      </v-card>

      <v-progress-linear v-if="fcLoading" indeterminate color="primary" rounded class="mb-3" />

      <!-- Hint: contracts without forecast date -->
      <v-alert v-if="fcNoDateCount > 0 && !fcLoading" type="info" variant="tonal"
        density="compact" class="mb-3" icon="mdi-calendar-alert">
        <span>{{ fcNoDateCount }} контр. без даты прогноза — выделены колонкой «Без даты».</span>
        <v-btn size="x-small" variant="text" color="info" class="ml-2"
          to="/manage/contracts">Заполнить в менеджере</v-btn>
      </v-alert>

      <!-- Summary chips -->
      <div v-if="fcGrandTotals && !fcLoading" class="d-flex ga-2 flex-wrap mb-3">
        <v-chip size="small" variant="tonal" color="primary"   prepend-icon="mdi-package-variant">{{ fcRows.length }} продуктов</v-chip>
        <v-chip size="small" variant="tonal" color="secondary" prepend-icon="mdi-file-document-outline">{{ fmt0(fcGrandTotals.count) }} в очереди</v-chip>
        <v-chip size="small" variant="tonal"                   prepend-icon="mdi-cash">{{ fmtRub(fcGrandTotals.volume) }} объём</v-chip>
      </div>

      <!-- Forecast matrix table -->
      <v-card v-if="!fcLoading" class="ds-card mx-card-wrap" elevation="0">
        <div class="mx-scroll">
          <table class="mx-tbl">
            <thead>
              <tr>
                <th class="th-name" rowspan="2">Продукт / Программа</th>
                <th v-for="mo in fcMonths" :key="mo"
                  :colspan="fcActiveMetrics.length" class="th-mgroup" :class="{ 'th-nodate': mo === fcNullKey }">
                  {{ mo === fcNullKey ? 'Без даты' : fmtMonthHdr(mo) }}
                </th>
                <th :colspan="fcActiveMetrics.length" class="th-mgroup th-total-hd">Итого</th>
              </tr>
              <tr>
                <template v-for="mo in fcMonths" :key="`fsh-${mo}`">
                  <th v-for="(m, mi) in fcActiveMetrics" :key="m.key"
                    class="th-sub" :class="{ 'th-sub-last': mi === fcActiveMetrics.length - 1 }">
                    {{ m.short }}
                  </th>
                </template>
                <th v-for="m in fcActiveMetrics" :key="`ftot-${m.key}`" class="th-sub th-sub-total">
                  {{ m.short }}
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="prod in fcRows" :key="`fp${prod.productId}`">
                <tr class="tr-prod" @click="fcToggle(prod.productId)">
                  <td class="td-name">
                    <div class="cell-row">
                      <v-icon size="14" class="ico-expand">
                        {{ fcExpanded.has(prod.productId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                      </v-icon>
                      <span class="label-prod">{{ prod.productName }}</span>
                      <span class="prog-pill">{{ prod.programs.length }}</span>
                    </div>
                  </td>
                  <template v-for="mo in fcMonths" :key="`fp${prod.productId}-${mo}`">
                    <td v-for="(m, mi) in fcActiveMetrics" :key="m.key"
                      class="td-num" :class="{ 'td-sep': mi === fcActiveMetrics.length - 1, 'fc-nodate': mo === fcNullKey }">
                      <span :class="fmtClass(prod.monthly[mo]?.[m.key])">{{ fmtCell(prod.monthly[mo]?.[m.key], m) }}</span>
                    </td>
                  </template>
                  <td v-for="m in fcActiveMetrics" :key="`fpt-${m.key}`" class="td-num td-total">
                    {{ fmtCell(prod[m.key], m) }}
                  </td>
                </tr>

                <template v-if="fcExpanded.has(prod.productId)">
                  <tr v-for="pg in prod.programs" :key="`fpg${pg.programId}`" class="tr-prog">
                    <td class="td-name">
                      <div class="cell-row cell-l2">
                        <span class="tree-arm"></span>
                        <span class="label-prog">{{ pg.programName }}</span>
                      </div>
                    </td>
                    <template v-for="mo in fcMonths" :key="`fpg${pg.programId}-${mo}`">
                      <td v-for="(m, mi) in fcActiveMetrics" :key="m.key"
                        class="td-num td-dim" :class="{ 'td-sep': mi === fcActiveMetrics.length - 1, 'fc-nodate': mo === fcNullKey }">
                        <span :class="fmtClass(pg.monthly[mo]?.[m.key])">{{ fmtCell(pg.monthly[mo]?.[m.key], m) }}</span>
                      </td>
                    </template>
                    <td v-for="m in fcActiveMetrics" :key="`fpgt-${m.key}`" class="td-num td-total td-dim">
                      {{ fmtCell(pg[m.key], m) }}
                    </td>
                  </tr>
                </template>
              </template>

              <!-- Grand totals -->
              <tr v-if="fcGrandTotals && fcRows.length" class="tr-grand">
                <td class="td-name"><strong>ИТОГО</strong></td>
                <template v-for="mo in fcMonths" :key="`fg-${mo}`">
                  <td v-for="(m, mi) in fcActiveMetrics" :key="m.key"
                    class="td-num" :class="{ 'td-sep': mi === fcActiveMetrics.length - 1, 'fc-nodate': mo === fcNullKey }">
                    <strong>{{ fmtCell(fcGrandTotals.monthly[mo]?.[m.key], m) }}</strong>
                  </td>
                </template>
                <td v-for="m in fcActiveMetrics" :key="`fgt-${m.key}`" class="td-num td-total">
                  <strong>{{ fmtCell(fcGrandTotals[m.key], m) }}</strong>
                </td>
              </tr>

              <tr v-if="!fcRows.length && !fcLoading">
                <td :colspan="1 + fcMonths.length * fcActiveMetrics.length + fcActiveMetrics.length" class="td-empty">
                  <v-icon class="mb-2 d-block mx-auto" size="36" color="grey-lighten-1">mdi-table-off</v-icon>
                  Нет контрактов в очереди (статусы «Сбор документов», «Комплайнс»)
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </v-card>
    </template>

    <!-- "Прогноз" (активированные контракты, /period) и "Факт" (транзакции, /fact)
         используют одну таблицу: период + метрики, различается только endpoint -->
    <template v-if="reportType === 'sales' && (reportMode === 'forecast' || reportMode === 'fact' || reportMode === 'inwork')">
      <!-- Filter bar -->
      <v-card class="ds-card mb-3" elevation="0">
        <v-card-text class="pa-2">
          <div class="d-flex ga-1 flex-wrap align-center">

            <!-- Period mode selector -->
            <v-btn-toggle v-model="periodMode" mandatory density="compact" variant="outlined" color="primary"
              @update:model-value="onPeriodModeChange">
              <v-btn value="year"    size="x-small">Год</v-btn>
              <v-btn value="quarter" size="x-small">Квартал</v-btn>
              <v-btn value="month"   size="x-small">Месяц</v-btn>
              <v-btn value="range"   size="x-small">Диапазон</v-btn>
            </v-btn-toggle>

            <!-- Year (all modes except range) -->
            <v-select v-if="periodMode !== 'range'" v-model="periodYear" :items="yearOptions"
              density="compact" variant="outlined" hide-details style="width:92px; flex:0 0 92px"
              @update:model-value="reload" />

            <!-- Q1–Q4 -->
            <v-btn-toggle v-if="periodMode === 'quarter'" v-model="periodQuarter" mandatory
              density="compact" variant="outlined" @update:model-value="reload">
              <v-btn v-for="q in ['Q1','Q2','Q3','Q4']" :key="q" :value="q" size="x-small">{{ q }}</v-btn>
            </v-btn-toggle>

            <!-- Single month -->
            <v-select v-if="periodMode === 'month'" v-model="periodMonth" :items="monthOpts"
              item-title="t" item-value="v" density="compact" variant="outlined"
              hide-details style="width:128px; flex:0 0 128px" @update:model-value="reload" />

            <!-- Custom range: year+month selects (no native type=month — browser locale) -->
            <template v-if="periodMode === 'range'">
              <v-select v-model="rangeFromYear" :items="yearOptions" density="compact" variant="outlined"
                hide-details style="width:86px;flex:0 0 86px" @update:model-value="reload" />
              <v-select v-model="rangeFromMonth" :items="monthOpts" item-title="t" item-value="v"
                density="compact" variant="outlined" hide-details style="width:120px;flex:0 0 120px"
                @update:model-value="reload" />
              <span class="text-medium-emphasis" style="flex-shrink:0">—</span>
              <v-select v-model="rangeToYear" :items="yearOptions" density="compact" variant="outlined"
                hide-details style="width:86px;flex:0 0 86px" @update:model-value="reload" />
              <v-select v-model="rangeToMonth" :items="monthOpts" item-title="t" item-value="v"
                density="compact" variant="outlined" hide-details style="width:120px;flex:0 0 120px"
                @update:model-value="reload" />
            </template>

            <!-- Прогноз активации (только «В работе») — как в Менеджере контрактов -->
            <template v-if="reportMode === 'inwork'">
              <v-divider vertical class="mx-1" style="height:24px;align-self:center" />
              <SmartRangeFilter label="Прогноз активации" kind="date"
                v-model:from="faFrom" v-model:to="faTo"
                @update:from="reload" @update:to="reload" />
            </template>

            <v-divider vertical class="mx-1" style="height:24px;align-self:center" />

            <!-- Supplier filter -->
            <v-autocomplete v-model="filterSuppliers" :items="supplierOptions"
              placeholder="Поставщик" prepend-inner-icon="mdi-domain"
              multiple chips closable-chips density="compact" variant="outlined"
              hide-details style="width:190px; flex:0 0 190px"
              @update:model-value="onSupplierFilterChange" />

            <!-- Product filter: debounce чтобы не закрывать дропдаун при множественном выборе -->
            <v-autocomplete v-model="filterProducts" :items="productOptions"
              item-title="name" item-value="id" placeholder="Продукт"
              prepend-inner-icon="mdi-magnify"
              multiple chips closable-chips density="compact" variant="outlined"
              hide-details style="width:220px; flex:0 0 220px"
              @update:model-value="onProductFilterChange" />

            <v-btn v-if="filterProducts.length || filterSuppliers.length"
              icon="mdi-filter-remove" size="x-small" variant="text" title="Сбросить" @click="resetFilters" />

            <v-spacer />

            <v-btn size="x-small" variant="text" prepend-icon="mdi-expand-all-outline" @click="expandAll">Все</v-btn>
            <v-btn size="x-small" variant="text" prepend-icon="mdi-collapse-all-outline" @click="collapseAll">Свернуть</v-btn>

            <!-- Metrics selector -->
            <v-menu :close-on-content-click="false" location="bottom end">
              <template #activator="{ props }">
                <v-btn v-bind="props" size="x-small" variant="tonal" color="primary"
                  prepend-icon="mdi-tune">
                  Метрики · {{ activeMetrics.length }}
                </v-btn>
              </template>
              <v-card min-width="210" elevation="4">
                <v-card-title class="text-body-2 pa-3 pb-1 font-weight-medium">Метрики</v-card-title>
                <v-divider />
                <v-list density="compact" class="pa-1">
                  <v-list-item v-for="m in availableMetrics" :key="m.key" :title="m.label"
                    rounded="lg" style="cursor:pointer" @click="toggleMetric(m.key)">
                    <template #prepend>
                      <v-checkbox-btn :model-value="selectedMetricKeys.includes(m.key)"
                        color="primary" density="compact"
                        @click.stop="toggleMetric(m.key)" />
                    </template>
                  </v-list-item>
                </v-list>
              </v-card>
            </v-menu>
          </div>
        </v-card-text>
      </v-card>

      <v-progress-linear v-if="loading" indeterminate color="primary" rounded class="mb-3" />

      <!-- Summary chips -->
      <div v-if="grandTotals && !loading" class="d-flex ga-2 flex-wrap mb-3">
        <v-chip size="small" variant="tonal" color="primary"   prepend-icon="mdi-package-variant">{{ rows.length }} продуктов</v-chip>
        <v-chip size="small" variant="tonal" color="secondary" prepend-icon="mdi-file-document-outline">{{ fmt0(grandTotals.count) }} контрактов</v-chip>
        <v-chip size="small" variant="tonal"                   prepend-icon="mdi-cash">{{ fmtRub(grandTotals.volume) }} объём</v-chip>
        <v-chip size="small" variant="tonal" color="success"   prepend-icon="mdi-trending-up">{{ fmtRub(grandTotals.revenue) }} выручка</v-chip>
        <v-chip size="small" variant="tonal" color="info"      prepend-icon="mdi-account-group-outline">{{ fmt0(grandTotals.fcCount) }} ФК</v-chip>
      </div>

      <!-- Matrix table -->
      <v-card v-if="!loading" class="ds-card mx-card-wrap" elevation="0">
        <div class="mx-scroll">
          <table class="mx-tbl">
            <thead>
              <!-- Row 1: month groups -->
              <tr>
                <th class="th-name" rowspan="2">Продукт / Программа</th>
                <th v-for="mo in months" :key="mo"
                  :colspan="activeMetrics.length" class="th-mgroup">
                  {{ fmtMonthHdr(mo) }}
                </th>
                <th :colspan="activeMetrics.length" class="th-mgroup th-total-hd">
                  Итого {{ periodLabel }}
                </th>
              </tr>
              <!-- Row 2: metric sub-labels -->
              <tr>
                <template v-for="mo in months" :key="`sh-${mo}`">
                  <th v-for="(m, mi) in activeMetrics" :key="m.key"
                    class="th-sub" :class="{ 'th-sub-last': mi === activeMetrics.length - 1 }">
                    {{ m.short }}
                  </th>
                </template>
                <th v-for="m in activeMetrics" :key="`tot-${m.key}`" class="th-sub th-sub-total">
                  {{ m.short }}
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="prod in rows" :key="prod.productId">
                <!-- Product -->
                <tr class="tr-prod" @click="toggleProduct(prod.productId)">
                  <td class="td-name">
                    <div class="cell-row">
                      <v-icon size="14" class="ico-expand">
                        {{ expandedProducts.has(prod.productId) ? 'mdi-chevron-down' : 'mdi-chevron-right' }}
                      </v-icon>
                      <span class="label-prod">{{ prod.productName }}</span>
                      <span class="prog-pill">{{ prod.programs.length }}</span>
                    </div>
                  </td>
                  <template v-for="mo in months" :key="`p${prod.productId}-${mo}`">
                    <td v-for="(m, mi) in activeMetrics" :key="m.key"
                      class="td-num" :class="{ 'td-sep': mi === activeMetrics.length - 1, 'td-fc': cellTitle(prod.monthly[mo], m.key) }"
                      :title="cellTitle(prod.monthly[mo], m.key)">
                      <span :class="fmtClass(prod.monthly[mo]?.[m.key])">
                        {{ fmtCell(prod.monthly[mo]?.[m.key], m) }}
                      </span>
                    </td>
                  </template>
                  <td v-for="m in activeMetrics" :key="`pt-${m.key}`" class="td-num td-total">
                    {{ fmtCell(prod[m.key], m) }}
                  </td>
                </tr>

                <!-- Programs -->
                <template v-if="expandedProducts.has(prod.productId)">
                  <tr v-for="pg in prod.programs" :key="pg.programId" class="tr-prog">
                    <td class="td-name">
                      <div class="cell-row cell-l2">
                        <span class="tree-arm"></span>
                        <span class="label-prog">{{ pg.programName }}</span>
                      </div>
                    </td>
                    <template v-for="mo in months" :key="`pg${pg.programId}-${mo}`">
                      <td v-for="(m, mi) in activeMetrics" :key="m.key"
                        class="td-num td-dim" :class="{ 'td-sep': mi === activeMetrics.length - 1, 'td-fc': cellTitle(pg.monthly[mo], m.key) }"
                        :title="cellTitle(pg.monthly[mo], m.key)">
                        <span :class="fmtClass(pg.monthly[mo]?.[m.key])">
                          {{ fmtCell(pg.monthly[mo]?.[m.key], m) }}
                        </span>
                      </td>
                    </template>
                    <td v-for="m in activeMetrics" :key="`pgt-${m.key}`" class="td-num td-total td-dim">
                      {{ fmtCell(pg[m.key], m) }}
                    </td>
                  </tr>
                </template>
              </template>

              <!-- Grand totals -->
              <tr v-if="grandTotals && rows.length" class="tr-grand">
                <td class="td-name"><strong>ИТОГО</strong></td>
                <template v-for="mo in months" :key="`g-${mo}`">
                  <td v-for="(m, mi) in activeMetrics" :key="m.key"
                    class="td-num" :class="{ 'td-sep': mi === activeMetrics.length - 1, 'td-fc': cellTitle(grandTotals.monthly[mo], m.key) }"
                    :title="cellTitle(grandTotals.monthly[mo], m.key)">
                    <strong>{{ fmtCell(grandTotals.monthly[mo]?.[m.key], m) }}</strong>
                  </td>
                </template>
                <td v-for="m in activeMetrics" :key="`gt-${m.key}`" class="td-num td-total">
                  <strong>{{ fmtCell(grandTotals[m.key], m) }}</strong>
                </td>
              </tr>

              <tr v-if="!rows.length && !loading">
                <td :colspan="1 + months.length * activeMetrics.length + activeMetrics.length" class="td-empty">
                  <v-icon class="mb-2 d-block mx-auto" size="36" color="grey-lighten-1">mdi-table-off</v-icon>
                  Нет данных за {{ periodLabel }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </v-card>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import SmartRangeFilter from '../../components/SmartRangeFilter.vue';

// ─── Report mode ──────────────────────────────────────────────
// inwork  — контракты в работе (pipeline, /forecast)
// forecast — прогноз доходов: активированные контракты (/period)
// fact    — финансовый факт: транзакции и пополнения (/fact)
const reportMode = ref('forecast');

// ─── Report type (Part 3, Lena) ───────────────────────────────
// 'sales'   — Продажи (текущая матрица: суммы контрактов)
// 'revenue' — Начисление выручки (логика будет описана отдельно → заглушка)
// Разрезы reportMode: inwork=в работе, forecast=активировано, fact=факт,
// total=итого (итого/начисление-выручка пока заглушки — «оставить пустыми»).
const reportType = ref('sales');
const isStub = computed(() => reportType.value === 'revenue' || reportMode.value === 'total');

// ─── Period ───────────────────────────────────────────────────
const now = new Date();
const currentQ = `Q${Math.ceil((now.getMonth() + 1) / 3)}`;

const periodMode    = ref('quarter');
const periodYear    = ref(now.getFullYear());
const periodQuarter = ref(currentQ);
const periodMonth   = ref(String(now.getMonth() + 1).padStart(2, '0'));

// Диапазон — раздельные year/month чтобы избежать нативного type=month (локаль браузера)
const rangeFromYear  = ref(now.getFullYear());
const rangeFromMonth = ref('01');
const rangeToYear    = ref(now.getFullYear());
const rangeToMonth   = ref(String(now.getMonth() + 1).padStart(2, '0'));
const rangeFrom = computed(() => `${rangeFromYear.value}-${rangeFromMonth.value}`);
const rangeTo   = computed(() => `${rangeToYear.value}-${rangeToMonth.value}`);

const yearOptions   = Array.from({ length: 7 }, (_, i) => now.getFullYear() - i);
const quarterRanges = { Q1: [1,3], Q2: [4,6], Q3: [7,9], Q4: [10,12] };
const monthOpts = [
  { t: 'Январь', v: '01' }, { t: 'Февраль', v: '02' }, { t: 'Март', v: '03' },
  { t: 'Апрель', v: '04' }, { t: 'Май',     v: '05' }, { t: 'Июнь', v: '06' },
  { t: 'Июль',   v: '07' }, { t: 'Август',  v: '08' }, { t: 'Сентябрь', v: '09' },
  { t: 'Октябрь',v: '10' }, { t: 'Ноябрь', v: '11' }, { t: 'Декабрь',  v: '12' },
];

const periodFrom = computed(() => {
  const y = periodYear.value;
  if (periodMode.value === 'year')    return `${y}-01`;
  if (periodMode.value === 'quarter') return `${y}-${String(quarterRanges[periodQuarter.value][0]).padStart(2,'0')}`;
  if (periodMode.value === 'month')   return `${y}-${periodMonth.value}`;
  return rangeFrom.value;
});
const periodTo = computed(() => {
  const y = periodYear.value;
  if (periodMode.value === 'year')    return `${y}-12`;
  if (periodMode.value === 'quarter') return `${y}-${String(quarterRanges[periodQuarter.value][1]).padStart(2,'0')}`;
  if (periodMode.value === 'month')   return `${y}-${periodMonth.value}`;
  return rangeTo.value;
});
const periodLabel = computed(() => {
  if (periodMode.value === 'year')    return String(periodYear.value);
  if (periodMode.value === 'quarter') return `${periodQuarter.value} ${periodYear.value}`;
  if (periodMode.value === 'month')   return `${monthOpts.find(m => m.v === periodMonth.value)?.t} ${periodYear.value}`;
  const fl = monthOpts.find(m => m.v === rangeFromMonth.value)?.t;
  const tl = monthOpts.find(m => m.v === rangeToMonth.value)?.t;
  return `${fl} ${rangeFromYear.value} — ${tl} ${rangeToYear.value}`;
});

// ─── Metrics (persist to localStorage) ───────────────────────
const METRICS_KEY = 'salesMatrix:metrics';
const allMetrics = [
  { key: 'volume',      short: 'Объём',     label: 'Объём (₽)',        fmt: 'rub' },
  { key: 'count',       short: 'Кол-во',    label: 'Кол-во (шт)',      fmt: 'int' },
  { key: 'avgCheck',    short: 'Ср.чек',    label: 'Средний чек (₽)',  fmt: 'rub' },
  { key: 'revenue',     short: 'Выручка',   label: 'Выручка (₽)',      fmt: 'rub' },
  { key: 'points',      short: 'Баллы',     label: 'Баллы',            fmt: 'num' },
  { key: 'fcCount',     short: 'Кол-во ФК', label: 'Кол-во ФК',       fmt: 'int' },
  { key: 'clientCount', short: 'Клиенты',   label: 'Кол-во клиентов', fmt: 'int' },
];
const validKeys = allMetrics.map(m => m.key);
const _saved = (() => { try { const s = JSON.parse(localStorage.getItem(METRICS_KEY)); return Array.isArray(s) && s.every(k => validKeys.includes(k)) && s.length ? s : null; } catch { return null; } })();
const selectedMetricKeys = ref(_saved ?? ['volume', 'revenue']);
// В «В работе» контракты не активированы → транзакций нет. Деньги = сумма
// контракта (метрика «Объём»), Баллы считаются из контракта на бэкенде
// (computePoints). Скрываем только «Выручку» — она приходит из транзакций.
const availableMetrics = computed(() => allMetrics.filter(
  m => !(reportMode.value === 'inwork' && m.key === 'revenue')
));
const activeMetrics = computed(() => {
  const sel = availableMetrics.value.filter(m => selectedMetricKeys.value.includes(m.key));
  return sel.length ? sel : availableMetrics.value.filter(m => m.key === 'volume');
});

function toggleMetric(key) {
  const idx = selectedMetricKeys.value.indexOf(key);
  if (idx !== -1) { if (selectedMetricKeys.value.length > 1) selectedMetricKeys.value.splice(idx, 1); }
  else selectedMetricKeys.value.push(key);
  localStorage.setItem(METRICS_KEY, JSON.stringify(selectedMetricKeys.value));
}

// ─── Filters persistence ──────────────────────────────────────
const SUPPLIERS_KEY = 'salesMatrix:suppliers';
const PRODUCTS_KEY  = 'salesMatrix:products';
function _loadSaved(key, validate) {
  try { const v = JSON.parse(localStorage.getItem(key)); return validate(v) ? v : null; }
  catch { return null; }
}

// ─── Data ─────────────────────────────────────────────────────
const loading          = ref(false);
const rows             = ref([]);
const grandTotals      = ref(null);
const months           = ref([]);
const supplierOptions  = ref([]);
const filterSuppliers  = ref(
  _loadSaved(SUPPLIERS_KEY, v => Array.isArray(v) && v.every(s => typeof s === 'string')) ?? []
);
const productOptions   = ref([]);
const filterProducts   = ref(
  _loadSaved(PRODUCTS_KEY, v => Array.isArray(v) && v.every(n => Number.isInteger(n))) ?? []
);
const expandedProducts = ref(new Set());

// Доп. фильтр «прогноз активации» — как в Менеджере контрактов (SmartRangeFilter,
// даты Y-m-d). faFrom/faTo уходят на бэкенд как fcFrom/fcTo.
const faFrom = ref('');
const faTo   = ref('');

function toggleProduct(pid) {
  const s = new Set(expandedProducts.value);
  if (s.has(pid)) s.delete(pid); else s.add(pid);
  expandedProducts.value = s;
}
function expandAll()   { expandedProducts.value = new Set(rows.value.map(r => r.productId)); }
function collapseAll() { expandedProducts.value = new Set(); }
async function resetFilters() {
  filterProducts.value = [];
  filterSuppliers.value = [];
  localStorage.removeItem(SUPPLIERS_KEY);
  localStorage.removeItem(PRODUCTS_KEY);
  await nextTick();
  loadData({ updateOptions: true });
}
function reload() { loadData(); }
function onPeriodModeChange() { reload(); }

watch([reportMode, reportType], () => {
  // Заглушки (Начисление выручки / Итого) данные не грузят — логика будет
  // описана отдельно, пока показываем пустой плейсхолдер.
  if (isStub.value) return;
  // inwork (по дате создания) / forecast (активированные) / fact (транзакции) —
  // все через loadData, различается endpoint.
  loadData();
});

// Debounce для фильтра продуктов — не закрываем дропдаун при множественном выборе
let _productTimer = null;
function onProductFilterChange() {
  localStorage.setItem(PRODUCTS_KEY, JSON.stringify(filterProducts.value));
  clearTimeout(_productTimer);
  _productTimer = setTimeout(() => loadData({ updateOptions: false }), 350);
}

function onSupplierFilterChange() {
  localStorage.setItem(SUPPLIERS_KEY, JSON.stringify(filterSuppliers.value));
  // updateOptions:false — иначе список поставщиков схлопывается до выбранного
  // и второе значение уже не выбрать (опции строятся один раз при загрузке).
  loadData({ updateOptions: false });
}

async function loadData({ updateOptions = true } = {}) {
  loading.value = true;
  try {
    const p = new URLSearchParams();
    p.set('from', periodFrom.value);
    p.set('to',   periodTo.value);
    filterSuppliers.value.forEach(s => p.append('suppliers[]', s));
    filterProducts.value.forEach(id => p.append('products[]', id));
    // Доп. фильтр по прогнозу активации — только для «В работе» (границы независимы).
    if (reportMode.value === 'inwork') {
      if (faFrom.value) p.set('fcFrom', faFrom.value);
      if (faTo.value) p.set('fcTo', faTo.value);
    }
    const endpoint = reportMode.value === 'inwork' ? 'inwork'
      : (reportMode.value === 'fact' ? 'fact' : 'period');
    const { data } = await api.get(`/admin/reports/sales-matrix/${endpoint}?${p}`);
    rows.value        = data.rows           ?? [];
    months.value      = data.period?.months ?? [];
    grandTotals.value = data.grandTotals    ?? null;
    if (updateOptions) {
      supplierOptions.value = data.suppliers ?? [];
      productOptions.value  = data.products  ?? [];
    }
  } catch (e) { console.error('matrix load failed', e); }
  loading.value = false;
}

// ─── Forecast state ───────────────────────────────────────────
const fcLoading         = ref(false);
const fcRows            = ref([]);
const fcGrandTotals     = ref(null);
const fcMonths          = ref([]);
const fcNullKey         = ref('__no_date__');
const fcNoDateCount     = ref(0);
const fcSupplierOptions = ref([]);
const fcProductOptions  = ref([]);
const fcFilterSuppliers = ref([]);
const fcFilterProducts  = ref([]);
const fcExpanded        = ref(new Set());

// Forecast metrics (доступны только не-транзакционные: нет выручки/баллов)
const FC_METRICS_KEY = 'salesMatrix:fcMetrics';
const fcAllMetrics = [
  { key: 'volume',      short: 'Объём',     label: 'Объём (₽)',       fmt: 'rub' },
  { key: 'count',       short: 'Кол-во',    label: 'Кол-во (шт)',     fmt: 'int' },
  { key: 'clientCount', short: 'Клиенты',   label: 'Кол-во клиентов', fmt: 'int' },
  { key: 'avgCheck',    short: 'Ср.чек',    label: 'Средний чек (₽)', fmt: 'rub' },
  { key: 'fcCount',     short: 'Кол-во ФК', label: 'Кол-во ФК',       fmt: 'int' },
];
const fcValidKeys = fcAllMetrics.map(m => m.key);
const _fcSaved = (() => { try { const s = JSON.parse(localStorage.getItem(FC_METRICS_KEY)); return Array.isArray(s) && s.every(k => fcValidKeys.includes(k)) && s.length ? s : null; } catch { return null; } })();
const fcSelectedMetricKeys = ref(_fcSaved ?? ['volume', 'count', 'clientCount']);
const fcActiveMetrics = computed(() => fcAllMetrics.filter(m => fcSelectedMetricKeys.value.includes(m.key)));
function fcToggleMetric(key) {
  const idx = fcSelectedMetricKeys.value.indexOf(key);
  if (idx !== -1) { if (fcSelectedMetricKeys.value.length > 1) fcSelectedMetricKeys.value.splice(idx, 1); }
  else fcSelectedMetricKeys.value.push(key);
  localStorage.setItem(FC_METRICS_KEY, JSON.stringify(fcSelectedMetricKeys.value));
}

// Forecast period (по activation_forecast; 'all' = без ограничения; включает будущие годы)
const fcPeriodMode    = ref('all');
const fcPeriodYear    = ref(now.getFullYear());
const fcPeriodQuarter = ref(currentQ);
const fcPeriodMonth   = ref(String(now.getMonth() + 1).padStart(2, '0'));
const fcRangeFromYear  = ref(now.getFullYear());
const fcRangeFromMonth = ref('01');
const fcRangeToYear    = ref(now.getFullYear());
const fcRangeToMonth   = ref('12');
const fcYearOptions = Array.from({ length: 9 }, (_, i) => now.getFullYear() + 2 - i); // year+2 … year-6
const fcPeriodFrom = computed(() => {
  const y = fcPeriodYear.value;
  if (fcPeriodMode.value === 'year')    return `${y}-01`;
  if (fcPeriodMode.value === 'quarter') return `${y}-${String(quarterRanges[fcPeriodQuarter.value][0]).padStart(2,'0')}`;
  if (fcPeriodMode.value === 'month')   return `${y}-${fcPeriodMonth.value}`;
  if (fcPeriodMode.value === 'range')   return `${fcRangeFromYear.value}-${fcRangeFromMonth.value}`;
  return null;
});
const fcPeriodTo = computed(() => {
  const y = fcPeriodYear.value;
  if (fcPeriodMode.value === 'year')    return `${y}-12`;
  if (fcPeriodMode.value === 'quarter') return `${y}-${String(quarterRanges[fcPeriodQuarter.value][1]).padStart(2,'0')}`;
  if (fcPeriodMode.value === 'month')   return `${y}-${fcPeriodMonth.value}`;
  if (fcPeriodMode.value === 'range')   return `${fcRangeToYear.value}-${fcRangeToMonth.value}`;
  return null;
});

// Forecast status filter (2 = Сбор документов, 3 = Комплайнс)
const fcStatuses = ref([2, 3]);
function onFcStatusChange() {
  // не допускаем пустой выбор — иначе данных не будет
  if (!fcStatuses.value.length) { fcStatuses.value = [2, 3]; return; }
  loadForecast();
}

function fcToggle(pid) {
  const s = new Set(fcExpanded.value);
  if (s.has(pid)) s.delete(pid); else s.add(pid);
  fcExpanded.value = s;
}
function fcExpandAll()   { fcExpanded.value = new Set(fcRows.value.map(r => r.productId)); }
function fcCollapseAll() { fcExpanded.value = new Set(); }
async function resetFcFilters() {
  fcFilterProducts.value  = [];
  fcFilterSuppliers.value = [];
  await nextTick();
  loadForecast();
}

let _fcProductTimer = null;
function onFcProductFilter() {
  clearTimeout(_fcProductTimer);
  // updateOptions:false — сохраняем полный список вариантов при мультивыборе.
  _fcProductTimer = setTimeout(() => loadForecast({ updateOptions: false }), 350);
}
function onFcSupplierFilter() {
  loadForecast({ updateOptions: false });
}

async function loadForecast({ updateOptions = true } = {}) {
  fcLoading.value = true;
  try {
    const p = new URLSearchParams();
    fcFilterSuppliers.value.forEach(s => p.append('suppliers[]', s));
    fcFilterProducts.value.forEach(id => p.append('products[]', id));
    fcStatuses.value.forEach(s => p.append('statuses[]', s));
    if (fcPeriodFrom.value && fcPeriodTo.value) {
      p.set('from', fcPeriodFrom.value);
      p.set('to',   fcPeriodTo.value);
    }
    const { data } = await api.get(`/admin/reports/sales-matrix/forecast?${p}`);
    fcRows.value        = data.rows        ?? [];
    fcGrandTotals.value = data.grandTotals ?? null;
    fcMonths.value      = data.months      ?? [];
    fcNullKey.value     = data.nullKey     ?? '__no_date__';
    fcNoDateCount.value = data.noDateCount ?? 0;
    if (updateOptions) {
      fcSupplierOptions.value = data.suppliers ?? [];
      fcProductOptions.value  = data.products  ?? [];
    }
  } catch (e) { console.error('forecast load failed', e); }
  fcLoading.value = false;
}

// ─── Formatting ───────────────────────────────────────────────
const MONTHS_SHORT = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
function fmtMonthHdr(dm) {
  const [y, m] = (dm || '').split('-');
  const mn = MONTHS_SHORT[parseInt(m, 10) - 1] ?? dm;
  return `${mn} '${String(y).slice(2)}`;
}

function fmt0(val) { return Number(val || 0).toLocaleString('ru-RU'); }

function fmtRub(val) {
  const n = Number(val || 0);
  if (n >= 1e6) return (n/1e6).toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' М ₽';
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
}

function fmtCell(val, m) {
  if (val == null) return '—';
  const n = Number(val);
  if (isNaN(n) || n === 0) return '—';
  if (m.fmt === 'int') return n.toLocaleString('ru-RU');
  if (m.fmt === 'rub') {
    if (n >= 1e6) return (n/1e6).toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' М ₽';
    return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 }) + ' ₽';
  }
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

function fmtClass(val) {
  const n = Number(val ?? 0);
  return (!val || isNaN(n) || n === 0) ? 'val-empty' : '';
}

// Тултип ячейки «В работе»: разбивка по прогнозу активации (сколько и когда).
function cellTitle(cell, metricKey) {
  if (reportMode.value !== 'inwork') return undefined;
  if (metricKey !== 'volume' && metricKey !== 'count') return undefined;
  const fc = cell?.forecast;
  if (!fc || !fc.length) return undefined;
  const lines = fc.map((f) => {
    const when = f.month ? fmtMonthHdr(f.month) : 'Без прогноза';
    return `${when}: ${fmt0(f.count)} шт · ${fmtRub(f.volume)}`;
  });
  return 'Прогноз активации\n' + lines.join('\n');
}

onMounted(loadData);
</script>

<style scoped>
/* ─── Scroll wrapper: handles both axes; sticky thead works within this context ─── */
.mx-scroll {
  overflow: auto;
  max-height: calc(100vh - 240px);
}

/* ─── Table base ─── */
.mx-tbl {
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
  font-size: 13px;
}
.mx-tbl th,
.mx-tbl td {
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.07);
}

/* ─── HEADER ─── */

/* Sticky header rows */
.mx-tbl thead th {
  position: sticky;
  top: 0;
  z-index: 2;
}

/* Name column header (spans 2 rows) */
.th-name {
  position: sticky;
  left: 0;
  z-index: 3;
  text-align: left;
  padding: 10px 14px;
  min-width: 230px;
  max-width: 300px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: rgba(var(--v-theme-on-surface), 0.45);
  background: rgba(var(--v-theme-surface-variant), 0.9);
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
  vertical-align: middle;
}

/* Month group header (level 1) */
.th-mgroup {
  text-align: center;
  padding: 7px 8px 5px;
  font-size: 11px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.75);
  background: rgba(var(--v-theme-surface-variant), 0.9);
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06) !important;
}

/* Total column group header */
.th-total-hd {
  background: rgba(var(--v-theme-primary), 0.07) !important;
  color: rgb(var(--v-theme-primary)) !important;
  border-left: 2px solid rgba(var(--v-theme-primary), 0.2);
}

/* Metric sub-header (level 2) */
.th-sub {
  text-align: right;
  padding: 4px 8px 6px;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(var(--v-theme-on-surface), 0.45);
  background: rgba(var(--v-theme-surface-variant), 0.9);
  min-width: 72px;
  border-bottom: 2px solid rgba(var(--v-theme-on-surface), 0.12) !important;
}
.th-sub-last { border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.th-sub-total {
  background: rgba(var(--v-theme-primary), 0.06) !important;
  color: rgb(var(--v-theme-primary)) !important;
  border-left: 2px solid rgba(var(--v-theme-primary), 0.18);
}

/* ─── ROWS ─── */

/* Sticky name column (body) */
.td-name {
  position: sticky;
  left: 0;
  z-index: 1;
  background: rgb(var(--v-theme-surface));
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  min-width: 230px;
  max-width: 300px;
  padding: 0;
}

.cell-row {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
  overflow: hidden;
}
.cell-l2 { padding-left: 14px; }

/* Product row */
.tr-prod { cursor: pointer; }
/* td-name остаётся непрозрачным — иначе скроллированный контент под sticky-колонкой просвечивает */
.tr-prod:hover .td-name    { background: rgb(var(--v-theme-surface)); }
.tr-prod:hover td          { background: rgba(var(--v-theme-primary), 0.03); }
.tr-prod td                { background: rgb(var(--v-theme-surface)); }

.ico-expand { flex-shrink: 0; opacity: 0.55; }
.label-prod {
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1 1 0;
  min-width: 0;
}
.prog-pill {
  font-size: 10px; font-weight: 700;
  padding: 1px 5px; border-radius: 8px;
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
  flex-shrink: 0;
}

/* Program row */
.tr-prog td                { background: rgba(var(--v-theme-surface), 1); }
.tr-prog:hover .td-name   { background: rgba(var(--v-theme-on-surface), 0.015); }
.tr-prog:hover td          { background: rgba(var(--v-theme-on-surface), 0.015); }
.tr-prog .td-name          { background: rgba(var(--v-theme-surface), 1); }

.label-prog {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  font-weight: 400;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1 1 0;
  min-width: 0;
}
.tree-arm {
  display: inline-block;
  width: 13px; height: 15px;
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  margin-right: 5px;
  flex-shrink: 0;
  position: relative;
  top: -3px;
}

/* Numeric cells */
.td-num {
  text-align: right;
  padding: 6px 8px;
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  min-width: 72px;
  color: rgb(var(--v-theme-on-surface));
}
/* Month right separator */
.td-sep { border-right: 1px solid rgba(var(--v-theme-on-surface), 0.07); }

/* Total column */
.td-total {
  background: rgba(var(--v-theme-primary), 0.05);
  border-left: 2px solid rgba(var(--v-theme-primary), 0.15);
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

/* Dim (program level) */
.td-dim { color: rgba(var(--v-theme-on-surface), 0.6); font-weight: 400; }
.td-dim.td-total { color: rgba(var(--v-theme-primary), 0.65); font-weight: 500; }

/* Empty/zero value style */
.val-empty { color: rgba(var(--v-theme-on-surface), 0.22); }

/* Forecast: «без даты» колонка */
.th-nodate {
  background: rgba(var(--v-theme-warning), 0.08) !important;
  color: rgb(var(--v-theme-warning)) !important;
}
.fc-nodate { background: rgba(var(--v-theme-warning), 0.04); }

/* Grand totals row */
.tr-grand td {
  background: rgba(var(--v-theme-surface-variant), 0.5) !important;
  border-top: 2px solid rgba(var(--v-theme-on-surface), 0.12);
  font-size: 13px;
  padding: 8px 10px;
}
/* Непрозрачный фон у липкого «ИТОГО» (0.5-оттенок над сплошным surface),
   иначе при скролле вправо цифры просвечивают сквозь колонку. */
.tr-grand .td-name {
  background:
    linear-gradient(rgba(var(--v-theme-surface-variant), 0.5), rgba(var(--v-theme-surface-variant), 0.5)),
    rgb(var(--v-theme-surface)) !important;
}
.tr-grand .td-total { background: rgba(var(--v-theme-primary), 0.1) !important; }

/* Empty state */
.td-empty {
  text-align: center;
  padding: 48px 20px;
  color: rgba(var(--v-theme-on-surface), 0.35);
  font-size: 14px;
}
/* Ячейка с разбивкой по прогнозу активации — подсказка о наведении. */
.td-fc { cursor: help; }
.td-fc span { border-bottom: 1px dotted rgba(var(--v-theme-primary), 0.5); }
</style>