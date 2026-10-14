/** Render hand-authored poses with the very same paths used by AsanaDrawing.vue. */
import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';

const root = path.resolve(import.meta.dirname, '..');
const args = process.argv.slice(2);
const option = (name, fallback) => {
  const index = args.indexOf(name);
  return index < 0 ? fallback : args[index + 1];
};
const canvasPath = option('--canvas-module', process.env.RIBKA_CANVAS_MODULE);
if (!canvasPath) throw new Error('Specify --canvas-module with the installed @napi-rs/canvas entry point.');
const { createCanvas, Path2D } = await import(pathToFileURL(path.resolve(canvasPath)));
globalThis.Path2D = Path2D;
const require = createRequire(path.join(root, 'package.json'));
const ts = require('typescript');
const geometrySource = await fs.readFile(
  path.join(root, 'resources/js/modules/catalog/drawingGeometry.ts'),
  'utf8',
);
const geometryModule = ts.transpileModule(geometrySource, {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 },
}).outputText;
const { drawElement } = await import(
  `data:text/javascript;base64,${Buffer.from(geometryModule).toString('base64')}`
);
const output = path.resolve(option('--output', path.join(root, '.runtime/drawing-pack')));
await fs.mkdir(output, { recursive: true });

function uuid(key) {
  const bytes = createHash('sha256').update(key).digest().subarray(0, 16);
  bytes[6] = (bytes[6] & 15) | 80;
  bytes[8] = (bytes[8] & 63) | 128;
  const h = bytes.toString('hex');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
function documentFromRecipe(key, recipe) {
  const elements = [];
  const add = (kind, points, width = 12) =>
    elements.push({
      id: uuid(`${key}:${elements.length}`),
      kind,
      width,
      rotation: 0,
      points: points.map(([x, y]) => ({ x, y })),
    });
  if (recipe.head) {
    const [x, y, r] = recipe.head;
    add('ellipse', [
      [x - r, y - r],
      [x + r, y + r],
    ]);
    if (recipe.filledHead) add('pen', [[x, y]]);
  }
  for (const points of recipe.lines ?? []) {
    for (let i = 1; i < points.length; i++) add('line', [points[i - 1], points[i]]);
  }
  for (const points of recipe.curves ?? []) add('curve', points);
  for (const points of recipe.paths ?? []) add('pen', points);
  for (const [dotX, dotY, dotRadius = 0.3] of recipe.dots ?? []) {
    add(
      'ellipse',
      [
        [dotX - dotRadius, dotY - dotRadius],
        [dotX + dotRadius, dotY + dotRadius],
      ],
      6,
    );
  }
  const points = [
    ...elements.flatMap((element) => element.points),
    ...(recipe.filledBoxes ?? []).flatMap(([left, top, right, bottom]) => [
      { x: left, y: top },
      { x: right, y: bottom },
    ]),
  ];
  const xs = points.map((p) => p.x),
    ys = points.map((p) => p.y);
  const minX = Math.min(...xs),
    maxX = Math.max(...xs),
    minY = Math.min(...ys),
    maxY = Math.max(...ys);
  const scale = Math.min(760 / Math.max(1, maxX - minX), 760 / Math.max(1, maxY - minY));
  if (recipe.filledHead && recipe.head) {
    elements[1].width = Number(Math.min(100, 2 * recipe.head[2] * scale).toFixed(3));
  }
  for (const element of elements) {
    element.points = element.points.map((p) => ({
      x: Number((512 + (p.x - (minX + maxX) / 2) * scale).toFixed(3)),
      y: Number((512 + (p.y - (minY + maxY) / 2) * scale).toFixed(3)),
    }));
  }
  for (const [left, top, right, bottom] of recipe.filledBoxes ?? []) {
    const x1 = 512 + (left - (minX + maxX) / 2) * scale;
    const x2 = 512 + (right - (minX + maxX) / 2) * scale;
    const y1 = 512 + (top - (minY + maxY) / 2) * scale;
    const y2 = 512 + (bottom - (minY + maxY) / 2) * scale;
    const stroke = Math.min(12, x2 - x1, y2 - y1);
    if (stroke < 1) throw new Error(`Invalid filled support box in ${key}`);
    const rows = Math.max(1, Math.ceil((y2 - y1 - stroke) / (stroke * 0.8)));
    for (let row = 0; row <= rows; row++) {
      const y = y1 + stroke / 2 + ((y2 - y1 - stroke) * row) / rows;
      add(
        'line',
        [
          [x1 + stroke / 2, y],
          [x2 - stroke / 2, y],
        ],
        stroke,
      );
    }
  }
  return { format: 'ribka-drawing', version: 1, width: 1024, height: 1024, smoothing: 70, elements };
}

const inputs = [
  ['catalog', path.join(root, 'resources/imports/catalog-redraw/recipes.json')],
  ['sheet', path.join(root, 'resources/imports/spinal-arc-sheet/recipes-upper.json')],
  ['sheet', path.join(root, 'resources/imports/spinal-arc-sheet/recipes-lower.json')],
  ...[
    'manual-a',
    'manual-b',
    'shared-a',
    'shared-b',
    'historical',
    'shared-refined-a',
    'shared-refined-b',
  ].map((part) => ['server', path.join(root, `resources/imports/server-catalog/recipes-${part}.json`)]),
];
const rendered = [];
for (const [family, file] of inputs) {
  let recipes;
  try {
    recipes = JSON.parse(await fs.readFile(file, 'utf8')).recipes;
  } catch (error) {
    if (error.code === 'ENOENT') continue;
    throw error;
  }
  for (const [key, recipe] of Object.entries(recipes)) {
    const drawing = documentFromRecipe(`${family}:${key}`, recipe);
    const stem = `${family}-${key.replaceAll(/[^a-zA-Z0-9_-]/g, '-')}`;
    const canvas = createCanvas(1024, 1024),
      ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, 1024, 1024);
    for (const element of drawing.elements) drawElement(ctx, element, drawing.smoothing);
    const imageFile = path.join(output, `${stem}.png`),
      sourceFile = path.join(output, `${stem}.json`);
    await fs.writeFile(imageFile, await canvas.encode('png'));
    await fs.writeFile(sourceFile, JSON.stringify(drawing, null, 2) + '\n');
    rendered.push({
      family,
      key,
      image_file: path.basename(imageFile),
      source_file: path.basename(sourceFile),
      elements: drawing.elements.length,
    });
  }
}
await fs.writeFile(
  path.join(output, 'rendered.json'),
  JSON.stringify({ style: 'ribka-clean-line-v1', items: rendered }, null, 2) + '\n',
);
console.log(`Rendered ${rendered.length} editable drawings through drawingGeometry.ts into ${output}`);
