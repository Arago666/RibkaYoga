export interface Category {
  id: string;
  name: string;
  revision: number;
}
export interface Asana {
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
}
export function errorText(error: unknown): string {
  if (error instanceof Error) return error.message;
  return 'Не удалось выполнить действие. Попробуйте ещё раз.';
}
export function categoryLabel(category: Category): string {
  return category.name;
}
