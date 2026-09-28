export interface Snapshot {
  id: string;
  name: string;
  image_url: string;
  source_asana_id: string;
}
export interface Cell {
  id: string;
  card_snapshot_id: string | null;
  custom_name: string | null;
  group_key: string | null;
  note: string | null;
  card: Snapshot | null;
}
export interface Row {
  id: string;
  note: string | null;
  cells: Cell[];
}
export interface Block {
  id: string;
  title: string;
  purpose: string | null;
  rows: Row[];
}
export interface Practice {
  id: string;
  kind: 'practice' | 'template';
  title: string;
  theme: string | null;
  note: string | null;
  practice_date: string | null;
  revision: number;
  blocks: Block[];
  archived_at?: string | null;
}
export interface RowTemplate {
  id: string;
  title: string;
  theme: string | null;
  note: string | null;
  revision: number;
  cells: Cell[];
  archived_at?: string | null;
}
export const uid = () => crypto.randomUUID();
export const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value));
export const emptyCell = (): Cell => ({
  id: uid(),
  card_snapshot_id: null,
  custom_name: null,
  group_key: null,
  note: null,
  card: null,
});
export const cellFilled = (cell: Cell): boolean => Boolean(cell.card_snapshot_id || cell.custom_name?.trim());
export const cellLabel = (cell: Cell): string => cell.card?.name || cell.custom_name || 'Пустая ячейка';
export function cellGroupEdges(row: Row, index: number): { start: boolean; end: boolean } {
  const key = row.cells[index]?.group_key;
  return {
    start: !!key && row.cells[index - 1]?.group_key !== key,
    end: !!key && row.cells[index + 1]?.group_key !== key,
  };
}
export function normalizeRowGroups(row: Row): void {
  const counts = new Map<string, number>();
  for (const cell of row.cells)
    if (cell.group_key) counts.set(cell.group_key, (counts.get(cell.group_key) || 0) + 1);
  for (const cell of row.cells) if (cell.group_key && counts.get(cell.group_key) === 1) cell.group_key = null;
}
export const emptyRow = (count = 6): Row => ({
  id: uid(),
  note: null,
  cells: Array.from({ length: count }, emptyCell),
});
export const emptyBlock = (title: string): Block => ({ id: uid(), title, purpose: null, rows: [emptyRow()] });
export const copyRow = (row: Row): Row => ({
  ...clone(row),
  id: uid(),
  cells: row.cells.map((cell) => ({ ...clone(cell), id: uid() })),
});
export const copyBlock = (block: Block): Block => ({
  ...clone(block),
  id: uid(),
  rows: block.rows.map(copyRow),
});
export function payload(document: Practice, rowMode = false) {
  const cells = (row: Row) =>
    row.cells.map(({ id, card_snapshot_id, custom_name, group_key, note }) => ({
      id,
      card_snapshot_id,
      group_key: group_key || null,
      custom_name: card_snapshot_id ? null : custom_name?.trim() || null,
      note: card_snapshot_id || custom_name?.trim() ? note || null : null,
    }));
  const common = {
    title: document.title.trim(),
    theme: document.theme || null,
    expected_revision: document.revision,
  };
  if (rowMode)
    return {
      ...common,
      note: document.blocks[0].rows[0].note || null,
      cells: cells(document.blocks[0].rows[0]),
    };
  return {
    ...common,
    note: document.note || null,
    practice_date: document.practice_date || null,
    blocks: document.blocks.map((block) => ({
      id: block.id,
      title: block.title.trim(),
      purpose: block.purpose || null,
      rows: block.rows.map((row) => ({ id: row.id, note: row.note || null, cells: cells(row) })),
    })),
  };
}
export function fingerprint(document: Practice, rowMode = false): string {
  const { expected_revision: _revision, ...data } = payload(document, rowMode);
  return JSON.stringify(data);
}
export function rowAsDocument(row: RowTemplate): Practice {
  return {
    id: row.id,
    kind: 'template',
    title: row.title,
    theme: row.theme,
    note: null,
    practice_date: null,
    revision: row.revision,
    archived_at: row.archived_at,
    blocks: [
      {
        id: 'row-wrapper',
        title: 'Сохранённая строка',
        purpose: null,
        rows: [{ id: 'row-wrapper-row', note: row.note, cells: row.cells }],
      },
    ],
  };
}
