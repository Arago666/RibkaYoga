import * as T from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { TransformControls } from 'three/addons/controls/TransformControls.js';
export interface PoseSource {
  format: 'ribka-pose';
  version: 1;
  model: 'yoga-human-v1';
  bones: Record<string, number[]>;
  position: number[];
  camera: number[];
  target: number[];
  zoom: number;
  orientation?: number[];
}
export const joints = [
  ['Body', 'Всё тело'],
  ['Hips', 'Таз'],
  ['Spine', 'Поясница'],
  ['Spine1', 'Грудной отдел'],
  ['Spine2', 'Плечевой пояс'],
  ['Neck', 'Шея'],
  ['Head', 'Голова'],
  ['LeftShoulder', 'Левая ключица'],
  ['LeftArm', 'Левое плечо'],
  ['LeftForeArm', 'Левый локоть'],
  ['LeftHand', 'Левая кисть'],
  ['RightShoulder', 'Правая ключица'],
  ['RightArm', 'Правое плечо'],
  ['RightForeArm', 'Правый локоть'],
  ['RightHand', 'Правая кисть'],
  ['LeftUpLeg', 'Левое бедро'],
  ['LeftLeg', 'Левое колено'],
  ['LeftFoot', 'Левая стопа'],
  ['LeftToeBase', 'Левые пальцы стопы'],
  ['RightUpLeg', 'Правое бедро'],
  ['RightLeg', 'Правое колено'],
  ['RightFoot', 'Правая стопа'],
  ['RightToeBase', 'Правые пальцы стопы'],
] as const;
export const presets = [
  ['standing', 'Стоя'],
  ['seated', 'Сидя'],
  ['lying', 'Лёжа'],
  ['table', 'На четвереньках'],
  ['plank', 'Планка'],
  ['dog', 'Собака вниз'],
  ['lunge', 'Выпад'],
] as const;
type Preset = (typeof presets)[number][0];
export async function createPoseScene(
  host: HTMLElement,
  changed: () => void,
  selected: (name: string, mode: 'rotate' | 'translate') => void,
) {
  const renderer = new T.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.5));
  renderer.outputColorSpace = T.SRGBColorSpace;
  host.appendChild(renderer.domElement);
  const scene = new T.Scene();
  const camera = new T.OrthographicCamera(-1.5, 1.5, 1.5, -1.5, 0.01, 100);
  camera.position.set(0, 0.9, 4);
  const orbit = new OrbitControls(camera, renderer.domElement);
  orbit.target.set(0, 0.9, 0);
  orbit.enableDamping = false;
  orbit.enableRotate = false;
  orbit.enablePan = false;
  orbit.minZoom = 0.1;
  orbit.maxZoom = 20;
  scene.add(new T.HemisphereLight(0xffffff, 0xb5b4a6, 2.2));
  const light = new T.DirectionalLight(0xffffff, 2.4);
  light.position.set(2, 4, 4);
  scene.add(light);
  const fill = new T.DirectionalLight(0xffffff, 0.7);
  fill.position.set(-3, 2, -2);
  scene.add(fill);
  const grid = new T.GridHelper(4, 20, 0x91a897, 0xe1e8df);
  scene.add(grid);
  const root = new T.Group();
  const person = new T.Group();
  scene.add(person);
  person.add(root);
  const bones: Record<string, T.Bone> = {};
  const rest: Record<string, T.Quaternion> = {};
  const handles = new T.Group();
  scene.add(handles);
  const gizmo = new TransformControls(camera, renderer.domElement);
  gizmo.setSize(0.8);
  gizmo.setMode('rotate');
  gizmo.setSpace('local');
  const gizmoHelper = gizmo.getHelper();
  scene.add(gizmoHelper);
  const target = new T.Object3D();
  scene.add(target);
  let active = 'Hips',
    mode: 'rotate' | 'translate' = 'rotate',
    disposed = false,
    exporting = false;
  let meshes: T.SkinnedMesh[] = [];
  const markers: Record<string, T.Mesh> = {};
  const point = (b: T.Object3D) => b.getWorldPosition(new T.Vector3());
  function render() {
    if (disposed) return;
    person.updateMatrixWorld(true);
    for (const [key, marker] of Object.entries(markers)) marker.position.copy(point(bones[key]!));
    renderer.render(scene, camera);
  }
  function resize() {
    const w = host.clientWidth,
      h = host.clientHeight;
    renderer.setSize(w, h);
    const aspect = w / h;
    camera.left = -1.4 * Math.max(1, aspect);
    camera.right = -camera.left;
    camera.top = 1.4 / Math.min(1, aspect);
    camera.bottom = -camera.top;
    camera.updateProjectionMatrix();
    render();
  }
  orbit.addEventListener('change', render);
  orbit.addEventListener('end', changed);
  gizmo.addEventListener('dragging-changed', (e) => {
    orbit.enabled = !e.value;
    if (!e.value) changed();
  });
  function aim(bone: T.Bone, child: T.Bone, direction: T.Vector3) {
    person.updateMatrixWorld(true);
    const from = point(child).sub(point(bone)).normalize();
    const delta = new T.Quaternion().setFromUnitVectors(from, direction.clone().normalize());
    const parent = bone.parent!.getWorldQuaternion(new T.Quaternion());
    bone.quaternion.copy(
      parent.clone().invert().multiply(delta).multiply(bone.getWorldQuaternion(new T.Quaternion())),
    );
    person.updateMatrixWorld(true);
  }
  function solve(key = active, destination = point(target)) {
    const end = bones[key];
    if (!end) return;
    const chain = [end.parent, end.parent?.parent];
    for (let iteration = 0; iteration < 12; iteration++)
      for (const joint of chain) {
        if (!(joint instanceof T.Bone)) continue;
        const origin = point(joint),
          a = point(end).sub(origin),
          b = destination.clone().sub(origin);
        if (a.length() < 1e-6 || b.length() < 1e-6) continue;
        const delta = new T.Quaternion().setFromUnitVectors(a.normalize(), b.normalize());
        const parent = joint.parent!.getWorldQuaternion(new T.Quaternion());
        joint.quaternion.copy(
          parent.clone().invert().multiply(delta).multiply(joint.getWorldQuaternion(new T.Quaternion())),
        );
        person.updateMatrixWorld(true);
      }
    if (key === 'Hips') {
      const displacement = destination
        .clone()
        .sub(point(end))
        .applyQuaternion(person.quaternion.clone().invert());
      root.position.add(displacement);
    }
  }
  let paired: string | null = null;
  let baseMain = new T.Quaternion(),
    basePair = new T.Quaternion();
  let pairStart = new T.Vector3(),
    mainStart = new T.Vector3();
  function propagateRotation() {
    if (!paired) return;
    const delta = baseMain.clone().invert().multiply(bones[active]!.quaternion);
    const mirrored = new T.Quaternion(delta.x, -delta.y, -delta.z, delta.w);
    bones[paired]!.quaternion.copy(basePair).multiply(mirrored);
  }
  gizmo.addEventListener('objectChange', () => {
    if (mode === 'translate') {
      solve();
      if (paired) {
        const delta = point(target).sub(mainStart);
        delta.x *= -1;
        solve(paired, pairStart.clone().add(delta));
      }
    } else propagateRotation();
    render();
  });
  function choose(key: string, pair: string | null = null) {
    if (key !== 'Body' && !bones[key]) return;
    active = key;
    paired = pair;
    gizmo.setMode(mode);
    gizmo.setSpace(mode === 'rotate' ? 'local' : 'world');
    if (key === 'Body') {
      if (mode === 'translate') {
        active = 'Hips';
        target.position.copy(point(bones.Hips!));
        gizmo.attach(target);
      } else gizmo.attach(person);
    } else if (mode === 'translate') {
      target.position.copy(point(bones[key]!));
      gizmo.attach(target);
    } else gizmo.attach(bones[key]!);
    if (paired) {
      baseMain.copy(bones[active]!.quaternion);
      basePair.copy(bones[paired]!.quaternion);
      mainStart.copy(point(bones[active]!));
      pairStart.copy(point(bones[paired]!));
    }
    selected(key, mode);
    render();
  }
  const ray = new T.Raycaster();
  const dragPlane = new T.Plane();
  const dragOffset = new T.Vector3();
  let markerPointer: number | null = null;
  function updateRay(event: PointerEvent) {
    const bounds = renderer.domElement.getBoundingClientRect();
    ray.setFromCamera(
      new T.Vector2(
        ((event.clientX - bounds.left) / bounds.width) * 2 - 1,
        (-(event.clientY - bounds.top) / bounds.height) * 2 + 1,
      ),
      camera,
    );
  }
  function moveMarker(event: PointerEvent) {
    if (exporting || markerPointer !== event.pointerId) return;
    updateRay(event);
    const intersection = ray.ray.intersectPlane(dragPlane, new T.Vector3());
    if (!intersection) return;
    target.position.copy(intersection).add(dragOffset);
    solve();
    if (paired) {
      const delta = point(target).sub(mainStart);
      delta.x *= -1;
      solve(paired, pairStart.clone().add(delta));
    }
    render();
  }
  function releaseMarker(event: PointerEvent) {
    if (markerPointer !== event.pointerId) return;
    markerPointer = null;
    gizmo.enabled = true;
    orbit.enabled = true;
    changed();
    if (renderer.domElement.hasPointerCapture(event.pointerId))
      renderer.domElement.releasePointerCapture(event.pointerId);
  }
  function click(event: PointerEvent) {
    if (exporting || gizmo.dragging || gizmo.axis) return;
    const r = renderer.domElement.getBoundingClientRect();
    ray.setFromCamera(
      new T.Vector2(
        ((event.clientX - r.left) / r.width) * 2 - 1,
        (-(event.clientY - r.top) / r.height) * 2 + 1,
      ),
      camera,
    );
    const hit = ray.intersectObjects(Object.values(markers))[0];
    if (hit) {
      choose(hit.object.name);
      if (mode === 'translate') {
        dragPlane.setFromNormalAndCoplanarPoint(camera.getWorldDirection(new T.Vector3()), target.position);
        const intersection = ray.ray.intersectPlane(dragPlane, new T.Vector3());
        if (intersection) {
          dragOffset.copy(target.position).sub(intersection);
          markerPointer = event.pointerId;
          gizmo.enabled = false;
          orbit.enabled = false;
          renderer.domElement.setPointerCapture(event.pointerId);
        }
      }
    }
  }
  renderer.domElement.addEventListener('pointerdown', click);
  renderer.domElement.addEventListener('pointermove', moveMarker);
  renderer.domElement.addEventListener('pointerup', releaseMarker);
  renderer.domElement.addEventListener('pointercancel', releaseMarker);
  const observer = new ResizeObserver(resize);
  observer.observe(host);
  function dispose() {
    disposed = true;
    observer.disconnect();
    renderer.domElement.removeEventListener('pointerdown', click);
    renderer.domElement.removeEventListener('pointermove', moveMarker);
    renderer.domElement.removeEventListener('pointerup', releaseMarker);
    renderer.domElement.removeEventListener('pointercancel', releaseMarker);
    orbit.dispose();
    gizmo.dispose();
    scene.traverse((o) => {
      if (o instanceof T.Mesh) {
        o.geometry.dispose();
        for (const m of Array.isArray(o.material) ? o.material : [o.material]) {
          for (const v of Object.values(m)) if (v instanceof T.Texture) v.dispose();
          m.dispose();
        }
      }
    });
    renderer.dispose();
    renderer.domElement.remove();
  }
  let model: T.Group;
  try {
    model = (await new GLTFLoader().loadAsync('/models/yoga-human-v1/model.glb')).scene;
  } catch (e) {
    dispose();
    throw e;
  }
  root.add(model);
  const box = new T.Box3().setFromObject(root);
  const height = box.max.y - box.min.y;
  model.scale.multiplyScalar(1.8 / height);
  person.updateMatrixWorld(true);
  const bounds = new T.Box3().setFromObject(root);
  model.position.sub(
    new T.Vector3((bounds.min.x + bounds.max.x) / 2, bounds.min.y, (bounds.min.z + bounds.max.z) / 2),
  );
  model.traverse((o) => {
    if (o instanceof T.Bone) {
      const key = o.name.replace(/^mixamorig:?/, '');
      bones[key] = o;
      rest[key] = o.quaternion.clone();
    }
    if (o instanceof T.SkinnedMesh) meshes.push(o);
  });
  for (const [key] of joints) {
    if (!bones[key]) continue;
    const marker = new T.Mesh(
      new T.SphereGeometry(0.022, 10, 8),
      new T.MeshBasicMaterial({ color: 0x2b8754, depthTest: false, transparent: true, opacity: 0.8 }),
    );
    marker.name = key;
    marker.renderOrder = 5;
    markers[key] = marker;
    handles.add(marker);
  }
  function ground() {
    person.updateMatrixWorld(true);
    for (const mesh of meshes) mesh.computeBoundingBox();
    const b = new T.Box3().setFromObject(root);
    root.position.y -= b.min.y;
    person.updateMatrixWorld(true);
  }
  function reset() {
    person.quaternion.identity();
    for (const [key, b] of Object.entries(bones)) b.quaternion.copy(rest[key]!);
    root.position.set(0, 0, 0);
  }
  function direction(name: string, child: string, v: number[]) {
    if (bones[name] && bones[child])
      aim(bones[name]!, bones[child]!, new T.Vector3(...(v as [number, number, number])));
  }
  function preset(key: Preset) {
    reset();
    const torso =
      key === 'lying'
        ? [0, 0, -1]
        : ['table', 'plank'].includes(key)
          ? [0, 0.25, -1]
          : key === 'dog'
            ? [0, -0.7, -0.7]
            : [0, 1, 0];
    direction('Hips', 'Spine', torso);
    if (['table', 'plank', 'dog'].includes(key)) {
      const hips = bones.Hips!;
      const parent = hips.parent!.getWorldQuaternion(new T.Quaternion());
      const twist = new T.Quaternion().setFromAxisAngle(
        new T.Vector3(...(torso as [number, number, number])).normalize(),
        Math.PI,
      );
      hips.quaternion.copy(
        parent.clone().invert().multiply(twist).multiply(hips.getWorldQuaternion(new T.Quaternion())),
      );
      person.updateMatrixWorld(true);
    }
    for (const side of ['Left', 'Right']) {
      const sign = side === 'Left' ? 1 : -1;
      let thigh = [sign * 0.06, -1, 0],
        shin = [0, -1, 0],
        upper = [sign * 0.08, -1, 0],
        fore = [0, -1, 0];
      if (key === 'lying') {
        thigh = [0, 0, 1];
        shin = [0, 0, 1];
        upper = [sign * 0.2, 0, 1];
        fore = [0, 0, 1];
      }
      if (key === 'seated') {
        thigh = [sign * 0.85, -0.35, 0.65];
        shin = [-sign * 0.9, 0.05, 0.4];
        upper = [sign * 0.4, -1, 0.3];
        fore = [-sign * 0.25, -0.4, 0.8];
      }
      if (key === 'table') {
        thigh = [0, -1, 0];
        shin = [0, 0, 1];
      }
      if (key === 'plank') {
        thigh = [0, -0.25, 1];
        shin = [0, -0.25, 1];
      }
      if (key === 'dog') {
        thigh = [0, -0.85, 0.6];
        shin = [0, -0.85, 0.6];
        upper = [0, -0.8, -0.7];
        fore = [0, -0.8, -0.7];
      }
      if (key === 'lunge') {
        thigh = side === 'Left' ? [0, -0.2, 1] : [0, -0.7, -0.8];
        shin = side === 'Left' ? [0, -1, 0] : [0, -1, 0];
        upper = [sign * 0.1, 1, 0];
        fore = [0, 1, 0];
      }
      direction(side + 'UpLeg', side + 'Leg', thigh);
      direction(side + 'Leg', side + 'Foot', shin);
      direction(side + 'Arm', side + 'ForeArm', upper);
      direction(side + 'ForeArm', side + 'Hand', fore);
      direction(
        side + 'Foot',
        side + 'ToeBase',
        key === 'lying' ? [0, 1, 0] : key === 'plank' ? [0, -1, -0.2] : [0, 0, 1],
      );
      if (['plank', 'table', 'dog'].includes(key)) direction(side + 'Hand', side + 'HandMiddle1', [0, 0, -1]);
    }
    ground();
    if (['lying', 'table', 'plank', 'dog', 'lunge'].includes(key)) person.rotation.y = Math.PI / 2;
    person.updateMatrixWorld(true);
    const bounds = new T.Box3().setFromObject(root);
    const center = bounds.getCenter(new T.Vector3());
    const offset = camera.position.clone().sub(orbit.target);
    orbit.target.copy(center);
    camera.position.copy(center).add(offset);
    const size = bounds.getSize(new T.Vector3());
    camera.zoom = 2.8 / Math.max(size.length() * 1.2, 0.8);
    camera.updateProjectionMatrix();
    orbit.update();
    choose(active);
    render();
  }
  function snapshot(): PoseSource {
    return {
      orientation: person.quaternion.toArray(),
      format: 'ribka-pose',
      version: 1,
      model: 'yoga-human-v1',
      bones: Object.fromEntries(Object.entries(bones).map(([k, b]) => [k, b.quaternion.toArray()])),
      position: root.position.toArray(),
      camera: camera.position.toArray(),
      target: orbit.target.toArray(),
      zoom: camera.zoom,
    };
  }
  function restore(p: PoseSource) {
    reset();
    for (const [key, q] of Object.entries(p.bones)) if (bones[key]) bones[key]!.quaternion.fromArray(q);
    root.position.fromArray(p.position);
    person.quaternion.fromArray(p.orientation ?? [0, 0, 0, 1]);
    camera.position.fromArray(p.target).add(new T.Vector3(0, 0, 4));
    orbit.target.fromArray(p.target);
    camera.zoom = p.zoom;
    camera.updateProjectionMatrix();
    orbit.update();
    choose(active);
    render();
  }
  function angles() {
    const q =
      active === 'Body'
        ? person.quaternion.clone()
        : rest[active]!.clone().invert().multiply(bones[active]!.quaternion);
    const e = new T.Euler().setFromQuaternion(q, 'XYZ');
    return [e.x, e.y, e.z].map((value) => Math.round(T.MathUtils.radToDeg(value) * 10) / 10);
  }
  function rotate(values: number[]) {
    if (values.some((value) => !Number.isFinite(value))) return;
    const rotation = new T.Quaternion().setFromEuler(
      new T.Euler(...(values.map(T.MathUtils.degToRad) as [number, number, number]), 'XYZ'),
    );
    if (active === 'Body') person.quaternion.copy(rotation);
    else {
      bones[active]!.quaternion.copy(rest[active]!).multiply(rotation);
      propagateRotation();
    }
    render();
  }
  async function image() {
    exporting = true;
    const orbitEnabled = orbit.enabled;
    orbit.enabled = false;
    gizmo.enabled = false;
    const w = host.clientWidth,
      h = host.clientHeight;
    const left = camera.left,
      right = camera.right;
    const visible = gizmoHelper.visible;
    gizmoHelper.visible = false;
    handles.visible = false;
    grid.visible = false;
    const span = 2.8;
    camera.left = -span / 2;
    camera.right = span / 2;
    camera.top = span / 2;
    camera.bottom = -span / 2;
    camera.updateProjectionMatrix();
    renderer.setPixelRatio(1);
    renderer.setSize(1024, 1024, false);
    renderer.render(scene, camera);
    try {
      return await new Promise<Blob>((resolve, reject) =>
        renderer.domElement.toBlob(
          (b) => (b ? resolve(b) : reject(new Error('Не удалось получить PNG.'))),
          'image/png',
        ),
      );
    } finally {
      exporting = false;
      orbit.enabled = orbitEnabled;
      gizmo.enabled = true;
      gizmoHelper.visible = visible;
      handles.visible = true;
      grid.visible = true;
      camera.left = left;
      camera.right = right;
      camera.top = 1.4;
      camera.bottom = -1.4;
      camera.updateProjectionMatrix();
      renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
      renderer.setSize(w, h);
      resize();
    }
  }
  preset('standing');
  resize();
  return {
    snapshot,
    restore,
    preset,
    choose,
    angles,
    rotate,
    image,
    dispose,
    group(group: string, value: 'rotate' | 'translate') {
      mode = value;
      const moving = value === 'translate';
      const mappings: Record<string, [string, string | null]> = {
        body: ['Body', null],
        torso: [moving ? 'Spine2' : 'Spine', null],
        arms: [moving ? 'LeftHand' : 'LeftArm', moving ? 'RightHand' : 'RightArm'],
        legs: [moving ? 'LeftFoot' : 'LeftUpLeg', moving ? 'RightFoot' : 'RightUpLeg'],
        leftArm: [moving ? 'LeftHand' : 'LeftArm', null],
        rightArm: [moving ? 'RightHand' : 'RightArm', null],
        leftLeg: [moving ? 'LeftFoot' : 'LeftUpLeg', null],
        rightLeg: [moving ? 'RightFoot' : 'RightUpLeg', null],
      };
      const entry = mappings[group];
      if (entry) choose(...entry);
      else choose(active);
    },
    values() {
      return mode === 'translate'
        ? target.position.toArray().map((v) => Math.round(v * 1000) / 1000)
        : angles();
    },
    transform(values: number[]) {
      if (values.some((v) => !Number.isFinite(v))) return;
      if (mode === 'rotate') rotate(values);
      else {
        target.position.fromArray(values);
        solve();
        if (paired) {
          const delta = point(target).sub(mainStart);
          delta.x *= -1;
          solve(paired, pairStart.clone().add(delta));
        }
        render();
      }
    },
    mode(value: 'rotate' | 'translate') {
      mode = value;
      choose(active);
    },
  };
}
