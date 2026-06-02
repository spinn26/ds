<template>
  <div>
    <PageHeader title="Обучение" icon="mdi-school">
      <template #actions>
        <ColumnVisibilityMenu
          :headers="courseHeaders"
          v-model:visible="courseColumnVisible"
          storage-key="education-courses-cols" />
        <v-btn variant="text" prepend-icon="mdi-eye" href="/education" target="_blank">
          Просмотр как партнёр
        </v-btn>
        <v-btn v-if="canEdit('education')" color="primary" prepend-icon="mdi-plus" @click="openCreateCourse">Добавить курс</v-btn>
      </template>
    </PageHeader>

    <!-- Filters -->
    <v-card class="mb-4 pa-3">
      <v-row dense>
        <v-col cols="12" sm="4">
          <v-text-field v-model="filters.search" label="Поиск по названию" prepend-inner-icon="mdi-magnify"
            clearable hide-details @update:model-value="debouncedLoad" />
        </v-col>
        <v-col cols="12" sm="2" class="d-flex align-center">
          <v-chip color="primary" size="small">{{ total }} записей</v-chip>
        </v-col>
      </v-row>
    </v-card>

    <!-- Courses Table -->
    <v-card>
      <v-data-table-server
        :headers="visibleCourseHeaders"
        :items="courses"
        :items-length="total"
        :loading="loading"
        :items-per-page="perPage"
        :items-per-page-options="[25, 50, 100, 200]"
        v-model:expanded="expanded"
        item-value="id"
        show-expand
        @update:page="page = $event; loadCourses()"
        @update:items-per-page="v => { perPage = v; page = 1; loadCourses(); }"
        @update:expanded="onExpandedChange"
        no-data-text="Курсы не найдены"
      >
        <template #item.categoryName="{ value }">
          <v-chip v-if="value" size="x-small" variant="tonal" color="primary">{{ value }}</v-chip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.productName="{ item }">
          <template v-if="(item.products || []).length">
            <v-chip v-for="p in item.products" :key="p.id" size="x-small" variant="tonal"
              color="success" class="me-1 mb-1">{{ p.name }}</v-chip>
          </template>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.active="{ item }">
          <v-chip :color="item.active ? 'success' : 'grey'" size="x-small">
            {{ item.active ? 'Активен' : 'Неактивен' }}
          </v-chip>
        </template>
        <template #item.actions="{ item }">
          <v-tooltip text="Уроки" location="top">
            <template #activator="{ props: p }">
              <v-btn v-bind="p" icon="mdi-book-open-variant" size="x-small" variant="text" color="primary"
                @click.stop="openCourseTab(item, 'lessons')" />
            </template>
          </v-tooltip>
          <v-tooltip text="Тесты" location="top">
            <template #activator="{ props: p }">
              <v-btn v-bind="p" icon="mdi-help-circle-outline" size="x-small" variant="text" color="primary"
                @click.stop="openCourseTab(item, 'tests')" />
            </template>
          </v-tooltip>
          <v-btn icon="mdi-pencil" size="x-small" variant="text" @click.stop="openEditCourse(item)" />
          <v-btn v-if="canFull('education')" icon="mdi-delete" size="x-small" variant="text" color="error" @click.stop="confirmDeleteCourse(item)" />
        </template>

        <!-- Expanded row: Lessons & Tests tabs -->
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="pa-4 expanded-row-body">
              <v-tabs v-model="activeTab[item.id]" density="compact" class="mb-3">
                <v-tab :value="'lessons'" prepend-icon="mdi-book-open-variant">Уроки</v-tab>
                <v-tab :value="'tests'" prepend-icon="mdi-help-circle-outline">Тесты</v-tab>
              </v-tabs>

              <!-- Lessons Tab -->
              <div v-if="(activeTab[item.id] || 'lessons') === 'lessons'">
                <div class="d-flex justify-space-between align-center mb-2">
                  <span class="text-subtitle-2 font-weight-bold">Уроки курса «{{ item.title }}»</span>
                  <div class="d-flex align-center ga-2">
                    <ColumnVisibilityMenu
                      :headers="lessonHeaders"
                      v-model:visible="lessonColumnVisible"
                      storage-key="education-lessons-cols" />
                    <v-btn v-if="canEdit('education')" size="small" color="primary" prepend-icon="mdi-plus" variant="flat"
                      @click="openCreateLesson(item)">Добавить урок</v-btn>
                  </div>
                </div>
                <v-data-table
                  :headers="visibleLessonHeaders"
                  :items="lessonsByCourse[item.id] || []"
                  :loading="lessonsLoading[item.id]"
                  density="compact"
                  hover
                  no-data-text="Нет уроков"
                  :items-per-page="9999"
                  hide-default-footer
                >
                  <template #item.videoCount="{ item: lesson }">
                    <span class="text-no-wrap">{{ (lesson.video_urls || []).length || (lesson.video_url ? 1 : 0) }}</span>
                  </template>
                  <template #item.docCount="{ item: lesson }">
                    <span class="text-no-wrap">{{ (lesson.document_urls || []).length || (lesson.document_url ? 1 : 0) }}</span>
                  </template>
                  <template #item.active="{ item: lesson }">
                    <v-chip :color="lesson.active ? 'success' : 'grey'" size="x-small">
                      {{ lesson.active ? 'Активен' : 'Неактивен' }}
                    </v-chip>
                  </template>
                  <template #item.actions="{ item: lesson }">
                    <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEditLesson(item, lesson)" />
                    <v-btn v-if="canFull('education')" icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteLesson(item, lesson)" />
                  </template>
                </v-data-table>
              </div>

              <!-- Tests Tab -->
              <div v-if="activeTab[item.id] === 'tests'">
                <div class="d-flex justify-space-between align-center mb-2">
                  <span class="text-subtitle-2 font-weight-bold">
                    Тесты курса «{{ item.title }}»
                    <v-chip v-if="(testsByCourse[item.id] || []).length" size="x-small"
                      variant="tonal" color="primary" class="ms-2">
                      {{ (testsByCourse[item.id] || []).length }}
                    </v-chip>
                  </span>
                  <v-btn v-if="canEdit('education')" size="small" color="primary" prepend-icon="mdi-plus" variant="flat"
                    @click="openCreateTest(item)">Добавить вопрос</v-btn>
                </div>

                <div v-if="testsLoading[item.id]" class="d-flex justify-center py-4">
                  <v-progress-circular indeterminate size="24" />
                </div>
                <div v-else-if="!(testsByCourse[item.id] || []).length"
                  class="text-center text-medium-emphasis py-4">
                  Нет вопросов
                </div>
                <!-- Простой v-list вместо v-data-table — у data-table'ы Vuetify
                     иногда срезает rows до items-per-page-options дефолта
                     (10), даже если items-per-page=9999 (баг видели на 12
                     вопросах). v-list гарантированно отрисует все.
                     Список draggable — порядок сохраняется через
                     POST /admin/education/courses/{id}/tests/reorder. -->
                <v-list v-else density="compact" class="pa-0 tests-list">
                  <v-list-item v-for="(test, i) in (testsByCourse[item.id] || [])"
                    :key="test.id"
                    :class="['border-b test-row', {
                      'test-row--drag': dragTestId === test.id,
                      'test-row--saving': reorderingCourseId === item.id,
                    }]"
                    :draggable="canEdit('education')"
                    @dragstart="onTestDragStart($event, item.id, test.id)"
                    @dragover.prevent="onTestDragOver($event, item.id)"
                    @drop.prevent="onTestDrop(item.id, test.id)"
                    @dragend="onTestDragEnd">
                    <template #prepend>
                      <v-icon v-if="canEdit('education')" size="16"
                        class="me-1 test-drag-handle"
                        title="Перетащите, чтобы изменить порядок">
                        mdi-drag-vertical
                      </v-icon>
                      <span class="text-medium-emphasis me-2" style="min-width: 24px">{{ i + 1 }}.</span>
                    </template>
                    <v-list-item-title class="text-body-2">{{ test.question }}</v-list-item-title>
                    <v-list-item-subtitle class="text-caption">
                      Вариантов: {{ (test.answers || []).length }} ·
                      Правильный: №{{ (test.correct_answer ?? 0) + 1 }}
                    </v-list-item-subtitle>
                    <template #append>
                      <v-btn v-if="canEdit('education')" icon="mdi-arrow-up" size="x-small" variant="text"
                        :disabled="i === 0 || reorderingCourseId === item.id"
                        title="Выше"
                        @click.stop="moveTest(item.id, i, -1)" />
                      <v-btn v-if="canEdit('education')" icon="mdi-arrow-down" size="x-small" variant="text"
                        :disabled="i === (testsByCourse[item.id] || []).length - 1 || reorderingCourseId === item.id"
                        title="Ниже"
                        @click.stop="moveTest(item.id, i, 1)" />
                      <v-btn icon="mdi-pencil" size="x-small" variant="text"
                        @click="openEditTest(item, test)" />
                      <v-btn v-if="canFull('education')" icon="mdi-delete"
                        size="x-small" variant="text" color="error"
                        @click="confirmDeleteTest(item, test)" />
                    </template>
                  </v-list-item>
                </v-list>
              </div>
            </td>
          </tr>
        </template>
      </v-data-table-server>
    </v-card>

    <!-- Course Dialog -->
    <v-dialog v-model="courseDialog" max-width="600" persistent>
      <v-card>
        <v-card-title>{{ editCourse.id ? 'Редактировать' : 'Добавить' }} курс</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="editCourse.title" label="Название *" :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="editCourse.description" label="Описание" rows="3" auto-grow />
            </v-col>
            <v-col cols="12" sm="6">
              <v-autocomplete v-model="editCourse.product_ids" label="Продукты" :items="productOptions"
                item-title="name" item-value="id" multiple chips closable-chips clearable
                hint="Можно выбрать несколько активных продуктов" persistent-hint />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select v-model="editCourse.category_id" label="Категория"
                :items="categoryOptions" item-title="name" item-value="id" clearable
                :hint="categoryOptions.length ? '' : 'Категорий пока нет — создайте в разделе «Категории курсов»'"
                persistent-hint />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="editCourse.sort_order" label="Сортировка" type="number" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-checkbox v-model="editCourse.active" label="Активен" density="compact" />
            </v-col>
          </v-row>
          <v-alert v-if="courseError" type="error" density="compact" class="mt-2">{{ courseError }}</v-alert>
          <div class="text-caption text-medium-emphasis mt-2">
            После сохранения курс появится в списке — уроки и тесты добавляются через кнопки
            <v-icon size="small">mdi-book-open-variant</v-icon> /
            <v-icon size="small">mdi-help-circle-outline</v-icon> в строке или раскрытием.
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="courseDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveCourse" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Lesson Dialog -->
    <v-dialog v-model="lessonDialog" max-width="700" persistent>
      <v-card>
        <v-card-title>{{ editLesson.id ? 'Редактировать' : 'Добавить' }} урок</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="editLesson.title" label="Название *" :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="editLesson.content" label="Содержание" rows="5" auto-grow
                hint="Произвольный текст. Видео и ссылки добавляются ниже отдельными полями." persistent-hint />
            </v-col>

            <!-- Видео — динамический список -->
            <v-col cols="12">
              <div class="d-flex align-center mb-1 mt-2">
                <v-icon size="18" color="primary" class="me-1">mdi-video</v-icon>
                <span class="text-subtitle-2">Видео</span>
                <v-spacer />
                <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="addVideoUrl">
                  Добавить видео
                </v-btn>
              </div>
              <div v-for="(item, i) in editLesson.video_urls" :key="'v' + i"
                class="d-flex align-center ga-2 mb-2">
                <v-text-field v-model="item.url"
                  placeholder="https://youtu.be/..."
                  prepend-inner-icon="mdi-play-circle"
                  variant="outlined" density="comfortable" hide-details
                  style="flex: 2 1 0" />
                <v-text-field v-model="item.label"
                  placeholder="Подпись кнопки (необязательно)"
                  variant="outlined" density="comfortable" hide-details
                  style="flex: 1 1 0" />
                <v-btn icon="mdi-close" size="small" variant="text" color="error"
                  @click="editLesson.video_urls.splice(i, 1)" />
              </div>
              <div v-if="!editLesson.video_urls.length" class="text-caption text-medium-emphasis">
                Видео не добавлены.
              </div>
            </v-col>

            <!-- Документы / ссылки — динамический список -->
            <v-col cols="12">
              <div class="d-flex align-center mb-1 mt-2">
                <v-icon size="18" color="primary" class="me-1">mdi-file-document</v-icon>
                <span class="text-subtitle-2">Документы и ссылки</span>
                <v-spacer />
                <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="addDocumentUrl">
                  Добавить ссылку
                </v-btn>
              </div>
              <div v-for="(item, i) in editLesson.document_urls" :key="'d' + i"
                class="d-flex align-center ga-2 mb-2">
                <v-text-field v-model="item.url"
                  placeholder="https://..."
                  prepend-inner-icon="mdi-link-variant"
                  variant="outlined" density="comfortable" hide-details
                  style="flex: 2 1 0" />
                <v-text-field v-model="item.label"
                  placeholder="Подпись кнопки (необязательно)"
                  variant="outlined" density="comfortable" hide-details
                  style="flex: 1 1 0" />
                <v-btn icon="mdi-close" size="small" variant="text" color="error"
                  @click="editLesson.document_urls.splice(i, 1)" />
              </div>
              <div v-if="!editLesson.document_urls.length" class="text-caption text-medium-emphasis">
                Ссылки не добавлены.
              </div>
            </v-col>

            <v-col cols="12" sm="4">
              <v-text-field v-model.number="editLesson.sort_order" label="Сортировка" type="number" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-checkbox v-model="editLesson.active" label="Активен" density="compact" />
            </v-col>
          </v-row>
          <v-alert v-if="lessonError" type="error" density="compact" class="mt-2">{{ lessonError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="lessonDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveLesson" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Test Question Dialog -->
    <v-dialog v-model="testDialog" max-width="700" persistent>
      <v-card>
        <v-card-title>{{ editTest.id ? 'Редактировать' : 'Добавить' }} вопрос</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12">
              <v-textarea v-model="editTest.question" label="Вопрос *" rows="3" auto-grow
                :rules="[v => !!v || 'Обязательное поле']" />
            </v-col>
            <v-col cols="12">
              <div class="text-subtitle-2 font-weight-bold mb-1">Варианты ответов</div>
              <div class="text-caption text-medium-emphasis mb-2">
                Отметьте радио-кнопкой правильный вариант.
              </div>
              <v-radio-group v-model="editTest.correct_answer" hide-details class="mt-0 pt-0">
                <div v-for="(answer, idx) in editTest.answers" :key="idx"
                  class="d-flex align-center ga-2 mb-2 px-2 py-1 rounded answer-row"
                  :class="{ 'answer-correct': editTest.correct_answer === idx }">
                  <v-radio :value="idx" color="success" hide-details class="flex-grow-0" />
                  <v-text-field v-model="editTest.answers[idx]" :label="'Вариант ' + (idx + 1)"
                    density="compact" hide-details class="flex-grow-1" />
                  <v-btn icon="mdi-close" size="x-small" variant="text" color="error"
                    :disabled="editTest.answers.length <= 2"
                    @click="removeAnswer(idx)" />
                </div>
              </v-radio-group>
              <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" class="mt-1"
                @click="addAnswer">Добавить вариант</v-btn>
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model.number="editTest.sort_order" label="Сортировка" type="number" />
            </v-col>
          </v-row>
          <v-alert v-if="testError" type="error" density="compact" class="mt-2">{{ testError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="testDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="saveTest" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Course Confirm -->
    <v-dialog v-model="deleteCourseDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить курс?</v-card-title>
        <v-card-text>{{ deleteCourseTarget?.title }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteCourseDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteCourse" :loading="saving">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Lesson Confirm -->
    <v-dialog v-model="deleteLessonDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить урок?</v-card-title>
        <v-card-text>{{ deleteLessonTarget?.title }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteLessonDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteLesson" :loading="saving">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Test Confirm -->
    <v-dialog v-model="deleteTestDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить вопрос?</v-card-title>
        <v-card-text>{{ deleteTestTarget?.question }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteTestDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteTest" :loading="saving">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';
import ColumnVisibilityMenu from '../../components/ColumnVisibilityMenu.vue';
import { usePermissions } from '../../composables/usePermissions';
import { useSnackbar } from '../../composables/useSnackbar';

const { canEdit, canFull } = usePermissions();
const { showSuccess, showError } = useSnackbar();

const loading = ref(false);
const saving = ref(false);
const courses = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const expanded = ref([]);
const activeTab = reactive({});
const productOptions = ref([]);
const categoryOptions = ref([]);

const filters = ref({ search: '' });

const courseHeaders = [
  { title: 'Название', key: 'title' },
  { title: 'Категория', key: 'categoryName', width: 160 },
  { title: 'Продукт', key: 'productName', width: 180 },
  { title: 'Статус', key: 'active', width: 120 },
  { title: 'Уроков', key: 'lessonCount', width: 90 },
  { title: 'Тестов', key: 'testCount', width: 90 },
  { title: 'Сортировка', key: 'sort_order', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const lessonHeaders = [
  { title: 'Название', key: 'title' },
  { title: 'Видео', key: 'videoCount', width: 90, align: 'end' },
  { title: 'Ссылки', key: 'docCount', width: 90, align: 'end' },
  { title: 'Статус', key: 'active', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const testHeaders = [
  { title: 'Вопрос', key: 'question' },
  { title: 'Вариантов', key: 'answersCount', width: 110 },
  { title: 'Правильный', key: 'correct_answer', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const courseColumnVisible = ref({});
const visibleCourseHeaders = computed(() =>
  courseHeaders.filter(h => courseColumnVisible.value[h.key] !== false)
);
const lessonColumnVisible = ref({});
const visibleLessonHeaders = computed(() =>
  lessonHeaders.filter(h => lessonColumnVisible.value[h.key] !== false)
);
const testColumnVisible = ref({});
// Course dialog
const courseDialog = ref(false);
const courseError = ref('');
const editCourse = ref({});

// Lesson dialog
const lessonDialog = ref(false);
const lessonError = ref('');
const editLesson = ref({});
const editLessonCourseId = ref(null);

// Test dialog
const testDialog = ref(false);
const testError = ref('');
const editTest = ref({ answers: ['', ''], correct_answer: 0 });
const editTestCourseId = ref(null);

// Delete dialogs
const deleteCourseDialog = ref(false);
const deleteCourseTarget = ref(null);
const deleteLessonDialog = ref(false);
const deleteLessonTarget = ref(null);
const deleteLessonCourseId = ref(null);
const deleteTestDialog = ref(false);
const deleteTestTarget = ref(null);
const deleteTestCourseId = ref(null);

// Lessons & tests per course
const lessonsByCourse = reactive({});
const lessonsLoading = reactive({});
const testsByCourse = reactive({});
const testsLoading = reactive({});

const { debounced: debouncedLoad } = useDebounce(loadCourses, 400);

async function loadCourses() {
  loading.value = true;
  try {
    const params = { page: page.value, per_page: perPage.value };
    if (filters.value.search) params.search = filters.value.search;
    const { data } = await api.get('/admin/education/courses', { params });
    courses.value = data.data || data;
    total.value = data.total || courses.value.length;
  } catch {}
  loading.value = false;
}

async function loadProductOptions() {
  try {
    // Активные + опубликованные продукты, полный список (без 100-cap).
    const { data } = await api.get('/admin/education/product-options');
    productOptions.value = data.data || data;
  } catch {}
}

async function loadCategoryOptions() {
  try {
    const { data } = await api.get('/admin/education/categories', { params: { only_active: 1 } });
    categoryOptions.value = data.data || [];
  } catch {}
}

// Vuetify 3's v-data-table-server stores `expanded` as an array of
// item-value keys (here: id). Fire off lazy loads whenever a new id appears.
function onExpandedChange(newExpanded) {
  for (const id of newExpanded) {
    if (!(id in activeTab)) activeTab[id] = 'lessons';
    if (!(id in lessonsByCourse)) loadLessons(id);
    if (!(id in testsByCourse)) loadTests(id);
  }
}

function openCourseTab(item, tab) {
  if (!expanded.value.includes(item.id)) {
    expanded.value.push(item.id);
    loadLessons(item.id);
    loadTests(item.id);
  }
  activeTab[item.id] = tab;
}

// Expand the row with the given id. Used after creating a course so
// the admin immediately lands on the Уроки tab without hunting the row.
function expandCourseById(id) {
  if (!expanded.value.includes(id)) {
    expanded.value.push(id);
    activeTab[id] = 'lessons';
    loadLessons(id);
    loadTests(id);
  }
}

async function loadLessons(courseId) {
  lessonsLoading[courseId] = true;
  try {
    const { data } = await api.get(`/admin/education/courses/${courseId}/lessons`);
    lessonsByCourse[courseId] = data.data || data;
  } catch {}
  lessonsLoading[courseId] = false;
}

async function loadTests(courseId) {
  testsLoading[courseId] = true;
  try {
    const { data } = await api.get(`/admin/education/courses/${courseId}/tests`);
    testsByCourse[courseId] = data.data || data;
  } catch {}
  testsLoading[courseId] = false;
}

// --- Reorder тест-вопросов через DnD/стрелки. ---
// Optimistic UI: сразу подменяем массив, шлём reorder, при ошибке —
// rollback и снэк. Поддерживает несколько одновременно открытых курсов
// (dragTestId хранит id перетаскиваемого, source-курс известен из
// dragSourceCourseId — drop за пределы своего курса игнорируем).
const dragTestId = ref(null);
const dragSourceCourseId = ref(null);
const reorderingCourseId = ref(null);

function onTestDragStart(ev, courseId, testId) {
  if (!canEdit('education')) return;
  dragTestId.value = testId;
  dragSourceCourseId.value = courseId;
  try { ev.dataTransfer?.setData('text/plain', String(testId)); } catch {}
  if (ev.dataTransfer) ev.dataTransfer.effectAllowed = 'move';
}
function onTestDragOver(ev, courseId) {
  if (!dragTestId.value || dragSourceCourseId.value !== courseId) return;
  if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
}
function onTestDrop(courseId, targetTestId) {
  if (!dragTestId.value || dragSourceCourseId.value !== courseId) return;
  if (dragTestId.value === targetTestId) return;
  const list = [...(testsByCourse[courseId] || [])];
  const from = list.findIndex(t => t.id === dragTestId.value);
  const to = list.findIndex(t => t.id === targetTestId);
  if (from < 0 || to < 0) return;
  list.splice(to, 0, list.splice(from, 1)[0]);
  applyTestReorder(courseId, list);
  dragTestId.value = null;
  dragSourceCourseId.value = null;
}
function onTestDragEnd() {
  dragTestId.value = null;
  dragSourceCourseId.value = null;
}

function moveTest(courseId, idx, delta) {
  const cur = testsByCourse[courseId] || [];
  const newIdx = idx + delta;
  if (newIdx < 0 || newIdx >= cur.length) return;
  const list = [...cur];
  [list[idx], list[newIdx]] = [list[newIdx], list[idx]];
  applyTestReorder(courseId, list);
}

async function applyTestReorder(courseId, newList) {
  const previous = testsByCourse[courseId];
  testsByCourse[courseId] = newList;
  reorderingCourseId.value = courseId;
  try {
    await api.post(`/admin/education/courses/${courseId}/tests/reorder`, {
      ids: newList.map(t => t.id),
    });
    showSuccess('Порядок сохранён');
  } catch (e) {
    testsByCourse[courseId] = previous;
    showError(e.response?.data?.message || 'Не удалось сохранить порядок');
  }
  reorderingCourseId.value = null;
}

// Course CRUD
function openCreateCourse() {
  editCourse.value = { title: '', description: '', product_ids: [], category_id: null, active: true, sort_order: 0 };
  courseError.value = '';
  courseDialog.value = true;
}

function openEditCourse(course) {
  editCourse.value = { ...course };
  courseError.value = '';
  courseDialog.value = true;
}

async function saveCourse() {
  if (!editCourse.value.title) {
    courseError.value = 'Название обязательно';
    return;
  }
  saving.value = true;
  courseError.value = '';
  const wasNew = !editCourse.value.id;
  try {
    let createdId = null;
    if (editCourse.value.id) {
      await api.put(`/admin/education/courses/${editCourse.value.id}`, editCourse.value);
    } else {
      const { data } = await api.post('/admin/education/courses', editCourse.value);
      createdId = data.id;
    }
    courseDialog.value = false;
    await loadCourses();
    if (wasNew && createdId) expandCourseById(createdId);
  } catch (e) {
    courseError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDeleteCourse(course) {
  deleteCourseTarget.value = course;
  deleteCourseDialog.value = true;
}

async function deleteCourse() {
  saving.value = true;
  try {
    await api.delete(`/admin/education/courses/${deleteCourseTarget.value.id}`);
    deleteCourseDialog.value = false;
    loadCourses();
  } catch {}
  saving.value = false;
}

// Lesson CRUD
function openCreateLesson(course) {
  editLessonCourseId.value = course.id;
  editLesson.value = {
    title: '', content: '',
    video_urls: [], document_urls: [],
    sort_order: 0, active: true,
  };
  lessonError.value = '';
  lessonDialog.value = true;
}

/**
 * Нормализуем поле video_urls/document_urls к [{url, label}].
 * Бэк может прислать:
 *   - массив объектов [{url, label}] (актуальный формат)
 *   - массив строк ["http://..."] (старый формат до лейблов)
 *   - single video_url/document_url в legacy-поле
 */
function normalizeUrlList(arr, legacySingle) {
  if (Array.isArray(arr) && arr.length) {
    return arr.map(item => typeof item === 'string'
      ? { url: item, label: '' }
      : { url: item?.url ?? '', label: item?.label ?? '' });
  }
  return legacySingle ? [{ url: legacySingle, label: '' }] : [];
}

function openEditLesson(course, lesson) {
  editLessonCourseId.value = course.id;
  editLesson.value = {
    ...lesson,
    video_urls: normalizeUrlList(lesson.video_urls, lesson.video_url),
    document_urls: normalizeUrlList(lesson.document_urls, lesson.document_url),
  };
  lessonError.value = '';
  lessonDialog.value = true;
}

function addVideoUrl() {
  if (!Array.isArray(editLesson.value.video_urls)) editLesson.value.video_urls = [];
  editLesson.value.video_urls.push({ url: '', label: '' });
}
function addDocumentUrl() {
  if (!Array.isArray(editLesson.value.document_urls)) editLesson.value.document_urls = [];
  editLesson.value.document_urls.push({ url: '', label: '' });
}

async function saveLesson() {
  if (!editLesson.value.title) {
    lessonError.value = 'Название обязательно';
    return;
  }
  saving.value = true;
  lessonError.value = '';
  const courseId = editLessonCourseId.value;
  // Готовим payload: выкидываем пары с пустым URL, тримим, отправляем
  // как [{url, label}]. Это и есть актуальный формат бэка.
  const cleanList = (arr) => (Array.isArray(arr) ? arr : [])
    .map(i => ({
      url: String(i?.url ?? '').trim(),
      label: String(i?.label ?? '').trim() || null,
    }))
    .filter(i => i.url);
  const payload = {
    ...editLesson.value,
    video_urls: cleanList(editLesson.value.video_urls),
    document_urls: cleanList(editLesson.value.document_urls),
  };
  try {
    if (editLesson.value.id) {
      await api.put(`/admin/education/courses/${courseId}/lessons/${editLesson.value.id}`, payload);
    } else {
      await api.post(`/admin/education/courses/${courseId}/lessons`, payload);
    }
    lessonDialog.value = false;
    loadLessons(courseId);
    loadCourses();
  } catch (e) {
    lessonError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDeleteLesson(course, lesson) {
  deleteLessonCourseId.value = course.id;
  deleteLessonTarget.value = lesson;
  deleteLessonDialog.value = true;
}

async function deleteLesson() {
  saving.value = true;
  const courseId = deleteLessonCourseId.value;
  try {
    await api.delete(`/admin/education/courses/${courseId}/lessons/${deleteLessonTarget.value.id}`);
    deleteLessonDialog.value = false;
    loadLessons(courseId);
    loadCourses();
  } catch {}
  saving.value = false;
}

// Test CRUD
function openCreateTest(course) {
  editTestCourseId.value = course.id;
  editTest.value = { question: '', answers: ['', ''], correct_answer: 0, sort_order: 0 };
  testError.value = '';
  testDialog.value = true;
}

function openEditTest(course, test) {
  editTestCourseId.value = course.id;
  editTest.value = { ...test, answers: [...(test.answers || [])] };
  testError.value = '';
  testDialog.value = true;
}

function addAnswer() {
  editTest.value.answers.push('');
}

function removeAnswer(idx) {
  editTest.value.answers.splice(idx, 1);
  if (editTest.value.correct_answer >= editTest.value.answers.length) {
    editTest.value.correct_answer = editTest.value.answers.length - 1;
  }
}

async function saveTest() {
  if (!editTest.value.question) {
    testError.value = 'Вопрос обязателен';
    return;
  }
  if (editTest.value.answers.some(a => !a.trim())) {
    testError.value = 'Все варианты ответов должны быть заполнены';
    return;
  }
  saving.value = true;
  testError.value = '';
  const courseId = editTestCourseId.value;
  try {
    if (editTest.value.id) {
      await api.put(`/admin/education/courses/${courseId}/tests/${editTest.value.id}`, editTest.value);
    } else {
      await api.post(`/admin/education/courses/${courseId}/tests`, editTest.value);
    }
    testDialog.value = false;
    loadTests(courseId);
    loadCourses();
  } catch (e) {
    testError.value = e.response?.data?.message || 'Ошибка сохранения';
  }
  saving.value = false;
}

function confirmDeleteTest(course, test) {
  deleteTestCourseId.value = course.id;
  deleteTestTarget.value = test;
  deleteTestDialog.value = true;
}

async function deleteTest() {
  saving.value = true;
  const courseId = deleteTestCourseId.value;
  try {
    await api.delete(`/admin/education/courses/${courseId}/tests/${deleteTestTarget.value.id}`);
    deleteTestDialog.value = false;
    loadTests(courseId);
    loadCourses();
  } catch {}
  saving.value = false;
}

onMounted(() => {
  loadCourses();
  loadProductOptions();
  loadCategoryOptions();
});
</script>

<style scoped>
/* Expanded row body — theme-aware surface so it works in both light
   admin and the forced-dark admin layout. Previously hardcoded to
   bg-grey-lighten-5 which looked off in dark mode. */
.expanded-row-body {
  background: rgba(var(--v-theme-on-surface), 0.04) !important;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.answer-row {
  transition: background-color 0.2s ease;
}
.answer-row:hover {
  background: rgba(0, 0, 0, 0.03);
}
.answer-correct {
  background: rgba(var(--v-theme-success), 0.12);
  outline: 1px solid rgba(var(--v-theme-success), 0.35);
}
.v-theme--dark .answer-row:hover {
  background: rgba(255, 255, 255, 0.04);
}
.v-theme--dark .answer-correct {
  background: rgba(var(--v-theme-success), 0.18);
}

/* DnD-перетаскивание вопросов теста. */
.tests-list .test-row[draggable="true"] {
  cursor: grab;
}
.tests-list .test-row[draggable="true"]:active {
  cursor: grabbing;
}
.tests-list .test-row--drag {
  opacity: 0.4;
}
.tests-list .test-row--saving {
  pointer-events: none;
  opacity: 0.7;
}
.tests-list .test-drag-handle {
  opacity: 0.45;
}
</style>
