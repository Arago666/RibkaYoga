<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Minus, Plus, Play, Square, RotateCcw, Volume2, Timer, SlidersHorizontal } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import { api } from '@/shared/api';
import { uid } from '@/shared/uuid';
import {
  defaultMetronome,
  normalizeMetronome,
  metronomeBeatLabels,
  metronomeClock,
  metronomeSoundLabels,
  type MetronomeBeat,
  type MetronomePreset,
  type MetronomeSettings,
} from '../metronome';
import { MetronomeEngine, type MetronomeState } from '../metronomeEngine';

const props = defineProps<{
  open: boolean;
  modelValue: MetronomeSettings;
  authenticated?: boolean;
}>();
const emit = defineEmits<{
  close: [];
  'update:modelValue': [settings: MetronomeSettings];
}>();
const form = ref(defaultMetronome());
const presets = ref<MetronomePreset[]>([]);
const selected = ref('');
const name = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const previewStarting = ref(false);
const previewState = ref<MetronomeState>({
  running: false,
  beat: -1,
  subdivision: 0,
  bar: 0,
  elapsed: 0,
  remaining: null,
  countingIn: false,
  bpm: 120,
  reason: null,
});
const engine = new MetronomeEngine((state) => {
  previewState.value = state;
});
let previewStarted = false;
let taps: number[] = [];
let pendingPresetId: string | null = null;
let disposed = false;
const currentPreset = computed(() => presets.value.find((item) => item.id === selected.value));
const symbols: Record<MetronomeBeat, string> = { normal: '●', accent: '⌃', strong: '›', mute: '○' };
const trackerValue = computed(() => {
  if (previewState.value.countingIn) return 'Подготовка';
  if (form.value.tracker.mode === 'bars') {
    const limit = form.value.tracker.bars;
    return form.value.tracker.countdown
      ? `${Math.max(0, limit - previewState.value.bar)} тактов`
      : `${previewState.value.bar}${form.value.tracker.stop_at_end ? ` / ${limit}` : ''} тактов`;
  }
  return metronomeClock(previewState.value.remaining ?? previewState.value.elapsed);
});
async function loadPresets() {
  if (!props.authenticated) return;
  loading.value = true;
  try {
    const response = await api<{ data: MetronomePreset[] }>('/api/metronome-presets');
    if (!disposed) presets.value = response.data;
  } catch (failure) {
    if (!disposed)
      error.value = failure instanceof Error ? failure.message : 'Не удалось загрузить настройки.';
  } finally {
    loading.value = false;
  }
}
function stopPreview(reset = false) {
  engine.stop(reset);
  if (reset) previewStarted = false;
}
function close() {
  stopPreview(true);
  emit('close');
}
function apply() {
  stopPreview(true);
  emit('update:modelValue', normalizeMetronome(form.value));
  emit('close');
}
async function preview() {
  if (previewStarting.value) return;
  if (previewState.value.running) {
    stopPreview();
    return;
  }
  previewStarting.value = true;
  error.value = '';
  try {
    await engine.start(normalizeMetronome(form.value), previewStarted);
    previewStarted = true;
    if (!props.open || disposed) stopPreview(true);
  } catch {
    error.value = 'Не удалось включить звук. Проверьте разрешение звука в браузере.';
  } finally {
    previewStarting.value = false;
  }
}
function changeBpm(delta: number) {
  form.value.bpm = Math.max(20, Math.min(300, (Number(form.value.bpm) || 120) + delta));
}
function tap() {
  const now = performance.now();
  if (taps.length && now - taps[taps.length - 1] > 3000) taps = [];
  taps.push(now);
  taps = taps.slice(-6);
  if (taps.length > 1) {
    const interval = (now - taps[0]) / (taps.length - 1);
    form.value.bpm = Math.max(20, Math.min(300, Math.round(60000 / interval)));
  }
}
function cycleBeat(index: number) {
  const kinds: MetronomeBeat[] = ['normal', 'accent', 'strong', 'mute'];
  const current = form.value.pattern[index];
  form.value.pattern[index] = kinds[(kinds.indexOf(current) + 1) % kinds.length];
}
function loadSelected() {
  if (!currentPreset.value) return;
  stopPreview(true);
  form.value = { ...normalizeMetronome(currentPreset.value.settings), enabled: true };
  name.value = currentPreset.value.name;
  notice.value = `Загружено: ${currentPreset.value.name}`;
  error.value = '';
}
async function savePreset(replace = false) {
  if (saving.value || !name.value.trim() || !props.authenticated) return;
  const existing = replace ? currentPreset.value : undefined;
  if (replace && !existing) return;
  saving.value = true;
  error.value = '';
  notice.value = '';
  const presetId = existing?.id ?? (pendingPresetId ??= uid());
  try {
    const saved = (
      await api<{ data: MetronomePreset }>(`/api/metronome-presets${existing ? `/${presetId}` : ''}`, {
        method: existing ? 'PUT' : 'POST',
        body: JSON.stringify({
          ...(existing ? { expected_revision: existing.revision } : { id: presetId }),
          name: name.value.trim(),
          settings: normalizeMetronome(form.value),
        }),
      })
    ).data;
    presets.value = [...presets.value.filter((item) => item.id !== saved.id), saved].sort((a, b) =>
      a.name.localeCompare(b.name, 'ru'),
    );
    selected.value = saved.id;
    pendingPresetId = null;
    notice.value = `Сохранено: ${saved.name}`;
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'Не удалось сохранить настройки.';
  } finally {
    saving.value = false;
  }
}
watch(
  () => props.open,
  (open) => {
    if (open) {
      form.value = { ...normalizeMetronome(props.modelValue), enabled: true };
      error.value = '';
      notice.value = '';
      selected.value = '';
      name.value = '';
      taps = [];
      void loadPresets();
    } else stopPreview(true);
  },
  { immediate: true },
);
watch(
  () => form.value.beats,
  (value) => {
    const count = Math.max(1, Math.min(12, Math.round(Number(value) || 4)));
    form.value.pattern = Array.from(
      { length: count },
      (_, i) => form.value.pattern[i] ?? (i === 0 ? 'accent' : 'normal'),
    );
  },
);
watch(
  form,
  (value) => {
    engine.update(normalizeMetronome(value));
  },
  { deep: true },
);
onBeforeUnmount(() => {
  disposed = true;
  engine.dispose();
});
</script>
<template>
  <Modal :open="open" title="Метроном" @close="close">
    <div class="metronome-settings">
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
      <p v-if="notice" class="metronome-notice" role="status">{{ notice }}</p>
      <section class="metronome-tempo" aria-label="Темп и доли">
        <div class="tempo-stepper">
          <button class="btn" aria-label="Уменьшить темп на 1 BPM" @click="changeBpm(-1)">
            <Minus :size="20" />
          </button>
          <label class="tempo-value"
            ><span>Темп, BPM</span
            ><input
              v-model.number="form.bpm"
              aria-label="Темп метронома, BPM"
              inputmode="numeric"
              type="number"
              min="20"
              max="300"
          /></label>
          <button class="btn" aria-label="Увеличить темп на 1 BPM" @click="changeBpm(1)">
            <Plus :size="20" />
          </button>
        </div>
        <input
          v-model.number="form.bpm"
          class="tempo-range"
          aria-label="Скорость метронома"
          type="range"
          min="20"
          max="300"
        />
        <div class="metronome-beats" aria-label="Рисунок такта">
          <button
            v-for="(kind, index) in form.pattern"
            :key="index"
            :class="[
              'beat-button',
              `beat-${kind}`,
              {
                pulsing:
                  previewState.running && previewState.beat === index && previewState.subdivision === 0,
              },
            ]"
            :aria-label="`Доля ${index + 1}: ${metronomeBeatLabels[kind]}. Изменить`"
            :title="metronomeBeatLabels[kind]"
            @click="cycleBeat(index)"
          >
            <span aria-hidden="true">{{ symbols[kind] }}</span
            ><small>{{ index + 1 }}</small>
          </button>
        </div>
        <p class="muted metronome-help">Нажмите на долю: обычная → акцент → сильный акцент → тишина.</p>
        <div class="metronome-preview-controls">
          <button class="btn btn-primary" :disabled="previewStarting" @click="preview">
            <Square v-if="previewState.running" :size="17" /><Play v-else :size="17" />{{
              previewStarting
                ? 'Включаем…'
                : previewState.running
                  ? 'Пауза'
                  : previewStarted
                    ? 'Продолжить прослушивание'
                    : 'Послушать'
            }}
          </button>
          <button class="btn" aria-label="Сбросить счётчик метронома" @click="stopPreview(true)">
            <RotateCcw :size="17" />
          </button>
          <button class="btn" @click="tap">TAP · задать ритм</button>
        </div>
        <div class="preview-status" role="status">
          <span>{{
            previewState.running
              ? `${previewState.bpm} BPM`
              : previewState.reason === 'limit'
                ? 'Лимит достигнут'
                : 'Предпрослушивание'
          }}</span
          ><strong>{{ trackerValue }}</strong>
        </div>
      </section>
      <div class="metronome-grid">
        <label class="field"
          ><span class="label">Долей в такте</span
          ><AppSelect v-model.number="form.beats" class="select">
            <option v-for="value in 12" :key="value" :value="value">{{ value }}</option>
          </AppSelect></label
        >
        <label class="field"
          ><span class="label">Размер доли</span
          ><AppSelect v-model.number="form.beat_unit" class="select">
            <option :value="2">Половинная · 2</option>
            <option :value="4">Четверть · 4</option>
            <option :value="8">Восьмая · 8</option>
            <option :value="16">Шестнадцатая · 16</option>
          </AppSelect></label
        >
        <label class="field"
          ><span class="label">Деление каждой доли</span
          ><AppSelect v-model.number="form.subdivision" class="select">
            <option :value="1">Без деления</option>
            <option :value="2">На 2</option>
            <option :value="3">На 3 · триоль</option>
            <option :value="4">На 4</option>
          </AppSelect></label
        >
        <label class="field"
          ><span class="label">Звук</span
          ><AppSelect v-model="form.sound" class="select">
            <option v-for="(label, value) in metronomeSoundLabels" :key="value" :value="value">
              {{ label }}
            </option>
          </AppSelect></label
        >
      </div>
      <label class="field"
        ><span class="label volume-label"
          ><Volume2 :size="17" /> Громкость <span>{{ form.volume }}%</span></span
        ><input v-model.number="form.volume" aria-label="Громкость метронома" type="range" min="0" max="100"
      /></label>
      <details class="metronome-details disclosure">
        <summary>
          <span class="disclosure-icon"><Timer aria-hidden="true" /></span>
          <span class="disclosure-copy"><strong>Подготовка и счётчик</strong></span>
        </summary>
        <div class="metronome-detail-body disclosure-body">
          <label class="field"
            ><span class="label">Отсчёт перед ритмом</span
            ><AppSelect v-model.number="form.count_in_bars" class="select">
              <option :value="0">Без отсчёта</option>
              <option :value="1">1 такт</option>
              <option :value="2">2 такта</option>
            </AppSelect></label
          >
          <label class="field"
            ><span class="label">Счётчик метронома</span
            ><AppSelect v-model="form.tracker.mode" class="select">
              <option value="off">Без ограничения</option>
              <option value="timer">Время</option>
              <option value="bars">Такты</option>
            </AppSelect></label
          >
          <label v-if="form.tracker.mode === 'timer'" class="field"
            ><span class="label">Время, секунд</span
            ><input v-model.number="form.tracker.seconds" class="input" type="number" min="1" max="86400"
          /></label>
          <label v-if="form.tracker.mode === 'bars'" class="field"
            ><span class="label">Количество тактов</span
            ><input v-model.number="form.tracker.bars" class="input" type="number" min="1" max="10000"
          /></label>
          <template v-if="form.tracker.mode !== 'off'">
            <label class="check-label"
              ><input v-model="form.tracker.countdown" type="checkbox" /> Обратный отсчёт</label
            >
            <label class="check-label"
              ><input v-model="form.tracker.stop_at_end" type="checkbox" /> Остановить метроном при достижении
              лимита</label
            >
            <label class="check-label"
              ><input v-model="form.tracker.reset_at_end" type="checkbox" /> Сбросить счётчик при достижении
              лимита</label
            >
            <label v-if="form.tracker.mode === 'bars' && !form.tracker.countdown" class="check-label"
              ><input v-model="form.tracker.start_from_one" type="checkbox" /> Считать такты с 1</label
            >
            <p class="muted metronome-help">
              Лимит останавливает только метроном. Практика продолжается по своему таймеру.
            </p>
          </template>
        </div>
      </details>
      <details class="metronome-details disclosure">
        <summary>
          <span class="disclosure-icon"><SlidersHorizontal aria-hidden="true" /></span>
          <span class="disclosure-copy"><strong>Автоизменение темпа</strong></span>
        </summary>
        <div class="metronome-detail-body disclosure-body">
          <label class="check-label"
            ><input v-model="form.automator.enabled" type="checkbox" /> Менять темп по тактам</label
          >
          <div v-if="form.automator.enabled" class="metronome-grid">
            <label class="field"
              ><span class="label">Изменять на BPM</span
              ><input v-model.number="form.automator.step_bpm" class="input" type="number" min="1" max="30"
            /></label>
            <label class="field"
              ><span class="label">Каждые N тактов</span
              ><input
                v-model.number="form.automator.every_bars"
                class="input"
                type="number"
                min="1"
                max="1000"
            /></label>
            <label class="field"
              ><span class="label">До темпа, BPM</span
              ><input
                v-model.number="form.automator.target_bpm"
                class="input"
                type="number"
                min="20"
                max="300"
            /></label>
          </div>
          <p class="muted metronome-help">Темп постепенно растёт или снижается до заданного значения.</p>
        </div>
      </details>
      <section class="metronome-presets" aria-labelledby="metronome-presets-title">
        <h3 id="metronome-presets-title">Мои настройки</h3>
        <template v-if="authenticated">
          <p v-if="loading" class="muted" role="status">Загружаем список…</p>
          <div v-if="presets.length" class="preset-load">
            <label class="field"
              ><span class="label">Сохранённые варианты</span
              ><AppSelect v-model="selected" class="select">
                <option value="">Выберите название</option>
                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                  {{ preset.name }}
                </option>
              </AppSelect></label
            ><button class="btn" :disabled="!selected || saving" @click="loadSelected">Загрузить</button>
          </div>
          <p v-else-if="!loading" class="muted">Сохраните первый вариант, чтобы выбирать его по названию.</p>
          <label class="field"
            ><span class="label">Название настроек</span
            ><input
              v-model="name"
              class="input"
              maxlength="120"
              placeholder="Например, спокойный ритм"
              @input="pendingPresetId = null"
          /></label>
          <div class="preset-actions">
            <button class="btn" :disabled="!name.trim() || saving" @click="savePreset(false)">
              {{ saving ? 'Сохраняем…' : 'Сохранить как новые' }}</button
            ><button
              v-if="currentPreset"
              class="btn"
              :disabled="!name.trim() || saving"
              @click="savePreset(true)"
            >
              Обновить выбранные
            </button>
          </div>
        </template>
        <p v-else class="muted">Войдите в аккаунт, чтобы сохранять настройки и выбирать их по названию.</p>
      </section>
      <label class="check-label"
        ><input v-model="form.enabled" type="checkbox" /> Использовать метроном в практике</label
      >
    </div>
    <template #footer
      ><button class="btn" @click="close">Отмена</button
      ><button class="btn btn-primary" @click="apply">Применить</button></template
    >
  </Modal>
</template>
<style scoped>
.metronome-settings {
  display: grid;
  gap: 22px;
  min-width: 0;
}
.metronome-tempo {
  display: grid;
  gap: 18px;
  padding: 20px;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface-soft);
}
.tempo-stepper {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr) 48px;
  gap: 12px;
  align-items: center;
}
.tempo-stepper .btn {
  padding: 0;
  width: 48px;
}
.tempo-value {
  display: grid;
  justify-items: center;
  gap: 2px;
  min-width: 0;
}
.tempo-value span {
  font-size: 12px;
  color: var(--muted);
}
.tempo-value input {
  width: 100%;
  min-width: 0;
  max-width: 170px;
  padding: 0;
  border: 0;
  color: var(--text);
  background: transparent;
  text-align: center;
  font-size: 54px;
  line-height: 1.1;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
  appearance: textfield;
}
.tempo-value input::-webkit-inner-spin-button {
  appearance: none;
}
.tempo-range,
input[type='range'] {
  width: 100%;
  min-width: 0;
  accent-color: var(--accent-dark);
}
.metronome-beats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(44px, 1fr));
  gap: 10px;
}
.beat-button {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-width: 44px;
  min-height: 58px;
  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--surface);
  color: var(--text);
  transition:
    background 0.12s,
    box-shadow 0.12s;
}
.beat-button > span {
  font-size: 23px;
  line-height: 1;
}
.beat-button small {
  font-size: 10px;
  color: var(--muted);
}
.beat-accent,
.beat-strong {
  color: var(--accent-dark);
  background: var(--accent-soft);
  border-color: #d1eed9;
}
.beat-strong {
  font-weight: 800;
}
.beat-mute {
  color: var(--muted);
  border-style: dashed;
}
.beat-button.pulsing {
  background: var(--accent);
  border-color: var(--accent);
  color: var(--text);
  box-shadow: 0 0 0 4px var(--accent-soft);
}
.metronome-help {
  font-size: 12px;
  line-height: 1.6;
}
.metronome-preview-controls,
.preset-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.preview-status {
  display: flex;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  font-size: 13px;
  color: var(--muted);
}
.preview-status strong {
  color: var(--text);
  font-variant-numeric: tabular-nums;
}
.metronome-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}
.metronome-grid .field,
.preset-load .field {
  min-width: 0;
}
.volume-label {
  display: flex;
  align-items: center;
  gap: 8px;
}
.volume-label > span {
  margin-left: auto;
  color: var(--muted);
  font-weight: 500;
}
.metronome-detail-body {
  display: grid;
  gap: 16px;
}
.metronome-presets {
  display: grid;
  gap: 16px;
  padding-top: 4px;
}
.metronome-presets h3 {
  font-size: var(--panel-title-size);
  font-weight: 650;
}
.metronome-presets p {
  font-size: 13px;
  line-height: 1.6;
}
.preset-load {
  display: flex;
  align-items: end;
  gap: 10px;
}
.preset-load .field {
  flex: 1;
}
.metronome-notice {
  padding: 12px 16px;
  border-radius: 12px;
  color: var(--accent-dark);
  background: var(--accent-soft);
  font-size: 13px;
}
@media (max-width: 480px) {
  .metronome-tempo {
    padding: 16px;
  }
  .metronome-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .metronome-preview-controls .btn-primary {
    flex: 1 1 100%;
  }
  .preset-load {
    flex-wrap: wrap;
  }
  .preset-load .field {
    flex-basis: 100%;
  }
  .preset-load > .btn {
    width: 100%;
  }
  .tempo-value input {
    font-size: 46px;
  }
}
</style>
