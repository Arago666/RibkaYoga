import { onBeforeUnmount, ref } from 'vue';
import type { Asana } from '@/modules/catalog/types';

export interface PaletteDragPoint {
  x: number;
  y: number;
}
interface DragOptions {
  enabled: () => boolean;
  move: (point: PaletteDragPoint) => void;
  drop: (card: Asana, point: PaletteDragPoint) => void;
  end: () => void;
}

/** Palette copies use pointer events so desktop and the explicit touch handle share one path. */
export function usePaletteDrag(options: DragOptions) {
  const preview = ref<{ card: Asana; x: number; y: number } | null>(null);
  let gesture: {
    pointerId: number;
    startX: number;
    startY: number;
    card: Asana;
    source: HTMLElement;
    dragging: boolean;
  } | null = null;
  let oldCursor = '',
    oldUserSelect = '',
    suppressUntil = 0;
  let clickTimer: ReturnType<typeof setTimeout> | undefined;

  function suppressClick(event: MouseEvent) {
    if (performance.now() >= suppressUntil) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    suppressUntil = 0;
    window.removeEventListener('click', suppressClick, true);
  }

  function stop(cancelled = false) {
    const previous = gesture;
    gesture = null;
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', finish);
    window.removeEventListener('pointercancel', cancel);
    window.removeEventListener('keydown', keyboard);
    window.removeEventListener('blur', cancel);
    if (previous) {
      previous.source.removeEventListener('lostpointercapture', lostCapture);
      try {
        if (previous.source.hasPointerCapture(previous.pointerId))
          previous.source.releasePointerCapture(previous.pointerId);
      } catch {
        /* The browser may have released capture already. */
      }
    }
    if (previous?.dragging) {
      document.body.style.cursor = oldCursor;
      document.body.style.userSelect = oldUserSelect;
    }
    if (previous && (previous.dragging || cancelled)) {
      suppressUntil = performance.now() + 450;
      window.addEventListener('click', suppressClick, true);
      clearTimeout(clickTimer);
      clickTimer = setTimeout(() => window.removeEventListener('click', suppressClick, true), 450);
    }
    preview.value = null;
    options.end();
  }

  function start(event: PointerEvent, card: Asana) {
    if (!options.enabled() || card.archived_at || event.button !== 0 || !event.isPrimary) return;
    // Ordinary touch gestures keep vertical scrolling. Only the dedicated handle starts touch dragging.
    if (event.pointerType === 'touch' && !(event.target as Element).closest('[data-palette-drag-handle]'))
      return;
    if (gesture) stop();
    gesture = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      card,
      source: event.currentTarget as HTMLElement,
      dragging: false,
    };
    // Capture before crossing a frame boundary, even if the first move is a large jump.
    try {
      gesture.source.setPointerCapture(event.pointerId);
    } catch {
      /* Window listeners still receive the gesture on older surfaces. */
    }
    gesture.source.addEventListener('lostpointercapture', lostCapture);
    window.addEventListener('pointermove', move, { passive: false });
    window.addEventListener('pointerup', finish);
    window.addEventListener('pointercancel', cancel);
    window.addEventListener('keydown', keyboard);
    window.addEventListener('blur', cancel);
  }

  function move(event: PointerEvent) {
    const current = gesture;
    if (!current || event.pointerId !== current.pointerId) return;
    if (!options.enabled()) {
      stop();
      return;
    }
    if (!current.dragging) {
      if (Math.hypot(event.clientX - current.startX, event.clientY - current.startY) < 8) return;
      current.dragging = true;
      oldCursor = document.body.style.cursor;
      oldUserSelect = document.body.style.userSelect;
      document.body.style.cursor = 'grabbing';
      document.body.style.userSelect = 'none';
      window.getSelection()?.removeAllRanges();
      try {
        current.source.setPointerCapture(event.pointerId);
      } catch {
        /* Window listeners still receive the gesture. */
      }
    }
    event.preventDefault();
    preview.value = { card: current.card, x: event.clientX, y: event.clientY };
    options.move({ x: event.clientX, y: event.clientY });
  }

  function finish(event: PointerEvent) {
    const current = gesture;
    if (!current || event.pointerId !== current.pointerId) return;
    const dropped = current.dragging && options.enabled();
    if (current.dragging) event.preventDefault();
    const point = { x: event.clientX, y: event.clientY };
    stop();
    if (dropped) options.drop(current.card, point);
  }

  function cancel() {
    if (gesture) stop(true);
  }
  function lostCapture(event: PointerEvent) {
    if (gesture?.pointerId === event.pointerId) cancel();
  }
  function keyboard(event: KeyboardEvent) {
    if (event.key === 'Escape') {
      event.preventDefault();
      cancel();
    }
  }
  function allowClick(event: MouseEvent): boolean {
    if (performance.now() >= suppressUntil) return true;
    event.preventDefault();
    event.stopPropagation();
    suppressUntil = 0;
    return false;
  }

  onBeforeUnmount(() => {
    cancel();
    clearTimeout(clickTimer);
    window.removeEventListener('click', suppressClick, true);
  });
  return { preview, start, cancel, allowClick };
}
