import { router } from '@inertiajs/vue3';

interface Insets {
  top: number;
  bottom: number;
  left: number;
  right: number;
}
interface TelegramWebApp {
  initData: string;
  platform: string;
  viewportStableHeight: number;
  isFullscreen?: boolean;
  safeAreaInset?: Insets;
  contentSafeAreaInset?: Insets;
  ready(): void;
  expand(): void;
  requestFullscreen?(): void;
  isVersionAtLeast(version: string): boolean;
  disableVerticalSwipes?(): void;
  setHeaderColor(color: string): void;
  setBackgroundColor(color: string): void;
  setBottomBarColor?(color: string): void;
  onEvent(name: string, handler: () => void): void;
  BackButton: { show(): void; hide(): void; onClick(handler: () => void): void };
}

/** Layout bridge only. Telegram data never grants authentication or access. */
export async function initializeTelegramMiniApp(): Promise<void> {
  const root = document.documentElement;
  const keyboard = () => {
    const viewport = window.visualViewport;
    const editable = document.activeElement?.matches('input, textarea, [contenteditable="true"]');
    root.dataset.keyboard = editable && viewport && viewport.height < innerHeight * 0.78 ? 'open' : 'closed';
    root.style.setProperty('--visual-viewport-height', `${viewport?.height || innerHeight}px`);
  };
  window.visualViewport?.addEventListener('resize', keyboard);
  document.addEventListener('focusin', keyboard);
  document.addEventListener('focusout', keyboard);
  keyboard();
  const context = window as Window & { Telegram?: { WebApp?: TelegramWebApp } };
  const launch = new URLSearchParams(location.hash.slice(1));
  if (!context.Telegram?.WebApp && launch.has('tgWebAppVersion')) {
    await new Promise<void>((resolve) => {
      const script = document.createElement('script');
      script.src = 'https://telegram.org/js/telegram-web-app.js?62';
      script.async = true;
      script.onload = script.onerror = () => resolve();
      document.head.append(script);
    });
  }
  const app = context.Telegram?.WebApp;
  if (!app || app.platform === 'unknown' || !app.initData) return;
  root.dataset.miniApp = 'telegram';
  const layout = () => {
    root.dataset.miniFullscreen = String(!!app.isFullscreen);
    if (app.viewportStableHeight > 0)
      root.style.setProperty('--mini-app-height', `${app.viewportStableHeight}px`);
    for (const edge of ['top', 'bottom', 'left', 'right'] as const) {
      root.style.setProperty(`--mini-safe-${edge}`, `${app.safeAreaInset?.[edge] || 0}px`);
      root.style.setProperty(`--mini-content-${edge}`, `${app.contentSafeAreaInset?.[edge] || 0}px`);
    }
  };
  layout();
  for (const event of ['viewportChanged', 'safeAreaChanged', 'contentSafeAreaChanged', 'fullscreenChanged'])
    app.onEvent(event, layout);
  app.setHeaderColor('#ffffff');
  app.setBackgroundColor('#fbfcf9');
  if (app.isVersionAtLeast('7.10')) app.setBottomBarColor?.('#ffffff');
  if (app.isVersionAtLeast('7.7')) app.disableVerticalSwipes?.();
  const back = () => {
    const dialog = [...document.querySelectorAll<HTMLElement>('[role="dialog"]')].at(-1);
    const close = dialog?.querySelector<HTMLButtonElement>(
      'button[aria-label="Закрыть"], button[aria-label^="Свернуть"]',
    );
    if (close) close.click();
    else router.visit('/practices');
  };
  app.BackButton.onClick(back);
  const navigation = () => {
    if (['/', '/practices', '/login', '/register'].includes(location.pathname)) app.BackButton.hide();
    else app.BackButton.show();
  };
  router.on('navigate', navigation);
  navigation();
  app.expand();
  app.ready();
  // Fullscreen is supported by Telegram 8.0+. Older clients keep expanded height.
  if (app.isVersionAtLeast('8.0') && app.requestFullscreen) {
    try {
      app.requestFullscreen();
    } catch {
      /* Keep the usable expanded layout. */
    }
    const retry = () => {
      if (!app.isFullscreen) {
        try {
          app.requestFullscreen?.();
        } catch {
          /* The client can decline fullscreen. */
        }
      }
    };
    document.addEventListener('pointerup', retry, { once: true });
  }
}
