// แคชเฉพาะไฟล์ static เท่านั้น ข้อมูลการเงินต้องมาจาก API เสมอ
const CACHE = 'et-static-v6'; // เปลี่ยนเลขทุกครั้งที่ปล่อยเวอร์ชันใหม่ เพื่อล้างแคชเก่าในเครื่องผู้ใช้
const SCOPE = new URL(self.registration.scope);
const ROOT = SCOPE.pathname; // รองรับการวางแอปในโฟลเดอร์ย่อย
const SHELL = ['./', 'manifest.webmanifest', 'favicon.svg', 'app-icons/icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith(`${ROOT}api/`)
    || url.pathname.startsWith(`${ROOT}admin/`)) return; // หน้าผู้ดูแลไม่แคช และไม่ทับเปลือกแอป

  // หน้าเว็บ: เอาจากเครือข่ายก่อน ถ้าออฟไลน์ค่อยใช้เปลือกแอปที่แคชไว้
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(ROOT, copy));
        }
        return res;
      }).catch(() => caches.match(ROOT)),
    );
    return;
  }

  // ไฟล์ assets มี hash ในชื่อ ใช้แคชก่อนได้เลย
  const cacheable = ['assets/', 'app-icons/', 'tesseract/'].some((d) => url.pathname.startsWith(ROOT + d));
  event.respondWith(
    caches.match(request).then((hit) => hit || fetch(request).then((res) => {
      if (res.ok && cacheable) {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(request, copy));
      }
      return res;
    })),
  );
});
