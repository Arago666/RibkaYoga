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
  cards: {
    id: string;
    name: string;
    image_asset_id: string;
    image_url: string;
    pose_key: string | null;
    supported_styles: IllustrationStyle[];
  }[];
  volume_source_revision: number;
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
  selectedId = ref(''),
  search = ref('');
const prepared = ref<PreparedIllustration | null>(null);
const selected = computed(() =>
  state.value?.cards.find((card) => card.id === (props.asanaId || selectedId.value)),
);
const pose = computed(() =>
  selected.value?.pose_key ? state.value?.pack.poses[selected.value.pose_key] : null,
);
const supported = computed(
  () =>
    style.value === 'original' ||
    (!!selected.value &&
      (!props.sourceAssetId || selected.value.image_asset_id === props.sourceAssetId) &&
      selected.value.supported_styles.includes(style.value)),
);
const filteredCards = computed(() => {
  const query = search.value.trim().toLocaleLowerCase('ru-RU').replaceAll('ё', 'е');
  return (state.value?.cards ?? []).filter((card) =>
    card.name.toLocaleLowerCase('ru-RU').replaceAll('ё', 'е').includes(query),
  );
});
const originalUrl = computed(() => props.imageUrl || selected.value?.image_url);
const styleNames: Record<IllustrationStyle, string> = {
  original: 'Мои рисунки',
  line: 'Линейная фигура',
  volume: 'Объёмная фигура человека',
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
    assetId = props.sourceAssetId || selected.value?.image_asset_id,
    requestedStyle = style.value,
    requestedCharacter = character.value;
  if (!asanaId || !assetId || busy.value) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  prepared.value = null;
  try {
    const result = (
      await api<{ data: PreparedIllustration }>('/api/illustration-styles/prepare', {
        method: 'POST',
        body: JSON.stringify({
          asana_id: asanaId,
          source_asset_id: assetId,
          style: requestedStyle,
          character: requestedCharacter,
        }),
      })
    ).data;
    if (
      asanaId !== (props.asanaId || selected.value?.id) ||
      assetId !== (props.sourceAssetId || selected.value?.image_asset_id) ||
      requestedStyle !== style.value ||
      requestedCharacter !== character.value
    )
      return;
    prepared.value = result;
    notice.value =
      prepared.value.reason || 'Вариант подготовлен. Исходная карточка и сохранённые снимки не менялись.';
    emit('select', prepared.value);
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Не удалось подготовить вариант.';
  } finally {
    busy.value = false;
  }
}
watch(search, () => {
  if (!filteredCards.value.some((card) => card.id === selectedId.value)) {
    selectedId.value = filteredCards.value[0]?.id ?? '';
  }
});
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
          >Представление<select v-model="style" :disabled="busy">
            <option v-for="(name, key) in styleNames" :key="key" :value="key">
              {{ name }} — {{ state.coverage[key] }} / {{ state.coverage.total }}
            </option>
          </select></label
        ><label
          >Персонаж<select v-model="character" :disabled="busy || style === 'original'">
            <option value="neutral">Нейтральная фигура</option>
            <option value="female">Женщина</option>
            <option value="male">Мужчина</option>
          </select></label
        >
      </div>
      <p class="muted">
        Объёмные варианты доступны для {{ state.coverage.volume }} из {{ state.coverage.total }} активных
        карточек, линейный манекен — для {{ state.coverage.line }}. Голова, грудная клетка, таз, руки и ноги
        образуют фигуру человека. Центры суставов, изгибы и точки опоры берутся из исходного рисунка. Персонаж
        меняет пропорции частей тела в пределах этой позы; глубина условная.
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
      <div v-if="!asanaId" class="illustration-pose-picker">
        <label
          >Найти позу<input v-model="search" type="search" :disabled="busy" placeholder="Название асаны"
        /></label>
        <label
          >Поза для предпросмотра<select v-model="selectedId" :disabled="busy || !filteredCards.length">
            <option v-for="card in filteredCards" :key="card.id" :value="card.id">{{ card.name }}</option>
          </select></label
        >
        <p v-if="!filteredCards.length" class="muted">По этому названию ничего не найдено.</p>
      </div>
      <div class="illustration-preview">
        <div v-if="originalUrl">
          <h3>Исходный рисунок</h3>
          <img :src="originalUrl" alt="Исходное изображение выбранной асаны" />
        </div>
        <div v-if="style === 'line' && pose && supported">
          <h3>
            {{ styleNames[style] }} ·
            {{ character === 'female' ? 'женщина' : character === 'male' ? 'мужчина' : 'нейтральный' }}
          </h3>
          <MannequinView :pose="pose" :style="'line'" :character="character" />
        </div>
        <div v-if="style === 'volume' && supported" class="prepared-image">
          <h3>Объёмная фигура человека</h3>
          <template v-if="prepared">
            <img :src="prepared.image_url" alt="Подготовленный объёмный вариант выбранной асаны" />
            <a :href="prepared.image_url" target="_blank" rel="noopener" class="btn">Открыть PNG</a>
          </template>
          <p v-else class="muted">
            Подготовьте выбранное представление, чтобы увидеть его рядом с исходным рисунком.
          </p>
        </div>
      </div>
      <p v-if="style !== 'original' && !supported" class="illustration-notice">
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
      <div v-if="prepared && (style !== 'volume' || !supported)" class="prepared-image">
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
          Объёмные варианты строятся по геометрии и анатомической разметке конкретного исходного рисунка.
          Каждый вариант сохраняется отдельным изображением; ранее подготовленные версии остаются доступны
          там, где были выбраны. Ревизия объёмных вариантов: {{ state.volume_source_revision }}. Изображение
          связано с точной версией исходника.
        </p>
        <p>
          Линейный манекен: ревизия {{ state.pack.model_revision }}. Его 3D модель можно вращать для осмотра.
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
.illustration-settings select,
.illustration-settings input {
  min-height: 44px;
  border: 1px solid #c5d0c0;
  border-radius: 0.5rem;
  padding: 0.5rem;
  width: 100%;
  background: white;
}
.illustration-pose-picker {
  display: grid;
  gap: 0.75rem;
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
.illustration-preview .prepared-image img {
  max-width: 520px;
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
