export interface CollectionFolder {
  id: string;
  name: string;
  parent_id: string | null;
  revision: number;
  path: string;
  collections_count: number;
  total_collections_count: number;
}

export interface FolderOption extends CollectionFolder {
  depth: number;
}

export function folderOptions(folders: CollectionFolder[], omitSubtreeOf?: string): FolderOption[] {
  const result: FolderOption[] = [];
  const seen = new Set<string>();
  const compare = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });
  const walk = (parent: string | null, depth: number) => {
    const children = folders.filter((folder) => folder.parent_id === parent);
    children.sort((first, second) => compare.compare(first.name, second.name));
    for (const folder of children) {
      if (folder.id === omitSubtreeOf || seen.has(folder.id)) continue;
      seen.add(folder.id);
      result.push({ ...folder, depth });
      walk(folder.id, depth + 1);
    }
  };
  walk(null, 0);
  return result;
}
