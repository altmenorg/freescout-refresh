/* Modern UI for FreeScout: service worker of the installable app (PWA).
   Served by the module at /modernui/service-worker with "Service-Worker-Allowed: /", so it covers the whole site
   without copying a file into FreeScout's public folder. It caches nothing: it only receives Web Push notifications,
   even when the app and the browser are closed (the push service wakes it up). */

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'FreeScout', {
        body: data.body || '',
        icon: data.icon || undefined,
        badge: data.badge || undefined,
        tag: data.tag || undefined,       // one notification per ticket: the next one replaces it
        renotify: !!data.tag,
        data: { url: data.url || '/' }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
                return c.focus().then(function (w) { return w.navigate ? w.navigate(url) : w; });
            }
        }
        return self.clients.openWindow(url);
    }));
});
