/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/*
 * The service worker that makes Augias installable as an app.
 *
 * It caches nothing but its own offline page. The pages hold a company's
 * invoices and clients, and a phone is shared, lost or switched between
 * companies: a copy kept on it would outlive the session that was allowed to
 * see it. The built assets are not versioned by name either, so a cached copy
 * would be served stale after an update. Without the network, a page that
 * cannot load shows the offline page instead of the browser's error.
 */
const CACHE = 'augias-offline-v1';
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE, '/icons/icon-192.png'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));
});
