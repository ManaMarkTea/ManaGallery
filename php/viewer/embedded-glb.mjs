import { LoadingManager } from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';

export async function loadEmbeddedGLB(bytes) {
  const view = new DataView(bytes);
  if (bytes.byteLength < 28 || bytes.byteLength > 25 * 1024 * 1024 || view.getUint32(0, true) !== 0x46546c67 || view.getUint32(4, true) !== 2 || view.getUint32(8, true) !== bytes.byteLength) throw new Error('Invalid GLB 2.0 model.');
  const length = view.getUint32(12, true);
  if (length > 2097152 || length + 20 > bytes.byteLength || view.getUint32(16, true) !== 0x4e4f534a) throw new Error('Invalid GLB model data.');
  const data = JSON.parse(new TextDecoder().decode(new Uint8Array(bytes, 20, length)));
  function check(value) {
    if (!value || typeof value !== 'object') return;
    for (const [key, child] of Object.entries(value)) {
      if (key === 'uri') throw new Error('Embed textures and geometry in the GLB; linked files are not supported.');
      check(child);
    }
  }
  check(data);
  if ((data.nodes?.length ?? 0) > 8192 || (data.meshes?.length ?? 0) > 4096 || (data.images?.length ?? 0) > 64) throw new Error('This model has too many objects or textures.');
  const states = new Map(); let vertices = 0;
  function visit(index, depth) {
    if (!Number.isInteger(index) || index < 0 || !data.nodes?.[index] || depth > 128 || states.get(index) === 1) throw new Error('Invalid model hierarchy.');
    if (states.get(index) === 2) return;
    states.set(index, 1);
    const node = data.nodes[index];
    if (node.mesh !== undefined) {
      const mesh = data.meshes?.[node.mesh]; if (!mesh) throw new Error('Invalid model mesh.');
      for (const primitive of mesh.primitives ?? []) vertices += data.accessors?.[primitive.attributes?.POSITION]?.count ?? 0;
    }
    for (const child of node.children ?? []) visit(child, depth + 1);
    states.set(index, 2);
  }
  for (let i = 0; i < (data.nodes?.length ?? 0); i++) visit(i, 0);
  if (!Number.isFinite(vertices) || vertices < 1 || vertices > 1000000) throw new Error('Models must contain up to 1,000,000 vertices.');
  const manager = new LoadingManager();
  manager.setURLModifier(url => {
    // GLTFLoader creates local blob URLs for embedded image buffer views.
    if (url.startsWith('blob:') && new URL(url).origin === location.origin) return url;
    throw new Error('External model resources are disabled.');
  });
  return new GLTFLoader(manager).parseAsync(bytes, '');
}

export function disposeModel(model) {
  const resources = new Set();
  model?.traverse(node => {
    if (node.geometry) resources.add(node.geometry);
    for (const material of Array.isArray(node.material) ? node.material : [node.material]) {
      if (!material) continue;
      resources.add(material);
      for (const value of Object.values(material)) if (value?.isTexture) resources.add(value);
    }
  });
  for (const resource of resources) resource.dispose();
}
