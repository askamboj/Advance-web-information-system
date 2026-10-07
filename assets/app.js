'use strict';
document.querySelector('.menu-toggle')?.addEventListener('click', function () {
  const open = this.getAttribute('aria-expanded') !== 'true';
  this.setAttribute('aria-expanded', String(open));
  document.querySelector('.site-header').classList.toggle('menu-open', open);
});
document.querySelectorAll('[data-password]').forEach(button => button.addEventListener('click', () => {
  const input = document.getElementById(button.dataset.password);
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.textContent = show ? 'Hide' : 'Show';
  button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}));
document.querySelectorAll('[data-dismiss]').forEach(button => button.addEventListener('click', () => button.closest('.notice').remove()));
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));
document.querySelectorAll('form[method="post"]').forEach(form => form.addEventListener('submit', event => {
  if (event.defaultPrevented) return;
  // Do not disable the named submitter: its decision value must reach PHP.
  if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
  form.dataset.submitting = 'true';
  setTimeout(() => { delete form.dataset.submitting; }, 8000);
}));
window.addEventListener('pageshow', () => document.querySelectorAll('form').forEach(form => { delete form.dataset.submitting; }));
