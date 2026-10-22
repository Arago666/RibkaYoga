export type Vec3 = [number, number, number];
export type IllustrationStyle = 'original' | 'line' | 'volume';
export type Character = 'neutral' | 'female' | 'male';
export interface MannequinPose {
  name: string;
  camera: { yaw: number; pitch: number };
  head_direction: Vec3;
  joints: Record<string, Vec3>;
}
type Triangle = [Vec3, Vec3, Vec3, Vec3];
const bones = [
  ['head', 'neck'],
  ['neck', 'pelvis'],
  ['leftShoulder', 'rightShoulder'],
  ['leftHip', 'rightHip'],
  ['leftShoulder', 'leftElbow'],
  ['leftElbow', 'leftWrist'],
  ['rightShoulder', 'rightElbow'],
  ['rightElbow', 'rightWrist'],
  ['leftHip', 'leftKnee'],
  ['leftKnee', 'leftAnkle'],
  ['leftAnkle', 'leftToe'],
  ['rightHip', 'rightKnee'],
  ['rightKnee', 'rightAnkle'],
  ['rightAnkle', 'rightToe'],
] as const;
const add = (a: Vec3, b: Vec3): Vec3 => [a[0] + b[0], a[1] + b[1], a[2] + b[2]];
const sub = (a: Vec3, b: Vec3): Vec3 => [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
const mul = (a: Vec3, n: number): Vec3 => [a[0] * n, a[1] * n, a[2] * n];
const cross = (a: Vec3, b: Vec3): Vec3 => [
  a[1] * b[2] - a[2] * b[1],
  a[2] * b[0] - a[0] * b[2],
  a[0] * b[1] - a[1] * b[0],
];
const length = (a: Vec3) => Math.hypot(...a);
const normal = (a: Vec3) => mul(a, 1 / Math.max(0.000001, length(a)));
function rotate(p: Vec3, yaw: number, pitch: number): Vec3 {
  const x = p[0] * Math.cos(yaw) + p[2] * Math.sin(yaw),
    z = -p[0] * Math.sin(yaw) + p[2] * Math.cos(yaw);
  return [x, p[1] * Math.cos(pitch) - z * Math.sin(pitch), p[1] * Math.sin(pitch) + z * Math.cos(pitch)];
}
function characterJoints(original: Record<string, Vec3>, character: Character): Record<string, Vec3> {
  const joints = Object.fromEntries(
    Object.entries(original).map(([key, point]) => [key, [...point] as Vec3]),
  );
  const shoulders = character === 'male' ? 1.1 : character === 'female' ? 0.94 : 1,
    hips = character === 'female' ? 1.12 : character === 'male' ? 0.94 : 1;
  for (const [left, right, factor] of [
    ['leftShoulder', 'rightShoulder', shoulders],
    ['leftHip', 'rightHip', hips],
  ] as [string, string, number][]) {
    const center = mul(add(joints[left]!, joints[right]!), 0.5);
    for (const key of [left, right]) joints[key] = add(center, mul(sub(joints[key]!, center), factor));
  }
  return joints;
}
function cylinder(triangles: Triangle[], a: Vec3, b: Vec3, radius: number, color: Vec3): void {
  const axis = normal(sub(b, a)),
    side = normal(cross(axis, Math.abs(axis[1]) < 0.9 ? [0, 1, 0] : [1, 0, 0])),
    other = normal(cross(axis, side));
  const radial = (angle: number) =>
    add(mul(side, Math.cos(angle) * radius), mul(other, Math.sin(angle) * radius));
  for (let i = 0; i < 16; i++) {
    const u = radial((i * 2 * Math.PI) / 16),
      v = radial(((i + 1) * 2 * Math.PI) / 16);
    triangles.push([add(a, u), add(b, u), add(b, v), color], [add(a, u), add(b, v), add(a, v), color]);
  }
}
function sphere(
  triangles: Triangle[],
  center: Vec3,
  radii: Vec3,
  color: Vec3,
  basis: [Vec3, Vec3, Vec3] = [
    [1, 0, 0],
    [0, 1, 0],
    [0, 0, 1],
  ],
): void {
  const vertex = (latitude: number, longitude: number): Vec3 => {
    const coords: Vec3 = [
      Math.cos(latitude) * Math.cos(longitude) * radii[0],
      Math.sin(latitude) * radii[1],
      Math.cos(latitude) * Math.sin(longitude) * radii[2],
    ];
    return add(
      center,
      add(mul(basis[0], coords[0]), add(mul(basis[1], coords[1]), mul(basis[2], coords[2]))),
    );
  };
  for (let lat = 0; lat < 10; lat++)
    for (let lon = 0; lon < 16; lon++) {
      const a = vertex(-Math.PI / 2 + (lat * Math.PI) / 10, (lon * Math.PI * 2) / 16),
        b = vertex(-Math.PI / 2 + ((lat + 1) * Math.PI) / 10, (lon * Math.PI * 2) / 16),
        c = vertex(-Math.PI / 2 + ((lat + 1) * Math.PI) / 10, ((lon + 1) * Math.PI * 2) / 16),
        d = vertex(-Math.PI / 2 + (lat * Math.PI) / 10, ((lon + 1) * Math.PI * 2) / 16);
      triangles.push([a, b, c, color], [a, c, d, color]);
    }
}

/** A real 3D joint rig and lit solid mesh, projected into canvas; no source PNG is used. */
export function renderMannequin(
  canvas: HTMLCanvasElement,
  pose: MannequinPose,
  style: 'line' | 'volume',
  character: Character,
  yawDegrees: number,
  pitchDegrees: number,
): void {
  const context = canvas.getContext('2d');
  if (!context) throw new Error('Браузер не поддерживает холст.');
  const joints = characterJoints(pose.joints, character),
    yaw = (yawDegrees * Math.PI) / 180,
    pitch = (pitchDegrees * Math.PI) / 180;
  const rotated = Object.values(joints).map((p) => rotate(p, yaw, pitch));
  const xs = rotated.map((p) => p[0]),
    ys = rotated.map((p) => p[1]);
  const minX = Math.min(...xs) - 0.25,
    maxX = Math.max(...xs) + 0.25,
    minY = Math.min(...ys) - 0.25,
    maxY = Math.max(...ys) + 0.25;
  const scale = 840 / Math.max(maxX - minX, maxY - minY),
    cx = (minX + maxX) / 2,
    cy = (minY + maxY) / 2;
  const project = (p: Vec3): Vec3 => {
    const [x, y, z] = rotate(p, yaw, pitch);
    return [512 + (x - cx) * scale, 512 - (y - cy) * scale, z];
  };
  context.save();
  context.setTransform(canvas.width / 1024, 0, 0, canvas.height / 1024, 0, 0);
  context.fillStyle = '#fff';
  context.fillRect(0, 0, 1024, 1024);
  if (style === 'line') {
    context.strokeStyle = '#121612';
    context.lineWidth = 10;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    for (const [a, b] of bones) {
      const start = project(joints[a]!),
        end = project(joints[b]!);
      context.beginPath();
      context.moveTo(start[0], start[1]);
      context.lineTo(end[0], end[1]);
      context.stroke();
    }
    const head = project(joints.head!);
    context.beginPath();
    context.arc(head[0], head[1], scale * 0.165, 0, 2 * Math.PI);
    context.fillStyle = 'white';
    context.fill();
    context.stroke();
    context.restore();
    return;
  }
  const triangles: Triangle[] = [],
    limbRadius = character === 'male' ? 0.067 : character === 'female' ? 0.054 : 0.06;
  const bodyColor: Vec3 =
      character === 'female' ? [117, 151, 125] : character === 'male' ? [112, 137, 159] : [141, 149, 144],
    jointColor: Vec3 = [188, 182, 163];
  cylinder(triangles, joints.leftShoulder!, joints.rightShoulder!, 0.09, bodyColor);
  for (const [a, b] of bones) {
    if (['neck:pelvis', 'leftShoulder:rightShoulder', 'leftHip:rightHip'].includes(`${a}:${b}`)) continue;
    const radius = a.includes('Hip') || a.includes('Knee') ? limbRadius * 1.2 : limbRadius;
    cylinder(triangles, joints[a]!, joints[b]!, radius, bodyColor);
    sphere(triangles, joints[b]!, [radius, radius, radius], jointColor);
  }
  const center = mul(add(joints.neck!, joints.pelvis!), 0.5),
    axis = normal(sub(joints.neck!, joints.pelvis!)),
    side = normal(sub(joints.rightShoulder!, joints.leftShoulder!)),
    depth = normal(cross(side, axis));
  sphere(
    triangles,
    center,
    [character === 'male' ? 0.22 : 0.19, length(sub(joints.neck!, joints.pelvis!)) * 0.5, 0.115],
    bodyColor,
    [side, axis, depth],
  );
  sphere(triangles, joints.pelvis!, [character === 'female' ? 0.18 : 0.155, 0.13, 0.12], bodyColor, [
    side,
    axis,
    depth,
  ]);
  sphere(triangles, joints.neck!, [0.065, 0.07, 0.065], jointColor);
  sphere(triangles, joints.head!, [0.16, 0.18, 0.15], [205, 194, 170]);
  sphere(
    triangles,
    add(joints.head!, mul(normal(pose.head_direction), 0.157)),
    [0.035, 0.03, 0.035],
    [183, 171, 149],
  );
  const light = normal([-0.4, 0.7, 1]);
  const projected = triangles
    .map(([a, b, c, color]) => {
      const n = rotate(normal(cross(sub(b, a), sub(c, a))), yaw, pitch),
        shade = 0.5 + 0.5 * Math.max(0, n[0] * light[0] + n[1] * light[1] + n[2] * light[2]);
      const points = [project(a), project(b), project(c)];
      return {
        points,
        depth: points.reduce((sum, p) => sum + p[2], 0) / 3,
        color: `rgb(${color.map((v) => Math.round(v * shade)).join(',')})`,
      };
    })
    .sort((a, b) => a.depth - b.depth);
  for (const triangle of projected) {
    context.fillStyle = triangle.color;
    context.strokeStyle = triangle.color;
    context.lineWidth = 0.6;
    context.beginPath();
    context.moveTo(triangle.points[0]![0], triangle.points[0]![1]);
    context.lineTo(triangle.points[1]![0], triangle.points[1]![1]);
    context.lineTo(triangle.points[2]![0], triangle.points[2]![1]);
    context.closePath();
    context.fill();
    context.stroke();
  }
  context.restore();
}
