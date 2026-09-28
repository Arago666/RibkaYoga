export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export async function api<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');
  headers.set('X-Requested-With', 'XMLHttpRequest');
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  const xsrf = document.cookie
    .split('; ')
    .find((value) => value.startsWith('XSRF-TOKEN='))
    ?.split('=')
    .slice(1)
    .join('=');
  if (xsrf) headers.set('X-XSRF-TOKEN', decodeURIComponent(xsrf));
  else if (token) headers.set('X-CSRF-TOKEN', token);
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  const response = await fetch(url, { credentials: 'same-origin', ...options, headers });
  const data = response.status === 204 ? {} : await response.json().catch(() => ({}));
  if (!response.ok) {
    const errors = data.errors ?? {};
    const detail = Object.values(errors).flat().slice(0, 3).join(' ');
    const message =
      response.status === 419
        ? 'Сессия истекла. Сохраните изменения копией после повторного входа.'
        : response.status === 401
          ? 'Нужно снова войти в аккаунт.'
          : response.status === 413
            ? 'Файл слишком большой. Максимум — 10 МБ.'
            : detail || data.message || 'Не удалось выполнить действие. Повторите попытку.';
    throw new ApiError(message, response.status, errors);
  }
  return data as T;
}
