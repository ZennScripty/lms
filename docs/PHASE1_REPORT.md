# Phase 1 completion report

27 October 2026. Scope: role hierarchy, centre access and account lifecycle only. Phase 2 and later phases have not started. The prioritized dependency checklist is in IMPLEMENTATION_PHASES.md.

## Audit and migration

Core PHP/PDO, existing session authentication and same-page routes were retained. Live schema originally had `admin`, `teacher`, `student`; all `admin` users were globally privileged through an alias. A separate unused `admins` table was not adopted or duplicated. Users/batches already had a primary centre; Admin multi-centre grants and centre-subject catalogue access had no relationships.

The repeat-safe CLI migration `scripts/migrate-centre-rbac.php` was applied locally:

- Adds the distinct `super_admin` role and migrates existing globally privileged Admin users once, preserving account IDs, usernames, password hashes and access. New `admin` accounts are centre scoped.
- Adds `admin_centres` and `centre_subjects` with foreign keys and unique relationships. Existing curriculum access is backfilled from Student/Teacher centre subject links. Unmapped catalogue entries stay Super Admin only.
- Adds nullable media centre provenance, backfilled from uploaders, without moving/removing files.
- Extends user status with pending/rejected and adds reviewer/time metadata. No existing learning/progress/attempt rows are deleted.
- Uses a migration lock, idempotent DDL and a transactional completion marker. Rerunning while a new centre Admin existed was checked: it remained `admin` and was not promoted.

## Implemented behavior

Super Admin → Users can create/edit Admin accounts, select multiple permitted centres, activate/deactivate and confirm soft deletion. Existing accounts retain their passwords. Self deletion/demotion/deactivation and removal of the last active Super Admin are blocked. Soft deletion keeps historical foreign keys and blocks subsequent authenticated requests.

Centre Admin sees/manages Student/Teacher accounts, batches, enrolments, attendance and eligible curriculum only in active assigned centres. Crafted roles, centres, reference IDs, record IDs, reports, CSV and media routes receive server-side checks. An Admin cannot create/promote Super Admin or assign another Admin's centres. Revoking grants/inactivating centres affects an existing session on its next request; an empty scope does not fall back to global access.

Existing dashboards and reports were scoped, including counts, scores, progress and enrolment history. Existing reports support centre filtering for consolidated versus permitted-centre analysis. This is Phase 1 authorization work, not the separate dashboard/chart redesign reserved for Phase 2.

Shared subject catalogue entries are readable by their assigned centres. A centre Admin cannot edit curriculum affecting an unassigned centre or delegate shared global content/test management powers. Admin-created new subjects are linked only to that Admin's centres. Super Admin controls centre-subject links. Teacher student details, attendance and reports require both subject assignment and matching primary centre, preventing delegated cross-centre exposure.

Existing Users lists support approve/reject for pending accounts and confirmed soft deletion. Review state, actor and timestamp are recorded; repeated review is rejected. Pending/rejected/deleted/inactive users cannot gain normal login access. Public self-registration, requested batch/subject data and dedicated approval screens remain Phases 3 and 4.

## Changed implementation files

| Component | Files |
| --- | --- |
| Authentication and reusable scope | app/Core/Auth.php; app/Core/Authorization.php |
| Migration | scripts/migrate-centre-rbac.php |
| Management and lifecycle | app/Services/ManagementService.php; app/Views/management.php |
| Routing and navigation | app/Support/staff_dispatch.php; app/Support/ui.php |
| Existing statistics and exports | app/Views/staff_dashboard.php; app/Views/staff_reports.php |
| File authorization and upload provenance | app/Views/media.php; public/media.php |
| Attendance evidence | app/Services/AttendanceService.php |
| Hindi UI labels | app/Config/ui_hi.php |
| New isolation tests | tests/centre_rbac.php; tests/centre_rbac_http.php |
| Updated regression fixtures for new Super Admin role | tests/staff_crud.php; tests/multiple_subjects.php; tests/attendance_rules.php; tests/attendance_http.php |

Unrelated concurrent edits, including the attendance date display, were retained. During final verification an older management save implementation reappeared; the missing Phase 1 save guards were reconciled and both isolation suites rerun successfully.

## Verification

| Suite | Passed |
| --- | ---: |
| Centre RBAC transactional rules | 33 |
| Centre RBAC real HTTP | 54 |
| Staff CRUD | 17 |
| Multiple subjects | 10 |
| Attendance rules | 27 |
| Attendance HTTP | 50 |
| Student HTTP using disposable account | 32 |
| Student learning/test rules | 24 |
| Core rules and validation | 43 |
| Service worker | 7 |
| Total | 297 |

PHP syntax checks passed. Browser verification showed the Super Admin centre-grant form and a centre Admin with exactly one permitted centre/student; the other test centre's student and Super Admin/Settings controls were absent. Screenshot: storage/qa/phase1-centre-admin-users.png. The API tests also exercised direct URLs, forged creates/deletes, export and binary file access, shared-subject Teacher isolation, pending login rejection, approval/rejection and live access revocation.

## Handover and limits

Use existing Super Admin login credentials; no owner password reset was required. To provision a centre Admin, use Users → role Admin → Admin centre access checkboxes → Save. To make catalogue subjects available, use Subjects → Centre subjects. Shared curriculum changes stay with Super Admin unless all affected centres are within an Admin's authorized scope.

Temporary HTTP/transactional fixtures were removed or rolled back. A preview Admin password was changed outside the verification flow, so that account and related preview data are retained rather than overwritten/deleted. The additional disposable scope-verification account is removed after checking. Any remaining preview Super Admin with the original test password is deactivated, retaining referenced history.

Production load/security-penetration testing is not claimed. Public registration and dedicated registration review, new dashboard graphs, batch-subject redesign, password UI changes, image optimization and translation-provider integration remain in their scheduled later phases. Phase 2 requires owner approval.
