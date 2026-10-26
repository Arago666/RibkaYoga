<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ActionMenu from '@/shared/ActionMenu.vue';
import AppShell from '@/shared/AppShell.vue';
import Modal from '@/shared/Modal.vue';
import PortableImportDialog from '../components/PortableImportDialog.vue';
import { api } from '@/shared/api';
import { emptyBlock, emptyRow, uid } from '../types';
interface Item {
  id: string;
  title: string;
  revision: number;
  archived_at: string | null;
  source_type: 'row' | 'document' | 'cycle';
  subtype: string;
}
interface Page {
  data: Item[];
  current_page: number;
  last_page: number;
  total: number;
}
const initial = new URLSearchParams(location.search).get('type');
const importOpen = ref(false);
const type = ref(initial === 'fragment' ? 'fragment' : 'practice');
const templateKind = ref<'practice' | 'cycle'>(initial === 'cycle' ? 'cycle' : 'practice');
const query = ref(''),
  archive = ref('active'),
  page = ref(1),
  result = ref<Page | null>(null),
  busy = ref(false),
  error = ref('');
const creating = ref(false),
  name = ref(''),
  subtype = ref('row'),
  count = ref(5),
  operationKey = ref(uid());
let version = 0;
async function load() {
  const request = ++version;
  error.value = '';
  try {
    const value = await api<Page>(
      `/api/templates?${new URLSearchParams({ type: type.value, q: query.value, archive: archive.value, page: String(page.value) })}`,
    );
    if (request === version) result.value = value;
  } catch (e) {
    if (request === version) error.value = e instanceof Error ? e.message : 'Не удалось загрузить шаблоны.';
  }
}
watch([type, archive], () => {
  page.value = 1;
  history.replaceState(history.state, '', `/templates?type=${type.value}`);
  void load();
});
onMounted(load);
function editUrl(item: Item) {
  return item.source_type === 'cycle'
    ? `/mesocycles/${item.id}/edit`
    : item.source_type === 'row'
      ? `/row-templates/${item.id}/edit`
      : `/practices/${item.id}/edit`;
}
function openCreate(kind: 'practice' | 'cycle' = 'practice') {
  templateKind.value = kind;
  name.value = '';
  count.value = 5;
  subtype.value = 'row';
  operationKey.value = uid();
  creating.value = true;
}
async function create() {
  if (busy.value || !name.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    let endpoint = '/api/practices';
    let body: Record<string, unknown> = { title: name.value.trim(), kind: 'template' };
    let source: Item['source_type'] = 'document';
    if (type.value === 'practice' && templateKind.value === 'cycle') {
      endpoint = '/api/mesocycles';
      body = {
        title: name.value.trim(),
        count: count.value,
        kind: 'template',
        idempotency_key: operationKey.value,
      };
      source = 'cycle';
    }
    if (type.value === 'fragment') {
      if (subtype.value === 'block') {
        endpoint = '/api/fragments';
        body = { title: name.value.trim(), fragment_kind: 'block', blocks: [emptyBlock('Блок фрагмента')] };
      } else {
        endpoint = '/api/row-templates';
        body = {
          title: name.value.trim(),
          kind: subtype.value,
          cells: emptyRow(subtype.value === 'transition' ? 2 : 6).cells,
        };
        source = 'row';
      }
    }
    const value = await api<{ data: { id: string } }>(endpoint, {
      method: 'POST',
      body: JSON.stringify(body),
    });
    router.visit(editUrl({ id: value.data.id, source_type: source } as Item));
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать шаблон.';
  } finally {
    busy.value = false;
  }
}
async function use(item: Item) {
  const title = window.prompt(
    item.source_type === 'cycle' ? 'Название нового цикла' : 'Название новой практики',
    item.title,
  );
  if (!title?.trim() || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const endpoint = item.source_type === 'cycle' ? `/api/mesocycles/${item.id}/duplicate` : '/api/practices';
    const body =
      item.source_type === 'cycle'
        ? { title, kind: 'cycle', expected_revision: item.revision, idempotency_key: uid() }
        : { title, template_id: item.id };
    const value = await api<{ data: { id: string } }>(endpoint, {
      method: 'POST',
      body: JSON.stringify(body),
    });
    router.visit(
      item.source_type === 'cycle' ? `/mesocycles/${value.data.id}/edit` : `/practices/${value.data.id}/edit`,
    );
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось создать копию.';
  } finally {
    busy.value = false;
  }
}
async function changeArchive(item: Item) {
  if (
    busy.value ||
    (!item.archived_at && !window.confirm('Архивировать шаблон? Ранее вставленные копии сохранятся.'))
  )
    return;
  busy.value = true;
  try {
    const base =
      item.source_type === 'cycle'
        ? 'mesocycles'
        : item.source_type === 'row'
          ? 'row-templates'
          : 'practices';
    await api(`/api/${base}/${item.id}/${item.archived_at ? 'restore' : 'archive'}`, {
      method: 'POST',
      body: JSON.stringify({ expected_revision: item.revision }),
    });
    await load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось изменить архив.';
  } finally {
    busy.value = false;
  }
}
const labels: Record<string, string> = {
  practice: 'Шаблон практики',
  row: 'Строка',
  transition: 'Связка / переход',
  block: 'Блок',
  sequence: 'Последовательность',
  cycle: 'Шаблон цикла',
};
</script>
<template>
  <PortableImportDialog :open="importOpen" @close="importOpen = false" />
  <Head title="Шаблоны" />
  <AppShell section="templates">
    <header class="page-header">
      <div>
        <h1 class="page-title">Шаблоны</h1>
        <p class="muted">Повторно используемые занятия, фрагменты и программы. Каждая вставка независима.</p>
      </div>
      <button class="btn btn-primary" @click="openCreate()">Создать шаблон</button>
      <ActionMenu label="Действия с шаблонами"
        ><button @click="importOpen = true">Импорт JSON</button></ActionMenu
      >
    </header>
    <nav class="tabs" aria-label="Виды шаблонов">
      <button
        v-for="entry in [
          ['practice', 'Шаблоны'],
          ['fragment', 'Фрагменты'],
        ]"
        :key="entry[0]"
        class="tab"
        :class="{ active: type === entry[0] }"
        @click="type = entry[0] || 'practice'"
      >
        {{ entry[1] }}
      </button>
    </nav>
    <div v-if="type === 'practice' && archive === 'active'" class="starter-grid">
      <article class="card template-card">
        <small>Шаблон 1</small>
        <h2>Пустая классическая практика</h2>
        <p class="muted">Пять блоков. Количество строк и ячеек можно менять.</p>
        <button class="btn btn-primary" @click="openCreate('practice')">Создать шаблон практики</button>
      </article>
      <article class="card template-card">
        <small>Шаблон 2</small>
        <h2>Мезоцикл на месяц</h2>
        <p class="muted">Пять тренировок по умолчанию. При создании выберите нужное количество занятий.</p>
        <button class="btn btn-primary" @click="openCreate('cycle')">Создать шаблон мезоцикла</button>
      </article>
    </div>
    <form
      class="template-filters"
      @submit.prevent="
        page = 1;
        load();
      "
    >
      <input v-model="query" class="input" placeholder="Найти шаблон" aria-label="Поиск шаблонов" /><select
        v-model="archive"
        class="select"
        aria-label="Архив"
      >
        <option value="active">Активные</option>
        <option value="archived">В архиве</option></select
      ><button class="btn btn-secondary">Найти</button>
    </form>
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <div v-if="result?.data.length" class="template-grid">
      <article v-for="item in result.data" :key="`${item.source_type}:${item.id}`" class="card template-card">
        <div class="template-card-top">
          <small>{{ labels[item.subtype] || 'Фрагмент' }}</small
          ><ActionMenu :label="`Действия с шаблоном: ${item.title}`"
            ><Link :href="editUrl(item)">Редактировать</Link
            ><button :disabled="busy" @click="changeArchive(item)">
              {{ item.archived_at ? 'Восстановить' : 'Удалить в архив' }}
            </button></ActionMenu
          >
        </div>
        <h2>{{ item.title }}</h2>
        <Link class="btn btn-secondary" :href="editUrl(item)">Открыть</Link
        ><button
          v-if="type !== 'fragment' && !item.archived_at"
          class="btn btn-primary"
          :disabled="busy"
          @click="use(item)"
        >
          Создать {{ item.source_type === 'cycle' ? 'мезоцикл' : 'практику' }}
        </button>
        <p v-if="type === 'fragment'" class="muted">
          Вставляется командой «Вставить фрагмент» в редакторе практики.
        </p>
      </article>
    </div>
    <div v-else class="empty-state">
      <p>Здесь пока нет шаблонов выбранного вида.</p>
      <button class="btn btn-primary" @click="openCreate()">Создать первый</button>
    </div>
    <div v-if="result && result.last_page > 1" class="template-filters">
      <button
        class="btn btn-secondary"
        :disabled="page <= 1"
        @click="
          page--;
          load();
        "
      >
        Назад</button
      ><span>{{ page }} / {{ result.last_page }}</span
      ><button
        class="btn btn-secondary"
        :disabled="page >= result.last_page"
        @click="
          page++;
          load();
        "
      >
        Далее
      </button>
    </div>
    <Modal :open="creating" title="Новый шаблон" @close="!busy && (creating = false)"
      ><form id="template-create" @submit.prevent="create">
        <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
        <label class="field"
          ><span class="label">Название</span
          ><input v-model="name" class="input" required maxlength="160" /></label
        ><label v-if="type === 'practice'" class="field"
          ><span class="label">Тип шаблона</span
          ><select v-model="templateKind" class="select">
            <option value="practice">Классическая практика · 5 блоков</option>
            <option value="cycle">Мезоцикл · несколько занятий</option>
          </select></label
        ><label v-if="type === 'fragment'" class="field"
          ><span class="label">Разновидность</span
          ><select v-model="subtype" class="select">
            <option value="row">Строка</option>
            <option value="transition">Связка / переход</option>
            <option value="block">Блок с несколькими строками</option>
          </select></label
        ><label v-if="type === 'practice' && templateKind === 'cycle'" class="field"
          ><span class="label">Количество занятий</span
          ><input v-model.number="count" class="input" type="number" min="1" max="52" required
        /></label>
      </form>
      <template #footer
        ><button class="btn btn-secondary" :disabled="busy" @click="creating = false">Отмена</button
        ><button class="btn btn-primary" type="submit" form="template-create" :disabled="busy">
          Создать
        </button></template
      ></Modal
    >
  </AppShell>
</template>
<style scoped>
.template-filters {
  display: flex;
  gap: 12px;
  margin: 20px 0;
  flex-wrap: wrap;
}
.template-filters .input {
  max-width: 500px;
}
.template-filters .select {
  max-width: 180px;
}
.starter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
  gap: 16px;
  margin: 22px 0;
}
.template-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}
.template-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 22px;
}
.template-card-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
}
.template-card h2 {
  font-size: 20px;
}
.template-card small {
  color: var(--muted);
}
</style>
