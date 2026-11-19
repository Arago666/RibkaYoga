<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link2, UserRound } from '@lucide/vue';
import Modal from '@/shared/Modal.vue';
import DocumentSharingPanel from './DocumentSharingPanel.vue';
import PublicationPanel from './PublicationPanel.vue';

const props = defineProps<{
  open: boolean;
  documentId: string;
  title?: string;
  revision: number;
  showNames?: boolean;
  orientation?: string;
}>();
const emit = defineEmits<{ close: [] }>();
const section = ref<'person' | 'link'>('person');
const openedLink = ref(false);
const busy = ref(false);

watch(
  () => props.open,
  (open) => {
    if (!open) {
      section.value = 'person';
      openedLink.value = false;
    }
  },
);

function chooseLink() {
  openedLink.value = true;
  section.value = 'link';
}
</script>

<template>
  <Modal :open="open" title="Поделиться" @close="!busy && emit('close')">
    <template v-if="open">
      <p v-if="title" class="share-practice-name">{{ title }}</p>
      <div class="share-sections" role="group" aria-label="Способ поделиться практикой">
        <button
          type="button"
          class="btn btn-ghost"
          :aria-pressed="section === 'person'"
          :disabled="busy"
          @click="section = 'person'"
        >
          <UserRound :size="17" />По логину
        </button>
        <button
          type="button"
          class="btn btn-ghost"
          :aria-pressed="section === 'link'"
          :disabled="busy"
          @click="chooseLink"
        >
          <Link2 :size="17" />По ссылке
        </button>
      </div>
      <DocumentSharingPanel v-show="section === 'person'" :document-id="documentId" v-model:busy="busy" />
      <PublicationPanel
        v-if="openedLink"
        v-show="section === 'link'"
        :document-id="documentId"
        :revision="revision"
        :show-names="showNames"
        :orientation="orientation"
        v-model:busy="busy"
      />
    </template>
    <template #footer>
      <button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('close')">Закрыть</button>
    </template>
  </Modal>
</template>

<style scoped>
.share-practice-name {
  margin: 0 0 14px;
  font-weight: 600;
  overflow-wrap: anywhere;
}
.share-sections {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px;
  margin-bottom: var(--section-gap);
  padding: 4px;
  border: 1px solid var(--line);
  border-radius: var(--panel-radius);
  background: var(--surface-soft);
}
.share-sections .btn {
  justify-content: center;
  white-space: normal;
  padding-inline: 8px;
}
.share-sections .btn[aria-pressed='true'] {
  background: var(--accent-soft);
  color: var(--accent-dark);
}
</style>
