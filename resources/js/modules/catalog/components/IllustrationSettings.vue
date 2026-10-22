<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '@/shared/api';
import MannequinView from './MannequinView.vue';
import type { Character, IllustrationStyle, MannequinPose } from '../illustrations/mannequin';
export interface PreparedIllustration {
  available: boolean;
  fallback: boolean;
  style: IllustrationStyle;
  character: Character;
  asana_id: string;
  source_asset_id: string;
  image_asset_id: string;
  image_url: string;
  variant_id?: string;
  reason?: string | null;
  drawing_source?: unknown;
  render_source?: unknown;
}
interface State {
  preference: { style: IllustrationStyle; character: Character; revision: number };
  coverage: { total: number; matched: number; original: number; line: number; volume: number };
  cards: { id: string; name: string; image_asset_id: string; image_url: string; pose_key: string }[];
  pack: { model_revision: number; rights: string; poses: Record<string, MannequinPose> };
}
const props = defineProps<{
  asanaId?: string;
  sourceAssetId?: string;
  imageUrl?: string;
  compact?: boolean;
}>();
const emit = defineEmits<{ select: [PreparedIllustration]; reset: [] }>();
const state = ref<State | null>(null),
  error = ref(''),
  notice = ref(''),
  busy = ref(false),
  loading = ref(true);
const style = ref<IllustrationStyle>('original'),
  character = ref<Character>('neutral'),
  selectedId = ref('');
const prepared = ref<PreparedIllustration | null>(null);
const selected = computed(() =>
  state.value?.cards.find((card) => card.id === (props.asanaId || selectedId.value)),
);
const pose = computed(() => (selected.value ? state.value?.pack.poses[selected.value.pose_key] : null));
const originalUrl = computed(() => props.imageUrl || selected.value?.image_url);
const styleNames: Record<IllustrationStyle, string> = {
  original: 'Мои рисунки',
  line: 'Линейная фигура',
  volume: 'Объёмная фигура',
};
async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    state.value = (await api<{ data: State }>('/api/illustration-styles')).data;
    style.value = state.value.preference.style;
    character.value = state.value.preference.character;
    selectedId.value = state.value.cards[0]?.id ?? '';
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Не удалось открыть стили.';
  } finally {
    loading.value = false;
  }
}
async function save(): Promise<void> {
  if (!state.value || busy.value) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    const result = await api<{ data: State['preference'] }>('/api/illustration-styles/preferences', {
      method: 'PUT',
      body: JSON.stringify({
        style: style.value,
        character: character.value,
        expected_revision: state.value.preference.revision,
      }),
    });
    state.value.preference = result.data;
    notice.value = 'Предпочтение сохранено для будущих вставок. Существующие листы и картинки не изменены.';
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Не удалось сохранить настройки.';
  } finally {
    busy.value = false;
  }
}
async function prepare(): Promise<void> {
  const asanaId = props.asanaId || selected.value?.id,
    assetId = props.sourceAssetId || selected.value?.image_asset_id;
  if (!asanaId || !assetId || busy.value) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  prepared.value = null;
  try {
    prepared.value = (
      await api<{ data: PreparedIllustration }>('/api/illustration-styles/prepare', {
        method: 'POST',
        body: JSON.stringify({
          asana_id: asanaId,
          source_asset_id: assetId,
          style: style.value,
          character: character.value,
        }),
      })
    ).data;
    notice.value =
      prepared.value.reason || 'Вариант подготовлен. Исходная карточка и сохранённые снимки не менялись.';
    emit('select', prepared.value);
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Не удалось подготовить вариант.';
  } finally {
    busy.value = false;
  }
}
watch(
  () => [style.value, character.value, selectedId.value, props.asanaId, props.sourceAssetId],
  () => {
    prepared.value = null;
    notice.value = '';
    emit('reset');
  },
);
onMounted(() => {
  void load();
});
</script>
<template>
  <section class="illustration-settings" :class="{ compact }">
    <p v-if="error" class="illustration-error" role="alert">{{ error }}</p>
    <p v-if="notice" role="status" class="illustration-notice">{{ notice }}</p>
    <p v-if="loading">Загрузка набора…</p>
    <button v-else-if="!state" type="button" class="btn" @click="load">Повторить</button>
    <template v-else>
      <div class="illustration-choices">
        <label
          >Представление<select v-model="style">
            <option v-for="(name, key) in styleNames" :key="key" :value="key">
              {{ name }} — {{ state.coverage[key] }} / {{ state.coverage.total }}
            </option>
          </select></label
        ><label
          >Персонаж<select v-model="character" :disabled="style === 'original'">
            <option value="neutral">Нейтральная фигура</option>
            <option value="female">Женщина</option>
            <option value="male">Мужчина</option>
          </select></label
        >
      </div>
      <p class="muted">
        Проверенные альтернативы есть для {{ state.coverage.matched }} из {{ state.coverage.total }} активных
        карточек. Для остальных сохраняется точный исходный рисунок. Женская и мужская фигуры меняют пропорции
        манекена, сохраняя заданную позу.
      </p>
      <div class="illustration-actions">
        <button type="button" class="btn" :disabled="busy" @click="save">
          Сохранить предпочтение для новых вставок
        </button>
      </div>
      <p class="muted">
        Глобальная настройка не меняет старые листы. Применение к существующему занятию требует отдельного
        предпросмотра и подтверждения в редакторе.
      </p>
      <label v-if="!asanaId"
        >Проверенная поза для предпросмотра<select v-model="selectedId">
          <option v-for="card in state.cards" :key="card.id" :value="card.id">{{ card.name }}</option>
        </select></label
      >
      <div class="illustration-preview">
        <div v-if="originalUrl">
          <h3>Исходный рисунок</h3>
          <img :src="originalUrl" alt="Исходное изображение выбранной асаны" />
        </div>
        <div v-if="style !== 'original' && pose">
          <h3>
            {{ styleNames[style] }} ·
            {{ character === 'female' ? 'женщина' : character === 'male' ? 'мужчина' : 'нейтральный' }}
          </h3>
          <MannequinView :pose="pose" :style="style" :character="character" />
        </div>
      </div>
      <p v-if="style !== 'original' && !pose" class="illustration-notice">
        Для этой точной версии позы проверенного альтернативного представления нет. Будет использован исходный
        рисунок.
      </p>
      <div class="illustration-actions">
        <button
          type="button"
          class="btn btn-primary"
          :disabled="busy || (!(asanaId && sourceAssetId) && !selected)"
          @click="prepare"
        >
          {{ busy ? 'Подготавливаем…' : 'Подготовить выбранное представление' }}
        </button>
      </div>
      <div v-if="prepared" class="prepared-image">
        <h3>{{ prepared.fallback ? 'Сохранён исходный рисунок' : 'Неизменяемое изображение готово' }}</h3>
        <img :src="prepared.image_url" alt="Подготовленное представление асаны" /><a
          :href="prepared.image_url"
          target="_blank"
          rel="noopener"
          class="btn"
          >Открыть PNG</a
        >
      </div>
      <details>
        <summary>Происхождение набора</summary>
        <p>{{ state.pack.rights }}</p>
        <p>
          Ревизия модели: {{ state.pack.model_revision }}. Геометрия — суставы, цилиндры и эллипсоиды в трёх
          измерениях, освещение и камера. Исходные PNG не превращаются в 3D фильтром; точное соответствие
          проверяется по SHA-256 изображения.
        </p>
      </details>
    </template>
  </section>
</template>
<style scoped>
.illustration-settings {
  display: grid;
  gap: 1rem;
}
.illustration-choices {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 1rem;
}
.illustration-settings label {
  display: grid;
  gap: 0.4rem;
}
.illustration-settings select {
  min-height: 44px;
  border: 1px solid #c5d0c0;
  border-radius: 0.5rem;
  padding: 0.5rem;
  width: 100%;
  background: white;
}
.illustration-preview {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
  gap: 1rem;
}
.illustration-preview img,
.prepared-image img {
  width: 100%;
  max-width: 520px;
  aspect-ratio: 1;
  object-fit: contain;
  background: white;
}
.prepared-image img {
  display: block;
  max-width: 320px;
}
.illustration-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.muted {
  color: #61705c;
  font-size: 0.85rem;
}
.illustration-error {
  padding: 1rem;
  background: #fff0ec;
  color: #842d1b;
  border-radius: 0.7rem;
}
.illustration-notice {
  padding: 0.8rem;
  background: #eef7e9;
  border-radius: 0.7rem;
}
.illustration-settings h3 {
  font-weight: 600;
  margin-bottom: 0.5rem;
}
.illustration-settings summary {
  cursor: pointer;
  padding: 0.8rem 0;
}
.compact .illustration-preview {
  grid-template-columns: 1fr;
}
.compact .illustration-preview img {
  max-height: 260px;
}
</style>
