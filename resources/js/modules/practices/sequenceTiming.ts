import { cellFilled, resolvedExecutionParameters, type Practice } from './types';

export function formatSequenceTime(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor(seconds / 60) % 60;
  const remainder = seconds % 60;
  return [
    hours ? `${hours} ч` : '',
    minutes ? `${minutes} мин` : '',
    remainder || !seconds ? `${remainder} сек` : '',
  ]
    .filter(Boolean)
    .join(' ');
}

export function practiceTiming(practice: Practice) {
  let hold = 0;
  let rest = 0;
  let lastRest = 0;
  let untimed = false;
  for (const block of practice.blocks) {
    for (const row of block.rows) {
      for (const cell of row.cells) {
        if (!cellFilled(cell)) continue;
        const values = resolvedExecutionParameters(cell, practice);
        const repeats = (values.repetitions ?? 1) * (values.side === 'both' ? 2 : 1);
        lastRest = values.rest_seconds ?? 0;
        rest += lastRest * repeats;
        if (values.advance_mode === 'time' && values.duration_seconds !== null) {
          hold += values.duration_seconds * repeats;
        } else {
          untimed = true;
        }
      }
    }
  }
  return { hold, rest: rest - lastRest, untimed };
}
