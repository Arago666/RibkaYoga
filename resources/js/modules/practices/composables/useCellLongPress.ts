import { onBeforeUnmount, watch } from 'vue';

interface LongPressOptions {
  enabled: () => boolean;
  hold: (id: string) => void;
}
interface Press {
  id: string;
  pointerId: number;
  pointerType: string;
  x: number;
  y: number;
  source: HTMLElement;
  held: boolean;
  cancelled: boolean;
}

/** A held cell selects without consuming the following intentional tap. */
export function useCellLongPress(options: LongPressOptions) {
  let press: Press | null = null;
  let holdTimer: ReturnType<typeof setTimeout> | undefined;
  let suppressTimer: ReturnType<typeof setTimeout> | undefined;
  let suppressedPointer: number | null = null;
  let lastPointerType = 'mouse';

  function clearSuppression() {
    clearTimeout(suppressTimer);
    suppressTimer = undefined;
    suppressedPointer = null;
    window.removeEventListener('click', suppressClick, true);
    window.removeEventListener('pointerdown', nextPress, true);
  }
  function suppressClick(event: MouseEvent) {
    // Keyboard and assistive-technology activation are independent of the held pointer.
    if (event.detail === 0 || suppressedPointer === null) return;
    if ('pointerId' in event && (event as PointerEvent).pointerId !== suppressedPointer) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    clearSuppression();
  }
  function nextPress() {
    // A new press is intentional even when the previous browser gesture produced no click.
    clearSuppression();
  }
  function suppressRelease(current: Press, released: boolean) {
    clearSuppression();
    suppressedPointer = current.pointerId;
    window.addEventListener('click', suppressClick, true);
    window.addEventListener('pointerdown', nextPress, true);
    if (released) suppressTimer = setTimeout(clearSuppression, 900);
  }
  function removeTracking() {
    clearTimeout(holdTimer);
    holdTimer = undefined;
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', finish);
    window.removeEventListener('pointercancel', cancelPointer);
    window.removeEventListener('scroll', scroll, true);
    window.removeEventListener('blur', cancel);
  }
  function end(suppress = false) {
    const previous = press;
    press = null;
    removeTracking();
    if (!previous) return;
    previous.source.removeEventListener('lostpointercapture', lostCapture);
    if (suppress) suppressRelease(previous, true);
    try {
      if (previous.source.hasPointerCapture(previous.pointerId))
        previous.source.releasePointerCapture(previous.pointerId);
    } catch {
      // Pointer capture may already have been released by the browser.
    }
  }
  function cancel() {
    end(Boolean(press));
  }
  function cancelPointer(event: PointerEvent) {
    if (press?.pointerId === event.pointerId) cancel();
  }
  function lostCapture(event: PointerEvent) {
    if (press?.pointerId === event.pointerId) cancel();
  }
  function markCancelled() {
    if (!press) return;
    press.cancelled = true;
    clearTimeout(holdTimer);
    holdTimer = undefined;
    // Keep tracking release so a long scroll cannot leave an expired click guard behind.
  }
  function move(event: PointerEvent) {
    if (!press || press.pointerId !== event.pointerId) return;
    if (!options.enabled() || Math.hypot(event.clientX - press.x, event.clientY - press.y) >= 8)
      markCancelled();
  }
  function scroll() {
    markCancelled();
  }
  function finish(event: PointerEvent) {
    if (!press || press.pointerId !== event.pointerId) return;
    end(press.held || press.cancelled);
  }
  function start(event: PointerEvent, id: string) {
    if (!options.enabled() || !event.isPrimary || event.button !== 0 || event.ctrlKey) return;
    end();
    clearSuppression();
    lastPointerType = event.pointerType;
    const current: Press = {
      id,
      pointerId: event.pointerId,
      pointerType: event.pointerType,
      x: event.clientX,
      y: event.clientY,
      source: event.currentTarget as HTMLElement,
      held: false,
      cancelled: false,
    };
    press = current;
    window.addEventListener('pointermove', move, { passive: true });
    window.addEventListener('pointerup', finish);
    window.addEventListener('pointercancel', cancelPointer);
    window.addEventListener('scroll', scroll, true);
    window.addEventListener('blur', cancel);
    current.source.addEventListener('lostpointercapture', lostCapture);
    holdTimer = setTimeout(() => {
      if (press !== current || current.cancelled || !options.enabled() || !current.source.isConnected) return;
      current.held = true;
      suppressRelease(current, false);
      // Capture only after recognition: ordinary scrolling and mouse dragging remain native.
      // This also sends release back to the original cell if the action toolbar changes layout.
      try {
        current.source.setPointerCapture(current.pointerId);
      } catch {
        // The capturing click guard covers surfaces without pointer capture.
      }
      options.hold(current.id);
    }, 1000);
  }
  function allowNativeDrag(event: DragEvent): boolean {
    if (press?.held || lastPointerType === 'touch' || lastPointerType === 'pen') {
      // Mobile browsers may start their image/HTML drag before the one-second hold elapses.
      // Cell holds take precedence; palette and cloud drag handles use separate controls.
      event.preventDefault();
      event.stopPropagation();
      return false;
    }
    end(Boolean(press));
    return true;
  }
  function contextMenu(event: MouseEvent) {
    if (press?.held || (press && press.pointerType !== 'mouse') || suppressedPointer !== null) {
      event.preventDefault();
      event.stopPropagation();
    }
  }
  function keyboard(event: KeyboardEvent, id: string) {
    if (!options.enabled() || !event.shiftKey || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    event.stopPropagation();
    if (event.repeat) return;
    end();
    clearSuppression();
    options.hold(id);
  }

  watch(options.enabled, (enabled) => {
    if (!enabled) cancel();
  });
  onBeforeUnmount(() => {
    end();
    clearSuppression();
  });
  return { start, keyboard, contextMenu, allowNativeDrag };
}
