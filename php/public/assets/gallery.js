"use strict";
let modelModule,hoverModel,hoverCard,modelRequest=0;
function modelTools(){
  const version=new URL(document.querySelector('script[src*="/assets/gallery.js"]').src).search;
  return modelModule??=import('./model-viewer.js'+version);
}
function stopModelHover(){modelRequest++;hoverModel?.dispose();hoverModel=null;hoverCard=null;}
function startModelHover(card){
  if(card===hoverCard)return;
  stopModelHover();hoverCard=card;const request=modelRequest;
  modelTools().then(module=>{if(request===modelRequest && card.isConnected)hoverModel=module.createPreview(card.querySelector('.art-image-wrap'),card.dataset.modelPreview);}).catch(()=>{});
}
for(const inline of document.querySelectorAll('[data-model-inline]')){
  const fallback=inline.parentElement.querySelector('[data-image-open]');
  inline.hidden=false;
  modelTools().then(async module=>{
    const preview=module.createPreview(inline.querySelector('[data-model-stage]'),inline.dataset.modelUrl,{interactive:true});
    module.bindModelControls(inline,preview);
    await preview.ready;
    if(inline.querySelector('[data-model-state="ready"]'))fallback.hidden=true;
    else inline.hidden=true;
  }).catch(()=>{inline.hidden=true;});
}
document.addEventListener('pointerover',event=>{const card=event.target.closest('[data-model-preview]');if(card)startModelHover(card);});
document.addEventListener('pointerout',event=>{if(hoverCard && !hoverCard.contains(event.relatedTarget))stopModelHover();});
document.addEventListener('focusin',event=>{const card=event.target.closest('[data-model-preview]');if(card)startModelHover(card);});
document.addEventListener('focusout',event=>{if(hoverCard && !hoverCard.contains(event.relatedTarget))stopModelHover();});
document.addEventListener('click',event=>{
  const button=event.target.closest('[data-model-open]');if(!button)return;
  stopModelHover();button.disabled=true;
  modelTools().then(module=>module.openModel(document.querySelector('#model-viewer'),button.dataset.modelUrl)).catch(()=>{button.textContent='3D preview unavailable';}).finally(()=>{button.disabled=false;});
});
for (const sortForm of document.querySelectorAll('form[data-comment-sort]')) {
  sortForm.querySelector('select').addEventListener('change',()=>sortForm.requestSubmit());
  sortForm.querySelector('button').hidden=true;
}
const form = document.querySelector('#gallery-filters');
if (form) {
  let timer, controller, requestId = 0, composing = false;
  const status = document.querySelector('#filter-status');
  const results = document.querySelector('#gallery-results');
  document.querySelector('#apply-filters').hidden = true;
  async function apply() {
    clearTimeout(timer);
    controller?.abort();
    controller = new AbortController();
    const id = ++requestId;
    const url = new URL('/', location.origin);
    for (const [key, value] of new FormData(form)) if (String(value).trim()) url.searchParams.set(key, String(value).trim());
    url.hash = 'gallery';
    results.setAttribute('aria-busy', 'true');
    status.textContent = 'Updating gallery…';
    try {
      const response = await fetch(url, { signal: controller.signal });
      if (!response.ok) throw new Error('Gallery request failed');
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      const next = doc.querySelector('#gallery-results');
      if (!next) throw new Error('Missing results');
      if (id !== requestId) return;
      results.replaceChildren(...next.childNodes);
      for (const selector of ['.software-nav', '.sidebar nav[aria-label="Categories"]']) {
        const current = document.querySelector(selector), updated = doc.querySelector(selector);
        if (current && updated) current.replaceWith(updated);
      }
      history.replaceState(null, '', url);
      status.textContent = results.querySelector('.result-line > span').textContent;
    } catch (error) {
      if (error.name !== 'AbortError' && id === requestId) {
        status.textContent = 'Could not update the gallery. Try again or use Apply filters.';
        document.querySelector('#apply-filters').hidden = false;
      }
    } finally { if (id === requestId) results.removeAttribute('aria-busy'); }
  }
  form.addEventListener('submit', event => { event.preventDefault(); apply(); });
  form.addEventListener('change', event => { if (event.target.tagName === 'SELECT') apply(); });
  form.addEventListener('input', () => { clearTimeout(timer); if (!composing) timer = setTimeout(apply, 350); });
  form.addEventListener('compositionstart', () => { composing = true; clearTimeout(timer); });
  form.addEventListener('compositionend', () => { composing = false; clearTimeout(timer); timer = setTimeout(apply, 350); });
}
const viewer = document.querySelector('#image-viewer');
const full = document.querySelector('#view-full');
if (viewer && full && typeof viewer.showModal === 'function') {
  full.addEventListener('click', event => { event.preventDefault(); viewer.showModal(); });
  document.querySelector('#close-viewer').addEventListener('click', () => viewer.close());
  for(const imageLink of document.querySelectorAll('[data-image-open]')){
    imageLink.addEventListener('click',event=>{event.preventDefault();viewer.showModal();});
  }
}

for (const time of document.querySelectorAll('time[data-local-time]')) {
  const date=new Date(time.dateTime);
  if (!Number.isNaN(date.getTime())) {
    time.textContent=new Intl.DateTimeFormat(undefined,{dateStyle:'medium',timeStyle:'short'}).format(date)+' (local)';
    const utc=document.createElement('small'); utc.className='time-utc'; utc.textContent=time.title; time.after(utc);
  }
}

for (const form of document.querySelectorAll('form[data-confirm]')) {
  form.addEventListener('submit',event=>{ if (!confirm(form.dataset.confirm)) event.preventDefault(); });
}

// Save category edits without navigation, keeping other editors and scroll intact.
document.addEventListener('submit', async event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.matches('[data-category-save]') || event.defaultPrevented) return;
  event.preventDefault();
  if (form.dataset.saving) return;
  const card = form.closest('[data-category-id]');
  const body = new FormData(form);
  const controls = [...form.elements].filter(control => !control.disabled);
  let status = form.querySelector('.category-save-status');
  if (!status) {
    status = document.createElement('p'); status.className = 'category-save-status';
    status.setAttribute('role', 'status'); form.append(status);
  }
  status.removeAttribute('data-error'); status.textContent = 'Saving...';
  form.dataset.saving = 'true'; form.setAttribute('aria-busy', 'true');
  controls.forEach(control => { control.disabled = true; });
  try {
    const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin' });
    const documentResult = new DOMParser().parseFromString(await response.text(), 'text/html');
    if (!response.ok) throw new Error(documentResult.querySelector('.form-error')?.textContent.trim() || 'Could not save. Please try again.');
    if (new URL(response.url).pathname !== '/admin/categories') throw new Error('Your session may have expired. Sign in again before saving.');
    const updated = [...documentResult.querySelectorAll('[data-category-id]')].find(node => node.dataset.categoryId === card.dataset.categoryId);
    if (!updated) throw new Error('Could not confirm the save. Reload before trying again.');
    const scrollX = window.scrollX, scrollY = window.scrollY;
    card.innerHTML = updated.innerHTML;
    const savedForm = card.querySelector('[data-category-save]');
    const savedStatus = document.createElement('p'); savedStatus.className = 'category-save-status';
    savedStatus.setAttribute('role', 'status'); savedStatus.textContent = 'Category saved.'; savedForm.append(savedStatus);
    savedForm.querySelector('button').focus({ preventScroll: true });
    window.scrollTo(scrollX, scrollY);
  } catch (error) {
    status.setAttribute('data-error', '');
    status.textContent = error instanceof TypeError ? 'Connection interrupted. Check your connection before retrying.' : error.message;
  } finally {
    controls.forEach(control => { control.disabled = false; });
    delete form.dataset.saving; form.removeAttribute('aria-busy');
  }
});
