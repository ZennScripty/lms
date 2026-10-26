/* UI wiring only. All authorization, scoring and unlocking is decided by PHP on the server. */
(function (w, d) {
  'use strict';
  var body = d.body;
  var menu = d.querySelector('[data-menu-toggle]');
  var closeMenu = d.querySelector('[data-menu-close]');
  function collapseMenu() { body.classList.remove('menu-open'); if (menu) { menu.setAttribute('aria-expanded', 'false'); menu.focus(); } }
  if (closeMenu) { closeMenu.addEventListener('click', collapseMenu); }
  d.addEventListener('click', function(e) { if (menu && body.classList.contains('menu-open') && !e.target.closest('.sidebar') && !e.target.closest('[data-menu-toggle]')) { collapseMenu(); } });
  if (menu) { menu.addEventListener('click', function () { var open = body.classList.toggle('menu-open'); menu.setAttribute('aria-expanded', String(open)); }); }
  d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu) { body.classList.remove('menu-open'); menu.setAttribute('aria-expanded','false'); } });
  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function text(el, t) { if (el) { el.textContent = t; } }

  // ---- online / offline banner, service worker ----
  var banner = $('#net-banner');
  function net() { if (banner) { banner.hidden = navigator.onLine; } }
  w.addEventListener('online', function () { net(); if (w.TlmtiSync) { TlmtiSync.flush(); } });
  w.addEventListener('offline', net); net();
  if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function () {}); }

  // ---- generic helpers ----
  d.addEventListener('submit', function (e) {
    var f = e.target, c = f.querySelector('[data-confirm]');
    if (c && e.submitter === c && !w.confirm(c.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
  $$('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });

  // logout: wipe cached pages so another user on this device cannot open them offline
  $$('form[data-logout]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!w.caches) { return; }
      e.preventDefault();
      caches.keys().then(function (ks) { return Promise.all(ks.filter(function (k) { return k.indexOf('tlmti-pages') === 0 || k.indexOf('tlmti-content') === 0; }).map(function (k) { return caches.delete(k); })); })
        .catch(function () {}).then(function () { f.submit(); });
    });
  });

  // ---- sync init + badge ----
  function fmt(c) { return c.pending + (c.failed ? c.failed : 0); }
  if (w.TlmtiSync) {
    TlmtiSync.onChange(function (c) {
      $$('[data-sync-badge]').forEach(function (b) { b.textContent = String(fmt(c)); b.className = 'badge' + (fmt(c) ? ' badge-warn' : ''); });
      ['pending', 'synced', 'failed', 'rejected'].forEach(function (k) { text($('[data-count="' + k + '"]'), String(c[k])); });
      text($('[data-last-sync]'), c.lastSync ? new Date(c.lastSync).toLocaleString() : 'never');
      text($('[data-sync-message]'), c.error || '');
      decoratePending(); renderList();
    });
    TlmtiSync.init({ userId: body.getAttribute('data-user-id'), csrf: body.getAttribute('data-csrf') }).then(function () { return TlmtiSync.flush(); });
    d.addEventListener('visibilitychange', function () { if (!d.hidden) { TlmtiSync.flush(); } });
  }

  function renderList() {
    var tb = $('[data-sync-list]'); if (!tb || !w.TlmtiSync) { return; }
    TlmtiSync.events().then(function (evs) {
      tb.textContent = '';
      evs.sort(function (a, b) { return b.created_at - a.created_at; }).slice(0, 100).forEach(function (e) {
        var tr = d.createElement('tr');
        [e.type, new Date(e.created_at).toLocaleString(), e.status, e.last_error || ''].forEach(function (v) { var td = d.createElement('td'); td.textContent = v; tr.appendChild(td); });
        tb.appendChild(tr);
      });
      if (!evs.length) { var tr = d.createElement('tr'), td = d.createElement('td'); td.colSpan = 4; td.className = 'muted'; td.textContent = 'Nothing queued on this device.'; tr.appendChild(td); tb.appendChild(tr); }
    });
  }
  $$('[data-sync-now]').forEach(function (b) { b.addEventListener('click', function () { TlmtiSync.flush(); }); });
  $$('[data-sync-retry]').forEach(function (b) { b.addEventListener('click', function () { TlmtiSync.retryFailed(); }); });
  $$('[data-sync-clear]').forEach(function (b) { b.addEventListener('click', function () { TlmtiSync.clearSynced(); }); });

  // storage info
  $$('[data-storage-info]').forEach(function (el) {
    if (!w.TlmtiDB) { return; }
    TlmtiDB.storageEstimate().then(function (s) {
      if (!s) { el.textContent = 'Storage information is not available in this browser.'; return; }
      el.textContent = 'Device storage used: ' + (s.usage / 1048576).toFixed(1) + ' MB of ' + (s.quota / 1048576).toFixed(0) + ' MB. Browsers may evict stored data under storage pressure; unsynced items are kept until synced.';
    });
  });

  // ---- lesson page: study timer + completion event ----
  var lesson = $('[data-lesson]');
  if (lesson && lesson.getAttribute('data-student') === '1' && lesson.getAttribute('data-completed') !== '1') {
    var min = parseInt(lesson.getAttribute('data-min-seconds'), 10) || 0, secs = 0;
    var btn = $('[data-complete-lesson]'), timerEl = $('[data-lesson-timer]'), form = $('[data-complete-form]'), status = $('[data-lesson-status]');
    setInterval(function () {
      if (d.hidden) { return; }
      secs++; text(timerEl, String(secs));
      if (secs >= min && btn) { btn.disabled = false; }
    }, 1000);
    if (form) {
      form.addEventListener('submit', function (e) {
        if (!w.TlmtiSync) { $('[data-seconds-field]').value = String(secs); return; }   // plain POST fallback
        e.preventDefault(); btn.disabled = true;
        TlmtiSync.submit({ page: lesson.getAttribute('data-page'), type: 'lesson_complete', entity_type: 'lesson',
          entity_id: parseInt(lesson.getAttribute('data-lesson-id'), 10), payload: { lesson_id: parseInt(lesson.getAttribute('data-lesson-id'), 10), seconds_spent: secs } })
          .then(function (ev) {
            if (ev.status === 'synced') { w.location.reload(); }
            else if (ev.status === 'rejected') { text(status, 'Rejected by server: ' + ev.last_error); btn.disabled = false; }
            else { text(status, navigator.onLine ? 'Saved on device; will sync shortly.' : 'Saved offline. It will sync when you are back online; the next lesson unlocks after the server accepts it.'); }
          });
      });
    }
  }

  // ---- test page: draft autosave + offline submit ----
  var tf = $('form[data-offline-test]');
  if (tf && w.TlmtiDB) {
    var key = 'test-draft-' + body.getAttribute('data-user-id') + '-' + tf.getAttribute('data-test-id');
    function answers() {
      var a = {}; $$('input[type=radio]:checked', tf).forEach(function (r) { var m = r.name.match(/^answers\[(\d+)\]$/); if (m) { a[m[1]] = parseInt(r.value, 10); } }); return a;
    }
    TlmtiDB.getDraft(key).then(function (dr) {
      if (!dr) { return; }
      Object.keys(dr.data).forEach(function (q) { var r = $('input[name="answers[' + q + ']"][value="' + dr.data[q] + '"]', tf); if (r) { r.checked = true; } });
    }).catch(function () {});
    tf.addEventListener('change', function () { TlmtiDB.saveDraft(key, answers()).catch(function () {}); });
    tf.addEventListener('submit', function (e) {
      if (navigator.onLine || !w.TlmtiSync) { TlmtiDB.deleteDraft(key).catch(function () {}); return; }   // online: normal same-page POST
      e.preventDefault();
      var tid = parseInt(tf.getAttribute('data-test-id'), 10);
      TlmtiSync.submit({ page: tf.getAttribute('data-page'), type: 'test_submit', entity_type: 'test', entity_id: tid,
        payload: { test_id: tid, answers: answers(), started_at: tf.getAttribute('data-started') } }).then(function () {
        TlmtiDB.deleteDraft(key).catch(function () {});
        text($('[data-test-status]'), 'Saved offline. The server will grade it when you sync; the result and unlocking are decided there.');
        $$('button', tf).forEach(function (b) { b.disabled = true; });
      });
    });
  }

  // ---- attendance: offline queue + pending marks ----
  var af = $('form[data-offline-attendance]');
  function decoratePending() {
    if (!af || !w.TlmtiSync) { return; }
    var sid = parseInt(af.getAttribute('data-session-id'), 10);
    TlmtiSync.events().then(function (evs) {
      $$('[data-pending-mark]', af).forEach(function (m) { m.hidden = true; });
      evs.filter(function (e) { return e.type === 'attendance_mark' && e.status === 'pending' && e.payload.session_id === sid; }).forEach(function (e) {
        var row = $('[data-att-row][data-student-id="' + e.payload.student_id + '"]', af), m = row && $('[data-pending-mark]', row);
        if (m) { m.hidden = false; }
      });
    });
  }
  if (af && w.TlmtiSync) {
    af.addEventListener('submit', function (e) {
      if (navigator.onLine) { return; }
      e.preventDefault();
      var sid = parseInt(af.getAttribute('data-session-id'), 10), jobs = [];
      $$('[data-att-row]', af).forEach(function (row) {
        var r = $('input[type=radio]:checked', row); if (!r) { return; }
        var stu = parseInt(row.getAttribute('data-student-id'), 10), note = $('input[name^="note"]', row);
        jobs.push(TlmtiSync.submit({ page: af.getAttribute('data-page'), type: 'attendance_mark', entity_type: 'attendance_session', entity_id: sid,
          payload: { session_id: sid, student_id: stu, status: r.value, note: note ? note.value : '' } }));
      });
      Promise.all(jobs).then(function () { text($('[data-att-status]'), jobs.length + ' record(s) queued as pending sync.'); decoratePending(); });
    });
  }

  // ---- reload lesson/attendance views after the server accepts queued events ----
  w.addEventListener('tlmti:synced', function (e) {
    var any = e.detail && e.detail.results && e.detail.results.some(function (r) { return r.status === 'accepted'; });
    if (any && (lesson || af) && !(af && af.querySelector(':focus'))) { setTimeout(function () { w.location.reload(); }, 800); }
  });

  // ---- offline downloads ----
  var df = $('form[data-download-form]');
  if (df && w.caches) {
    var CONTENT = 'tlmti-content-v1', st = $('[data-download-status]');
    function boxes() { return $$('input[data-lesson-url]', df); }
    function mark() {
      caches.open(CONTENT).then(function (c) { return c.keys(); }).then(function (keys) {
        var have = keys.map(function (k) { var u = new URL(k.url); return u.pathname.split('/').pop() + u.search; });
        boxes().forEach(function (b) {
          var u = new URL(b.getAttribute('data-lesson-url'), w.location.href), ok = have.indexOf(u.pathname.split('/').pop() + u.search) !== -1;
          var tag = $('[data-downloaded]', b.parentNode); if (tag) { tag.hidden = !ok; }
        });
      }).catch(function () {});
    }
    mark();
    $('[data-download-all]', df).addEventListener('click', function () { boxes().forEach(function (b) { b.checked = true; }); });
    $('[data-download-selected]', df).addEventListener('click', function () {
      if (!navigator.onLine) { text(st, 'You must be online to download.'); return; }
      var urls = boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.getAttribute('data-lesson-url'); });
      if (!urls.length) { text(st, 'Select at least one lesson.'); return; }
      caches.open(CONTENT).then(function (c) {
        var ok = 0;
        return urls.reduce(function (p, u) {
          return p.then(function () { return fetch(u, { credentials: 'same-origin' }).then(function (r) { if (r.ok && !r.redirected) { ok++; return c.put(new Request(u, { credentials: 'same-origin' }), r); } }).catch(function () {}); })
            .then(function () { text(st, 'Downloaded ' + ok + ' of ' + urls.length + '…'); });
        }, Promise.resolve()).then(function () { text(st, 'Downloaded ' + ok + ' of ' + urls.length + ' lessons.'); mark(); });
      });
    });
    $('[data-clear-downloads]', df).addEventListener('click', function () {
      if (!w.confirm('Remove all downloaded lessons from this device?')) { return; }
      caches.delete(CONTENT).then(function () { text(st, 'Downloads removed.'); mark(); });
    });
  }
}(window, document));
