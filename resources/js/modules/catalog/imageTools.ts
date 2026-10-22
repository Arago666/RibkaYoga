export interface ImageTaskContext {
  name: string;
  aliases: string[];
  category_ids: string[];
}

export function imageFileName(name: string, suffix = ''): string {
  const base =
    name
      .trim()
      .replace(/[<>:"/\\|?*\u0000-\u001f]/g, '')
      .slice(0, 100) || 'асана';
  return `${base}${suffix}.png`;
}

export function loadCanvasImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = () => {
      if (!image.naturalWidth || !image.naturalHeight) reject(new Error('Изображение пустое.'));
      else resolve(image);
    };
    image.onerror = () => reject(new Error('Не удалось открыть изображение. Выберите файл повторно.'));
    image.src = url;
  });
}

export async function canvasFile(canvas: HTMLCanvasElement, name: string): Promise<File> {
  const blob = await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob(
      (result) => (result ? resolve(result) : reject(new Error('Не удалось сохранить изображение.'))),
      'image/png',
    );
  });
  if (blob.size > 10 * 1024 * 1024) throw new Error('Результат больше 10 МБ. Уменьшите размер рисунка.');
  return new File([blob], imageFileName(name), { type: 'image/png' });
}

export function downloadJson(value: unknown, filename: string): void {
  const url = URL.createObjectURL(new Blob([JSON.stringify(value, null, 2)], { type: 'application/json' }));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function imageTask(context: ImageTaskContext, instructions: string, image: string) {
  return {
    format: 'ribka-image-task',
    version: 1,
    created_at: new Date().toISOString(),
    asana: context,
    instructions:
      instructions.trim() ||
      'Аккуратно выровняй линии рисунка асаны. Сохрани исходное положение тела, пропорции и все элементы. Чёрные линии на белом фоне, без текста и декоративных деталей. Не добавляй и не меняй положение конечностей. Если поза неоднозначна, попроси уточнение.',
    source_image: { mime: 'image/png', data_url: image },
    expected_result: { file: imageFileName(context.name), mime: 'image/png', background: 'white' },
    return_instructions:
      'Сохраните полученный PNG. В RibkaYoga откройте создание или редактирование этой карточки и выберите его в поле «Изображение». Данные карточки перед загрузкой можно сверить с asana.',
  };
}
