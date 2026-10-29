# Phase 3 — Student / Teacher self-registration

Implemented and verified locally on 27 October 2026. The owner explicitly requested Phase 3 next; Phase 2 remains pending. No dedicated Phase 4 approval section has been built.

## Working flow

1. Open `http://localhost/lms/public/register.php` or choose Create account from Sign in.
2. Choose Student or Teacher / Staff. Enter full name, email, password and confirmation; optionally choose a profile image.
3. Choose an active centre loaded from the database. Student sees only that centre's active batches. Teacher sees its active curriculum subjects and can select multiple subjects.
4. Submission validates all IDs and relationships on the server. Account role comes from the allowlisted account type; submitted status, role ID and username cannot grant privileges.
5. The account is saved as pending, without signing the applicant in. Matching active centre Admins and active Super Admins receive an in-app notification. No email delivery service was added.
6. Admin opens Review registration from Notifications, or edits the applicant in existing Users management. Requested batch/subjects, registration date and optional photo are visible within the existing centre authorization rules.
7. Existing Approve / Reject actions recheck centre, active batch and subject relationships. Approval preserves the selected relationships and records the existing reviewer/timestamp/audit fields. A rejected or soft-deleted identity cannot re-register with the same email to bypass review.
8. After approval, the applicant signs in using email and their chosen password through the existing login page. Pending/rejected/deleted accounts cannot access normal LMS routes or protected photo media.

## Architecture and data preservation

- Existing Core PHP/PDO authentication, roles, same-page POST routes, centre RBAC, local private storage and learning history are retained.
- Applied `scripts/migrate-registration.php`: nullable `users.batch_id` and `users.profile_asset_id` foreign keys plus `registration_submitted_at`. It requires the Phase 1 migration marker, serializes migration runs and is repeat-safe. No historical batch membership is guessed or rewritten.
- Teacher requests reuse `teacher_subjects`; both content/test management grants start at zero. Approval does not silently grant authoring permissions.
- Student registration records canonical batch membership. Course access continues through existing enrolments. Automatic access to batch-assigned subjects and subject removal remain Phase 6 work; registration does not create individual student-subject links as a substitute.
- Email login already exists. Public signup has no username input; an opaque internal username fits the existing 60-character column. Defaulting that column to email, wider-schema compatibility and username editing remain Phase 7 work.
- The existing 8-character password minimum is retained; signup also rejects passwords longer than 72 bytes to avoid bcrypt truncation. Phase 5's minimum-length change and eye toggles remain pending.
- Optional registration photos accept actual JPEG/PNG/WebP content up to 2 MB, validate dimensions, decode and re-encode a metadata-free private JPEG bounded to 512 pixels. They are excluded from curriculum media pickers/library. Existing authenticated `media.php` streams permitted photos; anonymous and foreign-centre access are denied. Profile editing/avatar integration and generic WebP cover services remain later phases.
- No R2, Flutter, Redis or new external dependency was introduced. Local testing/storage remains in use.

## Files created

| File | Purpose |
| --- | --- |
| `scripts/migrate-registration.php` | Repeat-safe nullable relationship migration |
| `app/Services/RegistrationService.php` | Validation, pending account creation, relationship rechecks, notifications and audit |
| `app/Services/ProfileImageService.php` | Validated private registration photo handling |
| `public/register.php` | Public form, options JSON and pending confirmation |
| `public/assets/js/registration.js` | Dynamic options, stale-response cancellation, loading/error/retry states and photo preview |
| `public/assets/css/registration.css` | Responsive registration styling |
| `app/Views/registration_summary.php` | Requested relationship/photo summary in existing Users edit screen |
| `tests/registration_rules.php` | Transactional registration/approval/image business rules |
| `tests/registration_http.php` | Public signup, approval, authentication and isolation integration checks |
| `docs/PHASE3_REPORT.md` | Working flow, verification and limitations |

## Existing files changed in Phase 3

| File | Change |
| --- | --- |
| `app/Core/Auth.php` | Expose canonical batch and profile references to existing session user |
| `app/Services/ManagementService.php` | Revalidate self-registration before activation; exclude private profile assets from curriculum options |
| `app/Views/management.php` | Include scoped registration summary on applicant edit |
| `app/Views/media.php` | Exclude registration photos from curriculum library |
| `public/media.php` | Allow approved Student to read own linked profile photo |
| `public/login.php` | Link to public registration |
| `public/notifications.php` | Currently authorized registration-review link |
| `app/Config/ui_hi.php` | Hindi registration/review messages |
| `docs/IMPLEMENTATION_PHASES.md` | Record approved scope and delivery status |

Other existing working-tree changes belong to earlier phases or the owner's work and were preserved.

## Verification results

All 363 assertions passed in this verification run:

| Suite | Passed |
| --- | ---: |
| `tests/registration_rules.php` | 25 |
| `tests/registration_http.php` | 41 |
| `tests/centre_rbac.php` | 33 |
| `tests/centre_rbac_http.php` | 54 |
| `tests/staff_crud.php` | 17 |
| `tests/multiple_subjects.php` | 10 |
| `tests/attendance_rules.php` | 27 |
| `tests/attendance_http.php` | 50 |
| `tests/student_http_fixture.php` | 32 |
| `tests/student_rules.php` | 24 |
| `tests/run.php` | 43 |
| `tests/service_worker.mjs` | 7 |

Registration coverage includes forged privileged roles, CSRF, foreign batch/subject IDs, malformed subject selections, duplicate email, no automatic login, pending/rejected login denial, correct Admin notifications, private photo validation/access, stale-batch approval rejection, multi-subject Teacher approval and deletion/re-registration bypass prevention. Test accounts, notifications, uploads and relationships are cleaned up; transaction-based fixtures roll back.

PHP lint passed for all 15 Phase 3 PHP components, and JavaScript syntax check passed. Migration was applied and rerun successfully. Browser checks verified Student batch selection, Teacher multi-subject options, empty-centre state, Hindi-to-English selector, mobile width 375 and desktop layout. Temporary viewport override was reset. Browser screenshots are saved in `storage/qa/phase3-registration-mobile.png` and `storage/qa/phase3-registration-desktop.png`.

## Limits and remaining checks

- No real email notification, email ownership verification or password-recovery service is claimed; Phase 3 uses in-app Admin notifications and existing authentication.
- Public registration requires an active centre and matching active batch/curriculum. Empty setup gives a clear message and disabled submission. JavaScript is required for dynamic options.
- Per-IP successful signups are limited to 10 per 15 minutes through existing audit storage and a database lock. High-concurrency load, quota exhaustion, storage failure and production proxy/IP behavior were not load-tested.
- Browser behavior was checked in the local Chromium-based browser; physical iOS/Android and separate browser engines were not tested.
- The private local image service requires the existing PHP GD/fileinfo support. Production installation must run the migration before updated routes/auth code is enabled.
- Phase 4 dedicated approval management, Phase 5 profile editing/password UI, Phase 6 batch subject access, Phase 7 username changes and later phases require separate approval.

Phase 3 stops here. No next phase is started automatically.
