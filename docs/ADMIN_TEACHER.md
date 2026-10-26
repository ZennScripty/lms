# Admin and Teacher module

This local release uses the existing `lms` database without schema migrations. It keeps the existing PHP routes and authentication. Database-backed staff views are dispatched in `app/Support/staff_dispatch.php`; student handlers remain in their existing pages. Student learning and offline sync still need a separate schema alignment and acceptance pass before that module is ready.

## Appearance and languages

The shared interface is light-only: teal `#17657A`, background `#F6F8FA`, white cards, navy text, and green progress indicators. Desktop uses a fixed sidebar; tablet and mobile use a menu and bottom navigation. Lists scroll inside their cards on narrow screens. Controls have visible keyboard focus, labels, and touch targets. Hindi and English are the only selectable UI/content languages. Existing other-language database rows have not been deleted. Language selection saves the account's preferred language and the current session; content authoring stores both Hindi and English explicitly.

## Admin workflows

- Centres: create/edit centre name, district, address and active state.
- Batches: create/edit centre, training mode and dates, with date validation.
- Users: create/edit teacher, student and admin accounts, hash/reset passwords, update contact details, role, centre and status. Self-deactivation/demotion and removal of the last active admin are blocked.
- Subjects: create/edit unique trade code, name, description and active state. Teacher assignments live in the subject page's second tab, with independent content/test management flags.
- Enrolments: link a student, subject and optional batch; validate the centre; open week one; change enrolment status without erasing progress/history.
- Content: create/edit bilingual modules and lessons, week/sequence, publication state and completion requirements. Upload local media and attach it to lessons. Staff can preview text, images, video, audio and PDF downloads. Only single-answer MCQs are authored in this release.
- Tests: question bank with four bilingual options, correct answer and marks; weekly/module/practice tests with a fixed question selection, duration, pass rule, attempts and retake settings. Server validates question subject and publication state and computes total marks. Tests/questions with submitted attempts cannot be edited in place.
- Attendance: daily attendance for students or teachers; corrections, remarks, and duplicate checks for a user/date.
- Reports: subject filters, completion aggregates, test averages, daily presence counts, student attempt history and CSV export. Attendance is person/date based in the supplied schema, not per subject session.
- Roles/permissions, validated application settings, audit log, profile/password management and personal notifications.

Lists use SQL `LIMIT/OFFSET`, bounded page sizes, deterministic ordering, search where meaningful, and appropriate filters. CSV export streams all matching records and guards spreadsheet formula prefixes.

## Teacher workflows and restrictions

Teachers see only assigned subjects and students actively enrolled in those subjects. The same restriction applies to record lookup, mutations, reports, student detail, media and CSV export. Content and question/test writes require both the role permission and the per-subject assignment flag. Teachers can mark attendance only for students in their assigned scope; they cannot create users, subjects, enrolments, centres, batches, or change system settings/roles.

## Demo records

Run `C:\xampp\php\php.exe scripts/seed-demo.php` from the project folder. The CLI-only script is transactional and idempotent. It inserts synthetic names with the `Demo` prefix and `@example.test` emails. It never resets the existing administrator.

- 5 teachers, 24 students, 3 batches and 2 additional demonstration centres.
- 15 bilingual modules/lessons, 15 MCQs, 15 module tests.
- Stored progress, 120 attendance rows and 36 synthetic assessment attempts/answers.
- Teacher usernames: `demo.teacher.copa`, `demo.teacher.elec`, `demo.teacher.weld`, `demo.teacher.sew`, `demo.teacher.dsl`.
- Student usernames: `demo.student.01` through `demo.student.24`.
- All demo accounts initially use `Demo@2026!`.

## Local media

Files live under `storage/uploads`, and metadata/checksums live in the existing `media_assets` table. Uploads check MIME type, size and permissions, use random server filenames, and allow JPG/PNG/WebP/PDF/MP4/MP3/OGG/WAV. PHP's configured upload limit may be lower than the application's 50 MB limit. Media is served through authenticated `public/media.php` with scoped access and HTTP byte ranges. The storage directory denies direct Apache access. R2 is intentionally deferred.

## Redis readiness

Local testing currently uses PHP file sessions. No Redis service or phpredis extension was present on this machine during verification. `SessionStore` supports an explicitly configured phpredis session handler with locking, expiry, password and TLS options. After installing/enabling phpredis and running Redis, set `SESSION_DRIVER=redis` and the `REDIS_*` values in `.env`, then restart Apache and verify login/logout/expiry. Configuration failures do not silently fall back to file sessions. Live Redis sessions and Redis caching/rate limiting have not been verified; the current login rate limit remains database-backed.

## Verification

- `php tests/run.php`: existing pure rule tests.
- `php tests/staff_crud.php`: transactional create/update, assignments, bilingual content/questions, tests, enrolment and attendance tests. All fixtures roll back.
- `TEST_ADMIN_PASSWORD` environment variable plus `php tests/staff_http.php`: local login, staff routes, forms, CSRF, pagination/search, languages, reports/export and cross-subject restrictions.

The test scripts require a local database and the HTTP suite expects Apache at `http://localhost/lms/public/`. Do not expose the project root in production; use `public/` as document root. Files under `storage/qa` are verification captures, not application data.
