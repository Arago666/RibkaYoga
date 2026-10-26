import type { ObjectDirective } from 'vue';

const cleanups = new WeakMap<HTMLElement, () => void>();

/** Native details menus keep keyboard access and dismiss like ordinary context menus. */
export const dismissMenu: ObjectDirective<HTMLElement> = {
  mounted(element) {
    let timer: ReturnType<typeof setTimeout> | undefined;
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
    document.addEventListener('pointerdown', outside, true);
    cleanups.set(element, () => {
      cancel();
      element.removeEventListener('pointerleave', leave);
      element.removeEventListener('pointerenter', cancel);
      element.removeEventListener('keydown', key);
      element.removeEventListener('focusout', focus);
      document.removeEventListener('pointerdown', outside, true);
    });
  },
  unmounted(element) {
    cleanups.get(element)?.();
    cleanups.delete(element);
  },
};
