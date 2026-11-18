// ตั้งโทนสีก่อนหน้าเว็บวาดครั้งแรก เพื่อไม่ให้กะพริบตอนโหลด
(function () {
  var t = null;
  try { t = localStorage.getItem('et-theme'); } catch (e) { /* โหมดส่วนตัว */ }
  if (t !== 'dark' && t !== 'light') t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.setAttribute('data-bs-theme', t);
})();
