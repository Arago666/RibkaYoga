<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppShell from '@/shared/AppShell.vue';
import PageHeading from '@/shared/PageHeading.vue';
import AppSelect from '@/shared/AppSelect.vue';
import CollectionChoices from '../components/CollectionChoices.vue';
import { api } from '@/shared/api';
import { createPoseScene, joints, presets, type PoseSource } from '../pose/scene';
import type { Asana } from '../types';
const host = ref<HTMLElement | null>(null),
  ready = ref(false),
  error = ref(''),
  notice = ref(''),
  busy = ref(false);
const name = ref('Новая асана'),
  selected = ref('Hips'),
  angles = ref([0, 0, 0]),
  mode = ref('translate'),
  collections = ref<string[]>([]);
const asana = ref<Asana | null>(null);
let editor: Awaited<ReturnType<typeof createPoseScene>> | undefined;
const bodyGroup = ref('body');
const bodyGroups = [
  ['body', 'Всё тело'],
  ['joint', 'Отдельный сустав'],
  ['torso', 'Туловище'],
  ['arms', 'Обе руки'],
  ['legs', 'Обе ноги'],
  ['leftArm', 'Левая рука'],
  ['rightArm', 'Правая рука'],
  ['leftLeg', 'Левая нога'],
  ['rightLeg', 'Правая нога'],
] as const;
function selectGroup() {
  const group = bodyGroup.value;
  editor?.group(group, mode.value as 'rotate' | 'translate');
  bodyGroup.value = group;
  sync();
}
function setTool(tool: string) {
  mode.value = tool;
  selectGroup();
}
const history = ref<PoseSource[]>([]),
  cursor = ref(-1);
let baseline = '',
  stopped = false;
const dirty = computed(
  () => ready.value && JSON.stringify(history.value[cursor.value]) + name.value !== baseline,
);
function sync() {
  if (editor) angles.value = editor.values();
}
function commit() {
  if (!editor) return;
  const state = editor.snapshot();
  if (JSON.stringify(history.value[cursor.value]) === JSON.stringify(state)) return;
  history.value = history.value.slice(0, cursor.value + 1);
  history.value.push(state);
  if (history.value.length > 80) history.value.shift();
  cursor.value = history.value.length - 1;
  sync();
}
function undo(delta: number) {
  const group = bodyGroup.value;
  cursor.value += delta;
  editor?.restore(history.value[cursor.value]!);
  bodyGroup.value = group;
  selectGroup();
}
function choose() {
  editor?.choose(selected.value);
  bodyGroup.value = 'joint';
  sync();
}
function rotate() {
  editor?.transform(angles.value);
  commit();
}
function preset(key: (typeof presets)[number][0]) {
  const group = bodyGroup.value;
  editor?.preset(key);
  bodyGroup.value = group;
  selectGroup();
  commit();
}
async function save() {
  if (!editor || busy.value) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    const blob = await editor.image();
    const data = new FormData();
    data.set('name', name.value.trim());
    data.set('pose_source', JSON.stringify(editor.snapshot()));
    data.set('image', new File([blob], 'yoga-pose.png', { type: 'image/png' }));
    if (asana.value) {
      data.set('_method', 'PATCH');
      data.set('expected_revision', String(asana.value.revision));
    } else data.set('collection_ids', JSON.stringify(collections.value));
    const result = await api<{ data: Asana }>(asana.value ? `/api/asanas/${asana.value.id}` : '/api/asanas', {
      method: 'POST',
      body: data,
    });
    asana.value = result.data;
    commit();
    baseline = JSON.stringify(history.value[cursor.value]) + name.value;
    notice.value = 'Асана и редактируемая поза сохранены в библиотеке.';
    window.history.replaceState(window.history.state, '', `/pose-editor?asana=${result.data.id}`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось сохранить позу.';
  } finally {
    busy.value = false;
  }
}
async function download() {
  if (!editor || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const blob = await editor.image();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'yoga-pose.png';
    link.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось получить PNG.';
  } finally {
    busy.value = false;
  }
}
function unload(event: BeforeUnloadEvent) {
  if (dirty.value) {
    event.preventDefault();
    event.returnValue = '';
  }
}
const removeNavigation = router.on(
  'before',
  () => !dirty.value || window.confirm('Уйти из редактора? Несохранённые изменения позы будут потеряны.'),
);
onMounted(async () => {
  window.addEventListener('beforeunload', unload);
  try {
    editor = await createPoseScene(host.value!, commit, (key, effectiveMode) => {
      bodyGroup.value = 'joint';
      selected.value = key;
      mode.value = effectiveMode;
      sync();
    });
    if (stopped) {
      editor.dispose();
      return;
    }
    const id = new URLSearchParams(location.search).get('asana');
    if (id) {
      const response = await api<{ data: Asana }>(`/api/asanas/${id}`);
      if (!response.data.pose_source)
        throw new Error('У этой асаны нет сохранённой 3D-позы. Создайте новую позу из библиотеки.');
      asana.value = response.data;
      name.value = response.data.name;
      editor.restore(response.data.pose_source);
    }
    ready.value = true;
    commit();
    baseline = JSON.stringify(history.value[cursor.value]) + name.value;
    sync();
    bodyGroup.value = 'body';
    mode.value = 'translate';
    selectGroup();
  } catch (e) {
    error.value =
      e instanceof Error ? e.message : 'Не удалось открыть 3D-модель. Проверьте поддержку WebGL в браузере.';
  }
});
onBeforeUnmount(() => {
  stopped = true;
  editor?.dispose();
  removeNavigation();
  window.removeEventListener('beforeunload', unload);
});
</script>
<template>
  <Head title="Редактор 3D-позы" />
  <AppShell section="library">
    <PageHeading
      eyebrow="Конструктор асан"
      title="Редактор позы"
      description="Выберите заготовку, настройте суставы и сохраните асану с готовой картинкой."
      ><Link class="btn btn-secondary" href="/library">Библиотека</Link></PageHeading
    >
    <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
    <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
    <div class="pose-layout">
      <div class="pose-workspace card">
        <div class="pose-presets" aria-label="Начальные позы">
          <button
            v-for="[key, label] in presets"
            :key="key"
            class="btn btn-secondary"
            :disabled="!ready || busy"
            @click="preset(key)"
          >
            {{ label }}
          </button>
        </div>
        <p v-if="!ready && !error" role="status">Загружаем модель…</p>
        <div ref="host" class="pose-canvas" aria-label="Трёхмерный редактор человека"></div>
        <div class="pose-tools" role="group" aria-label="Инструмент">
          <button
            class="btn"
            :class="mode === 'translate' ? 'btn-primary' : 'btn-secondary'"
            :aria-pressed="mode === 'translate'"
            :disabled="!ready || busy"
            @click="setTool('translate')"
          >
            Выделять и двигать
          </button>
          <button
            class="btn"
            :class="mode === 'rotate' ? 'btn-primary' : 'btn-secondary'"
            :aria-pressed="mode === 'rotate'"
            :disabled="!ready || busy"
            @click="setTool('rotate')"
          >
            Поворачивать
          </button>
          <button class="btn btn-secondary" :disabled="busy || cursor < 1" @click="undo(-1)">Отменить</button>
          <button class="btn btn-secondary" :disabled="busy || cursor >= history.length - 1" @click="undo(1)">
            Повторить
          </button>
        </div>
        <p class="muted pose-hint">
          Выберите часть тела и инструмент. Камера смотрит спереди, поверхность закреплена. Зелёные точки
          позволяют выбрать отдельный сустав.
        </p>
      </div>
      <aside class="card pose-panel">
        <label>Название асаны<input v-model="name" class="input" maxlength="160" :disabled="busy" /></label>
        <label
          >Часть тела<AppSelect v-model="bodyGroup" :disabled="!ready || busy" @change="selectGroup"
            ><option v-for="[key, label] in bodyGroups" :key="key" :value="key">
              {{ label }}
            </option></AppSelect
          ></label
        >
        <label
          >Сустав<AppSelect v-model="selected" :disabled="!ready || busy" @change="choose"
            ><option v-for="[key, label] in joints" :key="key" :value="key">{{ label }}</option></AppSelect
          ></label
        >
        <label v-for="(axis, index) in ['X', 'Y', 'Z']" :key="axis"
          >{{ mode === 'translate' ? 'Положение' : 'Поворот' }} {{ axis }},
          {{ mode === 'translate' ? 'м' : '°'
          }}<input
            v-model.number="angles[index]"
            class="input"
            type="number"
            :min="mode === 'translate' ? -10 : -180"
            :max="mode === 'translate' ? 10 : 180"
            :step="mode === 'translate' ? 0.05 : 5"
            :disabled="!ready || busy"
            @input="rotate"
        /></label>
        <CollectionChoices v-if="!asana" v-model="collections" :open="true" :disabled="busy" />
        <button
          class="btn btn-primary"
          :disabled="!ready || busy || !name.trim() || (!asana && !collections.length)"
          @click="save"
        >
          {{ busy ? 'Сохраняем…' : 'Сохранить асану' }}
        </button>
        <button class="btn btn-secondary" :disabled="!ready || busy" @click="download">Скачать PNG</button>
      </aside>
    </div>
  </AppShell>
</template>
<style scoped>
.pose-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 20px;
  align-items: start;
}
.pose-workspace {
  min-width: 0;
  padding: 16px;
}
.pose-presets,
.pose-views,
.pose-tools {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.pose-presets .btn,
.pose-views .btn {
  min-height: 40px;
  padding: 8px 13px;
  font-size: 12px;
}
.pose-canvas {
  height: 560px;
  max-height: 65vh;
  min-height: 320px;
  position: relative;
  touch-action: none;
}
.pose-canvas :deep(canvas) {
  width: 100%;
  height: 100%;
  display: block;
}
.pose-panel {
  display: grid;
  gap: 16px;
  padding: 22px;
}
.pose-panel label {
  display: grid;
  gap: 7px;
  font-size: 13px;
}
.pose-hint {
  font-size: 12px;
  line-height: 1.6;
  margin: 14px 0 0;
}
@media (max-width: 800px) {
  .pose-layout {
    grid-template-columns: 1fr;
  }
  .pose-canvas {
    height: 460px;
  }
  .pose-panel {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .pose-panel > :first-child,
  .pose-panel > :nth-child(2),
  .pose-panel > :nth-child(3) {
    grid-column: 1/-1;
  }
}
@media (max-width: 420px) {
  .pose-panel {
    grid-template-columns: 1fr;
  }
}
</style>
