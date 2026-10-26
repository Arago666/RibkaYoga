import type { Category, Page, Asana } from './types';
export const dimensions = ['effect', 'anatomy', 'position', 'difficulty'] as const;
export type Dimension = (typeof dimensions)[number];
export type FacetAssignments = Record<Dimension, string[]>;
export const dimensionLabels: Record<Dimension, string> = {
  effect: 'Эффект / Направление практики',
  anatomy: 'Зона тела / Анатомия',
  position: 'Положение тела',
  difficulty: 'Уровень сложности',
};
export interface FacetValue extends Category {
  dimension: Dimension;
  system_key: string;
  count?: number;
  sort_order?: number;
}
export type FacetDictionary = Record<Dimension, FacetValue[]>;
export interface CatalogFilters extends FacetAssignments {
  categories: string[];
  favorite: boolean;
  unclassified: boolean;
  has_bind: '' | 'yes' | 'no' | 'unknown';
  equipment: string;
}
export interface CatalogPage extends Page<Asana> {
  facets?: FacetDictionary;
}
export interface ReferenceFields {
  sanskrit_cyrillic: string;
  sanskrit_latin: string;
  steps: string[];
  teacher_cues: string[];
  equipment: string[];
  precautions: string[];
  adaptations: string[];
  has_bind: boolean | null;
  classification_status: 'unreviewed' | 'partial' | 'reviewed';
  editorial: { author?: string; source?: string; reviewed?: boolean; review_note?: string };
}
export function emptyAssignments(): FacetAssignments {
  return { effect: [], anatomy: [], position: [], difficulty: [] };
}
export function emptyFilters(): CatalogFilters {
  return {
    ...emptyAssignments(),
    categories: [],
    favorite: false,
    unclassified: false,
    has_bind: '',
    equipment: '',
  };
}
export function emptyReference(): ReferenceFields {
  return {
    sanskrit_cyrillic: '',
    sanskrit_latin: '',
    steps: [],
    teacher_cues: [],
    equipment: [],
    precautions: [],
    adaptations: [],
    has_bind: null,
    classification_status: 'unreviewed',
    editorial: {},
  };
}
export function filtersFromUrl(params: URLSearchParams): CatalogFilters {
  const result = emptyFilters();
  result.categories = params.getAll('categories[]');
  const legacyCategory = params.get('category');
  if (legacyCategory && !result.categories.includes(legacyCategory)) result.categories.push(legacyCategory);
  for (const dimension of dimensions) result[dimension] = params.getAll(`${dimension}[]`);
  result.favorite = params.get('favorite') === '1';
  result.unclassified = params.get('unclassified') === '1';
  const bind = params.get('has_bind');
  if (bind === 'yes' || bind === 'no' || bind === 'unknown') result.has_bind = bind;
  result.equipment = params.get('equipment') ?? '';
  return result;
}
export function setFilterParams(params: URLSearchParams, filters: CatalogFilters): URLSearchParams {
  params.delete('categories[]');
  for (const id of filters.categories) params.append('categories[]', id);
  for (const dimension of dimensions) {
    params.delete(`${dimension}[]`);
    for (const id of filters[dimension]) params.append(`${dimension}[]`, id);
  }
  for (const key of ['favorite', 'unclassified'] as const)
    filters[key] ? params.set(key, '1') : params.delete(key);
  filters.has_bind ? params.set('has_bind', filters.has_bind) : params.delete('has_bind');
  filters.equipment ? params.set('equipment', filters.equipment) : params.delete('equipment');
  return params;
}
export function referenceFromCard(card: Asana): ReferenceFields {
  const result = emptyReference();
  for (const key of Object.keys(result) as (keyof ReferenceFields)[]) {
    const value = card[key];
    if (value !== undefined && value !== null) Object.assign(result, { [key]: value });
  }
  return JSON.parse(JSON.stringify(result));
}
export function appendReference(data: FormData, reference: ReferenceFields, assignments: FacetAssignments) {
  for (const [key, value] of Object.entries(reference))
    data.set(
      key,
      value === null
        ? ''
        : typeof value === 'object'
          ? JSON.stringify(value)
          : typeof value === 'boolean'
            ? value
              ? '1'
              : '0'
            : value,
    );
  data.set('facet_assignments', JSON.stringify(assignments));
}
