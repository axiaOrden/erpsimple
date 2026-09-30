/* Simple ERP service worker — online-first with offline fallback shell. */
const VERSION = 'v1';
const SHELL_CACHE = `shell-${VERSION}`;
const OFFLINE_URL = '/offline';

const PRECACHE = [
    OFFLINE_URL,
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((k) => k !== SHELL_CACHE).map((k) => caches.delete(k)),
        )).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Only handle same-origin requests.
    if (url.origin !== self.location.origin) return;

    // Never cache CSRF-sensitive or dynamic app pages except the offline fallback.
    const isHtml = request.headers.get('accept')?.includes('text/html');

    if (isHtml) {
        // Network-first for pages, offline fallback when unreachable.
        event.respondWith(
            fetch(request)
                .then((response) => {
                    // Don't cache authenticated HTML.
                    if (!response.ok || response.redirected) return response;
                    return response;
                })
                .catch(() => caches.match(OFFLINE_URL)),
        );
        return;
    }

    // Cache-first for static assets with stale-while-revalidate flavor.
    event.respondWith(
        caches.match(request).then((cached) => {
            const fetchPromise = fetch(request).then((response) => {
                if (response.ok && url.pathname.startsWith('/build/')) {
                    const copy = response.clone();
                    caches.open(SHELL_CACHE).then((cache) => cache.put(request, copy));
                }
                return response;
            }).catch(() => cached);
            return cached || fetchPromise;
        }),
    );
});
