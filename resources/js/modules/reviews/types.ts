import type { Practice } from '../practices/types';

export interface ReviewComment {
  id: string;
  cell_id: string;
  body: string;
  color: string;
}
export interface ReviewGroup {
  id: string;
  cell_ids: string[];
  body: string;
  color: string;
}
export interface ReviewArrow {
  id: string;
  from_cell_id: string;
  to_cell_id: string;
  body?: string;
  color: string;
}
export interface ReviewAnnotations {
  comments: ReviewComment[];
  groups: ReviewGroup[];
  arrows: ReviewArrow[];
}
export interface Review {
  id: string;
  title: string;
  source_document_id: string;
  source_revision: number;
  current_source_revision: number;
  source_changed: boolean;
  revision: number;
  document: Practice;
  annotations: ReviewAnnotations;
  created_at: string;
  updated_at: string;
  archived_at: string | null;
}
export interface ReviewSummary {
  id: string;
  title: string;
  source_document_id: string;
  source_title: string;
  source_revision: number;
  current_source_revision: number;
  revision: number;
  comments_count: number;
  groups_count: number;
  arrows_count: number;
  created_at: string;
  updated_at: string;
  archived_at: string | null;
}
export interface ReviewSource {
  id: string;
  title: string;
  revision: number;
  scope: 'mine' | 'shared';
  owner: string;
  updated_at: string;
}
export const emptyReviewAnnotations = (): ReviewAnnotations => ({ comments: [], groups: [], arrows: [] });
