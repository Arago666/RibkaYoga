export interface Category {
  id: string;
  name: string;
  revision: number;
  dimension?: import('./facets').Dimension | null;
  system_key?: string | null;
}
export interface Asana {
  illustration_variant_id?: string | null;
  id: string;
  name: string;
  aliases: string[];
  description: string | null;
  categories: Category[];
  image_url: string;
  thumbnail_url: string;
  image_asset_id: string;
  revision: number;
  archived_at: string | null;
  collection_ids?: string[];
  relations?: AsanaRelation[];
  drawing_source?: DrawingDocument | null;
  sanskrit_cyrillic?: string | null;
  sanskrit_latin?: string | null;
  steps?: string[];
  teacher_cues?: string[];
  equipment?: string[];
  precautions?: string[];
  adaptations?: string[];
  editorial?: { author?: string; source?: string; reviewed?: boolean; review_note?: string };
  has_bind?: boolean | null;
  classification_status?: 'unreviewed' | 'partial' | 'reviewed';
  facet_assignments?: import('./facets').FacetAssignments;
  facet_values?: import('./facets').FacetValue[];
  favorite?: boolean;
}
export type RelationKind = 'simpler' | 'harder' | 'preparation' | 'follow_up' | 'compensation' | 'variation';
export interface AsanaRelation {
  asana_id: string;
  kind: RelationKind;
  name: string;
  image_url?: string;
  archived_at?: string | null;
}
export const relationLabels: Record<RelationKind, string> = {
  simpler: 'Упрощение',
  harder: 'Усложнение',
  preparation: 'Подводящее упражнение',
  follow_up: 'Последующий вариант',
  compensation: 'Компенсация',
  variation: 'Другой вариант',
};
export function categoryColor(name: string): string {
  const value = name.toLocaleLowerCase('ru').replaceAll('ё', 'е');
  return value === 'лежа'
    ? '#d95d5d'
    : value === 'сидя'
      ? '#52a85c'
      : value === 'стоя'
        ? '#e5b83d'
        : '#81947a';
}
export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
export interface Collection {
  id: string;
  name: string;
  description: string | null;
  revision: number;
  asana_ids: string[];
  items: Asana[];
  archived_at: string | null;
  items_count?: number;
  folder_id?: string | null;
  folder_path?: string | null;
}
export function errorText(error: unknown): string {
  if (error instanceof Error) return error.message;
  return 'Не удалось выполнить действие. Попробуйте ещё раз.';
}
export function categoryLabel(category: Category): string {
  return category.name;
}
export function cardCount(count: number): string {
  const tail = Math.abs(count) % 100;
  const last = tail % 10;
  const word =
    tail >= 11 && tail <= 14
      ? 'карточек'
      : last === 1
        ? 'карточка'
        : last >= 2 && last <= 4
          ? 'карточки'
          : 'карточек';
  return `${count} ${word}`;
}
import type { DrawingDocument } from './drawing';
