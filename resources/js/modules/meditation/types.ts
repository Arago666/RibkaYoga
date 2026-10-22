export type Signal = 'none' | 'gong' | 'bowl' | 'bell';
export interface MeditationConfig {
  kind: 'meditation' | 'breathing' | 'tones';
  timezone: string;
  duration_seconds: number | null;
  preparation_seconds: number;
  start_signal: Signal;
  end_signal: Signal;
  interval_signal: Signal;
  interval_seconds: number;
  signal_volume: number;
  ambience: 'none' | 'forest' | 'rain' | 'waves' | 'white';
  ambience_volume: number;
  audio_asset_id: string | null;
  audio_volume: number;
  audio_repeat: boolean;
  audio_end: 'continue' | 'finish';
  breathing_enabled: boolean;
  breathing_signal: Signal;
  inhale_seconds: number;
  exhale_seconds: number;
  hold_in_seconds: number;
  hold_out_seconds: number;
  breathing_cycles: number;
  tone_enabled: boolean;
  tone_frequency: number;
  tone_volume: number;
  tone_seconds: number;
}
export interface MeditationSession {
  id: string;
  kind: MeditationConfig['kind'];
  status: 'running' | 'paused' | 'completed' | 'interrupted';
  timezone: string;
  settings: MeditationConfig;
  active_ms: number;
  revision: number;
  started_at: string;
  ended_at: string | null;
  lease_remaining_ms: number;
}
export interface PersonalAudio {
  id: string;
  title: string;
  original_filename: string;
  mime: string;
  bytes: number;
  duration_ms: number;
  revision: number;
  archived_at: string | null;
  stream_url: string;
}
export interface AudioLimits {
  max_bytes: number;
  max_seconds: number;
  max_library_bytes: number;
}
export interface MeditationHistory {
  data: MeditationSession[];
  current_page: number;
  last_page: number;
  total: number;
  summary: {
    active_ms: number;
    completed: number;
    interrupted: number;
    days: Record<string, number>;
    timezone: string;
  };
}
export const defaults: MeditationConfig = {
  kind: 'meditation',
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  duration_seconds: 600,
  preparation_seconds: 10,
  start_signal: 'bowl',
  end_signal: 'gong',
  interval_signal: 'none',
  interval_seconds: 0,
  signal_volume: 0.5,
  ambience: 'none',
  ambience_volume: 0.35,
  audio_asset_id: null,
  audio_volume: 0.5,
  audio_repeat: false,
  audio_end: 'continue',
  breathing_enabled: false,
  breathing_signal: 'none',
  inhale_seconds: 4,
  exhale_seconds: 6,
  hold_in_seconds: 0,
  hold_out_seconds: 0,
  breathing_cycles: 10,
  tone_enabled: false,
  tone_frequency: 220,
  tone_volume: 0.1,
  tone_seconds: 600,
};
