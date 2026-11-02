<script setup lang="ts">
import AppSelect from '@/shared/AppSelect.vue';
import { onMounted, reactive, ref, watch } from 'vue';
import { Info, ListOrdered, Package, FileCheck } from '@lucide/vue';
import { api } from '@/shared/api';
import { errorText } from '../types';
import { type FacetAssignments, type FacetDictionary, type ReferenceFields } from '../facets';
import FacetChoices from './FacetChoices.vue';
const reference = defineModel<ReferenceFields>('reference', { required: true });
const assignments = defineModel<FacetAssignments>('assignments', { required: true });
defineProps<{ disabled?: boolean; prefix: string }>();
const dictionary = ref<FacetDictionary>({ effect: [], anatomy: [], position: [], difficulty: [] }),
  error = ref('');
async function load() {
  try {
    dictionary.value = (await api<{ facets: FacetDictionary }>('/api/categories')).facets;
    error.value = '';
  } catch (failure) {
    error.value = errorText(failure);
  }
}
onMounted(load);
const lineFields = ['steps', 'teacher_cues', 'benefits', 'equipment', 'precautions', 'adaptations'] as const;
type LineField = (typeof lineFields)[number];
const lineDrafts = reactive<Record<LineField, string>>({
  steps: '',
  teacher_cues: '',
  benefits: '',
  equipment: '',
  precautions: '',
  adaptations: '',
});
watch(
  reference,
  (value) => {
    for (const field of lineFields) lineDrafts[field] = value[field].join('\n');
  },
  { immediate: true },
);
function setLines(field: LineField, event: Event) {
  const raw = (event.target as HTMLTextAreaElement).value;
  // Preserve the raw draft, including a trailing newline, while updating save data immediately.
  // Updating the shared field follows the same path as scalar v-model bindings.
  lineDrafts[field] = raw;
  reference.value[field] = raw
    .split(/\r?\n/)
    .map((value) => value.trim())
    .filter(Boolean);
}
</script>
<template>
  <section class="reference-fields">
    <div class="language-fields">
      <label
        >Санскрит кириллицей<input
          v-model="reference.sanskrit_cyrillic"
          class="input"
          :disabled="disabled"
          maxlength="160" /></label
      ><label
        >Санскрит латиницей<input
          v-model="reference.sanskrit_latin"
          class="input"
          :disabled="disabled"
          maxlength="160"
      /></label>
    </div>
    <h3 class="label">Признаки асаны</h3>
    <p class="muted">
      Заполняйте только проверенные признаки этой версии позы. Неизвестный уровень оставьте без назначения.
    </p>
    <FacetChoices v-model="assignments" :dictionary="dictionary" :disabled="disabled" collapsible />
    <p v-if="error" class="error-banner">
      {{ error }} <button type="button" class="btn btn-ghost" @click="load">Повторить</button>
    </p>
    <label
      >Разметка<AppSelect v-model="reference.classification_status" class="select" :disabled="disabled">
        <option value="unreviewed">Требует проверки</option>
        <option value="partial">Частичная разметка</option>
        <option value="reviewed">Четыре группы проверены</option>
      </AppSelect></label
    >
    <details class="disclosure" open>
      <summary>
        <span class="disclosure-icon"><Info aria-hidden="true" /></span>
        <span class="disclosure-copy"><strong>Информация</strong></span>
      </summary>
      <div class="section-fields disclosure-body">
        <label :for="`${prefix}-information-description`"
          >Описание
          <textarea
            :id="`${prefix}-information-description`"
            v-model="reference.information_description"
            class="textarea"
            rows="4"
            maxlength="5000"
            :disabled="disabled"
            placeholder="Описание асаны обычным текстом"
          />
        </label>
        <label :for="`${prefix}-teacher-cues`"
          >Подсказки преподавателю
          <textarea
            :id="`${prefix}-teacher-cues`"
            class="textarea"
            rows="3"
            :disabled="disabled"
            :value="lineDrafts.teacher_cues"
            placeholder="Каждый пункт — с новой строки"
            @input="setLines('teacher_cues', $event)"
          />
        </label>
        <label :for="`${prefix}-benefits`"
          >Польза
          <textarea
            :id="`${prefix}-benefits`"
            class="textarea"
            rows="3"
            :disabled="disabled"
            :value="lineDrafts.benefits"
            placeholder="Каждый пункт — с новой строки"
            @input="setLines('benefits', $event)"
          />
        </label>
        <label :for="`${prefix}-precautions`"
          >Предосторожности
          <textarea
            :id="`${prefix}-precautions`"
            class="textarea"
            rows="3"
            :disabled="disabled"
            :value="lineDrafts.precautions"
            placeholder="Каждый пункт — с новой строки"
            @input="setLines('precautions', $event)"
          />
        </label>
      </div>
    </details>
    <details class="disclosure">
      <summary>
        <span class="disclosure-icon"><ListOrdered aria-hidden="true" /></span>
        <span class="disclosure-copy"><strong>Шаги выполнения</strong></span>
      </summary>
      <div class="section-fields disclosure-body">
        <label :for="`${prefix}-steps`"
          >Шаги выполнения — каждый с новой строки
          <textarea
            :id="`${prefix}-steps`"
            class="textarea"
            rows="4"
            :disabled="disabled"
            :value="lineDrafts.steps"
            @input="setLines('steps', $event)"
          />
        </label>
      </div>
    </details>
    <details class="disclosure">
      <summary>
        <span class="disclosure-icon"><Package aria-hidden="true" /></span>
        <span class="disclosure-copy"><strong>Оборудование и адаптации</strong></span>
      </summary>
      <div class="section-fields disclosure-body">
        <label
          >Оборудование — каждый предмет с новой строки<textarea
            class="textarea"
            :disabled="disabled"
            :value="lineDrafts.equipment"
            placeholder="блок&#10;ремень&#10;стена"
            @input="setLines('equipment', $event)"
          />
        </label>
        <label
          >Замок / связанное положение рук<AppSelect
            class="select"
            :disabled="disabled"
            :value="reference.has_bind === null ? '' : reference.has_bind ? 'yes' : 'no'"
            @change="
              reference.has_bind =
                ($event.target as HTMLSelectElement).value === ''
                  ? null
                  : ($event.target as HTMLSelectElement).value === 'yes'
            "
          >
            <option value="">Не указано</option>
            <option value="yes">Да</option>
            <option value="no">Нет</option>
          </AppSelect></label
        >
        <label
          >Допустимые адаптации<textarea
            class="textarea"
            :disabled="disabled"
            :value="lineDrafts.adaptations"
            @input="setLines('adaptations', $event)"
          />
        </label>
      </div>
    </details>
    <details class="disclosure">
      <summary>
        <span class="disclosure-icon"><FileCheck aria-hidden="true" /></span>
        <span class="disclosure-copy"><strong>Редакторский источник и проверка</strong></span>
      </summary>
      <div class="section-fields disclosure-body">
        <label
          >Автор справки<input
            v-model="reference.editorial.author"
            class="input"
            :disabled="disabled"
            maxlength="160" /></label
        ><label
          >Источник справки<textarea
            v-model="reference.editorial.source"
            class="textarea"
            :disabled="disabled"
            maxlength="2000"
          /></label
        ><label
          >Что требует уточнения<textarea
            v-model="reference.editorial.review_note"
            class="textarea"
            :disabled="disabled"
            maxlength="2000"
          /></label
        ><label class="check"
          ><input v-model="reference.editorial.reviewed" type="checkbox" :disabled="disabled" /> Справка
          проверена автором</label
        >
      </div>
    </details>
  </section>
</template>
<style scoped>
.reference-fields {
  display: grid;
  gap: var(--section-gap);
  min-width: 0;
}
.reference-fields h3,
.reference-fields p {
  margin: 0;
}
.reference-fields p {
  font-size: 0.8rem;
  line-height: 1.5;
}
.reference-fields label {
  display: grid;
  gap: 6px;
  font-size: 0.84rem;
}
.language-fields {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.section-fields {
  display: grid;
  gap: 16px;
}
.reference-fields .check {
  display: flex;
  align-items: center;
  gap: 6px;
}
@media (max-width: 500px) {
  .language-fields {
    grid-template-columns: 1fr;
  }
}
</style>
