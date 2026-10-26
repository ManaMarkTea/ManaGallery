import { Scene, PerspectiveCamera, WebGLRenderer, Color, HemisphereLight, DirectionalLight, Box3, Vector3, MeshBasicMaterial, MeshNormalMaterial, ShaderMaterial, DoubleSide } from 'three';
import { loadEmbeddedGLB } from './embedded-glb.mjs';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// Only same-origin, authenticated geometry derivatives are loaded.
export function createPreview(host,url,{interactive=false}={}) {
  if(!/^\/download\/[1-9][0-9]*\?format=glb$/.test(url))throw new Error('Invalid model URL');
  let renderer,controls,model,observer,frame,disposed=false;
  const originalMaterials=new Map(),debugMaterials=new Map(),materials=new Set();
  const texturedMaterials=new Map(),wireOverlays=new Map();
  const abort=new AbortController();
  const status=document.createElement('p');status.className='model-status';status.textContent='Loading 3D preview...';
  host.append(status);host.dataset.modelState='loading';
  const scene=new Scene();scene.background=new Color('#202b36');
  const camera=new PerspectiveCamera(40,1,0.01,1000);
  const initial=new Vector3();
  const reset=()=>{if(controls){camera.position.copy(initial);controls.target.set(0,0,0);controls.update();}};
  const dispose=()=>{
    if(disposed)return;disposed=true;abort.abort();cancelAnimationFrame(frame);observer?.disconnect();controls?.dispose();
    model?.traverse(node=>{if(node.isMesh)node.geometry.dispose();});
    for(const material of materials)material.dispose();
    if(renderer){renderer.dispose();renderer.forceContextLoss();renderer.domElement.remove();}
    status.remove();delete host.dataset.modelState;
  };
  const setMode=mode=>{
    if(!model || !['materials','textured-wireframe','wireframe','normals','uv','colors'].includes(mode))return '';
    if(mode!=='materials' && mode!=='textured-wireframe' && !debugMaterials.has(mode)){
      let material;
      if(mode==='wireframe')material=new MeshBasicMaterial({color:0xb9d9f5,wireframe:true,side:DoubleSide});
      if(mode==='normals')material=new MeshNormalMaterial({side:DoubleSide});
      if(mode==='colors')material=new MeshBasicMaterial({color:0xffffff,vertexColors:true,side:DoubleSide});
      if(mode==='uv')material=new ShaderMaterial({
        side:DoubleSide,
        vertexShader:'varying vec2 modelUV; void main(){ modelUV=uv; gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.0); }',
        fragmentShader:'varying vec2 modelUV; void main(){ vec2 p=fract(modelUV); float checker=mod(floor(modelUV.x*10.0)+floor(modelUV.y*10.0),2.0); gl_FragColor=vec4(p.x,p.y,0.2+0.5*checker,1.0); }',
      });
      debugMaterials.set(mode,material);materials.add(material);
    }
    let hasAttribute=false;
    for(const [node,original] of originalMaterials){
      if(mode==='textured-wireframe' && !wireOverlays.has(node)){
        const offsetMaterial=material=>{
          const copy=material.clone();copy.polygonOffset=true;copy.polygonOffsetFactor=1;copy.polygonOffsetUnits=1;
          materials.add(copy);return copy;
        };
        texturedMaterials.set(node,Array.isArray(original)?original.map(offsetMaterial):offsetMaterial(original));
        const wire=new MeshBasicMaterial({color:0x07111b,wireframe:true,transparent:true,opacity:0.8,depthWrite:false,side:DoubleSide});
        materials.add(wire);
        // Reuse the mesh type so skinning, morph targets and instances line up.
        const overlay=node.clone(false);overlay.material=wire;
        overlay.position.set(0,0,0);overlay.quaternion.identity();overlay.scale.set(1,1,1);overlay.matrix.identity();
        overlay.morphTargetInfluences=node.morphTargetInfluences;overlay.renderOrder=node.renderOrder+1;
        node.add(overlay);wireOverlays.set(node,overlay);
      }
      node.material=mode==='materials'?original:mode==='textured-wireframe'?texturedMaterials.get(node):debugMaterials.get(mode);
      const overlay=wireOverlays.get(node);if(overlay)overlay.visible=mode==='textured-wireframe';
      if(node.geometry.hasAttribute(mode==='uv'?'uv':'color'))hasAttribute=true;
    }
    host.dataset.modelMode=mode;
    if(mode==='uv' && !hasAttribute)return 'This model has no UV coordinates.';
    if(mode==='colors' && !hasAttribute)return 'This model has no vertex colors.';
    return 'Drag to rotate. Scroll or pinch to zoom. Embedded textures are supported; linked files are not loaded.';
  };
  const ready=(async()=>{
    try{
      renderer=new WebGLRenderer({antialias:true});renderer.setPixelRatio(Math.min(devicePixelRatio,interactive?2:1));
      renderer.domElement.className='model-canvas';renderer.domElement.setAttribute('aria-label','3D model preview');host.append(renderer.domElement);
      controls=new OrbitControls(camera,renderer.domElement);controls.enableDamping=true;
      controls.enableRotate=interactive;controls.enablePan=interactive;controls.enableZoom=interactive;
      controls.autoRotate=!interactive && !matchMedia('(prefers-reduced-motion: reduce)').matches;controls.autoRotateSpeed=1;
      scene.add(new HemisphereLight(0xffffff,0x334455,3));
      const light=new DirectionalLight(0xffffff,3);light.position.set(3,5,4);scene.add(light);
      const response=await fetch(url,{credentials:'same-origin',redirect:'error',signal:abort.signal});
      if(!response.ok || !response.headers.get('Content-Type')?.includes('model/gltf-binary'))throw new Error('Model unavailable');
      const limit=25*1024*1024;
      if(Number(response.headers.get('Content-Length'))>limit)throw new Error('Model too large');
      const bytes=await response.arrayBuffer();if(bytes.byteLength>limit)throw new Error('Model too large');
      const gltf=await loadEmbeddedGLB(bytes);model=gltf.scene;
      if(disposed){model.traverse(node=>{
        node.geometry?.dispose();
        for(const material of Array.isArray(node.material)?node.material:[node.material])material?.dispose();
      });return;}
      let vertices=0;model.traverse(node=>{if(node.isMesh){
        vertices+=node.geometry.getAttribute('position')?.count??0;
        originalMaterials.set(node,node.material);
        for(const material of Array.isArray(node.material)?node.material:[node.material])if(material)materials.add(material);
      }});
      if(vertices<3 || vertices>2000000)throw new Error('Unsupported model size');
      const bounds=new Box3().setFromObject(model),size=bounds.getSize(new Vector3());
      if(bounds.isEmpty() || !Number.isFinite(size.length()))throw new Error('Invalid model bounds');
      model.position.sub(bounds.getCenter(new Vector3()));scene.add(model);
      const radius=Math.max(size.length()/2,0.01),distance=radius/Math.sin(camera.fov*Math.PI/360)*1.15;
      initial.set(1.2,0.8,1.6).normalize().multiplyScalar(distance);camera.near=radius/100;camera.far=radius*100;
      controls.minDistance=radius*0.15;controls.maxDistance=radius*20;reset();
      const resize=()=>{const width=Math.max(host.clientWidth,1),height=Math.max(host.clientHeight,1);renderer.setSize(width,height,false);camera.aspect=width/height;camera.updateProjectionMatrix();};
      observer=new ResizeObserver(resize);observer.observe(host);resize();
      status.textContent='';status.hidden=true;host.dataset.modelState='ready';
      setMode(interactive?'materials':'textured-wireframe');
      const render=()=>{if(disposed)return;if(!host.isConnected){dispose();return;}controls.update();renderer.render(scene,camera);frame=requestAnimationFrame(render);};render();
    }catch(error){
      if(disposed)return;
      controls?.dispose();renderer?.dispose();renderer?.forceContextLoss();renderer?.domElement.remove();
      for(const material of materials)material.dispose();
      model?.traverse(node=>node.geometry?.dispose());host.dataset.modelState='error';
      status.textContent='3D preview unavailable. You can still download the model.';
    }
  })();
  return {dispose,reset,ready,setMode};
}

export function bindModelControls(container,preview){
  container.querySelector('[data-model-reset]').onclick=preview.reset;
  const select=container.querySelector('[data-model-mode]');
  select.value='materials';
  container.querySelector('[data-model-info]').textContent='Drag to rotate. Scroll or pinch to zoom. Embedded textures are supported; linked files are not loaded.';
  select.onchange=()=>{container.querySelector('[data-model-info]').textContent=preview.setMode(select.value);};
}

export function openModel(dialog,url) {
  dialog.showModal();
  const preview=createPreview(dialog.querySelector('[data-model-stage]'),url,{interactive:true});
  bindModelControls(dialog,preview);
  dialog.querySelector('[data-model-close]').onclick=()=>dialog.close();
  dialog.addEventListener('close',preview.dispose,{once:true});
  return preview;
}
