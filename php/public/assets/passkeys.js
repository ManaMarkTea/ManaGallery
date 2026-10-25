/* WebAuthn binary values travel as base64url; keys never leave the authenticator. */
(() => {
  if (!window.isSecureContext || !window.PublicKeyCredential) return;
  const decode = text => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), char => char.charCodeAt(0));
  const encode = buffer => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  async function request(path, values) {
    const response = await fetch(path, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'}, body: new URLSearchParams(values)});
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); } catch { throw new Error('Request failed. Reload the page and try again.'); }
    if (!response.ok) throw new Error(result.error || 'Passkey request failed.');
    return result;
  }
  document.querySelectorAll('[data-passkey-unsupported]').forEach(element => { element.hidden = true; });
  document.querySelectorAll('form[data-passkey]').forEach(form => {
    form.hidden = false;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = form.querySelector('button');
      const status = form.querySelector('[data-passkey-status]');
      const values = Object.fromEntries(new FormData(form));
      button.disabled = true;
      status.textContent = 'Waiting for your device…';
      try {
        const action = form.dataset.passkey;
        let result;
        if (action === 'delete') {
          result = await request('/passkeys/delete', values);
        } else {
          const data = await request(`/passkeys/${action}/options`, values);
          const options = data.options.publicKey;
          options.challenge = decode(options.challenge);
          let credential;
          if (action === 'register') {
            options.user.id = decode(options.user.id);
            options.excludeCredentials?.forEach(item => { item.id = decode(item.id); });
            credential = await navigator.credentials.create({publicKey: options});
          } else {
            options.allowCredentials?.forEach(item => { item.id = decode(item.id); });
            credential = await navigator.credentials.get({publicKey: options});
          }
          if (!credential) throw new Error('No passkey was selected.');
          // The password was only needed for the registration options request.
          delete values.current_password;
          Object.assign(values, {id: encode(credential.rawId), clientData: encode(credential.response.clientDataJSON)});
          if (action === 'register') {
            values.attestation = encode(credential.response.attestationObject);
          } else {
            values.authenticatorData = encode(credential.response.authenticatorData);
            values.signature = encode(credential.response.signature);
            values.userHandle = credential.response.userHandle ? encode(credential.response.userHandle) : '';
          }
          result = await request(`/passkeys/${action}/finish`, values);
        }
        window.location.assign(result.redirect);
        if (window.location.pathname === '/account') window.location.reload();
      } catch (error) {
        status.textContent = error.name === 'NotAllowedError' ? 'Passkey request cancelled or timed out. You can try again or use your password.' : error.message;
        button.disabled = false;
      }
    });
  });
})();
