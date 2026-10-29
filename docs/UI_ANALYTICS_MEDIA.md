# Student UI, progress analytics and separate management pages

Delivered and verified: 29 October 2026.

## Student experience

Light theme retained: teal controls, light grey background, white rounded cards and navy text. Dashboard now starts with a welcome panel and four prominent learning shortcuts, followed by progress/study/attendance cards, Continue Learning, next test and course cards. Attendance remains available further down the dashboard and on its own page. Mobile cards use a two-column layout; existing bottom navigation and desktop sidebar remain. Course cards share consistent thumbnails and full-width actions. Empty-state guidance now asks Admin to assign the Student's batch.

Browser checked at 390 × 844. The disposable preview Student intentionally had no course assignments; empty states were verified visually. Existing populated course/lesson routes were exercised in HTTP tests.

## Admin and Super Admin graphs

Dashboard and progress_analytics.php use ProgressAnalyticsService and a shared progress_charts view. The separate page provides an authorized-centre filter. Charts show overall completion, centre/batch/subject completion and six months of test scores/attempt counts. Values come from the database; no sample statistics are injected. Completion averages each eligible Student-subject membership's current-cycle published-module progress, including zero progress. The service reuses the effective Student assignment source. Historical test performance is scoped by the Student's current centre, matching existing report behavior.

Centre Admin sees only assigned active centres. Forged centre IDs return 403; Students cannot access analytics. Missing canonical batch memberships are shown as No batch selected, without silently assigning a batch. Graphs have text labels/values, progressbar semantics and responsive layouts; no chart-library dependency was added. Redundant older Admin dashboard charts are replaced by the new shared panel; Teacher dashboards retain their existing scoped charts.

## Separate pages

- lessons.php — lesson/content management
- media_library.php — upload/library
- question_bank.php — questions, Excel import/sample
- teacher_assignments.php — Teacher subject grants
- centre_subjects.php — centre subject availability
- progress_analytics.php — graphs

Modules, subjects, users, tests and course assignments retain their existing pages. New pages reuse existing view/service permissions and CSRF handling. Sidebar and content links point to separate pages; legacy ?view= routes remain compatible.

## Automatic media duration

media-duration.js reads local browser video/audio metadata on file selection and fills seconds, rounding fractional values up. The field stays editable. Object URLs are revoked on completion, failure, replacement or timeout; stale callbacks cannot overwrite a replacement file. Images/PDFs do not require duration. Corrupt, unsupported or slow media shows a clear manual-entry fallback after at most 12 seconds. Existing server-side type/size/duration validation and private upload storage remain.

The content policy now explicitly permits media from self and blob URLs for local metadata inspection. Other source directives remain unchanged. No external metadata service or package was introduced. This is browser detection, not an independent server-side ffprobe verification; ffprobe was unavailable. An actual two-second WAV fixture populated 2 seconds in the browser after the media policy fix. No preview file was submitted to storage. Library rows display saved duration.

## Changed components

New: app/Services/ProgressAnalyticsService.php, app/Views/progress_charts.php, public/progress_analytics.php, public/lessons.php, public/media_library.php, public/question_bank.php, public/teacher_assignments.php, public/centre_subjects.php, public/assets/js/media-duration.js, tests/media_duration.mjs.

Updated: app/bootstrap.php, app/Support/ui.php, app/Config/ui_hi.php, app/Views/student_dashboard.php, app/Views/staff_dashboard.php, app/Views/management.php, app/Views/media.php, app/Views/content_cards.php, public/course_assignments.php, public/assets/css/catalogue.css, public/sw.js, tests/catalogue_http.php, tests/centre_rbac_http.php.

No database schema changes, migrations, legacy mapping backfills or strict-mode changes.

## Verification executed

PHP CLI: tests/catalogue_http.php (64), tests/global_courses.php (68), tests/student_http_fixture.php (32), tests/centre_rbac_http.php (54). Node: tests/service_worker.mjs (10), tests/media_duration.mjs (8). **236 checks passed** across these suites. PHP syntax checks passed for 98 app/public files; final touched PHP files were checked again. Media JavaScript syntax check passed.

An old centre HTTP assertion expected subject-only Teacher access; its fixture expectation was aligned with the previously approved explicit batch-assignment policy and the suite passed on rerun. Temporary UI preview cleanup initially used an incorrect audit column; it was corrected and both disposable accounts were removed. Owner accounts and content were preserved.

Screenshots: storage/qa/student-mobile-refresh.png, storage/qa/progress-analytics.png, storage/qa/media-auto-duration.png. Browser viewport restored and preview session logged out. Unsupported-codec duration fallback is covered by automated events; an actual WAV metadata success was verified in-browser. Large production datasets and every browser codec were not load-tested.
