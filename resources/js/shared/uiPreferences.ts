export interface UiPreferences {
  libraryStyle: 'original' | 'volume';
  rememberFilters: boolean;
  meditationNotifications: boolean;
}
export function uiPreferencesKey(auth: unknown): string {
  const value = auth as { user?: { id: number }; workspace?: { id: string } } | undefined;
  return `ribka:ui:${value?.user?.id || 'guest'}:${value?.workspace?.id || 'none'}`;
}
export function readUiPreferences(key: string): UiPreferences {
  const result: UiPreferences = {
    libraryStyle: 'original',
    rememberFilters: true,
    meditationNotifications: false,
  };
  try {
    const saved = JSON.parse(localStorage.getItem(key) || '{}');
    if (saved.libraryStyle === 'volume') result.libraryStyle = 'volume';
    if (typeof saved.rememberFilters === 'boolean') result.rememberFilters = saved.rememberFilters;
    if (typeof saved.meditationNotifications === 'boolean')
      result.meditationNotifications = saved.meditationNotifications;
  } catch {
    /* Unavailable storage uses explicit safe defaults. */
  }
  return result;
}
export function notifyMeditationCompleted(key: string) {
  if (
    !readUiPreferences(key).meditationNotifications ||
    !('Notification' in window) ||
    Notification.permission !== 'granted'
  )
    return;
  try {
    new Notification('Медитация завершена', {
      body: 'Сессия завершена. Активное время сохранено.',
      tag: 'ribka-meditation-completed',
    });
  } catch {
    /* Sound and on-page completion remain available when notifications are blocked. */
  }
}
