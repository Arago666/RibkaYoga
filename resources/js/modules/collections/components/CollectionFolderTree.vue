<script setup lang="ts">
import { computed, ref } from 'vue';
import { Folder, FolderOpen, Plus, Pencil, Trash2, Layers3, ChevronDown, ChevronRight } from '@lucide/vue';
import { folderOptions, type CollectionFolder } from '../types';
const props = defineProps<{ folders: CollectionFolder[]; selected: string; disabled: boolean }>();
defineEmits<{
  select: [id: string];
  create: [parentId: string | null];
  edit: [folder: CollectionFolder];
  remove: [folder: CollectionFolder];
}>();
const collapsed = ref(new Set<string>());
const hasChildren = (id: string) => props.folders.some((folder) => folder.parent_id === id);
const options = computed(() =>
  folderOptions(props.folders).filter((folder) => {
    for (
      let parent = folder.parent_id;
      parent;
      parent = props.folders.find((entry) => entry.id === parent)?.parent_id || null
    ) {
      if (collapsed.value.has(parent)) return false;
    }
    return true;
  }),
);
function toggle(id: string) {
  const next = new Set(collapsed.value);
  next.has(id) ? next.delete(id) : next.add(id);
  collapsed.value = next;
}
</script>
<template>
  <nav class="folder-tree card" aria-label="Папки подборок">
    <div class="folder-heading">
      <strong>Папки</strong>
      <button
        class="icon-btn"
        :disabled="disabled"
        aria-label="Создать папку верхнего уровня"
        title="Создать папку"
        @click="$emit('create', null)"
      >
        <Plus :size="17" />
      </button>
    </div>
    <button
      class="folder-link"
      :class="{ active: selected === 'all' }"
      :disabled="disabled"
      @click="$emit('select', 'all')"
    >
      <Layers3 :size="17" /> Все подборки
    </button>
    <button
      class="folder-link"
      :class="{ active: selected === 'root' }"
      :disabled="disabled"
      @click="$emit('select', 'root')"
    >
      <Folder :size="17" /> Без папки
    </button>
    <div
      v-for="folder in options"
      :key="folder.id"
      class="folder-row"
      :style="{ paddingLeft: `${folder.depth * 13}px` }"
    >
      <button
        v-if="hasChildren(folder.id)"
        class="collapse-folder"
        :aria-label="`${collapsed.has(folder.id) ? 'Развернуть' : 'Свернуть'}: ${folder.name}`"
        :aria-expanded="!collapsed.has(folder.id)"
        @click="toggle(folder.id)"
      >
        <ChevronRight v-if="collapsed.has(folder.id)" :size="14" /><ChevronDown v-else :size="14" />
      </button>
      <span v-else class="collapse-space" />
      <button
        class="folder-link"
        :class="{ active: selected === folder.id }"
        :disabled="disabled"
        :title="folder.path"
        @click="$emit('select', folder.id)"
      >
        <FolderOpen v-if="selected === folder.id" :size="16" /><Folder v-else :size="16" /><span>{{
          folder.name
        }}</span
        ><small>{{ folder.collections_count }}</small>
      </button>
      <div class="folder-tools">
        <button
          class="icon-btn"
          :disabled="disabled"
          :aria-label="`Создать подпапку: ${folder.name}`"
          title="Создать подпапку"
          @click="$emit('create', folder.id)"
        >
          <Plus :size="14" />
        </button>
        <button
          class="icon-btn"
          :disabled="disabled"
          :aria-label="`Изменить или переместить папку: ${folder.name}`"
          title="Изменить или переместить"
          @click="$emit('edit', folder)"
        >
          <Pencil :size="13" />
        </button>
        <button
          class="icon-btn"
          :disabled="disabled"
          :aria-label="`Удалить пустую папку: ${folder.name}`"
          title="Удалить пустую папку"
          @click="$emit('remove', folder)"
        >
          <Trash2 :size="13" />
        </button>
      </div>
    </div>
    <p v-if="!folders.length" class="muted folder-empty">Организуйте подборки по годам, месяцам и неделям.</p>
  </nav>
</template>
<style scoped>
.folder-tree {
  padding: 12px !important;
  align-self: start;
  min-width: 0;
}
.folder-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.folder-heading strong {
  font-size: 0.92rem;
}
.folder-link {
  border: 0;
  background: transparent;
  color: #566352;
  display: flex;
  align-items: center;
  gap: 7px;
  width: 100%;
  text-align: left;
  border-radius: 8px;
  padding: 9px 7px;
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
  min-width: 0;
}
.folder-link.active {
  background: #edf5e7;
  color: #347621;
  font-weight: 650;
}
.folder-link:hover {
  background: #f2f6ef;
}
.folder-link svg {
  flex: none;
}
.folder-link span {
  min-width: 0;
  overflow-wrap: anywhere;
}
.folder-link small {
  margin-left: auto;
  color: #7d8c76;
}
.folder-row {
  display: flex;
  align-items: center;
  min-width: 0;
}
.folder-row > .folder-link {
  flex: 1;
}
.folder-tools {
  display: flex;
  flex: none;
}
.folder-tools > .icon-btn {
  height: 25px;
  width: 23px;
  padding: 3px;
}
.collapse-folder {
  border: 0;
  padding: 0;
  background: none;
  color: #73816c;
  cursor: pointer;
}
.collapse-folder,
.collapse-space {
  width: 14px;
  flex: none;
}
.folder-empty {
  font-size: 0.77rem;
  line-height: 1.5;
  margin: 12px 6px 3px;
}
</style>
