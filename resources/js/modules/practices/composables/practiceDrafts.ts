import { uid } from '@/shared/uuid';
import type { Practice } from '../types';

export interface PracticeDraft {
  key: string;
  raw: string;
  document: Practice;
  updatedAt: number;
}

/** Each open editor owns its own entry so another tab cannot erase it. */
export function practiceDrafts(scope: string, documentId: string) {
  const prefix = `ribka-practice-draft:v1:${scope}:`;
  const key = prefix + uid();

  function remove(draft: Pick<PracticeDraft, 'key' | 'raw'>) {
    if (localStorage.getItem(draft.key) === draft.raw) localStorage.removeItem(draft.key);
  }

  function list(): PracticeDraft[] {
    const found: PracticeDraft[] = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (!key?.startsWith(prefix)) continue;
      const raw = localStorage.getItem(key);
      if (!raw) continue;
      try {
        const data = JSON.parse(raw);
        const document = data?.document as Practice | undefined;
        if (
          document?.id === documentId &&
          Number.isSafeInteger(document.revision) &&
          typeof document.title === 'string' &&
          Array.isArray(document.blocks) &&
          document.blocks.length &&
          document.blocks.every(
            (block) =>
              typeof block.title === 'string' &&
              Array.isArray(block.rows) &&
              block.rows.length &&
              block.rows.every((row) => Array.isArray(row.cells) && row.cells.every(Boolean)),
          ) &&
          Number.isFinite(data.updatedAt)
        ) {
          found.push({ key, raw, document, updatedAt: data.updatedAt });
        }
      } catch {
        // An unreadable entry must not replace the server document.
      }
    }
    return found.sort((a, b) => b.updatedAt - a.updatedAt);
  }

  return {
    list,
    remove,
    write(document: Practice) {
      localStorage.setItem(key, JSON.stringify({ document, updatedAt: Date.now() }));
    },
    clear() {
      localStorage.removeItem(key);
    },
  };
}
