"use strict";
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
