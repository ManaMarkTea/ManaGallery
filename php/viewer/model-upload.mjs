import { Scene, PerspectiveCamera, WebGLRenderer, Color, HemisphereLight, DirectionalLight, Box3, Vector3 } from 'three';
import { loadEmbeddedGLB, disposeModel } from './embedded-glb.mjs';

async function thumbnail(bytes) {
  let renderer, model;
  try {
    const gltf = await loadEmbeddedGLB(bytes); model = gltf.scene;
    const scene = new Scene(); scene.background = new Color('#202b36'); scene.add(model);
    scene.add(new HemisphereLight(0xffffff, 0x667788, 3));
    const light = new DirectionalLight(0xffffff, 3); light.position.set(3, 5, 4); scene.add(light);
    model.updateMatrixWorld(true);
    const box = new Box3().setFromObject(model), size = box.getSize(new Vector3());
    if (box.isEmpty() || !Number.isFinite(size.length()) || size.length() <= 0) throw new Error('The model has no visible geometry.');
    model.position.sub(box.getCenter(new Vector3()));
    const radius = Math.max(size.length() / 2, 0.01);
    const camera = new PerspectiveCamera(40, 1, radius / 100, radius * 100);
    camera.position.set(1.2, 0.8, 1.6).normalize().multiplyScalar(radius / Math.sin(camera.fov * Math.PI / 360) * 1.1);
    camera.lookAt(0, 0, 0);
    renderer = new WebGLRenderer({ antialias: true, preserveDrawingBuffer: true }); renderer.setSize(640, 640, false);
    renderer.render(scene, camera);
    const blob = await new Promise(resolve => renderer.domElement.toBlob(resolve, 'image/webp', 0.9));
    if (!blob) throw new Error('Could not capture the thumbnail.');
    return blob;
  } finally {
    disposeModel(model); renderer?.dispose(); renderer?.forceContextLoss();
  }
}

const form = document.querySelector('[data-model-upload]');
if (form) {
  const fileInput = form.querySelector('[name="image"]'), previewInput = form.querySelector('[name="modelPreview"]');
  const label = form.querySelector('[data-model-thumbnail]'), status = form.querySelector('[data-model-upload-status]');
  const image = form.querySelector('[data-model-thumbnail-image]'), button = form.querySelector('[type="submit"]');
  label.hidden = true;
  let generation = 0, previewUrl;
  function showPreview(file) {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file); image.src = previewUrl; image.hidden = false;
  }
  previewInput.addEventListener('change', () => {
    generation++; button.disabled = false;
    if (previewInput.files[0]) { showPreview(previewInput.files[0]); status.textContent = 'Thumbnail selected.'; }
  });
  fileInput.addEventListener('change', async () => {
    const current = ++generation, file = fileInput.files[0], isModel = /\.glb$/i.test(file?.name ?? '');
    previewInput.value = ''; previewInput.required = isModel; label.hidden = !isModel; image.hidden = true;
    status.textContent = ''; button.disabled = false;
    if (!isModel) return;
    if (file.size > 10 * 1024 * 1024) { status.textContent = 'Choose a GLB up to 10 MiB.'; return; }
    button.disabled = true; status.textContent = 'Rendering model thumbnail...';
    try {
      const blob = await thumbnail(await file.arrayBuffer());
      if (current !== generation) return;
      const generated = new File([blob], 'model-thumbnail.webp', { type: blob.type });
      const transfer = new DataTransfer(); transfer.items.add(generated); previewInput.files = transfer.files;
      showPreview(generated); status.textContent = 'Model thumbnail ready. You can choose a different thumbnail below.';
    } catch (error) {
      if (current === generation) status.textContent = 'Could not render the thumbnail. Choose an image below. ' + error.message;
    } finally { if (current === generation) button.disabled = false; }
  });
}
