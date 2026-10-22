<script setup lang="ts">
import { onMounted, ref } from 'vue';
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
function setLines(
  field: 'steps' | 'teacher_cues' | 'equipment' | 'precautions' | 'adaptations',
  event: Event,
) {
  reference.value = {
    ...reference.value,
    [field]: (event.target as HTMLTextAreaElement).value
      .split('\n')
      .map((v) => v.trim())
      .filter(Boolean),
  };
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
      >Разметка<select v-model="reference.classification_status" class="select" :disabled="disabled">
        <option value="unreviewed">Требует проверки</option>
        <option value="partial">Частичная разметка</option>
        <option value="reviewed">Четыре группы проверены</option>
      </select></label
    >
    <details>
      <summary>Выполнение и подсказки</summary>
      <div class="section-fields">
        <label :for="`${prefix}-steps`"
          >Шаги выполнения — каждый с новой строки<textarea
            :id="`${prefix}-steps`"
            class="textarea"
            rows="4"
            :disabled="disabled"
            :value="reference.steps.join('\n')"
            @change="setLines('steps', $event)"
          />
        </label>
        <label
          >Подсказки преподавателю — каждая с новой строки<textarea
            class="textarea"
            rows="3"
            :disabled="disabled"
            :value="reference.teacher_cues.join('\n')"
            @change="setLines('teacher_cues', $event)"
          />
        </label>
      </div>
    </details>
    <details>
      <summary>Оборудование и адаптации</summary>
      <div class="section-fields">
        <label
          >Оборудование — каждый предмет с новой строки<textarea
            class="textarea"
            :disabled="disabled"
            :value="reference.equipment.join('\n')"
            placeholder="блок&#10;ремень&#10;стена"
            @change="setLines('equipment', $event)"
          />
        </label>
        <label
          >Замок / связанное положение рук<select
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
          </select></label
        >
        <label
          >Предосторожности — только проверенные автором<textarea
            class="textarea"
            :disabled="disabled"
            :value="reference.precautions.join('\n')"
            @change="setLines('precautions', $event)"
          />
        </label>
        <label
          >Допустимые адаптации<textarea
            class="textarea"
            :disabled="disabled"
            :value="reference.adaptations.join('\n')"
            @change="setLines('adaptations', $event)"
          />
        </label>
      </div>
    </details>
    <details>
      <summary>Редакторский источник и проверка</summary>
      <div class="section-fields">
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
  gap: 14px;
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
  gap: 12px;
}
.reference-fields details {
  border: 1px solid #e3e9df;
  border-radius: 10px;
  padding: 12px;
}
.reference-fields summary {
  cursor: pointer;
  font-weight: 600;
  font-size: 0.87rem;
}
.section-fields {
  display: grid;
  gap: 14px;
  margin-top: 14px;
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
