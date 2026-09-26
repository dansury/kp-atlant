'use strict';
/* Atlant Armour КП — service worker (installable PWA + web push).
   Caches the app shell so the panel installs on a phone and opens offline;
   API responses are always fetched fresh and never cached. Scope = the
   directory this file is served from, so a subdirectory mount works too.

   Code (scripts, styles) comes from the NETWORK, the cache is only its offline
   copy (module 066): a cache-first app.js is a cache nobody checks — half a file
   caught mid-deploy stayed there under the page's own URL, and the panel hung on
   «Загрузка...» on every reload. Bump VERSION whenever these rules change: the
   new worker's activation deletes every older cache. */

const VERSION = 'atlant-kp-shell-v4';
const BASE = new URL('.', self.location).pathname.replace(/\/+$/, '');
const SHELL = [
  BASE + '/',
  BASE + '/manifest.webmanifest',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/badge-96.png',
];

self.addEventListener('install', (e) => {
  self.skipWaiting();
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL).catch(() => {})));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // Never cache API traffic — a manager must never act on a stale request list.
  if (url.pathname.startsWith(BASE + '/api/')) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(BASE + '/')));
    return;
  }
  // Scripts and styles: network first, the cached copy only when offline.
  if (/\.(?:js|css)$/i.test(url.pathname)) {
    e.respondWith(fetch(req).then((res) => keep(req, res))
      .catch(() => caches.match(req).then((hit) => hit || Response.error())));
    return;
  }
  // Pictures and icons carry no logic: cache first.
  e.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((res) => keep(req, res))),
  );
});

// Only a complete answer of our own origin is stored: a 404, a 500 or a partial
// 206 kept here would be served on every visit.
function keep(req, res) {
  if (res && res.status === 200 && res.type === 'basic') {
    const copy = res.clone();
    caches.open(VERSION).then((c) => c.put(req, copy)).catch(() => {});
  }
  return res;
}

// ---- Web push: show the notification, focus or open the panel on click ----
self.addEventListener('push', (e) => {
  let data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { body: e.data && e.data.text ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(data.title || 'Atlant Armour КП', {
    body: data.body || '',
    tag: data.tag || undefined,
    icon: BASE + '/assets/icons/icon-192.png',
    badge: BASE + '/assets/icons/badge-96.png',
    data: { url: data.url || (BASE + '/') },
    renotify: !!data.tag,
  }));
});

// Tapping a notification must land on the thing it is about — the new letter,
// not just «the app». An already-open panel is steered there: navigate() first,
// and a postMessage as the fallback, because a window this worker does not
// control refuses navigate() outright.
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = (e.notification.data && e.notification.data.url) || (BASE + '/');
  e.waitUntil((async () => {
    // An address outside the panel (a GitHub issue) opens in its own window
    if (new URL(target, self.location.origin).origin !== self.location.origin) {
      if (clients.openWindow) return clients.openWindow(target);
      return;
    }
    const all = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    const open = all.filter((c) => new URL(c.url).origin === self.location.origin);
    if (open.length) {
      const c = open.find((w) => w.focused) || open[0];
      try { await c.navigate(target); } catch (err) {
        try { c.postMessage({ type: 'navigate', url: target }); } catch (err2) { /* nothing else to try */ }
      }
      if ('focus' in c) return c.focus();
      return;
    }
    if (clients.openWindow) return clients.openWindow(target);
  })());
});
