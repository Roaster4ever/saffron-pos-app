// POS Suite main.js

// Auto-dismiss alerts
document.querySelectorAll('.alert').forEach(el => {
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .5s'; setTimeout(()=>el.remove(), 500); }, 4000);
});

// Modal helpers
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open');
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// Confirm delete helper
function confirmDelete(msg) {
  return confirm(msg || 'Are you sure you want to delete this?');
}
