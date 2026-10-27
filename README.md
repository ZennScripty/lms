# TLMTI — Single-Page Core PHP Application

> Current local release: see [Admin and Teacher module](docs/ADMIN_TEACHER.md) and [Student module](docs/STUDENT_MODULE.md) for the schema-compatible workflows, demo accounts, verification, offline behaviour, local media and Redis setup status. The historical implementation notes below describe the original core; the linked module guides describe the active dispatchers and learning rules.

Core PHP 8.1+ · PDO · MySQL/MariaDB · no framework · no AI features · **no `/api/...` routes**.

Every feature page (`public/*.php`) contains its own request handling (permission checks, CSRF, validation, PDO calls)
**and** its own HTML. Forms post back to the same page (Post/Redirect/Get). The only non-HTML responses are same-page
JSON actions, e.g. `lesson.php?action=sync_push`. CSS and JavaScript live in `public/assets/`.
Reusable classes live in `app/` (shared by pages, never exposed as URLs).

## Requirements
PHP 8.1+ with `pdo_mysql`, `mbstring`; MySQL 5.7+/MariaDB 10.4+; Apache (`mod_headers` optional) or Nginx; HTTPS in production.

## Install
1. Copy the project outside the web root. Point the web server's document root at **`public/`** only
   (`app/`, `storage/`, `tests/`, `.env` must not be web-accessible).
2. `cp .env.example .env` and set `DB_*`, `APP_TIMEZONE`. Keep `APP_DEBUG=0` in production.
3. Make `storage/logs` writable by the PHP user.
4. Use your existing TLMTI database. **This package ships no migrations or seeds** (as requested).
   The tables/columns the code expects are listed in `docs/SCHEMA_REFERENCE.md`; compare with your dump first and
   adjust column names in `app/Repositories/*` / pages if your dump differs.
   The attendance tables are defined in section 8 of the specification (create them only if missing, after
   checking the real ID types of users/subjects/batches).
5. First-time access (no seeds): make sure these exist in your DB —
   roles with slugs `super_admin`, `subject_teacher`, `student`; one active `super_admin` user
   (hash a password with `php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"`).
   Then log in and open **Roles** → *Register default permissions* → *Apply default role grants*
   (this inserts permission codes at runtime; it is not a seed file). Open **Settings** and save once.
6. Run the unit tests: `php tests/run.php`.

## Behaviour summary
| Area | Implementation |
|---|---|
| Auth | `password_hash/verify`, session id regeneration on login, HttpOnly + SameSite=Lax (+Secure on HTTPS) cookies, idle timeout, login rate-limit via `audit_logs`, active-status re-checked every request |
| Authorization | DB roles/permissions (`Authorization::can`), Super Admin = everything, Teacher = subjects in `teacher_subjects`, Student = self only. IDs from the browser are always re-verified (`canAccessStudent`, `canAccessSubject`) |
| CSRF | Token on every state-changing POST (form field or `X-CSRF-Token`); 419 otherwise |
| Learning rules | Lesson *n+1* unlocks only after lesson *n* is completed **on the server**. Completion needs `lesson_min_seconds` of study time (online: capped by the server's own "opened at" clock) |
| Module tests | Available when all lessons are done **and** `test_due_at` is reached (default 15 days from the first valid study session; switchable to "from lesson completion" in Settings). Scored in PHP. `score >= 50%` (`module_pass_rule=gte`) unlocks the next module; below keeps it locked; retakes allowed |
| Offline | IndexedDB queue (`events`, `drafts`, `meta`), service worker caches shell + visited/downloaded pages. Each event has `event_uuid`; server is idempotent (`activity_events.event_uuid` unique, attendance `client_event_uuid` unique) |
| Sync | `sync-client.js` POSTs to the **owning page**: `lesson.php`, `tests.php`, `attendance.php` with `?action=sync_push`. Sync runs on page load/resume, when back online, or via *Sync now*. A service worker cannot run PHP, so there is no silent background sync (by design of this spec) |
| i18n / TTS | `languages`, `ui_translations`, `module_translations`, `content_item_translations` with fallback chain; speaker button uses `speechSynthesis`, picks a voice by `tts_locale`, shows a notice when no voice exists |
| Attendance | Sessions per subject/date/batch, one record per student per session (DB unique + code check); duplicates rejected; corrections only while a session is *open*; offline marks show **pending sync** until accepted |

## Pages
`login`, `dashboard`, `students`, `student_detail`, `subjects`, `modules` (modules + lessons + translations),
`lesson`, `tests` (take / grade / author), `attendance`, `reports` (+CSV), `roles_permissions`, `language_settings`,
`app_settings`, `offline_downloads`, `sync_status`. Logout and language switch are global same-page actions handled in
`app/bootstrap.php` on whichever page is open.

## Permission codes
`students.view`, `students.manage`, `subjects.view`, `subjects.manage`, `modules.view`, `modules.manage`, `tests.manage`,
`attendance.mark`, `attendance.view`, `reports.view`, `roles.manage`, `languages.manage`, `settings.manage`, `learn.access`
(catalogue + default grants: `app/Config/permissions.php`).

## Known limits (honest notes)
* Offline works only for pages/content opened or downloaded earlier while online. Nothing else is promised.
* Study time recorded while offline cannot be verified by the server; it is accepted up to 4 h per event and must meet the minimum.
* A queued event whose CSRF/session has expired stays *pending* until you log in / reload an online page.
* Lesson bodies are plain text (escaped, line breaks preserved); no HTML is rendered from the database.
* Service worker pages are cleared on logout, but browsers may keep IndexedDB events of other users on a shared device until synced (events are keyed by user id and only synced for the logged-in user).
