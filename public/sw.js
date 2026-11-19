// EzChat Bán hàng service worker: makes the app installable on phones and shows "Báo đơn mới" notifications.
// No offline cache yet, every request goes to the network.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

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
