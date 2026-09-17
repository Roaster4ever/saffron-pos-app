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

// Loading overlay
function showLoading(msg) {
  var existing = document.getElementById('appLoading');
  if (existing) existing.remove();
  var overlay = document.createElement('div');
  overlay.id = 'appLoading';
  overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999';
  overlay.innerHTML = '<div style="background:var(--bg2,#1a1a2e);border:1px solid var(--border2,#333);border-radius:8px;padding:20px 30px;text-align:center;color:var(--text,#e0e0e0);font-size:14px"><div style="margin-bottom:8px;font-size:24px;animation:pulse 1s infinite">&#9670;</div>' + (msg || 'Loading...') + '</div>';
  document.body.appendChild(overlay);
}
function hideLoading() {
  var el = document.getElementById('appLoading');
  if (el) el.remove();
}
