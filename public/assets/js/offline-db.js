/* IndexedDB wrapper: pending sync events, test drafts, small meta values. */
(function (w) {
  'use strict';
  var NAME = 'tlmti_offline', VERSION = 1, dbPromise = null;

  function open() {
    if (dbPromise) { return dbPromise; }
    dbPromise = new Promise(function (resolve, reject) {
      if (!w.indexedDB) { reject(new Error('IndexedDB is not available')); return; }
      var req = indexedDB.open(NAME, VERSION);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains('events')) {
          var s = db.createObjectStore('events', { keyPath: 'event_uuid' });
          s.createIndex('status', 'status'); s.createIndex('user_id', 'user_id');
        }
        if (!db.objectStoreNames.contains('drafts')) { db.createObjectStore('drafts', { keyPath: 'key' }); }
        if (!db.objectStoreNames.contains('meta')) { db.createObjectStore('meta', { keyPath: 'key' }); }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
    return dbPromise;
  }

  function run(store, mode, fn) {
    return open().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(store, mode), req = fn(tx.objectStore(store));
        tx.oncomplete = function () { resolve(req ? req.result : undefined); };
        tx.onerror = function () { reject(tx.error); };
        tx.onabort = function () { reject(tx.error || new Error('Transaction aborted')); };
      });
    });
  }

  w.TlmtiDB = {
    putEvent: function (e) { return run('events', 'readwrite', function (s) { return s.put(e); }); },
    getEvent: function (id) { return run('events', 'readonly', function (s) { return s.get(id); }); },
    allEvents: function () { return run('events', 'readonly', function (s) { return s.getAll(); }).then(function (r) { return r || []; }); },
    deleteEvent: function (id) { return run('events', 'readwrite', function (s) { return s.delete(id); }); },
    saveDraft: function (key, data) { return run('drafts', 'readwrite', function (s) { return s.put({ key: key, data: data, saved_at: Date.now() }); }); },
    getDraft: function (key) { return run('drafts', 'readonly', function (s) { return s.get(key); }); },
    deleteDraft: function (key) { return run('drafts', 'readwrite', function (s) { return s.delete(key); }); },
    setMeta: function (key, value) { return run('meta', 'readwrite', function (s) { return s.put({ key: key, value: value }); }); },
    getMeta: function (key) { return run('meta', 'readonly', function (s) { return s.get(key); }).then(function (r) { return r ? r.value : null; }); },
    storageEstimate: function () {
      if (navigator.storage && navigator.storage.estimate) { return navigator.storage.estimate(); }
      return Promise.resolve(null);
    }
  };
}(window));
