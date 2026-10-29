# Global courses, covers and batch learning — delivery report

Verified locally on 28 October 2026, in the existing Core PHP/PDO/MariaDB application.

## Delivery status

| Stage | Result |
| --- | --- |
| C | Super Admin course catalogue/create/edit/active state, global subject links and course/subject covers implemented |
| D | Centre course availability and batch course/subject assignment screens implemented |
| E | Teacher batch/course/subject assignment and personal assignment screen implemented; authoring grants retained separately |
| F | Module/lesson covers and strict learning/media/report integration implemented and tested; live strict access remains OFF pending legacy mapping review and explicit activation approval |
| G | Relevant regression suites, schema reruns, read-only impact preview and desktop/mobile verification complete; live strict-mode acceptance remains pending |

This is not a claim that strict batch-only access is active for existing users. The implementation is gated to avoid cutting off legacy students before their mappings are reviewed.

## Approvals and database impact

The owner explicitly approved disabling public Teacher/Staff signup and separately approved the additive cover migration. Both were implemented. Existing pending Teacher accounts can still be reviewed by authorized administrators; creating new staff accounts is an Admin workflow.

Applied `scripts/migrate-content-covers.php --apply` after showing its read-only preview:

- `courses.cover_asset_id`, `subjects.cover_asset_id`, `content_items.cover_asset_id`: nullable BIGINT UNSIGNED foreign keys to `media_assets.id`, with indexes and restrictive deletion.
- Existing `modules.cover_asset_id` reused.
- `content_covers_schema_version=1` marker recorded.
- No content backfill, canonical batch inference, historical enrolment conversion or strict-mode activation performed.
- The Phase B verifier now recognizes the approved additional course-cover FK, so rerunning it remains supported.

Migration was applied and rerun while hashing the existing record values in courses, subjects, modules, content items, users, enrolments, progress, attempts and certificates, excluding the new optional cover field. Existing values were preserved.

## Screens and working flow

### Super Admin

1. Open `public/courses.php` through **Global courses**. Search/list cards use SQL pagination, cover images/placeholders, status and linked subject counts.
2. Add or edit a course: code, name, description, active state, optional cover. Codes are validated and duplicates rejected.
3. Open a course to link existing global subjects or deactivate/restore links. Deactivation asks for confirmation.
4. Create/edit global subjects through the existing Subjects screen. Centre Admin cannot create/edit global subject definitions.
5. Use the existing **Centre subjects** tab to manage the centre's permitted subject allowlist.
6. Open **Course assignments**, select a centre, and make global courses available there. Deactivation retains child relationships and history.

### Centre Admin

1. Course and assignment screens restrict reads and actions to authorized centres.
2. Create/manage a batch through existing Batches. Assign one or more available courses to it.
3. Choose subjects belonging to the course and centre allowlist. Current assignments remain visible; inactive relationships are retained.
4. Unchecking existing subjects requires the explicit removal confirmation. Assignment removal does not delete global subjects, content, progress or attempts.
5. Assign a Student's canonical batch in Users, or review the batch selected during Student registration. This is an explicit account edit, not automatic backfill.

### Teacher / staff

1. An authorized Admin creates the account in its centre.
2. Assign an existing subject grant through Subjects → Teacher assignments, keeping content/test flags off unless explicitly intended and authorized.
3. In Course assignments, select the centre and batch and assign a valid course/subject tuple to a Teacher with that existing subject grant.
4. Teachers can view their explicit tuples in **My teaching assignments**. They cannot self-assign. Batch assignment does not create or raise authoring flags.
5. Full enforcement on existing Teacher subject/student/report routes is connected to the same reviewed strict-access gate as Student rollout. Until activation, legacy subject access remains for compatibility.

### Student learning after approved strict activation

The account must be active/approved and its canonical batch must belong to its centre. All parent centre/course/batch/subject mappings must be valid and active. The actual catalogue, lesson resolver, study, test start/submission, media, downloads, sync, relevant staff scopes and reporting source use this access model when the gate is enabled.

Students can learn batch subjects without creating individual subject-assignment records. Existing enrolment rows remain progression/history data, and existing week/cycle state is reused. A stale individual enrolment cannot independently grant strict access. The same subject reached through multiple batch courses is deduplicated.

Submitted historical attempts remain owned/readable after access removal; new attempts, pending submissions and study sync recheck current entitlement. Global content IDs and learning history are retained.

## Image/storage behavior

- Courses, subjects, modules and lessons have independent optional cover controls. Lesson covers are distinct from language-specific video/audio/PDF/content assets.
- Forms show current covers, local previews, replacement and explicit removal controls. Omitted replacement preserves the existing reference.
- Server checks actual bytes, MIME, matching extension, nonempty file size up to 2 MB, dimensions 32–4096 and a maximum pixel count. SVG, executables and mismatched formats are rejected.
- GD decodes and re-encodes a metadata-free cover, preserving aspect ratio and bounding its longest side to 1200 pixels. WebP is used where supported, with JPEG fallback.
- Random filenames and relative paths use existing private `storage/uploads` and `media_assets`. No external storage dependency or machine-specific path is stored in the database.
- Existing authenticated `public/media.php` serves permitted covers/content. Student and foreign-centre access is checked server-side. Static storage remains denied by `.htaccess`.
- Cards/thumbnails use a consistent 16:9 crop and a neutral trusted placeholder. Uploaded SVG is never accepted; the bundled placeholder is a controlled application asset.
- Old immutable assets/files are retained on replace/remove, including shared references. No automatic physical deletion or garbage collection was introduced. This is intentional data preservation; unreferenced cover files may accumulate until a separately reviewed cleanup operation is approved.

## Ownership and compatibility

- Existing authentication, CSRF, bilingual content, routes, test version/attempt protection and unrelated working-tree changes were retained.
- Student canonical batch edits validate role and centre. Existing callers that omit batch_id preserve a stored membership.
- Moving a batch with members, legacy enrolments or course assignments to another centre is denied.
- Course mutations and course availability are Super Admin only. Centre/batch/Teacher assignment operations reuse `CourseAssignmentService` validation rather than duplicate write rules in controllers.
- When strict access is enabled, Teacher data scopes include explicit batch/subject tuples and existing grants; an unrelated batch sharing the global subject does not grant student/report access.
- Protected offline caches remain account-partitioned. A changed server access revision clears old private curriculum caches on reconnection; online media denial also clears private cached data. Fully disconnected devices cannot receive immediate revocation instructions. Sync/study/test requests are revalidated when they reach the server.

## Executed verification

508 assertions/checks passed in the final relevant suite runs:

| Command suffix | Passed |
| --- | ---: |
| `php tests/content_covers_migration.php` | 3 |
| `php tests/content_images.php` | 10 |
| `php tests/global_courses_migration.php` | 11 |
| `php tests/global_courses.php` | 68 |
| `php tests/catalogue_http.php` | 40 |
| `php tests/centre_rbac.php` | 34 |
| `php tests/centre_rbac_http.php` | 54 |
| `php tests/registration_rules.php` | 26 |
| `php tests/registration_http.php` | 49 |
| `php tests/staff_crud.php` | 17 |
| `php tests/multiple_subjects.php` | 10 |
| `php tests/attendance_rules.php` | 27 |
| `php tests/attendance_http.php` | 50 |
| `php tests/student_rules.php` | 24 |
| `php tests/student_http_fixture.php` | 32 |
| `php tests/run.php` | 43 |
| `node tests/service_worker.mjs` | 10 |

Actual runtimes: `C:/xampp/php/php.exe` and bundled Node at `C:/Users/HP/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe`. PHP `-l` checked all 113 PHP files under app/public/scripts/tests with zero errors. Node syntax checks passed for `public/assets/js/catalogue.js` and `public/sw.js`.

Strict-mode service/controller-view tests ran inside an uncommitted transaction that rolled back; the global live setting was not enabled. They exercised batch-only learning without individual enrolment, stale enrolment denial, SQL catalogue pagination, lesson resolution, actual test start/grading, revoked submission/offline study denial, history preservation and Teacher/student/report scopes. Actual HTTP tests covered normal authentication, CSRF, course operations, real multipart uploads for all four entity types, protected media, assignment validation, confirmation and Teacher flags.

Visual checks used a disposable preview account/data, inspected desktop and 390px mobile cards/forms, Hindi labels and cover-removal preview. Temporary viewport override was reset. Preview account, relationships, image and files were removed afterward; real user-created course data was preserved.

Screenshots: `storage/qa/global-courses-desktop.png`, `storage/qa/global-courses-mobile.png`. They contain explicitly labeled QA preview records, not seeded production courses.

## Files changed for this delivery

New:

- `scripts/migrate-content-covers.php`, `scripts/preview-learning-access.php`
- `app/Services/ContentImageService.php`, `app/Services/LearningAccessPreviewService.php`
- `app/Views/global_courses.php`, `app/Views/cover_editor.php`, `app/Views/content_cards.php`
- `public/course_assignments.php`
- `public/assets/css/catalogue.css`, `public/assets/js/catalogue.js`, `public/assets/icons/cover-placeholder.svg`
- `tests/catalogue_http.php`, `tests/content_images.php`, `tests/content_covers_migration.php`
- This report and `docs/GLOBAL_COURSES_ACCESS_PREVIEW.json`

Updated:

- `app/Core/Authorization.php`
- `app/Services/AssignmentAccessService.php`, `ManagementService.php`, `StudentService.php`, `LearningService.php`, `RegistrationService.php`
- `app/Support/staff_dispatch.php`, `student_dispatch.php`, `ui.php`
- `app/Views/management.php`, `staff_dashboard.php`, `staff_reports.php`, `student_course_card.php`, `student_courses.php`, `student_dashboard.php`, `student_lesson.php`, `student_tests.php`
- `public/media.php`, `public/register.php`, `public/login.php`, `public/sw.js`
- `app/Config/ui_hi.php`, `scripts/migrate-global-courses.php`
- `tests/global_courses.php`, `centre_rbac.php`, `registration_rules.php`, `registration_http.php`, `service_worker.mjs`
- `docs/IMPLEMENTATION_PHASES.md`

Other existing working-tree changes belong to earlier work and were preserved.

## Required review before live activation

Latest read-only snapshot: `docs/GLOBAL_COURSES_ACCESS_PREVIEW.json`. Regenerate with:

```powershell
& C:/xampp/php/php.exe scripts/preview-learning-access.php
```

At this verification: 27 active students lack a canonical batch, effective configured batch-subject pairs are 0, and activating strict mode now would remove access for 25 current legacy enrolment pairs. The snapshot includes Student membership IDs, batch-subject mappings and a review hash. No missing membership or historical curriculum was automatically converted.

Required next steps:

1. Owner/Admin explicitly configures centre availability, batch courses/subjects and canonical student membership using the new screens.
2. Configure/review Teacher tuples and their separate authoring grants.
3. Regenerate and review the impact snapshot, resolving unintended loss or widening of access.
4. Obtain explicit approval before setting `batch_learning_strict_access=1` in app_settings and performing live strict-mode acceptance checks.

No activation was performed. No physical-device iOS/Android, alternate-browser-engine, concurrency/load or production deployment tests were claimed. Course/centre selection lists are bounded to 200 courses; larger catalogues will need searchable assignment selectors. JPEG fallback exists but local conversion tests used the available WebP encoder.
