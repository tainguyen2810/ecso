// EzSell service worker: makes the app installable on phones. No offline cache yet, every request goes to the network.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
