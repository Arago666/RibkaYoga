import type { PersonalAudio } from './types';

export function uploadAudio(
  file: File,
  title: string,
  progress: (percent: number) => void,
): { result: Promise<PersonalAudio>; cancel: () => void } {
  const xhr = new XMLHttpRequest();
  const result = new Promise<PersonalAudio>((resolve, reject) => {
    xhr.open('POST', '/api/audio');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    const xsrf = document.cookie
      .split('; ')
      .find((value) => value.startsWith('XSRF-TOKEN='))
      ?.split('=')
      .slice(1)
      .join('=');
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    if (xsrf) xhr.setRequestHeader('X-XSRF-TOKEN', decodeURIComponent(xsrf));
    else if (csrf) xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) progress(Math.round((event.loaded / event.total) * 100));
    };
    xhr.onload = () => {
      let data: { data?: PersonalAudio; message?: string; errors?: Record<string, string[]> } = {};
      try {
        data = JSON.parse(xhr.responseText);
      } catch {
        /* Handle server-level upload errors below. */
      }
      if (xhr.status >= 200 && xhr.status < 300 && data.data) resolve(data.data);
      else
        reject(
          new Error(
            xhr.status === 413
              ? 'Файл превышает лимит загрузки сервера. Уменьшите файл или обратитесь к администратору.'
              : Object.values(data.errors ?? {})
                  .flat()
                  .join(' ') ||
                  data.message ||
                  'Аудио не загружено. Повторите попытку.',
          ),
        );
    };
    xhr.onerror = () => reject(new Error('Нет связи. Загрузка не завершена.'));
    xhr.onabort = () => reject(new Error('Загрузка отменена.'));
    const body = new FormData();
    body.append('audio', file);
    body.append('title', title.trim());
    xhr.send(body);
  });
  return { result, cancel: () => xhr.abort() };
}
