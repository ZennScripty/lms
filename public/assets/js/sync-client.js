/* Offline event queue + sync. Events are POSTed to the *same feature page* that owns them (e.g. lesson.php?action=sync_push). */
(function (w) {
  'use strict';
  var ctx = { userId: '', csrf: '' }, flushing = false, listeners = [];
  var MAX_ATTEMPTS = 5, TIMEOUT_MS = 15000, lastError = '';

  function uuid() {
    if (w.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    var b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  function counts() {
    return TlmtiDB.allEvents().then(function (all) {
      var c = { pending: 0, synced: 0, failed: 0, rejected: 0 };
      all.forEach(function (e) { if (String(e.user_id) === String(ctx.userId) && c[e.status] !== undefined) { c[e.status]++; } });
      return TlmtiDB.getMeta('last_sync').then(function (t) { c.lastSync = t; c.error = lastError; return c; });
    });
  }
  function notify() { return counts().then(function (c) { listeners.forEach(function (f) { f(c); }); return c; }).catch(function () {}); }

  function init(o) {
    ctx.userId = o.userId || '';
    var p = Promise.resolve();
    if (navigator.onLine && o.csrf) { ctx.csrf = o.csrf; p = TlmtiDB.setMeta('csrf', o.csrf); }
    else { p = TlmtiDB.getMeta('csrf').then(function (t) { ctx.csrf = t || o.csrf || ''; }); }
    return p.then(notify);
  }

  function submit(ev) {
    var e = {
      event_uuid: uuid(), user_id: ctx.userId, page: ev.page, type: ev.type, entity_type: ev.entity_type || '',
      entity_id: ev.entity_id || 0, payload: ev.payload || {}, client_ts: new Date().toISOString(), schema_version: 1,
      queued_offline: !navigator.onLine, status: 'pending', attempts: 0, last_error: '', created_at: Date.now()
    };
    return TlmtiDB.putEvent(e).then(notify).then(function () {
      return navigator.onLine ? flush().then(function () { return TlmtiDB.getEvent(e.event_uuid); }) : e;
    });
  }

  function post(page, events) {
    var fd = new FormData();
    fd.append('csrf_token', ctx.csrf);
    fd.append('events', JSON.stringify(events.map(function (e) {
      return { event_uuid: e.event_uuid, type: e.type, entity_type: e.entity_type, entity_id: e.entity_id, payload: e.payload,
               client_ts: e.client_ts, schema_version: e.schema_version, queued_offline: e.queued_offline };
    })));
    var ctl = ('AbortController' in w) ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, TIMEOUT_MS) : null;
    var url = new URL(page, w.location.href); url.search = '?action=sync_push';
    return fetch(url.toString(), { method: 'POST', body: fd, credentials: 'same-origin', signal: ctl ? ctl.signal : undefined,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { if (timer) { clearTimeout(timer); } return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, json: j }; }); });
  }

  function markAll(events, patch) {
    return Promise.all(events.map(function (e) { Object.assign(e, patch(e)); return TlmtiDB.putEvent(e); }));
  }

  function flush() {
    if (flushing || !navigator.onLine || !ctx.csrf) { return Promise.resolve(); }
    flushing = true; lastError = '';
    return TlmtiDB.allEvents().then(function (all) {
      var pend = all.filter(function (e) { return String(e.user_id) === String(ctx.userId) && e.status === 'pending'; })
        .sort(function (a, b) { return a.client_ts < b.client_ts ? -1 : 1; });
      var groups = {};
      pend.forEach(function (e) { (groups[e.page] = groups[e.page] || []).push(e); });
      var done = [];
      return Object.keys(groups).reduce(function (chain, page) {
        return chain.then(function (stop) {
          if (stop) { return true; }
          var evs = groups[page];
          return post(page, evs).then(function (res) {
            if (res.status === 401) { lastError = 'Session expired. Log in again to sync.'; return true; }
            if (res.status === 419) { lastError = 'Security token expired. Reload an online page, then sync again.'; return true; }
            if (res.status === 403) { lastError = 'You are not allowed to sync these events.'; return markAll(evs, function () { return { status: 'rejected', last_error: 'forbidden' }; }).then(function () { return false; }); }
            if (res.status >= 400 || !res.json || !res.json.results) { lastError = 'Server error. Will retry.'; return markAll(evs, function (e) { return bump(e, 'server ' + res.status); }).then(function () { return false; }); }
            var map = {}; res.json.results.forEach(function (r) { map[r.event_uuid] = r; });
            return markAll(evs, function (e) {
              var r = map[e.event_uuid];
              if (!r || r.status === 'error') { return bump(e, r ? r.message : 'no result'); }
              done.push(r);
              if (r.status === 'accepted' || r.status === 'duplicate') { return { status: 'synced', last_error: '' }; }
              return { status: 'rejected', last_error: r.message || 'rejected' };
            }).then(function () { return false; });
          }).catch(function (err) {
            lastError = err && err.name === 'AbortError' ? 'Server timeout. Will retry.' : 'Network error. Will retry.';
            return markAll(evs, function (e) { return bump(e, lastError); }).then(function () { return true; });
          });
        });
      }, Promise.resolve(false)).then(function () {
        if (done.length) { TlmtiDB.setMeta('last_sync', new Date().toISOString()); }
        w.dispatchEvent(new CustomEvent('tlmti:synced', { detail: { results: done } }));
      });
    }).catch(function (e) { lastError = String(e && e.message || e); })
      .then(function () { flushing = false; return notify(); });
  }

  function bump(e, msg) {
    var n = (e.attempts || 0) + 1;
    return { attempts: n, last_error: msg, status: n >= MAX_ATTEMPTS ? 'failed' : 'pending' };
  }

  function retryFailed() {
    return TlmtiDB.allEvents().then(function (all) {
      return markAll(all.filter(function (e) { return e.status === 'failed' && String(e.user_id) === String(ctx.userId); }),
        function () { return { status: 'pending', attempts: 0 }; });
    }).then(flush);
  }
  function clearSynced() {
    return TlmtiDB.allEvents().then(function (all) {
      return Promise.all(all.filter(function (e) { return e.status === 'synced' && String(e.user_id) === String(ctx.userId); })
        .map(function (e) { return TlmtiDB.deleteEvent(e.event_uuid); }));
    }).then(notify);
  }

  w.TlmtiSync = {
    init: init, submit: submit, flush: flush, counts: counts, retryFailed: retryFailed, clearSynced: clearSynced,
    onChange: function (f) { listeners.push(f); },
    events: function () { return TlmtiDB.allEvents().then(function (a) { return a.filter(function (e) { return String(e.user_id) === String(ctx.userId); }); }); }
  };
}(window));
