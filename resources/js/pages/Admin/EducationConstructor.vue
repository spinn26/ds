<template>
  <div class="constructor-page">
    <PageHeader title="Конструктор обучения" icon="mdi-school-outline">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="addRoot">
          Добавить курс
        </v-btn>
      </template>
    </PageHeader>

    <div class="constructor-layout">
      <!-- Дерево курсов слева -->
      <aside class="tree-pane">
        <div class="tree-header">
          <span class="text-caption text-uppercase font-weight-bold letter-spacing-1 text-medium-emphasis">
            Структура
          </span>
        </div>
        <div v-if="loadingTree" class="d-flex justify-center pa-4">
          <v-progress-circular indeterminate size="20" />
        </div>
        <EmptyState
          v-else-if="!tree.length"
          icon="mdi-folder-plus-outline"
          title="Курсов пока нет"
          description="Создайте первый курс"
        />
        <div v-else class="tree-body">
          <ConstructorTreeNode
            v-for="node in tree"
            :key="node.id"
            :node="node"
            :selected-id="selectedId"
            :level="1"
            @select="selectNode"
            @add-child="addChild"
            @add-lesson="addLesson"
            @delete="confirmDelete"
            @move-up="moveCourse(node, -1)"
            @move-down="moveCourse(node, 1)"
            @drop-node="handleDrop"
          />
        </div>
      </aside>

      <!-- Редактор справа -->
      <section class="editor-pane">
        <EmptyState
          v-if="!selectedId"
          icon="mdi-cursor-pointer"
          title="Выберите узел слева"
          description="Кликните на курс или урок чтобы редактировать"
        />

        <!-- Редактор курса/модуля -->
        <div v-else-if="selectedType === 'course'" class="editor-content">
          <div v-if="savingCourse || loadingCourse" class="d-flex justify-center pa-4">
            <v-progress-circular indeterminate size="20" />
          </div>
          <template v-else-if="currentCourse">
            <v-form @submit.prevent="saveCourse">
              <div class="text-subtitle-2 font-weight-bold text-uppercase letter-spacing-1 text-medium-emphasis mb-3">
                {{ currentCourse.parent_id ? 'Подкурс / модуль' : 'Курс верхнего уровня' }}
              </div>

              <v-text-field
                v-model="currentCourse.title"
                label="Название *"
                variant="outlined" density="comfortable"
                required
              />
              <v-textarea
                v-model="currentCourse.description"
                label="Описание"
                variant="outlined" density="comfortable"
                rows="3" auto-grow
              />
              <v-text-field
                v-model="currentCourse.cover_url"
                label="URL обложки (опционально)"
                variant="outlined" density="comfortable"
                prepend-inner-icon="mdi-image-outline"
              />
              <v-row dense>
                <v-col cols="12" sm="6">
                  <v-autocomplete
                    v-model="currentCourse.product_id"
                    :items="productOptions"
                    item-title="name"
                    item-value="id"
                    label="Продукт для разблокировки"
                    variant="outlined" density="comfortable"
                    clearable
                    :loading="loadingProducts"
                    prepend-inner-icon="mdi-package-variant"
                    hint="После сдачи теста (100% правильных) откроется этот продукт"
                    persistent-hint
                  />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field
                    v-model.number="currentCourse.sort_order"
                    label="Порядок"
                    variant="outlined" density="comfortable" type="number"
                  />
                </v-col>
              </v-row>
              <v-switch
                v-model="currentCourse.is_container"
                label="Это модуль/папка (без своих уроков, только подкурсы)"
                color="primary" hide-details
                class="mt-2"
              />

              <div class="d-flex ga-2 mt-4">
                <v-btn color="primary" type="submit" :loading="savingCourse">
                  Сохранить
                </v-btn>
                <v-btn variant="text" @click="loadCourseToEdit(selectedId)">
                  Отмена
                </v-btn>
                <v-spacer />
                <v-btn color="error" variant="text" prepend-icon="mdi-delete"
                  @click="confirmDelete(currentCourse)">
                  Удалить
                </v-btn>
              </div>
            </v-form>

            <!-- Уроки курса (если не container) -->
            <div v-if="!currentCourse.is_container" class="lessons-section mt-6">
              <div class="d-flex align-center flex-wrap ga-2 mb-3">
                <span class="text-subtitle-1 font-weight-bold">
                  Уроки в этом курсе ({{ courseLessons.length }})
                </span>
                <v-spacer />
                <v-btn size="small" color="primary" variant="tonal"
                  prepend-icon="mdi-plus" @click="addLesson(currentCourse)">
                  Добавить урок
                </v-btn>
                <v-btn size="small" color="secondary" variant="tonal"
                  prepend-icon="mdi-help-circle-outline" @click="addLesson(currentCourse, true)">
                  Создать урок-тест
                </v-btn>
              </div>

              <v-list v-if="courseLessons.length" density="compact">
                <v-list-item
                  v-for="l in courseLessons"
                  :key="l.id"
                  :title="l.title"
                  :subtitle="l.is_test
                    ? `Урок-тест · sort ${l.sort_order || 0}`
                    : `${blockCount(l)} блоков · sort ${l.sort_order || 0}`"
                  :prepend-icon="l.is_test ? 'mdi-help-circle-outline' : 'mdi-text-box-outline'"
                  @click="selectLesson(l)"
                >
                  <template #append>
                    <v-btn
                      icon="mdi-delete-outline" size="small" variant="text" color="error"
                      @click.stop="deleteLesson(l)"
                    />
                  </template>
                </v-list-item>
              </v-list>
              <div v-else class="text-caption text-medium-emphasis pa-3 text-center">
                В этом курсе пока нет уроков
              </div>
            </div>

            <!-- Тесты курса -->
            <div v-if="!currentCourse.is_container" class="tests-section mt-6">
              <div class="d-flex align-center mb-3">
                <span class="text-subtitle-1 font-weight-bold">
                  Тест ({{ courseTests.length }} вопросов)
                </span>
                <v-spacer />
                <v-btn size="small" color="primary" variant="tonal"
                  prepend-icon="mdi-plus" @click="addTest">
                  Добавить вопрос
                </v-btn>
              </div>

              <v-alert
                v-if="!currentCourse.product_id"
                type="info" variant="tonal" density="compact" class="mb-3"
                icon="mdi-information-outline"
              >
                Чтобы после сдачи теста автоматически открывался продукт,
                выберите его в поле «Продукт для разблокировки» выше.
                Можно добавлять вопросы и без продукта.
              </v-alert>

              <v-list v-if="courseTests.length" density="compact">
                <v-list-item
                  v-for="(t, i) in courseTests"
                  :key="t.id"
                  :title="`${i + 1}. ${t.question}`"
                  :subtitle="`${t.answers?.length || 0} вариантов, правильный: ${t.correct_answer + 1}`"
                  prepend-icon="mdi-help-circle-outline"
                  @click="editTest(t)"
                >
                  <template #append>
                    <v-btn icon="mdi-delete-outline" size="small" variant="text" color="error"
                      @click.stop="deleteTest(t)" />
                  </template>
                </v-list-item>
              </v-list>
              <div v-else class="text-caption text-medium-emphasis pa-3 text-center">
                Вопросов пока нет. Сдача теста = 100% правильных открывает продукт.
              </div>
            </div>
          </template>
        </div>

        <!-- Редактор урока (с блоками) -->
        <div v-else-if="selectedType === 'lesson'" class="editor-content">
          <LessonBodyEditor
            v-if="currentLesson"
            :lesson="currentLesson"
            :course-id="currentLessonCourseId"
            :saving="savingLesson"
            @save="saveLesson"
            @cancel="clearSelection"
            @delete="deleteLesson(currentLesson)"
          />
        </div>
      </section>
    </div>

    <!-- Диалог редактирования теста -->
    <v-dialog v-model="testDialog" max-width="640" persistent>
      <v-card v-if="editingTest">
        <v-card-title>
          {{ editingTest.id ? 'Редактирование вопроса' : 'Новый вопрос' }}
        </v-card-title>
        <v-card-text>
          <v-textarea
            v-model="editingTest.question"
            label="Вопрос *"
            variant="outlined" density="comfortable"
            rows="2" auto-grow
          />
          <div class="text-caption text-medium-emphasis mb-2">
            4 варианта ответа · отметьте правильный
          </div>
          <div v-for="(a, i) in editingTest.answers" :key="i" class="d-flex align-center ga-2 mb-2">
            <v-radio-group
              v-model="editingTest.correct_answer"
              hide-details density="compact"
              class="flex-shrink-0"
              style="margin-top:0"
            >
              <v-radio :value="i" :label="''" color="primary" />
            </v-radio-group>
            <v-text-field
              v-model="editingTest.answers[i]"
              :placeholder="`Вариант ${i + 1}`"
              variant="outlined" density="compact" hide-details
            />
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="testDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingTest" @click="saveTest">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог удаления -->
    <v-dialog v-model="deleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить?</v-card-title>
        <v-card-text>
          <strong>{{ deleteTarget?.title }}</strong><br>
          <span v-if="deleteTarget?.children?.length || hasChildrenWarning">
            ⚠️ В этом узле есть вложенные курсы/уроки — удалятся каскадно.
          </span>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="deleting" @click="performDelete">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import ConstructorTreeNode from '../../components/education/ConstructorTreeNode.vue';
import LessonBodyEditor from '../../components/education/LessonBodyEditor.vue';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();

const tree = ref([]);
const loadingTree = ref(true);

const productOptions = ref([]);
const loadingProducts = ref(false);

async function loadProducts() {
  loadingProducts.value = true;
  try {
    const { data } = await api.get('/admin/products', { params: { per_page: 500 } });
    productOptions.value = (data.data || data || []).map(p => ({ id: p.id, name: p.name }));
  } catch (e) { /* тихо — поле останется пустым */ }
  loadingProducts.value = false;
}

const selectedId = ref(null);
const selectedType = ref(null);   // 'course' | 'lesson'
const currentCourse = ref(null);
const currentLesson = ref(null);
const currentLessonCourseId = ref(null);
const courseLessons = ref([]);
const courseTests = ref([]);

const loadingCourse = ref(false);
const savingCourse = ref(false);
const savingLesson = ref(false);
const savingTest = ref(false);

const testDialog = ref(false);
const editingTest = ref(null);
const deleteDialog = ref(false);
const deleteTarget = ref(null);
const deleteTargetType = ref(null);
const deleting = ref(false);
const hasChildrenWarning = ref(false);

function blockCount(l) {
  if (Array.isArray(l.body)) return l.body.length;
  return 0;
}

async function loadTree() {
  loadingTree.value = true;
  try {
    const { data } = await api.get('/education/tree');
    tree.value = data.tree || [];
  } catch (e) { showError('Не удалось загрузить дерево'); }
  loadingTree.value = false;
}

function findInTree(nodes, id) {
  for (const n of nodes || []) {
    if (n.id === id) return n;
    const sub = findInTree(n.children, id);
    if (sub) return sub;
  }
  return null;
}

async function selectNode(node) {
  selectedId.value = node.id;
  selectedType.value = 'course';
  currentLesson.value = null;
  await loadCourseToEdit(node.id);
}

async function loadCourseToEdit(id) {
  loadingCourse.value = true;
  try {
    const { data } = await api.get(`/education/courses/${id}/full`);
    currentCourse.value = {
      id: data.id, title: data.title, description: data.description,
      parent_id: data.parent_id, product_id: data.productId,
      cover_url: data.coverUrl, is_container: data.isContainer,
      sort_order: findInTree(tree.value, id)?.sortOrder || 0,
    };
    courseLessons.value = data.lessons || [];
    // Тесты
    if (currentCourse.value.product_id) {
      const { data: t } = await api.get(`/admin/education/courses/${id}/tests`);
      courseTests.value = (t || []).map(x => ({
        id: x.id, question: x.question,
        answers: Array.isArray(x.answers) && x.answers.length === 4
          ? x.answers : ['', '', '', ''],
        correct_answer: x.correct_answer ?? 0,
      }));
    } else {
      courseTests.value = [];
    }
  } catch (e) { showError('Курс не найден'); }
  loadingCourse.value = false;
}

async function saveCourse() {
  if (!currentCourse.value) return;
  savingCourse.value = true;
  try {
    await api.put(`/admin/education/courses/${currentCourse.value.id}`, {
      title: currentCourse.value.title,
      description: currentCourse.value.description,
      product_id: currentCourse.value.product_id || null,
      cover_url: currentCourse.value.cover_url || null,
      parent_id: currentCourse.value.parent_id || null,
      is_container: currentCourse.value.is_container,
      sort_order: currentCourse.value.sort_order || 0,
      active: true,
    });
    showSuccess('Сохранено');
    await loadTree();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  savingCourse.value = false;
}

async function addRoot() {
  await createCourse({ parent_id: null, title: 'Новый курс' });
}
async function addChild(parent) {
  await createCourse({
    parent_id: parent.id,
    title: 'Новый модуль',
    is_container: !!(parent.children?.length || parent.isContainer),
  });
}
async function createCourse(extra) {
  try {
    const { data } = await api.post('/admin/education/courses', {
      title: extra.title,
      parent_id: extra.parent_id,
      is_container: extra.is_container ?? false,
      sort_order: 0,
      active: true,
    });
    showSuccess('Создано');
    await loadTree();
    selectedId.value = data.id;
    selectedType.value = 'course';
    await loadCourseToEdit(data.id);
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
}

/**
 * Drag-and-drop: dragged-узел переносится к target:
 *   position='before' → стать sibling'ом до target
 *   position='after'  → sibling'ом после target
 *   position='into'   → стать первым потомком target
 *
 * Защита: бэк уже не пускает перемещение в собственное поддерево
 * (isDescendantOf). Здесь только определяем parent_id + sort_order.
 */
async function handleDrop({ draggedId, targetId, position }) {
  const target = findInTree(tree.value, targetId);
  if (!target) return;

  let newParentId, siblings, insertIdx;
  if (position === 'into') {
    newParentId = targetId;
    siblings = target.children || [];
    insertIdx = 0;
  } else {
    newParentId = target.parent_id || null;
    siblings = newParentId
      ? findInTree(tree.value, newParentId)?.children || []
      : tree.value;
    const tIdx = siblings.findIndex(s => s.id === targetId);
    insertIdx = position === 'before' ? tIdx : tIdx + 1;
  }

  try {
    // Шаг 1: переместить dragged.
    await api.post(`/admin/education/courses/${draggedId}/move`, {
      parent_id: newParentId,
      sort_order: insertIdx,
    });
    // Шаг 2: пере-нумеровать siblings (без dragged) — простой реиндекс
    // с шагом 10, чтобы потом было куда вставлять без коллизий. Делаем
    // последовательными запросами — drag-drop редкий, не критично по
    // производительности.
    const reindexList = siblings.filter(s => s.id !== draggedId);
    reindexList.splice(insertIdx, 0, { id: draggedId });
    for (let i = 0; i < reindexList.length; i++) {
      const s = reindexList[i];
      const desired = (i + 1) * 10;
      if (s.id === draggedId) continue; // уже поставлен выше
      await api.post(`/admin/education/courses/${s.id}/move`, {
        parent_id: newParentId, sort_order: desired,
      });
    }
    // dragged тоже выставим финально на свою позицию.
    await api.post(`/admin/education/courses/${draggedId}/move`, {
      parent_id: newParentId,
      sort_order: (insertIdx + 1) * 10 - 5,
    });
    showSuccess('Перемещено');
    await loadTree();
  } catch (e) {
    showError(e.response?.data?.message || 'Не удалось переместить');
  }
}

async function moveCourse(node, delta) {
  // Простое перемещение в пределах siblings — меняем sort_order у двух соседей.
  const siblings = node.parent_id
    ? findInTree(tree.value, node.parent_id)?.children || []
    : tree.value;
  const idx = siblings.findIndex(s => s.id === node.id);
  const newIdx = idx + delta;
  if (newIdx < 0 || newIdx >= siblings.length) return;
  const other = siblings[newIdx];
  try {
    await api.post(`/admin/education/courses/${node.id}/move`, {
      parent_id: node.parent_id, sort_order: other.sortOrder ?? newIdx,
    });
    await api.post(`/admin/education/courses/${other.id}/move`, {
      parent_id: other.parent_id, sort_order: node.sortOrder ?? idx,
    });
    await loadTree();
  } catch (e) { showError('Не удалось переместить'); }
}

async function addLesson(parent, isTest = false) {
  try {
    const { data } = await api.post(`/admin/education/courses/${parent.id}/lessons`, {
      title: isTest ? 'Тест по курсу' : 'Новый урок',
      sort_order: 0,
      active: true,
      is_test: !!isTest,
    });
    showSuccess(isTest ? 'Урок-тест создан' : 'Урок создан');
    selectedId.value = parent.id;
    selectedType.value = 'course';
    await loadCourseToEdit(parent.id);
    const lesson = courseLessons.value.find(l => l.id === data.id);
    if (lesson) selectLesson(lesson, parent.id);
  } catch (e) { showError('Не удалось создать урок'); }
}

function selectLesson(lesson, courseId) {
  currentLesson.value = { ...lesson, body: Array.isArray(lesson.body) ? lesson.body : [] };
  currentLessonCourseId.value = courseId || (currentCourse.value?.id);
  selectedType.value = 'lesson';
}

async function saveLesson(updated) {
  if (!currentLesson.value || !currentLessonCourseId.value) return;
  savingLesson.value = true;
  try {
    await api.put(
      `/admin/education/courses/${currentLessonCourseId.value}/lessons/${currentLesson.value.id}`,
      {
        title: updated.title,
        content: updated.content,
        body: updated.body,
        sort_order: updated.sort_order || 0,
        active: true,
        is_test: !!updated.is_test,
        // Drip + homework поля (миграция 2026_05_25_000020)
        drip_delay_hours: updated.drip_delay_hours || null,
        drip_open_at: updated.drip_open_at || null,
        is_stop_lesson: !!updated.is_stop_lesson,
        requires_homework: !!updated.requires_homework,
        homework_instructions: updated.homework_instructions || null,
      },
    );
    showSuccess('Сохранено');
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  savingLesson.value = false;
}

async function deleteLesson(lesson) {
  if (!confirm(`Удалить урок «${lesson.title}»?`)) return;
  const courseId = currentLessonCourseId.value || currentCourse.value?.id;
  try {
    await api.delete(`/admin/education/courses/${courseId}/lessons/${lesson.id}`);
    showSuccess('Удалено');
    if (currentLesson.value?.id === lesson.id) clearSelection();
    if (currentCourse.value) await loadCourseToEdit(currentCourse.value.id);
  } catch (e) { showError('Не удалось удалить'); }
}

function confirmDelete(node) {
  deleteTarget.value = node;
  deleteTargetType.value = 'course';
  hasChildrenWarning.value = !!(node.children?.length || courseLessons.value.length);
  deleteDialog.value = true;
}

async function performDelete() {
  if (!deleteTarget.value) return;
  deleting.value = true;
  try {
    await api.delete(`/admin/education/courses/${deleteTarget.value.id}`);
    showSuccess('Удалено');
    deleteDialog.value = false;
    clearSelection();
    await loadTree();
  } catch (e) { showError('Не удалось удалить'); }
  deleting.value = false;
}

function addTest() {
  editingTest.value = {
    id: null, question: '', answers: ['', '', '', ''], correct_answer: 0,
  };
  testDialog.value = true;
}
function editTest(t) {
  editingTest.value = JSON.parse(JSON.stringify(t));
  testDialog.value = true;
}
async function saveTest() {
  if (!editingTest.value || !currentCourse.value) return;
  savingTest.value = true;
  try {
    const url = editingTest.value.id
      ? `/admin/education/courses/${currentCourse.value.id}/tests/${editingTest.value.id}`
      : `/admin/education/courses/${currentCourse.value.id}/tests`;
    const method = editingTest.value.id ? 'put' : 'post';
    await api[method](url, {
      question: editingTest.value.question,
      answers: editingTest.value.answers,
      correct_answer: editingTest.value.correct_answer,
      sort_order: 0,
    });
    showSuccess('Сохранено');
    testDialog.value = false;
    await loadCourseToEdit(currentCourse.value.id);
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  savingTest.value = false;
}
async function deleteTest(t) {
  if (!confirm('Удалить вопрос?')) return;
  try {
    await api.delete(`/admin/education/courses/${currentCourse.value.id}/tests/${t.id}`);
    showSuccess('Удалено');
    await loadCourseToEdit(currentCourse.value.id);
  } catch (e) { showError('Не удалось удалить'); }
}

function clearSelection() {
  selectedId.value = null;
  selectedType.value = null;
  currentCourse.value = null;
  currentLesson.value = null;
}

onMounted(() => {
  loadTree();
  loadProducts();
});
</script>

<style scoped>
.constructor-page { display: flex; flex-direction: column; min-height: calc(100vh - 64px); }
.constructor-layout {
  display: grid;
  grid-template-columns: 320px 1fr;
  flex: 1;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
@media (max-width: 960px) {
  .constructor-layout { grid-template-columns: 1fr; }
  .tree-pane { border-right: none; border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
}

.tree-pane {
  background: rgba(var(--v-theme-on-surface), 0.02);
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  overflow-y: auto;
  max-height: calc(100vh - 124px);
}
.tree-header { padding: 14px 16px 6px; }
.tree-body { padding: 4px 8px 16px; }

.editor-pane {
  padding: 20px 28px 40px;
  overflow-y: auto;
  max-height: calc(100vh - 124px);
}
.editor-content { max-width: 800px; }

.letter-spacing-1 { letter-spacing: 1.2px; }
</style>
