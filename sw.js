/* 論理的読解タイムアタック 試作版 service worker：初回表示後はオフラインでも起動できるようにする */
const CACHE = 'lrta-proto-20260930-2236'; /* 版ごとに名前を変える：新しい版を置くと古いキャッシュが自動で消える */
const ASSETS = ['./', './index.html', './manifest.webmanifest', './icon-192.png', './icon-512.png', './icon-maskable-512.png', './apple-touch-icon.png'];
self.addEventListener('install', (e) => { e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting())); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
function store(req, res) { if (res && res.ok && new URL(req.url).origin === location.origin) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); } return res; }
self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return;
  const path = new URL(e.request.url).pathname;
  if (/\/(stock|data)\//.test(path)) return; /* 新作ストック・点呼・問題データは常に最新をネットワークから（アプリ側が端末に保存する） */
  const isPage = e.request.mode === 'navigate' || /\/(index\.html)?$/.test(path);
  if (isPage) { /* アプリ本体はネットワーク優先：新しい版を置いたらすぐ切り替わる。つながらないときだけ保存分で起動 */
    e.respondWith(fetch(e.request).then((res) => store(e.request, res)).catch(() => caches.match(e.request, { ignoreSearch: true }).then((hit) => hit || caches.match('./index.html'))));
    return;
  }
  e.respondWith(caches.match(e.request, { ignoreSearch: true }).then((hit) => hit || fetch(e.request).then((res) => store(e.request, res)).catch(() => caches.match('./index.html'))));
});
