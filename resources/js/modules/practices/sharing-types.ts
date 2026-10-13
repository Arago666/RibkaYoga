import type { Practice } from './types';

export interface PracticeShare {
  id: string;
  recipient: { name: string; login: string };
  permission: 'view' | 'comment';
  revision: number;
  revoked_at: string | null;
}
export interface SharingPermissions {
  document_revision: number;
  data: PracticeShare[];
}
export interface PracticeComment {
  id: string;
  share_id: string;
  body: string;
  author: string;
  context: string | null;
  cell_id: string | null;
  document_revision: number;
  revision: number;
  created_at: string;
  updated_at: string;
  can_edit: boolean;
}
export interface SharedPracticeData {
  document: Practice;
  access: {
    owner: boolean;
    can_comment: boolean;
    share_id: string | null;
    owner_name: string;
    channels: PracticeShare[];
  };
  comments: PracticeComment[];
}
export interface SharedPracticeItem {
  id: string;
  title: string;
  theme: string | null;
  kind: string;
  permission: 'view' | 'comment';
  owner: string;
  updated_at: string;
}
