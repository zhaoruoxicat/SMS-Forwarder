/* sw.js */
const CACHE_VERSION = 'v1.0.0';
const STATIC_CACHE = `static-${CACHE_VERSION}`;
const STATIC_ASSETS = [
  '/',                       // 可选：仅用于快速激活，实际不缓存HTML
  '/manifest.webmanifest',
  '/offline.html',
  '/style/tabler.min.css',
  '/style/tabler.min.js',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/maskable-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.map(k => (k !== STATIC_CACHE ? caches.delete(k) : null)))
    )
  );
  self.clients.claim();
});

// 仅缓存 GET 的静态资源；页面请求（text/html）不缓存，防止存储私密数据
self.addEventListener('fetch', (event) => {
  const req = event.request;

  // 只处理 GET
  if (req.method !== 'GET') return;

  const accept = req.headers.get('accept') || '';
  const url = new URL(req.url);

  // 如果是 HTML 页面，网络优先；失败则离线页
  if (accept.includes('text/html')) {
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  // 对图标、CSS、JS、manifest → 使用 Stale-While-Revalidate
  const isStatic =
    url.pathname.startsWith('/style/') ||
    url.pathname.startsWith('/icons/') ||
    url.pathname === '/manifest.webmanifest';

  if (isStatic) {
    event.respondWith(
      caches.open(STATIC_CACHE).then(async (cache) => {
        const cached = await cache.match(req);
        const networkPromise = fetch(req).then((res) => {
          if (res && res.status === 200) cache.put(req, res.clone());
          return res;
        }).catch(() => null);
        return cached || networkPromise || caches.match('/offline.html');
      })
    );
  }
});
