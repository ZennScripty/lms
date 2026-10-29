# LMS implementation checklist

## Current global-course workstream

The owner approved the global-course Phase B after a read-only Phase A audit. Phase B is implemented and verified; see [Phase B report](GLOBAL_COURSES_PHASE_B.md) and [legacy mapping preview](GLOBAL_COURSES_LEGACY_PREVIEW.json). No legacy mappings were applied and existing live access behavior is retained.

| Phase | Scope | Status |
| --- | --- | --- |
| A | Current code/live schema audit and relationship proposal | Complete |
| B | Additive schema, assignment services and compatibility verification | Complete; 346 assertions passed |
| C | Super Admin global course/subject management and covers | Implemented and verified |
| D | Centre availability and batch course/subject assignment UI | Implemented and verified |
| E | Teacher mapping UI and authorization integration | Implemented and verified; live strict enforcement gated |
| F | Module/lesson covers, batch learning/media/report integration | Implemented and tested; strict activation awaits reviewed legacy mappings and approval |
| G | Integration, regression verification and documentation | 508 checks passed; live strict acceptance pending |

Current delivery: [Global course and image report](GLOBAL_COURSES_DELIVERY.md). Cover migration and Student-only public registration were explicitly approved and applied. Read-only activation impact: [access preview](GLOBAL_COURSES_ACCESS_PREVIEW.json). Existing learning access is retained until reviewed strict activation; no legacy mappings were applied automatically.

## Earlier implementation checklist

The scope statement below records the earlier numbered-phase work, not authorization to start additional phases in the current global-course workstream.

Phase 1 is verified. The owner explicitly approved Phase 3 next; Phase 2 remains pending. Only Phase 3 is authorized in this run. Phase 4 and other later work require separate approval.

| Phase | Scope | Dependencies | Status |
| --- | --- | --- | --- |
| 1 | Distinct Super Admin and centre Admin, centre relationships, server-side isolation, safe account lifecycle, existing reports and exports scoped | Existing PHP/PDO authentication and live schema audit | Implemented and verified; see PHASE1_REPORT.md |
| 2 | Separate centre dashboards and new progress graphs | Verified Phase 1 and explicit approval | Not started |
| 3 | Public Student/Teacher registration and dynamic options | Centre RBAC and registration relationship design | Implemented and verified; see PHASE3_REPORT.md |
| 4 | Dedicated pending-registration section and review details | Phases 1 and 3 | Not started |
| 5 | Global UI cleanup, profile images, password length/toggles | Explicit approval; existing profile/storage audit | Not started |
| 6 | Batch subjects with safe removal and preserved history | Centre-subject scope; batch relationship migration review | Not started |
| 7 | Default email username and legacy login compatibility | Phase 3 | Not started |
| 8 | Subject/module/lesson images and reusable WebP optimization | Storage review and Phase 1 scope | Not started |
| 9 | Explicit bilingual translation suggestions/provider configuration | Existing linked translations; configured provider | Not started |
| 10 | End-to-end integration and final acceptance | Approval and completion of all preceding phases | Not started |

## Phase 3 delivery

Public `register.php` now creates pending Student/Teacher accounts with database-driven centre/batch/subject options, optional validated private profile photos, and notifications to authorized administrators. Existing Users review actions validate requested relationships again before approval. The nullable batch/profile/registration timestamp migration has been applied and rerun successfully. See PHASE3_REPORT.md for exact files, verification, and remaining phase boundaries. Phase 2 remains pending; Phase 4 has not started.

## Phase 1 audit and decisions

The application is Core PHP/PDO with same-page POST routes, file sessions and database permissions. Live schema was checked rather than relying on the old SCHEMA_REFERENCE document. Roles are currently `admin`, `teacher`, `student`; `admin` is incorrectly mapped to unrestricted `super_admin`. Users and batches have `centre_id`; subjects are a shared catalogue and have no centre ownership. Existing student/teacher subject links, learning history, tests and media are retained.

Migration plan: add `super_admin`, promote the existing globally privileged Admin accounts once to preserve their existing rights, keep `admin` as the new scoped role, add `admin_centres` and `centre_subjects` because neither relationship exists. Backfill catalogue access from existing enrolments and Teacher centre assignments. Unmapped subjects remain visible only to Super Admin until assigned. Shared subjects remain readable in permitted centres; centre Admin cannot change shared curriculum that would affect an unassigned centre. New subjects created by an Admin are linked only to that Admin's active assigned centres.

Media metadata also receives nullable centre provenance so revoked access cannot be bypassed through an old uploaded file. Existing media files are retained. Teacher access to student records/reports is restricted to the Teacher centre as well as subject assignments, preventing delegated access from crossing centre boundaries.

No public registration route/request model currently exists. Phase 1 adds scoped pending-account review primitives/status support in existing user management; public registration remains Phase 3 and dedicated request UI/relationship workflow remains Phase 4. Existing users, account IDs, password hashes, primary centre links and learning data are preserved. Admin removal uses soft deletion to keep foreign keys/history intact. Self-removal and last active Super Admin removal/demotion are blocked.

## Exact planned components

- `scripts/migrate-centre-rbac.php`: repeat-safe schema/data migration and relationship backfill.
- `app/Core/Auth.php`, `app/Core/Authorization.php`: explicit role mapping, fail-closed centre access and reusable query conditions.
- `app/Services/ManagementService.php`, `app/Views/management.php`: scoped reads/options/writes, Admin centre assignment, subject-centre links, account review/deletion guards.
- `app/Support/staff_dispatch.php`, `app/Support/ui.php`: scoped Admin routes/navigation using the current structure.
- `app/Views/staff_dashboard.php`, `app/Views/staff_reports.php`: scope existing statistics, progress and CSV; add centre filter to existing reports, without implementing Phase 2 graphs.
- `app/Views/media.php`, `public/media.php`, `app/Services/AttendanceService.php`: protect binary delivery, dropdown references and attendance evidence across centres.
- `app/Config/ui_hi.php`, UI confirmation wiring: existing Hindi/English conventions and safe lifecycle actions.
- `tests/centre_rbac.php`, `tests/centre_rbac_http.php`, existing fixture helpers: cross-centre, forged-ID, privilege escalation, stale scope and regression coverage.

## Risks and acceptance constraints

Schema changes are necessary for missing centre relationships. MariaDB DDL commits independently; migration uses idempotent DDL plus transactional role/backfill changes and a completion marker. Reruns must not promote newly created centre Admins. Shared curriculum cannot silently become centre-owned. Inactive centres must fail closed for their Admins. Existing global settings/permissions remain Super Admin only. New public registration, new analytics, batch-subject redesign, password changes, image optimization and translation are outside this phase.

## Current batch access update — 29 October 2026

Approved configured-batch access is active; global strict mode remains off. Student batch selection, editable subject/module suggestions, XLSX/CSV question imports and success dialogs are verified. See TESTS_QUESTION_IMPORT_BATCH_FIX.md for 523 checks and compatibility boundaries. Earlier phase entries above are historical.


## UI and analytics delivery — 29 October 2026

Student dashboard refresh, separate management routes, centre-scoped progress graphs and automatic browser media duration are delivered. See UI_ANALYTICS_MEDIA.md for scope, screenshots and 236 executed checks. No schema/mapping changes.

