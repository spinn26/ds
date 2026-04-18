<template>
  <div>
    <PageHeader title="Обучение" icon="mdi-school">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateCourse">Добавить курс</v-btn>
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
        :headers="courseHeaders"
        :items="courses"
        :items-length="total"
        :loading="loading"
        :items-per-page="25"
        :expanded="expanded"
        show-expand
        @update:page="page = $event; loadCourses()"
        @click:row="(e, { item }) => toggleExpand(item)"
        no-data-text="Курсы не найдены"
      >
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
          <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click.stop="confirmDeleteCourse(item)" />
        </template>

        <!-- Expanded row: Lessons & Tests tabs -->
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="pa-4 bg-grey-lighten-5">
              <v-tabs v-model="activeTab[item.id]" density="compact" class="mb-3">
                <v-tab :value="'lessons'" prepend-icon="mdi-book-open-variant">Уроки</v-tab>
                <v-tab :value="'tests'" prepend-icon="mdi-help-circle-outline">Тесты</v-tab>
              </v-tabs>

              <!-- Lessons Tab -->
              <div v-if="(activeTab[item.id] || 'lessons') === 'lessons'">
                <div class="d-flex justify-space-between align-center mb-2">
                  <span class="text-subtitle-2 font-weight-bold">Уроки курса «{{ item.title }}»</span>
                  <v-btn size="small" color="primary" prepend-icon="mdi-plus" variant="tonal"
                    @click="openCreateLesson(item)">Добавить урок</v-btn>
                </div>
                <v-data-table
                  :headers="lessonHeaders"
                  :items="lessonsByCourse[item.id] || []"
                  :loading="lessonsLoading[item.id]"
                  density="compact"
                  hover
                  no-data-text="Нет уроков"
                  hide-default-footer
                >
                  <template #item.content_type="{ item: lesson }">
                    <v-chip size="x-small" :color="contentTypeColor(lesson.content_type)">
                      {{ contentTypeLabel(lesson.content_type) }}
                    </v-chip>
                  </template>
                  <template #item.active="{ item: lesson }">
                    <v-chip :color="lesson.active ? 'success' : 'grey'" size="x-small">
                      {{ lesson.active ? 'Активен' : 'Неактивен' }}
                    </v-chip>
                  </template>
                  <template #item.actions="{ item: lesson }">
                    <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEditLesson(item, lesson)" />
                    <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteLesson(item, lesson)" />
                  </template>
                </v-data-table>
              </div>

              <!-- Tests Tab -->
              <div v-if="activeTab[item.id] === 'tests'">
                <div class="d-flex justify-space-between align-center mb-2">
                  <span class="text-subtitle-2 font-weight-bold">Тесты курса «{{ item.title }}»</span>
                  <v-btn size="small" color="primary" prepend-icon="mdi-plus" variant="tonal"
                    @click="openCreateTest(item)">Добавить вопрос</v-btn>
                </div>
                <v-data-table
                  :headers="testHeaders"
                  :items="testsByCourse[item.id] || []"
                  :loading="testsLoading[item.id]"
                  density="compact"
                  hover
                  no-data-text="Нет вопросов"
                  hide-default-footer
                >
                  <template #item.answersCount="{ item: test }">
                    {{ (test.answers || []).length }}
                  </template>
                  <template #item.correct_answer="{ item: test }">
                    <v-chip size="x-small" color="success">{{ test.correct_answer + 1 }}</v-chip>
                  </template>
                  <template #item.actions="{ item: test }">
                    <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openEditTest(item, test)" />
                    <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteTest(item, test)" />
                  </template>
                </v-data-table>
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
              <v-select v-model="editCourse.product_id" label="Продукт" :items="productOptions"
                item-title="name" item-value="id" clearable />
            </v-col>
            <v-col cols="12" sm="3">
              <v-text-field v-model.number="editCourse.sort_order" label="Сортировка" type="number" />
            </v-col>
            <v-col cols="12" sm="3">
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
              <v-textarea v-model="editLesson.content" label="Содержание" rows="5" auto-grow />
            </v-col>
            <v-col cols="12" sm="4">
              <v-select v-model="editLesson.content_type" label="Тип контента"
                :items="contentTypeOptions" />
            </v-col>
            <v-col cols="12" sm="8">
              <v-text-field v-model="editLesson.video_url" label="URL видео" prepend-inner-icon="mdi-video" />
            </v-col>
            <v-col cols="12" sm="8">
              <v-text-field v-model="editLesson.document_url" label="URL документа" prepend-inner-icon="mdi-file-document" />
            </v-col>
            <v-col cols="12" sm="2">
              <v-text-field v-model.number="editLesson.sort_order" label="Сортировка" type="number" />
            </v-col>
            <v-col cols="12" sm="2">
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
              <div class="text-subtitle-2 font-weight-bold mb-2">Варианты ответов</div>
              <v-list density="compact">
                <v-list-item v-for="(answer, idx) in editTest.answers" :key="idx" class="px-0">
                  <template #prepend>
                    <v-radio-group v-model="editTest.correct_answer" inline hide-details class="mt-0 pt-0">
                      <v-radio :value="idx" />
                    </v-radio-group>
                  </template>
                  <v-text-field v-model="editTest.answers[idx]" :label="'Вариант ' + (idx + 1)"
                    density="compact" hide-details />
                  <template #append>
                    <v-btn icon="mdi-close" size="x-small" variant="text" color="error"
                      :disabled="editTest.answers.length <= 2"
                      @click="removeAnswer(idx)" />
                  </template>
                </v-list-item>
              </v-list>
              <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" class="mt-2"
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
import { ref, reactive, onMounted } from 'vue';
import api from '../../api';
import { useDebounce } from '../../composables/useDebounce';
import PageHeader from '../../components/PageHeader.vue';

const loading = ref(false);
const saving = ref(false);
const courses = ref([]);
const total = ref(0);
const page = ref(1);
const expanded = ref([]);
const activeTab = reactive({});
const productOptions = ref([]);

const filters = ref({ search: '' });

const courseHeaders = [
  { title: 'Название', key: 'title' },
  { title: 'Продукт', key: 'productName', width: 180 },
  { title: 'Статус', key: 'active', width: 120 },
  { title: 'Уроков', key: 'lessonCount', width: 90 },
  { title: 'Тестов', key: 'testCount', width: 90 },
  { title: 'Сортировка', key: 'sort_order', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const lessonHeaders = [
  { title: 'Название', key: 'title' },
  { title: 'Тип', key: 'content_type', width: 100 },
  { title: 'URL видео', key: 'video_url', width: 200 },
  { title: 'Статус', key: 'active', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const testHeaders = [
  { title: 'Вопрос', key: 'question' },
  { title: 'Вариантов', key: 'answersCount', width: 110 },
  { title: 'Правильный', key: 'correct_answer', width: 110 },
  { title: 'Действия', key: 'actions', sortable: false, width: 100 },
];

const contentTypeOptions = [
  { title: 'Текст', value: 'text' },
  { title: 'Видео', value: 'video' },
  { title: 'Аудио', value: 'audio' },
];

function contentTypeColor(type) {
  return type === 'video' ? 'blue' : type === 'audio' ? 'purple' : 'grey';
}

function contentTypeLabel(type) {
  return type === 'video' ? 'Видео' : type === 'audio' ? 'Аудио' : 'Текст';
}

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
    const params = { page: page.value };
    if (filters.value.search) params.search = filters.value.search;
    const { data } = await api.get('/admin/education/courses', { params });
    courses.value = data.data || data;
    total.value = data.total || courses.value.length;
  } catch {}
  loading.value = false;
}

async function loadProductOptions() {
  try {
    const { data } = await api.get('/admin/products', { params: { active: 'true', per_page: 100 } });
    productOptions.value = data.data || data;
  } catch {}
}

function toggleExpand(item) {
  const idx = expanded.value.findIndex(e => e === item);
  if (idx >= 0) {
    expanded.value.splice(idx, 1);
  } else {
    expanded.value.push(item);
    activeTab[item.id] = 'lessons';
    loadLessons(item.id);
    loadTests(item.id);
  }
}

function openCourseTab(item, tab) {
  const existing = expanded.value.find(e => e === item);
  if (!existing) {
    expanded.value.push(item);
    loadLessons(item.id);
    loadTests(item.id);
  }
  activeTab[item.id] = tab;
}

// Expand the row matching the given id. Used after creating a course so
// the user sees the Уроки/Тесты tabs right away without hunting the row.
function expandCourseById(id) {
  const item = courses.value.find(c => c.id === id);
  if (!item) return;
  if (!expanded.value.find(e => e === item)) {
    expanded.value.push(item);
    activeTab[item.id] = 'lessons';
    loadLessons(item.id);
    loadTests(item.id);
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

// Course CRUD
function openCreateCourse() {
  editCourse.value = { title: '', description: '', product_id: null, active: true, sort_order: 0 };
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
  editLesson.value = { title: '', content: '', content_type: 'text', video_url: '', document_url: '', sort_order: 0, active: true };
  lessonError.value = '';
  lessonDialog.value = true;
}

function openEditLesson(course, lesson) {
  editLessonCourseId.value = course.id;
  editLesson.value = { ...lesson };
  lessonError.value = '';
  lessonDialog.value = true;
}

async function saveLesson() {
  if (!editLesson.value.title) {
    lessonError.value = 'Название обязательно';
    return;
  }
  saving.value = true;
  lessonError.value = '';
  const courseId = editLessonCourseId.value;
  try {
    if (editLesson.value.id) {
      await api.put(`/admin/education/courses/${courseId}/lessons/${editLesson.value.id}`, editLesson.value);
    } else {
      await api.post(`/admin/education/courses/${courseId}/lessons`, editLesson.value);
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
});
</script>
