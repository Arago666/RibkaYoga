import type { DrawingDocument } from '@/modules/catalog/drawing';

export interface QueuedDrawing {
  id: string;
  name: string;
  file: File;
  drawing: DrawingDocument;
}

export interface SketchDraft {
  key: string;
  scope: string;
  updatedAt: number;
  revision: number;
  title: string;
  format: string;
  drawing: DrawingDocument | null;
  queue: QueuedDrawing[];
  target: string;
}

let database: Promise<IDBDatabase> | undefined;
function openDatabase() {
  database ??= new Promise<IDBDatabase>((resolve, reject) => {
    const request = indexedDB.open('ribkayoga-sketch-drafts', 1);
    request.onupgradeneeded = () => {
      const drafts = request.result.createObjectStore('drafts', { keyPath: 'key' });
      drafts.createIndex('scope', 'scope');
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
    request.onblocked = () => reject(new Error('Хранилище черновиков занято.'));
  }).catch((error) => {
    database = undefined;
    throw error;
  });
  return database;
}

async function readScope(scope: string): Promise<SketchDraft[]> {
  const db = await openDatabase();
  return new Promise((resolve, reject) => {
    const request = db.transaction('drafts').objectStore('drafts').index('scope').getAll(scope);
    request.onsuccess = () =>
      resolve((request.result as SketchDraft[]).sort((a, b) => b.updatedAt - a.updatedAt));
    request.onerror = () => reject(request.error);
  });
}

async function write(action: (store: IDBObjectStore) => void) {
  const db = await openDatabase();
  return new Promise<void>((resolve, reject) => {
    const transaction = db.transaction('drafts', 'readwrite');
    transaction.oncomplete = () => resolve();
    transaction.onabort = () => reject(transaction.error);
    action(transaction.objectStore('drafts'));
  });
}

export const readSketchDrafts = readScope;
export const writeSketchDraft = (draft: SketchDraft) =>
  write((store) => {
    store.put(draft);
  });
export const clearSketchDraft = (key: string) =>
  write((store) => {
    store.delete(key);
  });
/** Successful recovery or explicit discard must leave any newer edit in another open tab intact. */
export function removeSketchDraft(draft: SketchDraft) {
  return write((store) => {
    const request = store.get(draft.key);
    request.onsuccess = () => {
      if (request.result?.updatedAt === draft.updatedAt) store.delete(draft.key);
    };
  });
}
