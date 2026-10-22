export interface ExecutionStep {
  id: string;
  cell_id: string;
  name: string;
  image_url: string | null;
  note: string | null;
  side: 'left' | 'right' | null;
  repeat: number;
  advance_mode: 'manual' | 'time' | 'breaths';
  duration_seconds: number | null;
  breath_count: number | null;
  anatomy: { key: string; name: string }[];
  classification_revision: number | null;
}
export interface ExecutionPlan {
  document_id: string;
  revision: number;
  title: string;
  steps: ExecutionStep[];
}
export interface ExecutionProgress {
  step: number;
  seconds_by_step: number[];
  completed: number[];
}
export interface ExecutionSession {
  id: string;
  title: string;
  mode: 'teach' | 'practice';
  status: string;
  revision: number;
  active_seconds: number;
  started_at: string;
  timezone: string;
  plan: ExecutionPlan;
  progress: ExecutionProgress;
}
