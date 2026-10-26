# Integration / security checklist (needs DB + browser)

`php tests/run.php` covers the pure rules (49% fail / 50% pass, gte vs gt, scoring, CSRF token logic, validators, sync helpers, language fallback).
The scenarios below need a running app. Items marked ✔ were also exercised with scripted HTTP requests against a local MariaDB during development.

- [ ] ✔ Teacher changes `student_detail.php?id=` to an unassigned student → 403
- [ ] ✔ Teacher opens `modules.php?subject_id=` / `reports.php?subject_id=` of an unassigned subject → 403
- [ ] ✔ Student opens another student's `student_detail.php` → 403; student opens `students.php` → 403
- [ ] ✔ Lesson 2 (and a lesson of the next module) is locked until the previous step is verified; direct URL → 403
- [ ] ✔ Test result 49% fails and next module stays locked; 50% passes with `module_pass_rule=gte`
- [ ] ✔ The same offline event synced twice → one `activity_events` row, one study session, one attendance row
- [ ] ✔ Duplicate student/session attendance rejected; student outside roster rejected; student cannot post attendance
- [ ] ✔ POST without CSRF token → 419 (JSON 419 for `sync_push`); anonymous `sync_push` → 401/419
- [ ] ✔ XSS: `<script>` in lesson body is rendered escaped; non-numeric IDs → 404 (no SQL error)
- [ ] ✔ Login lockout after repeated failures
- [ ] Airplane mode: open a downloaded lesson, complete it, see "pending", reconnect → sync → next lesson unlocks
- [ ] Browser restart while offline: pending events still listed on Sync page
- [ ] Offline page reload of a visited page works; an unvisited page shows the offline notice
- [ ] Cache eviction (clear site data): app asks to go online again; no crash
- [ ] Sync retry: stop the server mid-sync → events stay pending, attempts increase, "Retry failed" works; server timeout handled
- [ ] Offline attendance rows show "pending sync" until accepted
- [ ] Session fixation: session id changes after login; expired session → redirect to login (JSON 401 for sync)
- [ ] Permission escalation: remove a permission in Roles and confirm the page returns 403 immediately
- [ ] TTS: language with an installed voice speaks; language without a voice shows the notice
