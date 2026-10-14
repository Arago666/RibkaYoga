import type { PaletteDragPoint } from './composables/usePaletteDrag';

export interface MesocycleTarget {
  documentId: string;
  cellId: string;
  label: string;
}

interface Envelope {
  documentId: string;
  sessionId: string;
}
export type MesocycleCommandPayload =
  | {
      type: 'ribka:meso-asana';
      cellId: string;
      asanaId: string;
      name: string;
      requestId: string;
      sequence: number;
    }
  | { type: 'ribka:meso-hover'; cellId: string | null }
  | { type: 'ribka:meso-save'; requestId: string };
export type MesocycleCommand = Envelope & MesocycleCommandPayload;
export type MesocycleEventPayload =
  | { type: 'ribka:meso-cell'; cellId: string; label: string }
  | { type: 'ribka:meso-clear-target' }
  | { type: 'ribka:meso-status'; dirty: boolean; pending: number }
  | { type: 'ribka:meso-saved'; requestId: string; saved: boolean };
export type MesocycleEvent = Envelope & MesocycleEventPayload;
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const isMesocycleId = (value: unknown): value is string =>
  typeof value === 'string' && uuidPattern.test(value);
const shortText = (value: unknown, max: number): value is string =>
  typeof value === 'string' && value.length <= max;
function envelope(value: unknown): value is Record<string, unknown> & Envelope {
  return (
    !!value &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    isMesocycleId((value as Envelope).documentId) &&
    isMesocycleId((value as Envelope).sessionId)
  );
}
export function isMesocycleCommand(value: unknown): value is MesocycleCommand {
  if (!envelope(value)) return false;
  switch (value.type) {
    case 'ribka:meso-asana':
      return (
        isMesocycleId(value.cellId) &&
        isMesocycleId(value.asanaId) &&
        isMesocycleId(value.requestId) &&
        typeof value.sequence === 'number' &&
        Number.isSafeInteger(value.sequence) &&
        value.sequence > 0 &&
        shortText(value.name, 160)
      );
    case 'ribka:meso-hover':
      return value.cellId === null || isMesocycleId(value.cellId);
    case 'ribka:meso-save':
      return isMesocycleId(value.requestId);
    default:
      return false;
  }
}
export function isMesocycleEvent(value: unknown): value is MesocycleEvent {
  if (!envelope(value)) return false;
  switch (value.type) {
    case 'ribka:meso-cell':
      return isMesocycleId(value.cellId) && shortText(value.label, 480);
    case 'ribka:meso-clear-target':
      return true;
    case 'ribka:meso-status':
      return (
        typeof value.dirty === 'boolean' &&
        typeof value.pending === 'number' &&
        Number.isSafeInteger(value.pending) &&
        value.pending >= 0 &&
        value.pending <= 1000
      );
    case 'ribka:meso-saved':
      return isMesocycleId(value.requestId) && typeof value.saved === 'boolean';
    default:
      return false;
  }
}

/** The overlay keeps pointer events in the parent; only registered, visible same-origin frames are read. */
export function mesocycleCellAtPoint(
  frames: Map<string, HTMLIFrameElement>,
  point: PaletteDragPoint,
): MesocycleTarget | null {
  const surface = document
    .elementsFromPoint(point.x, point.y)
    .find((element) => !element.closest('[data-mesocycle-drag-overlay]'));
  if (!(surface instanceof HTMLIFrameElement)) return null;
  const entry = [...frames].find(([, frame]) => frame === surface);
  if (!entry) return null;
  try {
    const content = surface.contentDocument;
    if (!content || surface.contentWindow?.location.origin !== window.location.origin) return null;
    const rect = surface.getBoundingClientRect();
    if (!rect.width || !rect.height) return null;
    const x = (point.x - rect.left) * (surface.offsetWidth / rect.width) - surface.clientLeft;
    const y = (point.y - rect.top) * (surface.offsetHeight / rect.height) - surface.clientTop;
    const cell = content.elementFromPoint(x, y)?.closest<HTMLElement>('[data-editor-cell]');
    const cellId = cell?.dataset.editorCell;
    if (
      !cell ||
      !isMesocycleId(cellId) ||
      !cell.closest('[data-mesocycle-drop-enabled="true"]') ||
      cell.getAttribute('aria-disabled') === 'true' ||
      cell.classList.contains('pending')
    )
      return null;
    return { documentId: entry[0], cellId, label: cell.getAttribute('aria-label')?.slice(0, 480) || '' };
  } catch {
    // A frame may have navigated or be unloading. Such a surface never accepts a drop.
    return null;
  }
}

const edgeScroll = (position: number, start: number, end: number, margin = 28): number =>
  position < start + margin ? -10 : position > end - margin ? 10 : 0;

/** Edge scrolling keeps lower rows, long rows and off-screen weeks reachable during a gesture. */
export function scrollMesocycleAtPoint(
  frames: Map<string, HTMLIFrameElement>,
  board: HTMLElement | null,
  point: PaletteDragPoint,
) {
  if (board) {
    const rect = board.getBoundingClientRect();
    if (point.y >= rect.top && point.y <= rect.bottom) {
      board.scrollLeft += edgeScroll(
        point.x,
        Math.max(0, rect.left),
        Math.min(window.innerWidth, rect.right),
      );
      if (point.y > window.innerHeight - 28) window.scrollBy(0, 10);
      else if (point.y < 28) window.scrollBy(0, -10);
    }
  }
  const surface = document
    .elementsFromPoint(point.x, point.y)
    .find((element) => !element.closest('[data-mesocycle-drag-overlay]'));
  if (!(surface instanceof HTMLIFrameElement) || ![...frames.values()].includes(surface)) return;
  try {
    const content = surface.contentDocument;
    if (
      !content ||
      surface.contentWindow?.location.origin !== window.location.origin ||
      !content.querySelector('[data-mesocycle-drop-enabled="true"]')
    )
      return;
    const rect = surface.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const x = (point.x - rect.left) * (surface.offsetWidth / rect.width) - surface.clientLeft;
    const y = (point.y - rect.top) * (surface.offsetHeight / rect.height) - surface.clientTop;
    content.scrollingElement?.scrollBy(0, edgeScroll(y, 0, surface.clientHeight));
    const row = content.elementFromPoint(x, y)?.closest<HTMLElement>('.cells-scroll');
    if (row && row.scrollWidth > row.clientWidth) {
      const rowRect = row.getBoundingClientRect();
      row.scrollLeft += edgeScroll(x, rowRect.left, rowRect.right, 18);
    }
  } catch {
    /* A navigated or unmounted editor must not be accessed. */
  }
}
