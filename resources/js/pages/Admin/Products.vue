<template>
  <div>
    <PageHeader title="Продукты и программы" icon="mdi-package-variant-closed">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-eye" to="/manage/products-preview">
          Просмотр как партнёр
        </v-btn>
        <v-btn v-if="canEdit('products')" color="primary" prepend-icon="mdi-plus" @click="openCreateProduct">Добавить продукт</v-btn>
      </template>
    </PageHeader>

    <FilterBar
      :search="filters.search"
      search-placeholder="Поиск по названию"
      :search-cols="4"
      :show-reset="activeFilterCount > 0"
      @update:search="v => { filters.search = v ?? ''; debouncedLoad(); }"
      @reset="resetProductFilters"
    >
      <v-col cols="12" md="3">
        <v-select v-model="filters.active" label="Статус" :items="activeOptions"
          variant="outlined" density="comfortable"
          clearable hide-details @update:model-value="loadProducts" />
      </v-col>
      <v-col v-if="activeFilterCount > 0" cols="auto" class="d-flex align-center">
        <v-chip size="small" color="info" variant="tonal">
          {{ activeFilterCount }} {{ activeFilterCount === 1 ? 'фильтр' : 'фильтра' }}
        </v-chip>
      </v-col>
      <v-col cols="auto" class="d-flex align-center ms-auto">
        <ColumnVisibilityMenu :headers="headers" v-model:visible="columnVisible" storage-key="products-cols" />
      </v-col>
    </FilterBar>

    <!-- Products Table -->
    <v-card>
      <v-data-table-server
        :headers="visibleHeaders"
        :items="products"
        :items-length="total"
        :loading="loading"
        :items-per-page="perPage"
        :items-per-page-options="[25, 50, 100, 200]"
        v-model:expanded="expanded"
        item-value="id"
        show-expand
        @update:page="page = $event; loadProducts()"
        @update:items-per-page="v => { perPage = v; page = 1; loadProducts(); }"
        @update:sort-by="v => { sortBy = v; page = 1; loadProducts(); }"
        @update:expanded="onExpandedChange"
      >
        <template #item.name="{ item }">
          {{ item.name }}
          <v-chip v-if="item.isPrimary === false" size="x-small" color="warning"
            variant="tonal" class="ms-2">Доп.</v-chip>
        </template>
        <template #item.active="{ item }">
          <StatusChip
            :color="item.active ? 'success' : 'grey'"
            :text="item.active ? 'Активен' : 'Неактивен'"
            size="x-small"
          />
        </template>
        <template #item.visibleToResident="{ item }">
          <v-icon :color="item.visibleToResident ? 'success' : 'grey'" size="small">
            {{ item.visibleToResident ? 'mdi-eye' : 'mdi-eye-off' }}
          </v-icon>
        </template>
        <template #item.visibleToCalculator="{ item }">
          <v-icon :color="item.visibleToCalculator ? 'success' : 'grey'" size="small">
            {{ item.visibleToCalculator ? 'mdi-calculator' : 'mdi-calculator-variant' }}
          </v-icon>
        </template>
        <template #item.publishStatus="{ item }">
          <v-chip size="x-small"
            :color="item.publishStatus === 'published' ? 'success' : 'warning'"
            variant="tonal">
            {{ item.publishStatus === 'published' ? 'Опубликован' : 'Черновик' }}
          </v-chip>
        </template>
        <template #item.actions="{ item }">
          <v-btn v-if="canFull('products')"
            :icon="item.publishStatus === 'published' ? 'mdi-arrow-down-bold-circle' : 'mdi-rocket-launch'"
            size="x-small" variant="text"
            :color="item.publishStatus === 'published' ? 'grey' : 'success'"
            :title="item.publishStatus === 'published' ? 'Снять с публикации' : 'Опубликовать'"
            :loading="publishingId === item.id"
            @click.stop="togglePublish(item)" />
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click.stop="openEditProduct(item)" />
          <v-btn v-if="canFull('products')" icon="mdi-delete" size="x-small" variant="text" color="error" @click.stop="confirmDeleteProduct(item)" />
        </template>

        <!-- Expanded row: Programs -->
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="pa-4 expanded-programs-cell">
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="text-subtitle-2 font-weight-bold">Программы продукта «{{ item.name }}»</span>
                <v-btn v-if="canEdit('products')" size="small" color="primary" prepend-icon="mdi-plus" variant="tonal"
                  @click="openCreateProgram(item)">Добавить программу</v-btn>
              </div>
              <v-data-table
                :headers="programHeaders"
                :items="programsByProduct[item.id] || []"
                :loading="programsLoading[item.id]"
                density="compact"
                hover
                no-data-text="Нет программ"
                :items-per-page="-1"
                hide-default-footer
              >
                <template #item.active="{ item: prog }">
                  <StatusChip
                    :color="prog.active ? 'success' : 'grey'"
                    :text="prog.active ? 'Активна' : 'Неактивна'"
                    size="x-small"
                  />
                </template>
                <template #item.visibleToCalculator="{ item: prog }">
                  <v-icon :color="prog.visibleToCalculator ? 'success' : 'grey'" size="small">
                    {{ prog.visibleToCalculator ? 'mdi-calculator' : 'mdi-calculator-variant' }}
                  </v-icon>
                </template>
                <template #item.actions="{ item: prog }">
                  <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEditProgram(item, prog)" />
                  <v-btn v-if="canFull('products')" icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteProgram(item, prog)" />
                </template>
              </v-data-table>
            </td>
          </tr>
        </template>
        <template #no-data><EmptyState /></template>
      </v-data-table-server>
    </v-card>

    <!-- Product Dialog -->
    <v-dialog v-model="productDialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ editProduct.id ? 'Редактировать' : 'Добавить' }} продукт</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="editProduct.name" label="Название *" :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="editProduct.description" label="Краткое описание" rows="3" auto-grow
                hint="Отображается в карточке продукта" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.imageUrl" label="URL логотипа / иконки"
                prepend-inner-icon="mdi-image" hint="Маленькое изображение (логотип поставщика). Можно вставить URL или загрузить файл ниже."
                persistent-hint />
            </v-col>
            <v-col cols="12" v-if="editProduct.id">
              <v-file-input v-model="logoFile" label="Загрузить логотип" density="compact"
                accept="image/*" prepend-icon="" prepend-inner-icon="mdi-upload" show-size hide-details
                @update:model-value="uploadImage('image')" :loading="uploadingLogo" />
            </v-col>
            <v-col cols="12" v-if="editProduct.imageUrl">
              <v-img :src="editProduct.imageUrl" height="80" class="rounded border" contain />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.heroImage" label="URL баннера карточки (hero-image)"
                prepend-inner-icon="mdi-image-area" hint="Большое изображение сверху карточки на витрине"
                persistent-hint />
            </v-col>
            <v-col cols="12" v-if="editProduct.id">
              <v-file-input v-model="heroFile" label="Загрузить hero-баннер" density="compact"
                accept="image/*" prepend-icon="" prepend-inner-icon="mdi-upload" show-size hide-details
                @update:model-value="uploadImage('hero')" :loading="uploadingHero" />
            </v-col>
            <v-col cols="12" v-if="editProduct.heroImage">
              <v-img :src="editProduct.heroImage" height="140" class="rounded border" />
            </v-col>
            <v-col cols="12" md="6">
              <!-- Категория из products_catalog.type (строка, не legacy id).
                   references endpoint отдаёт distinct по type c полем name=type,
                   поэтому item-value="name" — это и есть строка, уезжающая
                   в editProduct.type → updateProduct в бэке. -->
              <v-select v-model="editProduct.type" :items="productTypeItems"
                item-title="name" item-value="name" label="Тип / категория"
                clearable hint="Определяет категорию продукта. Очистить = убрать категорию." persistent-hint
                prepend-inner-icon="mdi-shape" />
            </v-col>
            <v-col cols="12" md="6">
              <!-- Поставщик на уровне продукта (migration 2026_06_26_000010).
                   Combobox = выбрать из известных ИЛИ ввести нового. При
                   сохранении проставляется всем программам продукта (бэкенд),
                   чтобы отчёты «Комиссии»/«Матрица продаж» его подхватили. -->
              <v-combobox v-model="editProduct.providerName" :items="supplierOptions"
                label="Поставщик" clearable
                prepend-inner-icon="mdi-domain"
                hint="Выберите из списка или введите нового. Проставится всем программам продукта."
                persistent-hint />
            </v-col>
            <v-col cols="12" md="6">
              <!-- Основной / дополнительный: в каталоге ФК основные выводятся
                   первыми, дополнительные — после. -->
              <v-select v-model="editProduct.isPrimary" :items="primaryItems"
                item-title="title" item-value="value" label="Вид продукта"
                hint="Основные показываются в каталоге ФК выше дополнительных" persistent-hint
                prepend-inner-icon="mdi-star-circle" />
            </v-col>
            <v-col cols="12" md="6">
              <!-- Прогноз начисления: число месяцев, прибавляемых к месяцу
                   активации контракта (из тарифной матрицы «Период выплаты»). -->
              <v-select v-model="editProduct.accrualForecastMonths"
                :items="[{title:'В месяц активации (0)',value:0},{title:'+1 месяц',value:1},{title:'+2 месяца',value:2}]"
                item-title="title" item-value="value" label="Прогноз начисления"
                hint="Через сколько месяцев после активации ожидается начисление" persistent-hint
                prepend-inner-icon="mdi-calendar-clock" />
            </v-col>
            <v-col cols="12" md="6">
              <v-select v-model="editProduct.educationCourseId" :items="courseItems"
                item-title="title" item-value="id" label="Привязанное обучение"
                clearable prepend-inner-icon="mdi-school"
                hint="Курс из конструктора LMS" persistent-hint
                no-data-text="Активных курсов нет" />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.educationUrl" label="Внешняя ссылка на обучение"
                prepend-inner-icon="mdi-link"
                hint="Опционально — старая внешняя ссылка. Если выбран курс выше, она не используется."
                persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.instructionUrl" label="Инструкция по открытию"
                prepend-inner-icon="mdi-file-document" hint="Ссылка на инструкцию по открытию продукта" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProduct.openProductUrl" label="Ссылка 'Открыть продукт'"
                prepend-inner-icon="mdi-open-in-new" hint="Куда ведёт кнопка 'Открыть продукт' (форма БЭКа)" persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-select v-model="editProduct.publishStatus"
                :items="[{title: 'Черновик', value: 'draft'}, {title: 'Опубликован', value: 'published'}]"
                label="Статус публикации" hint="Партнёры видят только опубликованные" persistent-hint />
            </v-col>
            <v-col cols="6">
              <v-checkbox v-model="editProduct.active" label="Активен" density="compact" />
            </v-col>
            <!-- «Без комиссии» (noComission) скрыт 2026-07-13: флаг сохранялся,
                 но CommissionCalculator его не читает — комиссии начисляются
                 в любом случае. У продукта «Образовательные продукты» галка
                 стоит, а по нему выплачено 32,1 млн ₽ по цепочке: подключение
                 флага «как задумано» обнулило бы их. Что флаг должен означать —
                 открытый вопрос к владельцу; до решения не показываем. -->
            <v-col cols="6">
              <v-checkbox v-model="editProduct.visibleToResident" label="Виден партнёру" density="compact" />
            </v-col>
            <v-col cols="6">
              <v-checkbox v-model="editProduct.visibleToCalculator" label="Виден в калькуляторе" density="compact" />
            </v-col>
            <v-col cols="12">
              <div class="text-caption text-medium-emphasis mb-1">
                Релевантные параметры (показываются в формах транзакций)
              </div>
            </v-col>
            <v-col cols="6" md="4">
              <v-checkbox v-model="editProduct.hasProperty"
                label="«Свойство»" density="compact"
                hint="commissionCalcProperty (стандарт / 1 год / 2 год …)"
                persistent-hint />
            </v-col>
            <v-col cols="6" md="4">
              <v-checkbox v-model="editProduct.hasTerm"
                label="«Срок контракта»" density="compact"
                hint="число лет действия программы"
                persistent-hint />
            </v-col>
            <v-col cols="6" md="4">
              <v-checkbox v-model="editProduct.hasYearKv"
                label="«Год КВ»" density="compact"
                hint="указывается за какой год контракта выплата"
                persistent-hint />
            </v-col>
          </v-row>

          <!-- Inline-список программ продукта. Видим только когда продукт
               уже сохранён (нужен id для GET /programs). Использует те же
               стейты programsByProduct/programsLoading, что и expanded-row
               в основной таблице — открытие диалога догружает их через
               loadPrograms() в openEditProduct(). -->
          <template v-if="editProduct.id">
            <v-divider class="my-3" />
            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-subtitle-2 font-weight-bold">Программы продукта</span>
              <v-btn v-if="canEdit('products')"
                size="small" variant="tonal" color="primary" prepend-icon="mdi-plus"
                @click="openCreateProgram(editProduct)">
                Добавить программу
              </v-btn>
            </div>
            <v-data-table
              :headers="programHeaders"
              :items="programsByProduct[editProduct.id] || []"
              :loading="programsLoading[editProduct.id]"
              density="compact"
              hover
              no-data-text="Нет программ"
              :items-per-page="-1"
              hide-default-footer
            >
              <template #item.active="{ item: prog }">
                <StatusChip
                  :color="prog.active ? 'success' : 'grey'"
                  :text="prog.active ? 'Активна' : 'Неактивна'"
                  size="x-small"
                />
              </template>
              <template #item.visibleToCalculator="{ item: prog }">
                <v-icon :color="prog.visibleToCalculator ? 'success' : 'grey'" size="small">
                  {{ prog.visibleToCalculator ? 'mdi-calculator' : 'mdi-calculator-variant' }}
                </v-icon>
              </template>
              <template #item.actions="{ item: prog }">
                <v-btn icon="mdi-pencil" size="x-small" variant="text"
                  @click="openEditProgram(editProduct, prog)" />
                <v-btn v-if="canFull('products')" icon="mdi-delete" size="x-small" variant="text"
                  color="error" @click="confirmDeleteProgram(editProduct, prog)" />
              </template>
            </v-data-table>
          </template>

          <v-alert v-if="productError" type="error" density="compact" class="mt-2">{{ productError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="productDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveProduct" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Program Dialog — на телефоне во весь экран, на ПК широкий фрейм. -->
    <v-dialog v-model="programDialog" :max-width="xs ? undefined : 1200"
      :fullscreen="xs" persistent scrollable>
      <v-card>
        <v-card-title class="d-flex align-center">
          <span>{{ editProgram.id ? 'Редактировать' : 'Добавить' }} программу</span>
          <v-spacer />
          <v-btn v-if="xs" icon="mdi-close" variant="text" size="small" @click="programDialog = false" />
        </v-card-title>
        <v-card-text :style="xs ? {} : { maxHeight: '80vh' }">
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="editProgram.name" label="Название *" :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="12" sm="6">
              <!-- Per spec ✅Продукты §2 — поле «Поставщик». -->
              <v-text-field v-model="editProgram.providerName" label="Поставщик"
                hint="DS / Axevil / БКС / Insmart …" persistent-hint />
            </v-col>
            <v-col cols="12" sm="6">
              <!-- Колонка programs_catalog.category — категория для фильтра
                   витрины партнёра (НЕ «свойство продукта»; свойство задаётся
                   построчно в редакторе тарифов ниже). -->
              <v-text-field v-model="editProgram.vendorName" label="Категория"
                hint="Фильтр витрины: Страховые / Страховая оболочка / Портфельное управление / IPO / Недвижимость"
                persistent-hint />
            </v-col>
            <v-col cols="12">
              <v-text-field v-model="editProgram.formLink" label="Ссылка на форму"
                placeholder="https://..." prepend-inner-icon="mdi-link-variant"
                hint="URL для кнопки «Открыть» в модалке «Программы продукта» на витрине партнёра"
                persistent-hint />
            </v-col>
            <v-col cols="12">
              <!-- Срок/Год КВ/Валюта теперь живут ПОСТРОЧНО в тарифах ниже
                   (у свойств может быть разная валюта). Здесь — только сводка
                   read-only, чтобы не плодить конфликтующий дубль. -->
              <div class="text-caption text-medium-emphasis d-flex flex-wrap ga-3">
                <span>Сроки: <b>{{ tariffSummary.terms || '—' }}</b></span>
                <span>Годы КВ: <b>{{ tariffSummary.years || '—' }}</b></span>
                <span>Валюты: <b>{{ tariffSummary.currencies || '—' }}</b></span>
              </div>
            </v-col>
          </v-row>

          <v-divider class="my-3" />
          <div class="d-flex align-center flex-wrap ga-2 mb-1">
            <div class="text-subtitle-2 font-weight-bold">Тарифы (источник для калькулятора)</div>
            <v-spacer />
            <!-- Быстрое заполнение по годам выплаты КВ: создаёт строки на
                 годы 1…N (шаблон Свойство/Срок берётся из последней строки),
                 остаётся проставить % у каждого года. -->
            <v-text-field v-model.number="yearGenTo" type="number" min="1" max="40"
              density="compact" hide-details variant="outlined" style="max-width:120px"
              placeholder="до года" prepend-inner-icon="mdi-calendar-range" />
            <v-btn size="small" variant="text" prepend-icon="mdi-table-row-plus-after"
              :disabled="!yearGenTo || yearGenTo < 1" @click="generateYearRows">Годы 1…N</v-btn>
            <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-plus"
              @click="addTariff">Строка</v-btn>
          </div>
          <div class="text-caption text-medium-emphasis mb-2">
            Одна программа — много строк: разный «Год КВ» (или «Свойство») со
            своим %ДС в одной программе, отдельные программы создавать не нужно.
            Калькулятор сам подбирает строку по Свойству / Сроку / Году КВ. «Искл.» —
            строка по старым контрактам, в калькуляторе не показывается.
          </div>
          <template v-if="editProgram.tariffs && editProgram.tariffs.length">
            <!-- ПК (≥960): таблица. Обёрнута в скролл-контейнер, чтобы на узких
                 окнах колонки не сжимались, а скроллились по горизонтали. -->
            <div v-if="!smAndDown" class="tariff-scroll mb-2">
              <v-table density="compact" class="tariff-table">
                <thead>
                  <tr>
                    <th style="min-width:140px">Свойство</th>
                    <th style="min-width:80px">Срок</th>
                    <th style="min-width:80px">Год КВ</th>
                    <th style="min-width:100px">% ДС</th>
                    <th style="min-width:110px">Валюта</th>
                    <th style="min-width:220px">Формула / комментарий</th>
                    <th style="min-width:56px" class="text-center">Искл.</th>
                    <th style="width:44px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, i) in editProgram.tariffs" :key="i">
                    <td><v-text-field v-model="row.property" density="compact" variant="plain" hide-details
                      placeholder="MF / SF / upfront" /></td>
                    <td><v-text-field v-model="row.term" density="compact" variant="plain" hide-details
                      placeholder="25" /></td>
                    <td><v-text-field v-model="row.year_kv" density="compact" variant="plain" hide-details
                      placeholder="1" /></td>
                    <td><v-text-field v-model="row.ds_pct" density="compact" variant="plain" hide-details
                      placeholder="72,5" suffix="%" /></td>
                    <td><v-combobox v-model="row.currency" :items="tariffCurrencyOptions" density="compact"
                      variant="plain" hide-details placeholder="USD" /></td>
                    <td>
                      <v-text-field v-model="row.formula" density="compact" variant="plain" hide-details
                        placeholder="формула (необяз.)" />
                      <v-text-field v-model="row.comment" density="compact" variant="plain" hide-details
                        placeholder="комментарий (необяз.)" />
                    </td>
                    <td class="text-center">
                      <v-checkbox v-model="row.is_red" density="compact" hide-details
                        class="d-inline-flex justify-center" />
                    </td>
                    <td>
                      <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error"
                        @click="removeTariff(i)" />
                    </td>
                  </tr>
                </tbody>
              </v-table>
            </div>

            <!-- Узкие экраны (<960): каждая строка тарифа — карточка со
                 столбиком полей, ничего не обрезается. -->
            <div v-else>
              <v-card v-for="(row, i) in editProgram.tariffs" :key="i" variant="outlined" class="mb-3 pa-3">
                <div class="d-flex align-center mb-1">
                  <span class="text-caption font-weight-medium">Строка {{ i + 1 }}</span>
                  <v-spacer />
                  <v-checkbox v-model="row.is_red" label="Искл." density="compact" hide-details
                    class="ma-0 flex-grow-0" />
                  <v-btn icon="mdi-delete-outline" size="small" variant="text" color="error"
                    @click="removeTariff(i)" />
                </div>
                <v-row dense>
                  <v-col cols="12">
                    <v-text-field v-model="row.property" label="Свойство" density="compact" hide-details
                      placeholder="MF / SF / upfront" />
                  </v-col>
                  <v-col cols="4">
                    <v-text-field v-model="row.term" label="Срок" density="compact" hide-details placeholder="25" />
                  </v-col>
                  <v-col cols="4">
                    <v-text-field v-model="row.year_kv" label="Год КВ" density="compact" hide-details placeholder="1" />
                  </v-col>
                  <v-col cols="4">
                    <v-text-field v-model="row.ds_pct" label="% ДС" suffix="%" density="compact" hide-details
                      placeholder="72,5" />
                  </v-col>
                  <v-col cols="12">
                    <v-combobox v-model="row.currency" :items="tariffCurrencyOptions" label="Валюта"
                      density="compact" hide-details placeholder="USD" />
                  </v-col>
                  <v-col cols="12">
                    <v-text-field v-model="row.formula" label="Формула (необяз.)" density="compact" hide-details />
                  </v-col>
                  <v-col cols="12">
                    <v-text-field v-model="row.comment" label="Комментарий (необяз.)" density="compact" hide-details />
                  </v-col>
                </v-row>
              </v-card>
            </div>
          </template>
          <div v-else class="text-caption text-medium-emphasis mb-2">
            Тарифов нет — нажмите «Строка», чтобы добавить вариант свойства/%ДС.
          </div>

          <v-divider class="my-3" />
          <v-row dense>
            <v-col cols="12" sm="4">
              <v-checkbox v-model="editProgram.active" label="Активна" density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="4">
              <v-checkbox v-model="editProgram.visibleToResident" label="Виден партнёру" density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="4">
              <v-checkbox v-model="editProgram.visibleToCalculator" label="В калькуляторе" density="compact" hide-details />
            </v-col>
          </v-row>
          <v-alert v-if="programError" type="error" density="compact" class="mt-2">{{ programError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="programDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveProgram" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Product Confirm -->
    <v-dialog v-model="deleteProductDialog" max-width="400">
      <v-card>
        <v-card-title>Деактивировать продукт?</v-card-title>
        <v-card-text>{{ deleteProductTarget?.name }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteProductDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteProduct" :loading="saving">Деактивировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Program Confirm -->
    <v-dialog v-model="deleteProgramDialog" max-width="400">
      <v-card>
        <v-card-title>Деактивировать программу?</v-card-title>
        <v-card-text>{{ deleteProgramTarget?.name }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteProgramDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteProgram" :loading="saving">Деактивировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useDisplay } from 'vuetify';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import StatusChip from '../../components/StatusChip.vue';
import FilterBar from '../../components/FilterBar.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { useAuthStore } from '../../stores/auth';
import { usePermissions } from '../../composables/usePermissions';

const auth = useAuthStore();
const { canEdit, canFull } = usePermissions();
// Адаптив диалога программы по ширине экрана (не по vuetify `mobile`, у которого
// порог lg=1280 — он считал «мобилой» обычные ПК <1280):
//   xs (<600, телефон)      → диалог во весь экран;
//   smAndDown (<960)        → тарифы карточками (узкие окна/планшеты/телефон);
//   md и выше (≥960, ПК)    → широкая таблица с горизонтальным скроллом.
const { xs, smAndDown } = useDisplay();

const loading = ref(false);
const saving = ref(false);
const products = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const sortBy = ref([]);
const expanded = ref([]);

const filters = ref({ search: '', active: null });

const activeFilterCount = computed(() => {
  let c = 0;
  if (filters.value.search) c++;
  if (filters.value.active) c++;
  return c;
});

function resetProductFilters() {
  filters.value = { search: '', active: null };
  loadProducts();
}
// Вид продукта: основной / дополнительный (catalog.is_primary).
const primaryItems = [
  { title: 'Основной', value: true },
  { title: 'Дополнительный', value: false },
];
const activeOptions = [
  { title: 'Активные', value: 'true' },
  { title: 'Неактивные', value: 'false' },
];

const headers = [
  { title: 'Название', key: 'name' },
  { title: 'Публикация', key: 'publishStatus', width: 130 },
  { title: 'Статус', key: 'active', width: 110 },
  { title: 'Партнёр', key: 'visibleToResident', width: 90 },
  { title: 'Калькулятор', key: 'visibleToCalculator', width: 100 },
  { title: 'Программ', key: 'programCount', width: 90 },
  { title: 'Действия', key: 'actions', sortable: false, width: 140 },
];

const columnVisible = ref({});
const visibleHeaders = computed(() => headers.filter(h => columnVisible.value[h.key] !== false));

const programHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Статус', key: 'active', width: 120 },
  { title: 'Срок', key: 'term', width: 100 },
  { title: 'Валюта', key: 'currencyName', width: 100 },
  { title: 'Калькулятор', key: 'visibleToCalculator', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

// Селектор валют — только рабочие 4 (RUB/USD/EUR/GBP). Управляется
// через currency.selectable в БД, эндпоинт /currencies/selectable.
const currencyOptions = ref([]);
async function loadCurrencies() {
  try {
    const { data } = await api.get('/currencies/selectable');
    currencyOptions.value = (data.items || []).map(c => ({
      id: c.id,
      label: c.label || (c.symbol ? `${c.name} (${c.symbol})` : c.name),
    }));
  } catch {}
}

// Product dialog
const productDialog = ref(false);
const productError = ref('');
const editProduct = ref({});

// Загрузка логотипа / hero-баннера. Работает только для уже сохранённого
// продукта (нужен id) — для нового сначала жмём «Сохранить», потом
// переоткрываем форму на редактирование и грузим картинки.
const logoFile = ref(null);
const heroFile = ref(null);
const uploadingLogo = ref(false);
const uploadingHero = ref(false);

async function uploadImage(kind) {
  const fileRef = kind === 'image' ? logoFile : heroFile;
  const loadingRef = kind === 'image' ? uploadingLogo : uploadingHero;
  const file = Array.isArray(fileRef.value) ? fileRef.value[0] : fileRef.value;
  if (!file || !editProduct.value.id) return;
  loadingRef.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('kind', kind);
    const { data } = await api.post(`/admin/products-catalog/${editProduct.value.id}/image`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (kind === 'image') editProduct.value.imageUrl = data.url;
    else editProduct.value.heroImage = data.url;
    fileRef.value = null;
  } catch (e) {
    productError.value = e.response?.data?.message || 'Ошибка загрузки файла';
  }
  loadingRef.value = false;
}

// Program dialog
const programDialog = ref(false);
const programError = ref('');
const editProgram = ref({});
const editProgramProductId = ref(null);

// Delete dialogs
const deleteProductDialog = ref(false);
const deleteProductTarget = ref(null);
const deleteProgramDialog = ref(false);
const deleteProgramTarget = ref(null);
const deleteProgramProductId = ref(null);

// Programs per product
const programsByProduct = reactive({});
const programsLoading = reactive({});

const { debounced: debouncedLoad } = useDebounce(loadProducts, 400);

async function loadProducts() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.active) params.active = filters.value.active;
    if (sortBy.value.length) {
      params.sort_by = sortBy.value[0].key;
      params.sort_dir = sortBy.value[0].order || 'asc';
    }
    const { data } = await api.get('/admin/products-catalog', { params });
    products.value = data.data || data;
    total.value = data.total || products.value.length;
  } catch {}
  loading.value = false;
}

// Vuetify v-data-table-server хранит в expanded массив item-value (мы
// сказали item-value="id" — значит здесь id'ы продуктов). Раскрытие
// может произойти через chevron-кнопку или клик на строку — оба теперь
// обновят массив, и мы догрузим программы для всех новых id.
function onExpandedChange(newExpanded) {
  for (const id of newExpanded) {
    if (programsByProduct[id] === undefined) {
      loadPrograms(id);
    }
  }
}

async function loadPrograms(productId) {
  programsLoading[productId] = true;
  try {
    const { data } = await api.get(`/admin/products-catalog/${productId}/programs`);
    programsByProduct[productId] = data.data || data;
  } catch {}
  programsLoading[productId] = false;
}

// Product CRUD
const publishingId = ref(null);

async function togglePublish(product) {
  publishingId.value = product.id;
  try {
    const { data } = await api.post(`/admin/products-catalog/${product.id}/toggle-publish`);
    product.publishStatus = data.publishStatus;
  } catch {}
  publishingId.value = null;
}

function openCreateProduct() {
  editProduct.value = {
    name: '', description: '', imageUrl: '', heroImage: '',
    type: null, providerName: null, productType: null, educationCourseId: null,
    educationUrl: '', instructionUrl: '', openProductUrl: '',
    active: true, noComission: false, visibleToResident: true, visibleToCalculator: true,
    hasProperty: false, hasTerm: false, hasYearKv: false,
    isPrimary: true,
    accrualForecastMonths: 0,
    publishStatus: 'draft',
  };
  productError.value = '';
  productDialog.value = true;
}

function openEditProduct(product) {
  editProduct.value = { ...product };
  productError.value = '';
  productDialog.value = true;
  // Программы показываем inline внутри диалога — догружаем сразу при
  // открытии, если ещё не были загружены через expanded-row. После
  // save/delete программы делаем повторную загрузку в saveProgram/deleteProgram.
  if (product.id && programsByProduct[product.id] === undefined) {
    loadPrograms(product.id);
  }
}

async function saveProduct() {
  if (!editProduct.value.name) {
    productError.value = 'Название обязательно';
    return;
  }
  saving.value = true;
  productError.value = '';
  try {
    if (editProduct.value.id) {
      await api.put(`/admin/products-catalog/${editProduct.value.id}`, editProduct.value);
    } else {
      await api.post('/admin/products-catalog', editProduct.value);
    }
    productDialog.value = false;
    loadProducts();
  } catch (e) {
    productError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDeleteProduct(product) {
  deleteProductTarget.value = product;
  deleteProductDialog.value = true;
}

async function deleteProduct() {
  saving.value = true;
  try {
    await api.delete(`/admin/products-catalog/${deleteProductTarget.value.id}`);
    deleteProductDialog.value = false;
    loadProducts();
  } catch {}
  saving.value = false;
}

// Program CRUD

// Канонический тариф из БД → строка редактора. Год берём из year_kv (новый
// формат) или year (legacy). %ДС очищаем от «%», запятую → точку, чтобы поле
// с suffix="%" не задваивало знак; legacy-доля (ds_percent 0..1) переводится
// в проценты.
function tariffsToRows(tariffs) {
  const strip = (v) => {
    if (v === null || v === undefined || v === '') return '';
    return String(v).replace('%', '').replace(',', '.').trim();
  };
  return (Array.isArray(tariffs) ? tariffs : []).map((t) => ({
    property: t.property ?? '',
    term: t.term ?? '',
    year_kv: t.year_kv ?? t.year ?? '',
    ds_pct: t.ds_pct != null && t.ds_pct !== ''
      ? strip(t.ds_pct)
      : (t.ds_percent != null && t.ds_percent !== '' ? String(Number(t.ds_percent) * 100) : ''),
    formula: t.formula ?? '',
    comment: t.comment ?? '',
    currency: t.currency ?? '',
    is_red: !!t.is_red,
  }));
}

// Коды валют тарифа — подсказки для combobox (значение пишется строкой как в
// данных: «USD»). Combobox оставляет и комбинированные значения вроде «USD/EUR».
const tariffCurrencyOptions = ['RUB', 'USD', 'EUR', 'KZT', 'GBP'];

// Сводка по тарифам (read-only) — выводится вместо прежних program-level полей
// «Срок»/«Валюта», которые конфликтовали с построчными значениями.
const tariffSummary = computed(() => {
  const rows = Array.isArray(editProgram.value.tariffs) ? editProgram.value.tariffs : [];
  const uniq = (key) => [...new Set(rows.map(r => String(r[key] ?? '').trim()).filter(Boolean))];
  return {
    terms: uniq('term').join(', '),
    years: uniq('year_kv').join(', '),
    currencies: uniq('currency').join(', '),
  };
});

function addTariff() {
  if (!Array.isArray(editProgram.value.tariffs)) editProgram.value.tariffs = [];
  editProgram.value.tariffs.push({
    property: '', term: '', year_kv: '', ds_pct: '', formula: '', comment: '', currency: '', is_red: false,
  });
}

function removeTariff(i) {
  editProgram.value.tariffs.splice(i, 1);
}

// «Год КВ от 1 до N с разным %»: одна программа, N тарифных строк. Генерируем
// строки на годы 1…N (Свойство/Срок наследуем от последней строки как шаблон),
// пропуская уже существующие годы — оператор затем проставляет % в каждой.
const yearGenTo = ref(null);
function generateYearRows() {
  const n = Number(yearGenTo.value);
  if (!Number.isFinite(n) || n < 1) return;
  if (!Array.isArray(editProgram.value.tariffs)) editProgram.value.tariffs = [];
  const rows = editProgram.value.tariffs;
  const tpl = rows.length ? rows[rows.length - 1] : {};
  const existingYears = new Set(rows.map((r) => String(r.year_kv ?? '').trim()).filter(Boolean));
  for (let y = 1; y <= Math.min(n, 40); y++) {
    if (existingYears.has(String(y))) continue;
    rows.push({
      property: tpl.property ?? '',
      term: tpl.term ?? '',
      year_kv: String(y),
      ds_pct: '',
      formula: '',
      comment: '',
      currency: tpl.currency ?? '',
      is_red: false,
    });
  }
  yearGenTo.value = null;
}

function openCreateProgram(product) {
  editProgramProductId.value = product.id;
  editProgram.value = { name: '', term: '', currency: null, active: true, visibleToResident: true, visibleToCalculator: true, tariffs: [] };
  programError.value = '';
  programDialog.value = true;
}

function openEditProgram(product, program) {
  editProgramProductId.value = product.id;
  editProgram.value = { ...program, tariffs: tariffsToRows(program.tariffs) };
  programError.value = '';
  programDialog.value = true;
}

async function saveProgram() {
  if (!editProgram.value.name) {
    programError.value = 'Название обязательно';
    return;
  }
  saving.value = true;
  programError.value = '';
  const productId = editProgramProductId.value;
  try {
    if (editProgram.value.id) {
      await api.put(`/admin/products-catalog/${productId}/programs/${editProgram.value.id}`, editProgram.value);
    } else {
      await api.post(`/admin/products-catalog/${productId}/programs`, editProgram.value);
    }
    programDialog.value = false;
    loadPrograms(productId);
    loadProducts();
  } catch (e) {
    programError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDeleteProgram(product, program) {
  deleteProgramProductId.value = product.id;
  deleteProgramTarget.value = program;
  deleteProgramDialog.value = true;
}

async function deleteProgram() {
  saving.value = true;
  const productId = deleteProgramProductId.value;
  try {
    await api.delete(`/admin/products-catalog/${productId}/programs/${deleteProgramTarget.value.id}`);
    deleteProgramDialog.value = false;
    loadPrograms(productId);
    loadProducts();
  } catch {}
  saving.value = false;
}

// Справочники для формы редактирования продукта.
const productTypeItems = ref([]);
const courseItems = ref([]);
async function loadProductReferences() {
  try {
    const { data } = await api.get('/admin/products-catalog/references');
    productTypeItems.value = data.types || [];
    courseItems.value = data.courses || [];
  } catch {}
}

// Список известных поставщиков для селектора «Поставщик» в форме продукта.
// Тот же источник, что в фильтрах «Комиссий» (distinct program.providerName).
const supplierOptions = ref([]);
async function loadSuppliers() {
  try {
    const { data } = await api.get('/admin/manual-tx/lookups');
    supplierOptions.value = data.suppliers || [];
  } catch {}
}

onMounted(() => {
  loadProducts();
  loadCurrencies();
  loadProductReferences();
  loadSuppliers();
});
</script>

<style scoped>
/* Расширенная строка с программами продукта — использует theme-токены
   вместо хардкода bg-grey-lighten-5 (он остаётся светло-серым в тёмной
   теме и ломает контраст). Surface-variant даёт лёгкий контраст с
   основным фоном и в светлой, и в тёмной теме. */
.expanded-programs-cell {
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.expanded-programs-cell :deep(.v-data-table) {
  background: transparent;
}

/* Редактор тарифов: горизонтальный скролл на узких окнах, чтобы колонки-поля
   не сжимались и не «съедались». Таблица держит минимальную ширину. */
.tariff-scroll {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.tariff-scroll .tariff-table {
  min-width: 760px;
}
/* Поля внутри ячеек — без лишних внутренних отступов, плотная сетка. */
.tariff-table :deep(td) {
  padding-inline: 6px;
  vertical-align: middle;
}
.tariff-table :deep(.v-field__input) {
  padding-top: 4px;
  padding-bottom: 4px;
  min-height: 32px;
}
</style>

