export type MetronomeBeat = 'normal' | 'accent' | 'strong' | 'mute';
export type MetronomeSound = 'click' | 'wood' | 'beep' | 'bell';
export interface MetronomeSettings {
  enabled: boolean;
  bpm: number;
  beats: number;
  beat_unit: 2 | 4 | 8 | 16;
  pattern: MetronomeBeat[];
  subdivision: 1 | 2 | 3 | 4;
  sound: MetronomeSound;
  volume: number;
  count_in_bars: 0 | 1 | 2;
  tracker: {
    mode: 'off' | 'timer' | 'bars';
    countdown: boolean;
    seconds: number;
    bars: number;
    stop_at_end: boolean;
    reset_at_end: boolean;
    start_from_one: boolean;
  };
  automator: {
    enabled: boolean;
    step_bpm: number;
    every_bars: number;
    target_bpm: number;
  };
}
export interface MetronomePreset {
  id: string;
  name: string;
  settings: MetronomeSettings;
  revision: number;
}
export function defaultMetronome(): MetronomeSettings {
  return {
    enabled: false,
    bpm: 120,
    beats: 4,
    beat_unit: 4,
    pattern: ['accent', 'normal', 'normal', 'normal'],
    subdivision: 1,
    sound: 'click',
    volume: 60,
    count_in_bars: 0,
    tracker: {
      mode: 'off',
      countdown: false,
      seconds: 60,
      bars: 100,
      stop_at_end: true,
      reset_at_end: false,
      start_from_one: false,
    },
    automator: { enabled: false, step_bpm: 1, every_bars: 4, target_bpm: 160 },
  };
}
const object = (value: unknown): Record<string, unknown> =>
  value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
const integer = (value: unknown, fallback: number, min: number, max: number) =>
  typeof value === 'number' && Number.isFinite(value)
    ? Math.max(min, Math.min(max, Math.round(value)))
    : fallback;
const choice = <T extends string | number>(value: unknown, values: readonly T[], fallback: T): T =>
  values.includes(value as T) ? (value as T) : fallback;
export function normalizeMetronome(value?: unknown): MetronomeSettings {
  const source = object(value),
    defaults = defaultMetronome(),
    tracker = object(source.tracker),
    automator = object(source.automator),
    beats = integer(source.beats, defaults.beats, 1, 12),
    pattern = Array.isArray(source.pattern) ? source.pattern : [];
  return {
    enabled: source.enabled === true,
    bpm: integer(source.bpm, defaults.bpm, 20, 300),
    beats,
    beat_unit: choice(source.beat_unit, [2, 4, 8, 16] as const, 4),
    pattern: Array.from({ length: beats }, (_, i) =>
      choice(pattern[i], ['normal', 'accent', 'strong', 'mute'] as const, i === 0 ? 'accent' : 'normal'),
    ),
    subdivision: choice(source.subdivision, [1, 2, 3, 4] as const, 1),
    sound: choice(source.sound, ['click', 'wood', 'beep', 'bell'] as const, 'click'),
    volume: integer(source.volume, defaults.volume, 0, 100),
    count_in_bars: choice(source.count_in_bars, [0, 1, 2] as const, 0),
    tracker: {
      mode: choice(tracker.mode, ['off', 'timer', 'bars'] as const, 'off'),
      countdown: tracker.countdown === true,
      seconds: integer(tracker.seconds, defaults.tracker.seconds, 1, 86400),
      bars: integer(tracker.bars, defaults.tracker.bars, 1, 10000),
      stop_at_end: tracker.stop_at_end !== false,
      reset_at_end: tracker.reset_at_end === true,
      start_from_one: tracker.start_from_one === true,
    },
    automator: {
      enabled: automator.enabled === true,
      step_bpm: integer(automator.step_bpm, defaults.automator.step_bpm, 1, 30),
      every_bars: integer(automator.every_bars, defaults.automator.every_bars, 1, 1000),
      target_bpm: integer(automator.target_bpm, defaults.automator.target_bpm, 20, 300),
    },
  };
}
export const metronomeBeatLabels: Record<MetronomeBeat, string> = {
  normal: 'Обычная доля',
  accent: 'Акцент',
  strong: 'Сильный акцент',
  mute: 'Без звука',
};
export const metronomeSoundLabels: Record<MetronomeSound, string> = {
  click: 'Щелчок',
  wood: 'Деревянный блок',
  beep: 'Электронный',
  bell: 'Колокольчик',
};
export const metronomeClock = (seconds: number) => {
  const rounded = Math.max(0, Math.ceil(seconds));
  return `${Math.floor(rounded / 60)}:${String(rounded % 60).padStart(2, '0')}`;
};
