// EzChat Bán hàng service worker: makes the app installable on phones, shows "Báo đơn mới" notifications, and lets
// the counter (Bán tại quầy) open without network: the app page and its files, and the few answers it needs to start
// (who is signed in, the business, its settings, channels and warehouses), always fetched fresh and only served from
// the cache when there is no connection. Sales made offline are kept by the page itself (pages/sales/offline.ts).
const SHELL = 'ecso-shell-v1';
const PRIVATE = 'ecso-private-v1';
const SHELL_KEY = '/__app-shell';
const BOOT_API = ['/api/v1/auth/me', '/api/v1/tenants', '/api/v1/tenant', '/api/v1/tenant/settings', '/api/v1/sales-channels', '/api/v1/warehouses'];
const NOT_APP = /^\/(api|admin|install|sso|sanctum|build|health|storage|icons|images)(\/|$)/;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil((async () => {
    for (const name of await caches.keys()) {
        if (![SHELL, PRIVATE].includes(name)) await caches.delete(name);
    }
    await self.clients.claim();
})()));

// Signed out: drop the cached answers about the account.
self.addEventListener('message', (event) => {
    if (event.data === 'clear-private') event.waitUntil(caches.delete(PRIVATE));
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    if (req.mode === 'navigate' && !NOT_APP.test(url.pathname)) {
        event.respondWith(networkFirst(req, SHELL, SHELL_KEY, (res) => (res.headers.get('content-type') || '').includes('text/html')));
    } else if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(req));
    } else if (BOOT_API.includes(url.pathname) && !url.search) {
        // One business at a time on a device; the key keeps businesses apart anyway.
        const key = `${url.pathname}?t=${encodeURIComponent(req.headers.get('X-Tenant') || '')}`;
        event.respondWith(networkFirst(req, PRIVATE, key, (res) => res.ok, true));
    }
});

/** Fresh from the network; the cached copy only when the network cannot be reached at all. */
async function networkFirst(req, cacheName, key, keep, dropOnAuthError = false) {
    const cache = await caches.open(cacheName);
    try {
        const res = await fetch(req);
        if (res.ok && keep(res)) await cache.put(key, res.clone());
        else if (dropOnAuthError && (res.status === 401 || res.status === 403)) await cache.delete(key);
        return res;
    } catch (e) {
        const hit = await cache.match(key);
        if (hit) return hit;
        throw e;
    }
}

/** Built files never change under the same name: once fetched, served from the cache. */
async function cacheFirst(req) {
    const cache = await caches.open(SHELL);
    const hit = await cache.match(req);
    if (hit) return hit;
    const res = await fetch(req);
    if (res.ok) await cache.put(req, res.clone());
    return res;
}

// Pushes carry no payload: read what to show by this browser's (secret) push address.
self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let msg = null;
        try {
            const sub = await self.registration.pushManager.getSubscription();
            if (sub) {
                const res = await fetch('/api/v1/push/last', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ endpoint: sub.endpoint }),
                });
                msg = res.ok ? (await res.json()).data : null;
            }
        } catch (e) {
            msg = null;
        }
        const title = (msg && msg.title) || 'Có đơn hàng mới';
        await self.registration.showNotification(title, {
            body: (msg && msg.body) || 'Mở EzChat Bán hàng để xem.',
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            tag: 'ezchat-banhang-orders',
            renotify: true,
            data: { url: (msg && msg.url) || '/orders' },
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL((event.notification.data && event.notification.data.url) || '/orders', self.location.origin).href;
    event.waitUntil((async () => {
        const open = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of open) {
            if (new URL(c.url).origin === self.location.origin && 'focus' in c) {
                await c.focus();
                if ('navigate' in c) return c.navigate(url);
                return;
            }
        }
        return self.clients.openWindow(url);
    })());
});
