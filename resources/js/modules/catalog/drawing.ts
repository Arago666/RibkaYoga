import { uid } from '@/shared/uuid';

export interface Point {
  x: number;
  y: number;
}

export type DrawingKind = 'pen' | 'eraser' | 'line' | 'curve' | 'ellipse' | 'figure';

export interface DrawingElement {
  id: string;
  kind: DrawingKind;
  width: number;
  rotation: number;
  points: Point[];
  /** V2 only; a flat persistent group, unrelated to practice brackets. */
  group_id?: string;
  color?: string;
}

export interface DrawingDocument {
  format: 'ribka-drawing';
  version: 1 | 2;
  width: 1024;
  height: 1024;
  smoothing: number;
  elements: DrawingElement[];
}

export const drawingLimits = { elements: 1000, points: 50000, strokePoints: 3000, bytes: 4 * 1024 * 1024 };

export function emptyDrawing(): DrawingDocument {
  return { format: 'ribka-drawing', version: 1, width: 1024, height: 1024, smoothing: 70, elements: [] };
}

export function cloneDrawing(document: DrawingDocument): DrawingDocument {
  return JSON.parse(JSON.stringify(document)) as DrawingDocument;
}

export function drawingPointCount(document: DrawingDocument): number {
  return document.elements.reduce((count, element) => count + element.points.length, 0);
}

/** Validate and normalize documents from the server, local drafts and imported files. */
export function parseDrawing(value: unknown): DrawingDocument {
  if (!value || typeof value !== 'object') throw new Error('Файл не содержит рисунок RibkaYoga.');
  const input = value as Record<string, unknown>;
  if (input.format === 'ribka-image-task' && input.version === 1) {
    const legacy = input.drawing as Record<string, unknown> | undefined;
    if (!legacy || legacy.width !== 1024 || legacy.height !== 1024 || !Array.isArray(legacy.strokes))
      throw new Error('Файл не содержит исходник рисунка.');
    return parseDrawing({
      ...emptyDrawing(),
      smoothing: legacy.smoothing ?? 70,
      elements: legacy.strokes.map((stroke: Record<string, unknown>) => ({
        id: uid(),
        kind: stroke?.tool,
        width: stroke?.width,
        rotation: 0,
        points: stroke?.points,
      })),
    });
  }
  if (
    Object.keys(input).some(
      (key) => !['format', 'version', 'width', 'height', 'smoothing', 'elements'].includes(key),
    ) ||
    input.format !== 'ribka-drawing' ||
    (input.version !== 1 && input.version !== 2) ||
    input.width !== 1024 ||
    input.height !== 1024 ||
    !Number.isFinite(input.smoothing) ||
    Number(input.smoothing) < 0 ||
    Number(input.smoothing) > 100 ||
    !Array.isArray(input.elements) ||
    input.elements.length > drawingLimits.elements
  )
    throw new Error('Выберите исходник рисунка, сохранённый в RibkaYoga.');

  const ids = new Set<string>();
  let pointCount = 0;
  const kinds: DrawingKind[] = ['pen', 'eraser', 'line', 'curve', 'ellipse', 'figure'];
  const elements = input.elements.map((raw: unknown): DrawingElement => {
    if (!raw || typeof raw !== 'object') throw new Error('Некорректный элемент рисунка.');
    const element = raw as Record<string, unknown>;
    if (
      typeof element.id !== 'string' ||
      !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(element.id) ||
      ids.has(element.id.toLowerCase()) ||
      !kinds.includes(element.kind as DrawingKind) ||
      !Number.isFinite(element.width) ||
      Number(element.width) < 1 ||
      Number(element.width) > 100 ||
      !Number.isFinite(element.rotation) ||
      Number(element.rotation) < -360 ||
      Number(element.rotation) > 360 ||
      !Array.isArray(element.points)
    )
      throw new Error('Некорректный элемент рисунка.');
    const allowed = [
      'id',
      'kind',
      'width',
      'rotation',
      'points',
      ...(input.version === 2 ? ['color', 'group_id'] : []),
    ];
    if (Object.keys(element).some((key) => !allowed.includes(key)))
      throw new Error('Исходник содержит неподдерживаемые свойства элемента.');
    if (
      element.color !== undefined &&
      (typeof element.color !== 'string' || !/^#[0-9a-f]{6}$/i.test(element.color))
    )
      throw new Error('Некорректный цвет рисунка.');
    if (
      element.group_id !== undefined &&
      (typeof element.group_id !== 'string' ||
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(element.group_id))
    )
      throw new Error('Некорректная группа рисунка.');
    ids.add(element.id.toLowerCase());
    const kind = element.kind as DrawingKind;
    const expected =
      kind === 'figure' ? 15 : kind === 'curve' ? 3 : ['line', 'ellipse'].includes(kind) ? 2 : null;
    if (
      expected
        ? element.points.length !== expected
        : element.points.length < 1 || element.points.length > drawingLimits.strokePoints
    )
      throw new Error('Некорректное число точек в рисунке.');
    pointCount += element.points.length;
    if (pointCount > drawingLimits.points) throw new Error('Рисунок содержит слишком много точек.');
    const points = element.points.map((rawPoint: unknown): Point => {
      const point = rawPoint as Record<string, unknown> | null;
      if (
        !point ||
        !Number.isFinite(point.x) ||
        !Number.isFinite(point.y) ||
        Number(point.x) < 0 ||
        Number(point.x) > 1024 ||
        Number(point.y) < 0 ||
        Number(point.y) > 1024
      )
        throw new Error('Точка рисунка находится за пределами листа.');
      return { x: Number(point.x), y: Number(point.y) };
    });
    return {
      id: element.id.toLowerCase(),
      kind,
      width: Number(element.width),
      rotation: Number(element.rotation),
      points,
      ...(element.color !== undefined ? { color: String(element.color).toLowerCase() } : {}),
      ...(element.group_id !== undefined ? { group_id: String(element.group_id).toLowerCase() } : {}),
    };
  });
  const document: DrawingDocument = {
    ...emptyDrawing(),
    version: input.version as 1 | 2,
    smoothing: Number(input.smoothing),
    elements,
  };
  const groups = new Map<string, number>();
  for (const element of elements)
    if (element.group_id) groups.set(element.group_id, (groups.get(element.group_id) ?? 0) + 1);
  if ([...groups.values()].some((count) => count < 2))
    throw new Error('Группа рисунка должна содержать хотя бы два элемента.');
  if (new TextEncoder().encode(JSON.stringify(document)).length > drawingLimits.bytes)
    throw new Error('Исходник рисунка больше 4 МБ. Удалите лишние элементы.');
  return document;
}
