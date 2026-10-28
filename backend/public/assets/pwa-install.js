(() => {
  'use strict';
  let deferredPrompt = null;
  const duration = 7 * 24 * 60 * 60 * 1000;
  const key = 'boosted-one-install-later-until';
  let until = 0;
  try { until = Number(localStorage.getItem(key)) || 0; } catch (_) {
    try { until = Number(sessionStorage.getItem(key)) || 0; } catch (_) {}
  }
  const standalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const ready = (fn) => document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn, {once: true}) : fn();
  const dialog = () => document.querySelector('[data-install-dialog]');
  const later = () => {
    until = Date.now() + duration;
    try { localStorage.setItem(key, String(until)); } catch (_) {
      try { sessionStorage.setItem(key, String(until)); } catch (_) {}
    }
    dialog()?.close();
    render();
  };
  function guidance() {
    const ua = navigator.userAgent;
    const ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (!window.isSecureContext) return 'Open the secure version of this site to install the app.';
    if (ios) return 'In Safari, tap Share, then Add to Home Screen.';
    if (/Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua)) return 'In Safari, choose File, then Add to Dock.';
    if (/; wv\)|FBAN|FBAV|Instagram/.test(ua)) return 'Open this site in Safari, Chrome or Edge, then choose Install app or Add to Home Screen from the browser menu.';
    return deferredPrompt ? 'Choose Install app below, then confirm in your browser.' : 'Choose Install app or Add to Home Screen from your browser menu.';
  }
  function render() {
    const installed = standalone();
    document.querySelectorAll('[data-install-banner]').forEach(el => { el.hidden = installed || until > Date.now(); });
    document.querySelectorAll('[data-install-guidance]').forEach(el => { el.textContent = guidance(); });
    document.querySelectorAll('[data-install-button]').forEach(el => { el.hidden = !deferredPrompt || installed; el.disabled = false; });
    if (installed && dialog()?.open) dialog().close();
  }
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault(); deferredPrompt = event; ready(render);
  });
  window.addEventListener('appinstalled', () => { deferredPrompt = null; later(); ready(render); });
  ready(() => {
    render();
    document.querySelectorAll('[data-install-open]').forEach(el => el.addEventListener('click', () => { render(); dialog()?.showModal(); }));
    document.querySelectorAll('[data-install-close]').forEach(el => el.addEventListener('click', () => dialog()?.close()));
    document.querySelectorAll('[data-install-later]').forEach(el => el.addEventListener('click', later));
    dialog()?.addEventListener('click', event => {
      if (event.target !== dialog()) return;
      const bounds = dialog().getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog().close();
    });
    document.querySelectorAll('[data-install-button]').forEach(el => el.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      const prompt = deferredPrompt; deferredPrompt = null; el.disabled = true;
      try {
        await prompt.prompt();
        const choice = await prompt.userChoice;
        if (choice.outcome === 'dismissed') later();
        else dialog()?.close();
      } catch (_) {
        const guidanceElement = document.querySelector('[data-install-guidance]');
        if (guidanceElement) guidanceElement.textContent = guidance();
      } finally { render(); }
    }));
    window.matchMedia('(display-mode: standalone)').addEventListener?.('change', render);
    if ('serviceWorker' in navigator && window.isSecureContext) {
      navigator.serviceWorker.register('/service-worker.js', {scope: '/'}).catch(() => {});
    }
  });
})();
