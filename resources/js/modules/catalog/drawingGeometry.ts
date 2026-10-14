import type { DrawingElement, Point } from './drawing';

export interface Bounds {
  x: number;
  y: number;
  width: number;
  height: number;
}
export interface DrawingGuide {
  kind: 'vertical' | 'horizontal' | 'head-axis' | 'perpendicular';
  from: Point;
  to: Point;
  label: string;
}
export interface LineSnapOptions {
  /** Distance in document coordinates; the caller accounts for canvas zoom. */
  threshold: number;
  headCenter?: Point | null;
  previousSegment?: [Point, Point] | null;
}
export interface LineSnapResult {
  point: Point;
  guides: DrawingGuide[];
  label: string | null;
}
export const figureConnections: [number, number][] = [
  [1, 2],
  [2, 3],
  [3, 4],
  [1, 5],
  [5, 6],
  [6, 7],
  [1, 8],
  [8, 9],
  [9, 10],
  [10, 11],
  [8, 12],
  [12, 13],
  [13, 14],
];
export const jointNames = [
  'Голова',
  'Шея',
  'Левое плечо',
  'Левый локоть',
  'Левая кисть',
  'Правое плечо',
  'Правый локоть',
  'Правая кисть',
  'Таз',
  'Левое бедро',
  'Левое колено',
  'Левая стопа',
  'Правое бедро',
  'Правое колено',
  'Правая стопа',
];
export function headRadius(element: DrawingElement): number {
  return Math.max(
    3,
    Math.hypot(element.points[0].x - element.points[1].x, element.points[0].y - element.points[1].y) * 0.45,
  );
}
export function elementBounds(element: DrawingElement): Bounds {
  const xs = element.points.map((point) => point.x),
    ys = element.points.map((point) => point.y);
  if (element.kind === 'figure') {
    const radius = headRadius(element),
      head = element.points[0];
    xs.push(head.x - radius, head.x + radius);
    ys.push(head.y - radius, head.y + radius);
  }
  const x = Math.min(...xs),
    y = Math.min(...ys);
  return { x, y, width: Math.max(1, Math.max(...xs) - x), height: Math.max(1, Math.max(...ys) - y) };
}
export function boundsCenter(bounds: Bounds): Point {
  return { x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2 };
}
export function rotatePoint(point: Point, center: Point, angle: number): Point {
  const radians = (angle * Math.PI) / 180,
    c = Math.cos(radians),
    s = Math.sin(radians);
  return {
    x: center.x + (point.x - center.x) * c - (point.y - center.y) * s,
    y: center.y + (point.x - center.x) * s + (point.y - center.y) * c,
  };
}
export function worldPoint(point: Point, element: DrawingElement): Point {
  return rotatePoint(point, boundsCenter(elementBounds(element)), element.rotation);
}
/** The first circle is the head; a figure supplies its head when no circle exists. */
export function drawingHeadCenter(elements: DrawingElement[]): Point | null {
  const head =
    elements.find((element) => element.kind === 'ellipse') ??
    elements.find((element) => element.kind === 'figure');
  if (!head) return null;
  return worldPoint(head.kind === 'figure' ? head.points[0] : boundsCenter(elementBounds(head)), head);
}
/** Reflect the visible geometry exactly. Constrain paired gestures before calling this helper. */
export function mirrorElement(element: DrawingElement, axisX: number, id: string): DrawingElement {
  const mirrored = {
    ...element,
    id,
    rotation: -element.rotation,
    points: element.points.map((point) => ({ x: axisX * 2 - point.x, y: point.y })),
  };
  const originalCenter = boundsCenter(elementBounds(element)),
    mirroredCenter = boundsCenter(elementBounds(mirrored));
  // Bounds have a minimum size of one, so a degenerate segment can shift the rotation origin.
  const delta = {
    x: mirroredCenter.x - (axisX * 2 - originalCenter.x),
    y: mirroredCenter.y - originalCenter.y,
  };
  const rotated = rotatePoint(delta, { x: 0, y: 0 }, mirrored.rotation);
  const correction =
    element.kind === 'ellipse'
      ? { x: -delta.x, y: -delta.y }
      : { x: rotated.x - delta.x, y: rotated.y - delta.y };
  return {
    ...mirrored,
    points: mirrored.points.map((point) => ({
      x: point.x + correction.x,
      y: point.y + correction.y,
    })),
  };
}
/** Convert a straight line without changing its path or rotation origin. */
export function lineToCurve(element: DrawingElement): DrawingElement {
  if (element.kind !== 'line') return { ...element, points: element.points.map((point) => ({ ...point })) };
  const [start, end] = element.points;
  return {
    ...element,
    kind: 'curve',
    points: [{ ...start }, { x: (start.x + end.x) / 2, y: (start.y + end.y) / 2 }, { ...end }],
  };
}
/** The visible middle of a quadratic curve, in document coordinates. */
export function curveMidpoint(element: DrawingElement): Point {
  const start = element.points[0],
    end = element.points[element.points.length - 1];
  const midpoint =
    element.kind === 'curve'
      ? {
          x: (start.x + element.points[1].x * 2 + end.x) / 4,
          y: (start.y + element.points[1].y * 2 + end.y) / 4,
        }
      : { x: (start.x + end.x) / 2, y: (start.y + end.y) / 2 };
  return worldPoint(midpoint, element);
}
/** Local control point that places the curve's middle at midpoint, within the source format limits. */
export function quadraticControlPoint(start: Point, end: Point, midpoint: Point): Point {
  return {
    x: Math.max(0, Math.min(1024, midpoint.x * 2 - (start.x + end.x) / 2)),
    y: Math.max(0, Math.min(1024, midpoint.y * 2 - (start.y + end.y) / 2)),
  };
}
/** Snap only to compatible exact guides; coordinates and threshold are independent of zoom. */
export function snapLineEndpoint(start: Point, end: Point, options: LineSnapOptions): LineSnapResult {
  const threshold = Math.max(0, options.threshold),
    point = { x: Math.max(0, Math.min(1024, end.x)), y: Math.max(0, Math.min(1024, end.y)) };
  type Candidate = { point: Point; guide: DrawingGuide; distance: number; priority: number };
  const candidates: Candidate[] = [];
  const add = (target: Point, guide: DrawingGuide, priority: number) => {
    const distance = Math.hypot(target.x - point.x, target.y - point.y);
    if (distance <= threshold && target.x >= 0 && target.x <= 1024 && target.y >= 0 && target.y <= 1024)
      candidates.push({ point: target, guide, distance, priority });
  };
  add(
    { x: start.x, y: point.y },
    { kind: 'vertical', from: { x: start.x, y: 0 }, to: { x: start.x, y: 1024 }, label: 'Вертикально' },
    2,
  );
  add(
    { x: point.x, y: start.y },
    { kind: 'horizontal', from: { x: 0, y: start.y }, to: { x: 1024, y: start.y }, label: 'Горизонтально' },
    2,
  );
  if (options.headCenter) {
    const axisX = options.headCenter.x;
    add(
      { x: axisX, y: point.y },
      { kind: 'head-axis', from: { x: axisX, y: 0 }, to: { x: axisX, y: 1024 }, label: 'По центру головы' },
      0,
    );
  }
  if (options.previousSegment) {
    const [a, b] = options.previousSegment,
      dx = b.x - a.x,
      dy = b.y - a.y,
      lengthSquared = dx * dx + dy * dy;
    if (lengthSquared > 0) {
      const along = ((point.x - start.x) * -dy + (point.y - start.y) * dx) / lengthSquared,
        target = { x: start.x - dy * along, y: start.y + dx * along };
      add(target, { kind: 'perpendicular', from: { ...start }, to: target, label: '90°' }, 1);
    }
  }
  candidates.sort((a, b) => a.distance - b.distance || a.priority - b.priority);
  const closest = candidates[0];
  if (!closest) return { point, guides: [], label: null };
  const horizontal = candidates.find((candidate) => candidate.guide.kind === 'horizontal'),
    headAxis = candidates.find((candidate) => candidate.guide.kind === 'head-axis');
  if (horizontal && headAxis && (closest.guide.kind === 'horizontal' || closest.guide.kind === 'head-axis')) {
    const combined = { x: headAxis.point.x, y: horizontal.point.y };
    if (Math.hypot(combined.x - point.x, combined.y - point.y) <= threshold)
      return {
        point: combined,
        guides: [headAxis.guide, horizontal.guide],
        label: `${headAxis.guide.label} · ${horizontal.guide.label}`,
      };
  }
  return { point: closest.point, guides: [closest.guide], label: closest.guide.label };
}
export function selectionCorners(element: DrawingElement): Point[] {
  const bounds = elementBounds(element);
  return [
    { x: bounds.x, y: bounds.y },
    { x: bounds.x + bounds.width, y: bounds.y },
    { x: bounds.x + bounds.width, y: bounds.y + bounds.height },
    { x: bounds.x, y: bounds.y + bounds.height },
  ].map((point) => worldPoint(point, element));
}
export function rotationHandle(element: DrawingElement): Point {
  const bounds = elementBounds(element);
  return worldPoint({ x: bounds.x + bounds.width / 2, y: bounds.y - 35 }, element);
}
function distanceToSegment(point: Point, a: Point, b: Point): number {
  const dx = b.x - a.x,
    dy = b.y - a.y;
  const t =
    dx || dy
      ? Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / (dx * dx + dy * dy)))
      : 0;
  return Math.hypot(point.x - a.x - t * dx, point.y - a.y - t * dy);
}
function simplify(points: Point[], tolerance: number): Point[] {
  if (points.length < 3 || !tolerance) return points;
  const keep = new Set<number>([0, points.length - 1]),
    queue: [number, number][] = [[0, points.length - 1]];
  while (queue.length) {
    const [first, last] = queue.pop()!;
    let maximum = tolerance,
      index = -1;
    for (let i = first + 1; i < last; i++) {
      const distance = distanceToSegment(points[i], points[first], points[last]);
      if (distance > maximum) {
        maximum = distance;
        index = i;
      }
    }
    if (index >= 0) {
      keep.add(index);
      queue.push([first, index], [index, last]);
    }
  }
  return [...keep].sort((a, b) => a - b).map((index) => points[index]);
}
export function elementPath(element: DrawingElement, smoothing: number): Path2D {
  const path = new Path2D(),
    points = element.points;
  if (element.kind === 'ellipse') {
    const bounds = elementBounds(element);
    path.ellipse(
      bounds.x + bounds.width / 2,
      bounds.y + bounds.height / 2,
      bounds.width / 2,
      bounds.height / 2,
      0,
      0,
      Math.PI * 2,
    );
  } else if (element.kind === 'curve') {
    path.moveTo(points[0].x, points[0].y);
    path.quadraticCurveTo(points[1].x, points[1].y, points[2].x, points[2].y);
  } else if (element.kind === 'figure') {
    const head = points[0],
      neck = points[1],
      radius = headRadius(element);
    path.arc(head.x, head.y, radius, 0, Math.PI * 2);
    const distance = Math.max(1, Math.hypot(neck.x - head.x, neck.y - head.y));
    path.moveTo(
      head.x + ((neck.x - head.x) * radius) / distance,
      head.y + ((neck.y - head.y) * radius) / distance,
    );
    path.lineTo(neck.x, neck.y);
    for (const [a, b] of figureConnections) {
      path.moveTo(points[a].x, points[a].y);
      path.lineTo(points[b].x, points[b].y);
    }
  } else {
    const line = element.kind === 'line' ? points : simplify(points, smoothing / 25);
    if (line.length === 1) path.arc(line[0].x, line[0].y, element.width / 2, 0, Math.PI * 2);
    else {
      path.moveTo(line[0].x, line[0].y);
      if (smoothing && element.kind !== 'line') {
        for (let i = 1; i < line.length - 1; i++)
          path.quadraticCurveTo(
            line[i].x,
            line[i].y,
            (line[i].x + line[i + 1].x) / 2,
            (line[i].y + line[i + 1].y) / 2,
          );
        path.lineTo(line[line.length - 1].x, line[line.length - 1].y);
      } else for (const point of line.slice(1)) path.lineTo(point.x, point.y);
    }
  }
  return path;
}
export function drawElement(ctx: CanvasRenderingContext2D, element: DrawingElement, smoothing: number): void {
  const center = boundsCenter(elementBounds(element)),
    path = elementPath(element, smoothing);
  ctx.save();
  ctx.translate(center.x, center.y);
  ctx.rotate((element.rotation * Math.PI) / 180);
  ctx.translate(-center.x, -center.y);
  ctx.strokeStyle = ctx.fillStyle = element.kind === 'eraser' ? '#fff' : '#111';
  ctx.lineWidth = element.width;
  ctx.lineCap = ctx.lineJoin = 'round';
  if (element.points.length === 1) ctx.fill(path);
  else ctx.stroke(path);
  ctx.restore();
}
export function hitElement(
  ctx: CanvasRenderingContext2D,
  element: DrawingElement,
  point: Point,
  smoothing: number,
  tolerance: number,
): boolean {
  const local = rotatePoint(point, boundsCenter(elementBounds(element)), -element.rotation);
  const path = elementPath(element, smoothing);
  ctx.save();
  ctx.lineWidth = element.width + tolerance * 2;
  ctx.lineCap = ctx.lineJoin = 'round';
  const hit =
    ctx.isPointInStroke(path, local.x, local.y) ||
    (element.kind === 'ellipse' && ctx.isPointInPath(path, local.x, local.y)) ||
    (element.points.length === 1 &&
      Math.hypot(local.x - element.points[0].x, local.y - element.points[0].y) <=
        element.width / 2 + tolerance);
  ctx.restore();
  return hit;
}
export function moveElement(element: DrawingElement, dx: number, dy: number): DrawingElement {
  const xs = element.points.map((point) => point.x),
    ys = element.points.map((point) => point.y);
  dx = Math.max(-Math.min(...xs), Math.min(1024 - Math.max(...xs), dx));
  dy = Math.max(-Math.min(...ys), Math.min(1024 - Math.max(...ys), dy));
  return { ...element, points: element.points.map((point) => ({ x: point.x + dx, y: point.y + dy })) };
}
/** Preserve the old rotation origin while changing the local geometry. */
export function editPoints(original: DrawingElement, points: Point[]): DrawingElement {
  const next = { ...original, points };
  const before = boundsCenter(elementBounds(original)),
    after = boundsCenter(elementBounds(next));
  const delta = { x: before.x - after.x, y: before.y - after.y };
  const rotated = rotatePoint(delta, { x: 0, y: 0 }, original.rotation);
  return moveElement(next, delta.x - rotated.x, delta.y - rotated.y);
}

/** Keep both stored coordinates and the visible rotated outline inside the printable sheet. */
export function confineElement(element: DrawingElement): DrawingElement {
  let result = { ...element, points: element.points.map((point) => ({ ...point })) };
  const margin = result.width / 2 + 1;
  for (let attempt = 0; attempt < 5; attempt++) {
    const corners = selectionCorners(result),
      xs = corners.map((point) => point.x),
      ys = corners.map((point) => point.y);
    const px = result.points.map((point) => point.x),
      py = result.points.map((point) => point.y);
    const lowX = Math.max(margin - Math.min(...xs), -Math.min(...px)),
      highX = Math.min(1024 - margin - Math.max(...xs), 1024 - Math.max(...px));
    const lowY = Math.max(margin - Math.min(...ys), -Math.min(...py)),
      highY = Math.min(1024 - margin - Math.max(...ys), 1024 - Math.max(...py));
    if (lowX <= highX && lowY <= highY) {
      const dx = Math.max(lowX, Math.min(highX, 0)),
        dy = Math.max(lowY, Math.min(highY, 0));
      result.points = result.points.map((point) => ({
        x: Math.max(0, Math.min(1024, point.x + dx)),
        y: Math.max(0, Math.min(1024, point.y + dy)),
      }));
      return result;
    }
    const center = boundsCenter(elementBounds(result));
    const extentX = Math.max(...xs) - Math.min(...xs),
      extentY = Math.max(...ys) - Math.min(...ys);
    const factor = Math.min(
      0.95,
      (1024 - margin * 2) / Math.max(1, extentX),
      (1024 - margin * 2) / Math.max(1, extentY),
    );
    result.points = result.points.map((point) => ({
      x: center.x + (point.x - center.x) * factor,
      y: center.y + (point.y - center.y) * factor,
    }));
  }
  return result;
}
