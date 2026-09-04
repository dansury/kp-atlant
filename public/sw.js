'use strict';
/* Atlant Armour КП — service worker (installable PWA + web push).
   Caches the app shell so the panel installs on a phone and opens offline;
   API responses are always fetched fresh and never cached. Scope = the
   directory this file is served from, so a subdirectory mount works too. */

const VERSION = 'atlant-kp-shell-v1';
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
  // Static assets: serve from cache, refresh it in the background.
  e.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(VERSION).then((c) => c.put(req, copy)).catch(() => {});
      return res;
    }).catch(() => hit)),
  );
});

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

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = (e.notification.data && e.notification.data.url) || (BASE + '/');
  e.waitUntil((async () => {
    const all = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if ('focus' in c) { try { await c.navigate(target); } catch (err) { /* */ } return c.focus(); }
    }
    if (clients.openWindow) return clients.openWindow(target);
  })());
});
