import type { DrawingElement, Point } from './drawing';
import {
  boundsCenter,
  figureConnections,
  headRadius,
  elementBounds,
  rotatePoint,
  selectionCorners,
  worldPoint,
  type Bounds,
} from './drawingGeometry';

/** A common world-space frame; no element is clamped independently. */
export function selectionBounds(elements: DrawingElement[]): Bounds {
  if (!elements.length) return { x: 0, y: 0, width: 1, height: 1 };
  const points = elements.flatMap((element) => selectionCorners(element));
  const x = Math.min(...points.map((p) => p.x)),
    y = Math.min(...points.map((p) => p.y));
  return {
    x,
    y,
    width: Math.max(1, Math.max(...points.map((p) => p.x)) - x),
    height: Math.max(1, Math.max(...points.map((p) => p.y)) - y),
  };
}
export function frameCorners(bounds: Bounds): Point[] {
  return [
    { x: bounds.x, y: bounds.y },
    { x: bounds.x + bounds.width, y: bounds.y },
    { x: bounds.x + bounds.width, y: bounds.y + bounds.height },
    { x: bounds.x, y: bounds.y + bounds.height },
  ];
}
function extent(elements: DrawingElement[]): Bounds {
  const points = elements.flatMap((e) =>
    [...selectionCorners(e), ...e.points].flatMap((p) => [
      { x: p.x - e.width / 2 - 1, y: p.y - e.width / 2 - 1 },
      { x: p.x + e.width / 2 + 1, y: p.y + e.width / 2 + 1 },
    ]),
  );
  const x = Math.min(...points.map((p) => p.x)),
    y = Math.min(...points.map((p) => p.y));
  return {
    x,
    y,
    width: Math.max(...points.map((p) => p.x)) - x,
    height: Math.max(...points.map((p) => p.y)) - y,
  };
}
export function scaleElements(elements: DrawingElement[], factor: number, center: Point): DrawingElement[] {
  return elements.map((element) => {
    // Bake visible coordinates for paths so even degenerate rotated lines scale exactly.
    const bounds = elementBounds(element);
    const points =
      element.kind === 'ellipse'
        ? [
            { x: bounds.x, y: bounds.y },
            { x: bounds.x + bounds.width, y: bounds.y + bounds.height },
          ]
        : element.points.map((point) => worldPoint(point, element));
    return {
      ...element,
      rotation: element.kind === 'ellipse' ? element.rotation : 0,
      points: points.map((point) => ({
        x: center.x + (point.x - center.x) * factor,
        y: center.y + (point.y - center.y) * factor,
      })),
    };
  });
}
export function fitElements(elements: DrawingElement[]): DrawingElement[] {
  if (!elements.length) return [];
  let result = elements;
  for (let i = 0; i < 6; i++) {
    const bounds = extent(result);
    if (bounds.width > 1024 || bounds.height > 1024) {
      const factor = Math.min(0.98, 1020 / Math.max(bounds.width, bounds.height));
      result = scaleElements(result, factor, boundsCenter(bounds));
      continue;
    }
    const dx = Math.max(-bounds.x, Math.min(1024 - bounds.x - bounds.width, 0)),
      dy = Math.max(-bounds.y, Math.min(1024 - bounds.y - bounds.height, 0));
    return result.map((e) => ({ ...e, points: e.points.map((p) => ({ x: p.x + dx, y: p.y + dy })) }));
  }
  return result;
}
export function moveElements(elements: DrawingElement[], dx: number, dy: number): DrawingElement[] {
  const bounds = extent(elements);
  dx = Math.max(-bounds.x, Math.min(1024 - bounds.x - bounds.width, dx));
  dy = Math.max(-bounds.y, Math.min(1024 - bounds.y - bounds.height, dy));
  return elements.map((e) => ({ ...e, points: e.points.map((p) => ({ x: p.x + dx, y: p.y + dy })) }));
}
export function rotateElements(
  elements: DrawingElement[],
  angle: number,
  center = boundsCenter(selectionBounds(elements)),
): DrawingElement[] {
  return fitElements(
    elements.map((e) => {
      const origin = boundsCenter(elementBounds(e)),
        destination = rotatePoint(origin, center, angle);
      return {
        ...e,
        rotation: ((((e.rotation + angle + 540) % 360) + 360) % 360) - 180,
        points: e.points.map((p) => ({
          x: p.x + destination.x - origin.x,
          y: p.y + destination.y - origin.y,
        })),
      };
    }),
  );
}
function inPolygon(point: Point, polygon: Point[]): boolean {
  let inside = false;
  for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
    const a = polygon[i],
      b = polygon[j];
    if (a.y > point.y !== b.y > point.y && point.x < ((b.x - a.x) * (point.y - a.y)) / (b.y - a.y) + a.x)
      inside = !inside;
  }
  return inside;
}
function intersects(a: Point, b: Point, c: Point, d: Point): boolean {
  const cross = (p: Point, q: Point, r: Point) => (q.x - p.x) * (r.y - p.y) - (q.y - p.y) * (r.x - p.x);
  const ca = cross(a, b, c),
    da = cross(a, b, d),
    ac = cross(c, d, a),
    bc = cross(c, d, b);
  return ca * da < 0 && ac * bc < 0;
}
function outline(element: DrawingElement): Point[] {
  if (element.kind === 'ellipse') {
    const b = elementBounds(element),
      c = boundsCenter(b);
    return Array.from({ length: 33 }, (_, i) =>
      worldPoint(
        {
          x: c.x + (Math.cos((i * Math.PI) / 16) * b.width) / 2,
          y: c.y + (Math.sin((i * Math.PI) / 16) * b.height) / 2,
        },
        element,
      ),
    );
  }
  if (element.kind === 'curve')
    return Array.from({ length: 33 }, (_, i) => {
      const t = i / 32,
        [a, b, c] = element.points;
      return worldPoint(
        {
          x: (1 - t) ** 2 * a.x + 2 * (1 - t) * t * b.x + t * t * c.x,
          y: (1 - t) ** 2 * a.y + 2 * (1 - t) * t * b.y + t * t * c.y,
        },
        element,
      );
    });
  return element.points.map((p) => worldPoint(p, element));
}
export function selectedByPolygon(element: DrawingElement, polygon: Point[]): boolean {
  if (polygon.length < 3) return false;
  const shape = selectionBounds([element]),
    px = polygon.map((p) => p.x),
    py = polygon.map((p) => p.y);
  if (
    shape.x > Math.max(...px) ||
    shape.x + shape.width < Math.min(...px) ||
    shape.y > Math.max(...py) ||
    shape.y + shape.height < Math.min(...py)
  )
    return false;
  const paths =
    element.kind === 'figure'
      ? [
          ...figureConnections.map(([a, b]) => [
            worldPoint(element.points[a], element),
            worldPoint(element.points[b], element),
          ]),
          Array.from({ length: 33 }, (_, i) =>
            worldPoint(
              {
                x: element.points[0].x + Math.cos((i * Math.PI) / 16) * headRadius(element),
                y: element.points[0].y + Math.sin((i * Math.PI) / 16) * headRadius(element),
              },
              element,
            ),
          ),
        ]
      : [outline(element)];
  for (const line of paths) {
    if (line.some((p) => inPolygon(p, polygon))) return true;
    for (let i = 1; i < line.length; i++)
      for (let j = 0; j < polygon.length; j++)
        if (intersects(line[i - 1], line[i], polygon[j], polygon[(j + 1) % polygon.length])) return true;
  }
  return false;
}
export function normalizeSelection(elements: DrawingElement[]): DrawingElement[] {
  const bounds = selectionBounds(elements),
    center = boundsCenter(bounds),
    scale = Math.min(
      864 / bounds.width,
      864 / bounds.height,
      100 / Math.max(...elements.map((e) => e.width)),
    );
  return fitElements(
    scaleElements(elements, scale, center).map((e) => ({
      ...e,
      width: Math.max(1, e.width * scale),
      points: e.points.map((p) => ({ x: p.x + 512 - center.x, y: p.y + 512 - center.y })),
    })),
  );
}
