import type { ObjectDirective } from 'vue';

const cleanups = new WeakMap<HTMLElement, () => void>();

/** Native details menus keep keyboard access and dismiss like ordinary context menus. */
export const dismissMenu: ObjectDirective<HTMLElement> = {
  mounted(element) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let frame = 0;
    const place = () => {
      cancelAnimationFrame(frame);
      if (!element.hasAttribute('open')) return;
      frame = requestAnimationFrame(() => {
        if (!element.hasAttribute('open')) return;
        const panel = element.querySelector<HTMLElement>(':scope > div');
        if (!panel) return;
        const viewport = window.visualViewport;
        const left = (viewport?.offsetLeft ?? 0) + 8;
        const top = (viewport?.offsetTop ?? 0) + 8;
        const width = viewport?.width ?? window.innerWidth;
        let bottom = top + (viewport?.height ?? window.innerHeight) - 16;
        const navigation = document.querySelector<HTMLElement>('.main-nav');
        if (navigation && getComputedStyle(navigation).position === 'fixed') {
          const nav = navigation.getBoundingClientRect();
          if (nav.top > top && nav.top < bottom) bottom = nav.top - 8;
        }
        panel.style.translate = '';
        panel.style.minWidth = '';
        const minimum = parseFloat(getComputedStyle(panel).minWidth) || 0;
        panel.style.minWidth = `${Math.min(minimum, width - 16)}px`;
        panel.style.maxWidth = `${Math.min(320, width - 16)}px`;
        panel.style.maxHeight = `${Math.max(80, bottom - top)}px`;
        panel.style.overflowY = 'auto';
        panel.style.overscrollBehavior = 'contain';
        const bounds = panel.getBoundingClientRect();
        const shiftX = Math.max(left - bounds.left, Math.min(0, left + width - 16 - bounds.right));
        let shiftY = 0;
        if (bounds.bottom > bottom) {
          const summary = element.querySelector('summary')!.getBoundingClientRect();
          const above = summary.top - bounds.height - 6;
          shiftY = Math.max(top, Math.min(above, bottom - bounds.height)) - bounds.top;
        } else if (bounds.top < top) shiftY = top - bounds.top;
        panel.style.translate = `${shiftX}px ${shiftY}px`;
      });
    };
    const scroll = (event: Event) => {
      if (!(event.target instanceof Node) || !element.contains(event.target)) place();
    };
    const close = () => element.removeAttribute('open');
    const cancel = () => clearTimeout(timer);
    const outside = (event: PointerEvent) => {
      if (!element.contains(event.target as Node)) close();
    };
    const leave = (event: PointerEvent) => {
      if (event.pointerType === 'mouse') timer = setTimeout(close, 250);
    };
    const key = (event: KeyboardEvent) => {
      if (!element.hasAttribute('open')) return;
      if (event.key === 'Escape') {
        close();
        element.querySelector('summary')?.focus();
        event.stopPropagation();
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const items = [...element.querySelectorAll<HTMLElement>('button:not(:disabled), a[href]')];
        if (!items.length) return;
        event.preventDefault();
        const index = items.indexOf(document.activeElement as HTMLElement);
        const start = index < 0 && event.key === 'ArrowUp' ? 0 : index;
        items[(start + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
      }
    };
    const focus = (event: FocusEvent) => {
      if (event.relatedTarget && !element.contains(event.relatedTarget as Node)) close();
    };
    element.addEventListener('pointerleave', leave);
    element.addEventListener('pointerenter', cancel);
    element.addEventListener('keydown', key);
    element.addEventListener('focusout', focus);
    element.addEventListener('toggle', place);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', scroll, true);
    window.visualViewport?.addEventListener('resize', place);
    window.visualViewport?.addEventListener('scroll', place);
    document.addEventListener('pointerdown', outside, true);
    cleanups.set(element, () => {
      cancel();
      cancelAnimationFrame(frame);
      element.removeEventListener('pointerleave', leave);
      element.removeEventListener('pointerenter', cancel);
      element.removeEventListener('keydown', key);
      element.removeEventListener('focusout', focus);
      element.removeEventListener('toggle', place);
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', scroll, true);
      window.visualViewport?.removeEventListener('resize', place);
      window.visualViewport?.removeEventListener('scroll', place);
      document.removeEventListener('pointerdown', outside, true);
    });
  },
  unmounted(element) {
    cleanups.get(element)?.();
    cleanups.delete(element);
  },
};
