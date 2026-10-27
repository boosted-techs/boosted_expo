'use strict';

document.addEventListener('click', function (event) {
  const button = event.target.closest('button[data-password-toggle]');
  if (!button) return;

  const id = button.getAttribute('aria-controls');
  const input = id ? document.getElementById(id) : null;
  if (!(input instanceof HTMLInputElement) || !['password', 'text'].includes(input.type)) return;

  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.textContent = show ? 'Hide' : 'Show';
  button.setAttribute('aria-pressed', String(show));
  button.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + (input.id === 'login-password' ? 'password' : input.id === 'new-password' ? 'new password' : 'confirm new password'));
});
