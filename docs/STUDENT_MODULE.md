# Student module

The student web module uses the supplied database and the existing authentication, CSRF checks and PHP page routes. No database tables or columns were replaced. `StudentService` owns student access, current-cycle learning progress and assessments; `student_dispatch.php` selects schema-compatible student views. Staff routes continue to use their existing management dispatcher.

## Screens

- Dashboard: saved content progress, study time, completed lessons, attendance, continue learning, next available test and enrolled courses. No theory/practical/placement percentages are invented when the database has no such data.
- My Courses: SQL search, status filter and pagination; bilingual published modules and lessons; completion and lock states. Completed enrolments remain reviewable without allowing new progress writes or attempts. Dropped enrolments and inactive subjects are unavailable for learning/new attempts; students can still read their own submitted result history.
- Learning: text, image, authenticated PDF, audio/video player, saved media position, browser TTS, active study timer, periodic saves and explicit lesson completion. The server caps online study seconds by its own session clock. Seeking a video does not earn skipped watch time.
- Tests: published module/weekly/practice tests, server-created/resumable attempts, one-question navigation, local answer drafts, countdown, submission, server grading and result view. The browser never receives correct-answer flags.
- My Progress: current-cycle module progress and paginated attempt history, with links to the student's own results.
- My Attendance: paginated daily history and self check-in using Admin's selected simple/selfie policy. Students cannot edit history or mark another person. See [daily check-in and multiple subjects](MULTIPLE_SUBJECTS_ATTENDANCE.md).
- Downloads: explicitly download available, downloadable lesson pages and attached media; view pending sync state; remove private downloads.
- Profile/password and notifications use the shared account pages.

## Learning and test rules

Week one starts open. Later weeks require the preceding weekly test. Within an open week, modules and mandatory lessons are sequential. A module with published module tests requires all those tests to be passed before the following module opens. Optional lessons do not block the sequence. The existing legacy 15-day module delay is not applied to this workflow: availability is controlled by study requirements and each test's availability window.

Module tests require the module's mandatory lessons. Weekly and practice tests use their configured minimum study percentage. Pass percentage, `gte`/`gt`, maximum attempts, retake interval, availability windows and duration are checked server-side. An assessment's question/options/marks, pass rule and duration are snapshotted in a private `test_start` activity event. Tests/questions with attempts are protected against in-place authoring changes, including pending attempts. The student receives only question text, marks and option text/IDs.

A module pass unlocks the next module. A weekly pass opens the next week. A weekly failure starts a fresh cycle for that week; earlier progress and attempts remain in history. Old-cycle offline activity cannot satisfy the new cycle. Repeated submissions return the saved result without duplicating answers or changing the grade. Expired timed attempts receive a failed zero-score result; changing a device clock cannot extend the server deadline. Current authoring and automatic grading support single-answer MCQ/true-false; other question types require instructor review.

For video/audio, the author supplies or confirms duration at upload (the browser detects it where supported). Required study time is the greater of the minimum lesson seconds and the media duration multiplied by the lesson's minimum completion percentage. Existing assets without duration use the configured minimum lesson seconds. The application cannot independently verify offline playback; offline study events are marked as such and bounded to four hours per event.

## Offline behaviour

The web module downloads selected lessons while online. Available student pages are also cached on visits. Text and fully downloaded media can then be reopened offline, including media byte ranges. A previously started assessment can keep an offline draft; final grading requires server sync. Timed assessment answers must reach the server by its deadline; late offline sync does not extend it.

Cache Storage is partitioned by account. Switching accounts or logging out clears private downloaded pages and media. Unsynced IndexedDB events and drafts remain partitioned by user ID. CSRF and last-sync metadata are also user-specific. Expired login/security tokens require signing in/reloading an online page before retrying sync. There is no native background execution guarantee: sync runs while the app is open, on reconnect/resume, or with Sync now. Browser storage eviction can remove cached content. Staff pages and answer keys are not cached as student learning material.

The browser schema's `study_sessions.platform` enum only represents Android/iOS. Web study is recorded accurately in `activity_events` with `target_type=web` and in progress aggregates, rather than labelling browser sessions as Android. Redis activation remains a separate local runtime setup task; R2 remains deferred.

## Local verification

- `php tests/student_rules.php`: transactional progression, completion, duplicate sync, test snapshots, grading, retakes, weekly cycles, deadlines and completed/withdrawn enrolments. Fixtures are rolled back.
- `php tests/student_http.php`: real local student login, pages, subject/ownership restrictions, CSRF, language and rejected cross-subject offline events.
- `node tests/service_worker.mjs`: isolated service-worker cache/navigation/range/account/logout tests with network loss simulated in the test runtime.
- Browser acceptance: `demo.student.01` completed a lesson, received a server-scored 100% test result, unlocked the second module and downloaded two available lessons. These demo actions remain as legitimate sample progress. Browser UI was checked at phone, tablet and desktop widths. The service-worker outage simulation is automated, rather than an OS network disconnection.

Demo account: `demo.student.01`, initial password `Demo@2026!`. Other seeded accounts run through `demo.student.24`. Existing user-created accounts/content are preserved. Screenshots under `storage/qa` record the verified UI.
