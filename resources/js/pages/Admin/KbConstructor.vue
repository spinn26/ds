<template>
  <div class="kb-constructor-page">
    <PageHeader title="База знаний — конструктор" icon="mdi-book-open-variant">
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-eye" href="/education/kb" target="_blank">
          Просмотр как партнёр
        </v-btn>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="addRootSection">
          Раздел
        </v-btn>
      </template>
    </PageHeader>

    <div class="layout">
      <aside class="tree-pane">
        <div class="tree-header text-caption text-uppercase font-weight-bold letter-spacing-1 text-medium-emphasis">
          Разделы
        </div>
        <div v-if="loadingTree" class="d-flex justify-center pa-4">
          <v-progress-circular indeterminate size="20" />
        </div>
        <EmptyState
          v-else-if="!tree.length"
          icon="mdi-folder-plus-outline"
          title="Разделов нет"
          description="Создайте первый раздел"
        />
        <div v-else class="tree-body">
          <KbTreeNode
            v-for="node in tree"
            :key="node.id"
            :node="node"
            :selected-id="selectedSectionId"
            :level="1"
            @select="selectSection"
            @add-child="addChildSection"
            @delete="confirmDeleteSection"
            @drop-node="handleSectionDrop"
          />
        </div>
      </aside>

      <section class="editor-pane">
        <EmptyState
          v-if="!selectedSectionId && !currentArticle"
          icon="mdi-cursor-pointer"
          title="Выберите раздел слева"
          description="Или создайте новый материал внутри раздела"
        />

        <!-- Редактор раздела + список материалов -->
        <div v-else-if="selectedSectionId && !currentArticle" class="editor-content">
          <div class="text-subtitle-2 font-weight-bold text-uppercase letter-spacing-1 text-medium-emphasis mb-3">
            Раздел
          </div>
          <v-text-field v-model="section.title" label="Название *" variant="outlined" density="comfortable" />
          <v-text-field v-model="section.icon"
            label="Иконка MDI (например, mdi-bullhorn)"
            variant="outlined" density="comfortable"
            :prepend-inner-icon="section.icon || 'mdi-folder-outline'"
          />
          <v-textarea v-model="section.description"
            label="Описание раздела"
            variant="outlined" density="comfortable" rows="2" auto-grow
          />
          <div class="d-flex ga-2 mt-2">
            <v-btn color="primary" :loading="savingSection" @click="saveSection">
              Сохранить раздел
            </v-btn>
            <v-spacer />
            <v-btn color="error" variant="text" prepend-icon="mdi-delete"
              @click="confirmDeleteSection(section)">
              Удалить раздел
            </v-btn>
          </div>

          <!-- Список материалов -->
          <div class="d-flex align-center mt-6 mb-2">
            <span class="text-subtitle-1 font-weight-bold">
              Материалы ({{ articles.length }})
            </span>
            <v-spacer />
            <v-btn size="small" color="primary" variant="tonal"
              prepend-icon="mdi-plus" @click="addArticle">
              Новый материал
            </v-btn>
          </div>

          <v-list v-if="articles.length" density="compact">
            <v-list-item
              v-for="a in articles"
              :key="a.id"
              :title="a.title"
              :subtitle="`${a.body?.length || 0} блоков · ${a.tags?.length || 0} тегов`"
              prepend-icon="mdi-file-document-outline"
              @click="openArticle(a)"
            >
              <template #append>
                <v-chip v-if="!a.published" size="x-small" color="warning" variant="tonal" class="me-2">
                  черновик
                </v-chip>
                <v-btn icon="mdi-delete-outline" size="small" variant="text" color="error"
                  @click.stop="deleteArticle(a)" />
              </template>
            </v-list-item>
          </v-list>
          <div v-else class="text-caption text-medium-emphasis pa-3 text-center">
            В разделе пока нет материалов
          </div>
        </div>

        <!-- Редактор материала -->
        <div v-else-if="currentArticle" class="editor-content">
          <div class="d-flex align-center mb-2">
            <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="currentArticle = null">
              К разделу
            </v-btn>
            <v-spacer />
            <v-switch
              v-model="currentArticle.published"
              label="Опубликовано"
              color="primary" hide-details density="compact"
            />
          </div>

          <v-text-field v-model="currentArticle.title" label="Название *"
            variant="outlined" density="comfortable" />
          <v-textarea v-model="currentArticle.description" label="Короткое описание"
            variant="outlined" density="comfortable" rows="2" auto-grow />
          <v-combobox
            v-model="currentArticle.tags"
            label="Теги (для поиска)"
            chips multiple closable-chips
            variant="outlined" density="comfortable"
            hide-details placeholder="Введите тег и Enter"
          />

          <div class="text-subtitle-1 font-weight-bold mt-4 mb-2">
            Содержимое ({{ currentArticle.body.length }} блоков)
          </div>

          <LessonBodyEditor
            :lesson="currentArticle"
            :saving="savingArticle"
            @save="saveArticleFromEditor"
            @cancel="currentArticle = null"
            @delete="deleteArticle(currentArticle)"
          />
        </div>
      </section>
    </div>

    <!-- Диалог удаления -->
    <v-dialog v-model="deleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить раздел?</v-card-title>
        <v-card-text>
          <strong>{{ deleteTarget?.title }}</strong><br>
          Удалятся все подразделы и материалы внутри.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="deleting" @click="performDeleteSection">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api';
import PageHeader from '../../components/PageHeader.vue';
import EmptyState from '../../components/EmptyState.vue';
import KbTreeNode from '../../components/education/KbTreeNode.vue';
import LessonBodyEditor from '../../components/education/LessonBodyEditor.vue';
import { useSnackbar } from '../../composables/useSnackbar';

const { showSuccess, showError } = useSnackbar();

const tree = ref([]);
const loadingTree = ref(true);
const selectedSectionId = ref(null);
const section = ref({ id: null, title: '', icon: '', description: '' });
const articles = ref([]);
const currentArticle = ref(null);

const savingSection = ref(false);
const savingArticle = ref(false);
const deleting = ref(false);
const deleteDialog = ref(false);
const deleteTarget = ref(null);

function findInTree(nodes, id) {
  for (const n of nodes || []) {
    if (n.id === id) return n;
    const sub = findInTree(n.children, id);
    if (sub) return sub;
  }
  return null;
}

async function loadTree() {
  loadingTree.value = true;
  try {
    const { data } = await api.get('/admin/kb/tree');
    tree.value = data.tree || [];
  } catch (e) {
    if (e.response?.status === 503) {
      showError(e.response.data?.message || 'База знаний не настроена');
    } else { showError('Не удалось загрузить'); }
  }
  loadingTree.value = false;
}

async function selectSection(node) {
  selectedSectionId.value = node.id;
  currentArticle.value = null;
  section.value = {
    id: node.id, title: node.title, icon: node.icon || '',
    description: node.description || '', parent_id: node.parent_id,
    sort_order: node.sortOrder || 0,
  };
  await loadArticles(node.id);
}

async function loadArticles(sectionId) {
  try {
    const { data } = await api.get(`/admin/kb/sections/${sectionId}/articles`);
    articles.value = data.articles || [];
  } catch { articles.value = []; }
}

async function addRootSection() { await createSection(null, 'Новый раздел'); }
async function addChildSection(parent) { await createSection(parent.id, 'Подраздел'); }
async function createSection(parentId, title) {
  try {
    const { data } = await api.post('/admin/kb/sections', {
      title, parent_id: parentId, sort_order: 0,
    });
    await loadTree();
    const n = findInTree(tree.value, data.id);
    if (n) selectSection(n);
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
}

async function saveSection() {
  savingSection.value = true;
  try {
    await api.put(`/admin/kb/sections/${section.value.id}`, {
      title: section.value.title,
      icon: section.value.icon || null,
      description: section.value.description || null,
      parent_id: section.value.parent_id || null,
      sort_order: section.value.sort_order || 0,
    });
    showSuccess('Сохранено');
    await loadTree();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  savingSection.value = false;
}

function confirmDeleteSection(node) {
  deleteTarget.value = node;
  deleteDialog.value = true;
}
async function performDeleteSection() {
  if (!deleteTarget.value) return;
  deleting.value = true;
  try {
    await api.delete(`/admin/kb/sections/${deleteTarget.value.id}`);
    showSuccess('Удалено');
    deleteDialog.value = false;
    selectedSectionId.value = null;
    section.value = { id: null, title: '', icon: '', description: '' };
    currentArticle.value = null;
    await loadTree();
  } catch (e) { showError('Ошибка'); }
  deleting.value = false;
}

async function handleSectionDrop({ draggedId, targetId, position }) {
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
    await api.post(`/admin/kb/sections/${draggedId}/move`, {
      parent_id: newParentId, sort_order: insertIdx,
    });
    showSuccess('Перемещено');
    await loadTree();
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
}

async function addArticle() {
  if (!selectedSectionId.value) return;
  try {
    const { data } = await api.post('/admin/kb/articles', {
      section_id: selectedSectionId.value,
      title: 'Новый материал',
      published: false,
      sort_order: articles.value.length,
    });
    await loadArticles(selectedSectionId.value);
    const created = articles.value.find(a => a.id === data.id);
    if (created) openArticle(created);
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
}

function openArticle(a) {
  currentArticle.value = {
    id: a.id,
    sectionId: a.section_id || a.sectionId,
    title: a.title,
    content: a.description || '',   // LessonBodyEditor использует поле content
    description: a.description || '',
    body: Array.isArray(a.body) ? JSON.parse(JSON.stringify(a.body)) : [],
    tags: Array.isArray(a.tags) ? [...a.tags] : [],
    published: a.published !== false,
    sort_order: a.sortOrder || a.sort_order || 0,
  };
}

async function saveArticleFromEditor(updated) {
  // LessonBodyEditor возвращает { title, content, body, sort_order }
  if (!currentArticle.value) return;
  savingArticle.value = true;
  try {
    await api.put(`/admin/kb/articles/${currentArticle.value.id}`, {
      section_id: selectedSectionId.value,
      title: updated.title,
      description: updated.content || currentArticle.value.description || null,
      body: updated.body || [],
      tags: currentArticle.value.tags,
      published: currentArticle.value.published,
      sort_order: updated.sort_order || 0,
    });
    showSuccess('Сохранено');
    await loadArticles(selectedSectionId.value);
  } catch (e) { showError(e.response?.data?.message || 'Ошибка'); }
  savingArticle.value = false;
}

async function deleteArticle(a) {
  if (!confirm(`Удалить материал «${a.title}»?`)) return;
  try {
    await api.delete(`/admin/kb/articles/${a.id}`);
    showSuccess('Удалено');
    if (currentArticle.value?.id === a.id) currentArticle.value = null;
    await loadArticles(selectedSectionId.value);
  } catch (e) { showError('Ошибка'); }
}

onMounted(loadTree);
</script>

<style scoped>
.kb-constructor-page { display: flex; flex-direction: column; min-height: calc(100vh - 64px); }
.layout {
  display: grid;
  grid-template-columns: 320px 1fr;
  flex: 1;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
@media (max-width: 960px) {
  .layout { grid-template-columns: 1fr; }
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
.editor-pane { padding: 20px 28px 40px; overflow-y: auto; max-height: calc(100vh - 124px); }
.editor-content { max-width: 800px; }
.letter-spacing-1 { letter-spacing: 1.2px; }
</style>
