<script setup lang="ts">
import type { Category } from '../types';
defineProps<{
  categories: Category[];
  prefix: string;
  disabled?: boolean;
  imageUrl: string;
  imageName?: string;
  imageRequired?: boolean;
}>();
const name = defineModel<string>('name', { required: true });
const aliases = defineModel<string>('aliases', { required: true });
const categoryIds = defineModel<string[]>('categoryIds', { required: true });
const emit = defineEmits<{ image: [file: File] }>();
function chooseImage(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (file) emit('image', file);
  input.value = '';
}
</script>

<template>
  <div class="asana-fields">
    <div class="field">
      <label class="label" :for="`${prefix}-name`">Название</label>
      <input
        :id="`${prefix}-name`"
        v-model="name"
        class="input"
        required
        maxlength="160"
        :disabled="disabled"
      />
    </div>
    <div class="field">
      <label class="label" :for="`${prefix}-aliases`">Другие названия</label>
      <textarea
        :id="`${prefix}-aliases`"
        v-model="aliases"
        class="textarea"
        rows="2"
        placeholder="Каждое название — с новой строки"
        :disabled="disabled"
      />
    </div>
    <fieldset class="choice-group">
      <legend class="label">Категории</legend>
      <div v-if="categories.length" class="choices">
        <label v-for="category in categories" :key="category.id">
          <input v-model="categoryIds" type="checkbox" :value="category.id" :disabled="disabled" />{{
            category.name
          }}
        </label>
      </div>
      <p v-else class="muted">Категории можно создать в разделе «Категории».</p>
    </fieldset>
    <div class="image-field">
      <img v-if="imageUrl" :src="imageUrl" :alt="name || 'Предпросмотр карточки'" />
      <div class="field">
        <label class="label" :for="`${prefix}-image`"
          >Изображение{{ imageRequired ? ' (обязательно)' : '' }}</label
        >
        <input
          :id="`${prefix}-image`"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          :disabled="disabled"
          @change="chooseImage"
        />
        <p v-if="imageName" class="image-name">{{ imageName }}</p>
        <p class="image-help muted">PNG, JPEG или WebP · до 10 МБ. Можно выбрать другое изображение.</p>
        <slot name="image-help" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.asana-fields {
  display: grid;
  gap: 18px;
  min-width: 0;
}
.choice-group {
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}
.choices {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
  max-height: 160px;
  overflow: auto;
}
.choices label {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 10px;
  background: #f7f9f6;
  border-radius: 9px;
  font-size: 0.85rem;
}
.choices input {
  accent-color: #397a28;
}
.image-field {
  display: flex;
  gap: 16px;
  align-items: center;
  padding: 14px;
  background: #f7f9f6;
  border-radius: 14px;
}
.image-field img {
  width: 80px;
  height: 90px;
  object-fit: contain;
  flex: none;
}
.image-field .field {
  min-width: 0;
}
.image-field input {
  max-width: 100%;
  font-size: 0.8rem;
}
.image-help,
.image-name {
  font-size: 0.78rem;
  line-height: 1.5;
  margin: 0;
}
.image-name {
  overflow-wrap: anywhere;
}
@media (max-width: 480px) {
  .image-field {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
