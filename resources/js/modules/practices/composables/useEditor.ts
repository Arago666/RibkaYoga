import { computed, onBeforeUnmount, ref } from 'vue';
import { api, ApiError } from '@/shared/api';
import {
  clone,
  fingerprint,
  normalizeRowGroups,
  payload,
  rowAsDocument,
  type Practice,
  type RowTemplate,
} from '../types';

export function useEditor(id: string, rowMode: boolean) {
  const document = ref<Practice | null>(null);
  const loading = ref(true),
    saving = ref(false),
    error = ref(''),
    conflict = ref(false),
    pending = ref(0);
  const undoStack = ref<Practice[]>([]),
    redoStack = ref<Practice[]>([]);
  const acknowledged = ref('');
  let timer: ReturnType<typeof setTimeout> | undefined;
  let inFlight: Promise<boolean> | null = null;
  let disposed = false;
  let loadVersion = 0;
  let historyGroup: string | null = null;
  const endpoint = `/api/${rowMode ? 'row-templates' : 'practices'}/${id}`;
  const dirty = computed(
    () =>
      !!document.value && (pending.value > 0 || fingerprint(document.value, rowMode) !== acknowledged.value),
  );
  const status = computed(() =>
    conflict.value
      ? 'Документ изменён в другом окне'
      : error.value
        ? 'Не удалось сохранить'
        : pending.value
          ? 'Добавляем асану…'
          : saving.value
            ? 'Сохраняем…'
            : dirty.value
              ? 'Есть изменения'
              : 'Все изменения сохранены',
  );
  async function load() {
    clearTimeout(timer);
    const version = ++loadVersion;
    loading.value = true;
    error.value = '';
    try {
      const result = await api<{ data: Practice | RowTemplate }>(endpoint, {
        signal: AbortSignal.timeout(30000),
      });
      if (disposed || version !== loadVersion) return false;
      document.value = rowMode ? rowAsDocument(result.data as RowTemplate) : (result.data as Practice);
      acknowledged.value = fingerprint(document.value, rowMode);
      undoStack.value = [];
      redoStack.value = [];
      conflict.value = false;
      historyGroup = null;
      return true;
    } catch (e) {
      if (!disposed && version === loadVersion)
        error.value = e instanceof Error ? e.message : 'Не удалось открыть документ.';
      return false;
    } finally {
      if (!disposed && version === loadVersion) loading.value = false;
    }
  }
  function schedule() {
    clearTimeout(timer);
    if (!disposed && !loading.value && !conflict.value && !pending.value && !document.value?.archived_at)
      timer = setTimeout(() => {
        void save();
      }, 800);
  }
  function change(action: (doc: Practice) => void, group: string | null = null) {
    if (!document.value || document.value.archived_at || loading.value || disposed) return;
    const before = clone(document.value);
    action(document.value);
    for (const block of document.value.blocks) for (const row of block.rows) normalizeRowGroups(row);
    const cellCount = document.value.blocks.reduce(
      (sum, block) => sum + block.rows.reduce((total, row) => total + row.cells.length, 0),
      0,
    );
    if (cellCount > 1000) {
      document.value = before;
      error.value = 'В одной практике допускается до 1000 ячеек.';
      return;
    }
    if (fingerprint(before, rowMode) === fingerprint(document.value, rowMode)) return;
    if (!group || historyGroup !== group) {
      undoStack.value.push(before);
      if (undoStack.value.length > 50) undoStack.value.shift();
    }
    historyGroup = group;
    redoStack.value = [];
    error.value = '';
    schedule();
  }
  function restore(from: typeof undoStack, to: typeof redoStack) {
    if (
      !document.value ||
      !from.value.length ||
      pending.value ||
      document.value.archived_at ||
      loading.value ||
      disposed
    )
      return;
    to.value.push(clone(document.value));
    const old = from.value.pop()!;
    old.revision = document.value.revision;
    document.value = old;
    error.value = '';
    historyGroup = null;
    schedule();
  }
  async function performSave(): Promise<boolean> {
    if (!document.value || pending.value || conflict.value || loading.value || disposed) return false;
    if (!dirty.value) return true;
    if (document.value.archived_at) return false;
    const version = loadVersion;
    const isCurrent = () => !disposed && version === loadVersion && !!document.value;
    const outgoing = clone(document.value),
      sent = fingerprint(outgoing, rowMode);
    saving.value = true;
    error.value = '';
    let succeeded = false;
    try {
      const result = await api<{ data: Practice | RowTemplate }>(endpoint, {
        method: 'PUT',
        body: JSON.stringify(payload(outgoing, rowMode)),
        signal: AbortSignal.timeout(30000),
      });
      if (!isCurrent()) return false;
      document.value.revision = result.data.revision;
      acknowledged.value = sent;
      succeeded = true;
    } catch (e) {
      if (!isCurrent()) return false;
      if (e instanceof ApiError && e.status === 409) conflict.value = true;
      else if (!(e instanceof ApiError)) {
        // A lost reply may have followed a committed transaction. Compare before retrying.
        try {
          const current = await api<{ data: Practice | RowTemplate }>(endpoint, {
            signal: AbortSignal.timeout(30000),
          });
          if (!isCurrent()) return false;
          const server = rowMode ? rowAsDocument(current.data as RowTemplate) : (current.data as Practice);
          if (fingerprint(server, rowMode) === sent) {
            document.value.revision = server.revision;
            acknowledged.value = sent;
            succeeded = true;
          } else if (server.revision !== outgoing.revision) conflict.value = true;
          else error.value = 'Соединение прервалось. Изменения остаются здесь — повторите сохранение.';
        } catch {
          if (isCurrent()) error.value = 'Нет соединения. Изменения остаются здесь — повторите сохранение.';
        }
      } else error.value = e.message;
    } finally {
      saving.value = false;
    }
    return succeeded;
  }
  async function save(): Promise<boolean> {
    clearTimeout(timer);
    if (inFlight) {
      await inFlight;
      if (disposed || loading.value || pending.value || conflict.value) return false;
      return dirty.value && !error.value ? save() : !!document.value && !dirty.value;
    }
    const attempt = performSave();
    inFlight = attempt;
    let result: boolean;
    try {
      result = await attempt;
    } finally {
      if (inFlight === attempt) inFlight = null;
    }
    if (result && dirty.value && !pending.value && !disposed) return save();
    // A newer unresolved insertion is still a local edit, even when this PUT succeeded.
    return result && !dirty.value && !pending.value && !conflict.value && !loading.value && !disposed;
  }
  function stop() {
    disposed = true;
    loadVersion++;
    clearTimeout(timer);
  }
  onBeforeUnmount(stop);
  return {
    document,
    loading,
    saving,
    error,
    conflict,
    pending,
    undoStack,
    redoStack,
    dirty,
    status,
    load,
    change,
    save,
    schedule,
    stop,
    endGroup: () => {
      historyGroup = null;
    },
    undo: () => restore(undoStack, redoStack),
    redo: () => restore(redoStack, undoStack),
  };
}
