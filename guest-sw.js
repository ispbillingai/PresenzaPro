/*
 * Service worker for the guest table page (t.php): only there so the page can
 * show a system notification when a dish is ready (Android Chrome needs a
 * service worker for that). No caching, no push. Tapping the notification
 * brings the guest page back.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || '/';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const c of list) {
            if (c.url.indexOf('/t.php') !== -1 && 'focus' in c) return c.focus();
        }
        return self.clients.openWindow ? self.clients.openWindow(url) : undefined;
    }));
});
