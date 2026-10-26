/* TLMTI service worker: app shell + previously visited / downloaded pages. It never talks to a server API. */
'use strict';
var VERSION = 'v3-light';
var SHELL = 'tlmti-shell-' + VERSION;
var PAGES = 'tlmti-pages-' + VERSION;
var CONTENT = 'tlmti-content-v1';          // lesson pages downloaded from offline_downloads.php
var SHELL_ASSETS = ['assets/css/app.css', 'assets/js/app-ui.js', 'assets/js/offline-db.js', 'assets/js/sync-client.js',
  'assets/js/tts.js', 'manifest.webmanifest', 'assets/icons/icon.svg'];
var CACHEABLE = ['lesson.php', 'offline_downloads.php'];

function abs(p) { return new URL(p, self.registration.scope).toString(); }

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(SHELL).then(function (c) { return c.addAll(SHELL_ASSETS.map(abs)); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('tlmti-') === 0 && [SHELL, PAGES, CONTENT].indexOf(k) === -1; })
      .map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function offlineResponse() {
  return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>' +
    '<body style="font-family:sans-serif;padding:2rem"><h1>You are offline</h1><p>This page has not been downloaded to this device. ' +
    'Open it once while online, or use Downloads to save lessons.</p></body>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }
  var file = url.pathname.split('/').pop();

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then(function (res) {
      if (res.ok && !res.redirected && CACHEABLE.indexOf(file) !== -1) {
        var copy = res.clone();
        caches.open(PAGES).then(function (c) { c.put(req, copy); });
      }
      return res;
    }).catch(function () {
      return caches.match(req).then(function (hit) { return hit || offlineResponse(); });
    }));
    return;
  }
  if (SHELL_ASSETS.some(function (a) { return abs(a) === url.origin + url.pathname; })) {
    e.respondWith(caches.match(req).then(function (hit) {
      var net = fetch(req).then(function (res) {
        if (res.ok) { var copy = res.clone(); caches.open(SHELL).then(function (c) { c.put(req, copy); }); }
        return res;
      }).catch(function () { return hit; });
      return hit || net;
    }));
  }
});
