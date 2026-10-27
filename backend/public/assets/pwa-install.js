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
  const later = () => {
    until = Date.now() + duration;
    try { localStorage.setItem(key, String(until)); } catch (_) {
      try { sessionStorage.setItem(key, String(until)); } catch (_) {}
    }
    render();
  };
  function guidance() {
    const ua = navigator.userAgent;
    const ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (!window.isSecureContext) return 'Installation needs a secure HTTPS connection. You can continue signing in on the web.';
    if (ios) return 'Open this page in your browser, choose Share, then Add to Home Screen. If that option is missing, open the page in Safari. Sign in normally after opening the app.';
    if (/Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua)) return 'In supported Safari versions, choose File, then Add to Dock. If unavailable, use Chrome or Edge, or continue in this browser.';
    if (/; wv\)|FBAN|FBAV|Instagram/.test(ua)) return 'Open this page in an external browser such as Chrome, Edge or Safari, then use its Install app or Add to Home Screen option.';
    return 'Look for Install app or Add to Home Screen in your browser menu or address bar. If unavailable, use a supported Chrome, Edge or Safari browser. You can always continue on the web.';
  }
  function render() {
    const installed = standalone();
    document.querySelectorAll('[data-install-banner]').forEach(el => { el.hidden = installed || until > Date.now(); });
    document.querySelectorAll('[data-install-help]').forEach(el => { el.hidden = installed; });
    document.querySelectorAll('[data-install-guidance]').forEach(el => { el.textContent = guidance(); });
    document.querySelectorAll('[data-install-button]').forEach(el => { el.textContent = deferredPrompt ? 'Install' : 'How to install'; el.disabled = false; });
  }
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault(); deferredPrompt = event; ready(render);
  });
  window.addEventListener('appinstalled', () => { deferredPrompt = null; later(); ready(render); });
  ready(() => {
    render();
    document.querySelectorAll('[data-install-later]').forEach(el => el.addEventListener('click', later));
    document.querySelectorAll('[data-install-button]').forEach(el => el.addEventListener('click', async () => {
      if (!deferredPrompt) {
        const help = document.querySelector('[data-install-help]');
        if (help) { help.open = true; help.querySelector('summary')?.focus(); }
        return;
      }
      const prompt = deferredPrompt; deferredPrompt = null; el.disabled = true;
      try {
        await prompt.prompt();
        const choice = await prompt.userChoice;
        if (choice.outcome === 'dismissed') later();
        else document.querySelectorAll('[data-install-message]').forEach(message => { message.textContent = 'Installation requested. Complete the browser steps, then open Boosted One.'; });
      } catch (_) {
        const help = document.querySelector('[data-install-help]'); if (help) help.open = true;
      } finally { render(); }
    }));
    window.matchMedia('(display-mode: standalone)').addEventListener?.('change', render);
    if ('serviceWorker' in navigator && window.isSecureContext) {
      navigator.serviceWorker.register('/service-worker.js', {scope: '/'}).catch(() => {});
    }
  });
})();
