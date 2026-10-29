# Global courses — Phase B delivery

Verified locally on 28 October 2026. Phase B was explicitly approved after the read-only audit. Phase C and subsequent UI/access integration phases have not started.

## Scope and compatibility

This phase adds the schema and internal assignment/access services. Existing public routes, authentication, individual enrolment access, Teacher registration behavior, curriculum, progress, attempts and certificates are unchanged. In particular, existing student screens still use `StudentService` and `student_subjects`: batch-only access will be wired and verified in Phase F. The new strict access service does not silently replace the existing access rules.

There is no new management UI in this phase, no content duplication and no automatic legacy assignment backfill. All six new tables are empty after cleanup. Existing unrelated/uncommitted work, including the registration review work, was preserved.

## Schema applied

Migration: `scripts/migrate-global-courses.php --apply`.

| Table | Key / responsibility |
| --- | --- |
| `courses` | INT UNSIGNED ID; unique course code; name, description, active flag, creator and timestamps |
| `course_subjects` | Composite primary key `(course_id,subject_id)`; supports subject reuse across courses |
| `centre_courses` | Composite primary key `(centre_id,course_id)`; Super Admin controls availability |
| `batch_courses` | Composite primary key `(batch_id,course_id)`; multiple courses per batch |
| `batch_subjects` | Composite primary key `(batch_id,course_id,subject_id)`; composite foreign keys to batch-course and course-subject relationships |
| `teacher_batch_subjects` | Composite primary key `(teacher_id,batch_id,course_id,subject_id)`; composite foreign key to the exact batch-subject tuple |

All tables use InnoDB and utf8mb4. Assignment rows have `is_active`, `assigned_by`, `assigned_at` and `updated_at`. New foreign keys have no cascading deletion. Actual centre ownership/availability, account roles and active parent eligibility are validated in services; foreign keys alone cannot express these rules.

Migration behavior:

- Default / `--preview` is read-only and works before the new tables exist.
- `--apply` requires the prior registration migration marker and takes a named database migration lock.
- Existing tables are not altered. New tables use repeat-safe creation, followed by engine, ID type, primary-key, required-column and foreign-key checks.
- Marker `global_courses_schema_version=1` is recorded only after verification. Unexpected schema versions are rejected.
- MariaDB DDL is not transactionally rolled back. After an interrupted installation, inspect errors and rerun; do not drop learning tables or disable foreign-key checks.

## Service contracts

`app/Services/CourseAssignmentService.php`:

- `saveCourse`: Super Admin only; validates code/name and retains existing content references.
- `setCourseSubject`: Super Admin only; link/deactivate a global subject without copying it.
- `setCentreCourse`: Super Admin only; availability can be deactivated without deleting child mappings.
- `setBatchCourse`: authorized Admin, scoped centre/batch, active course available at the centre.
- `setBatchSubjects`: desired subject set for one batch/course; validates course membership, centre allowlist and active parents. Duplicate IDs collapse; malformed IDs fail. Removing existing subjects requires explicit confirmation. Empty confirmed selection removes all active assignments for that batch/course.
- `setTeacherAssignment`: authorized Admin; Teacher must belong to the batch centre, have an existing explicit `teacher_subjects` link and be assigned a valid batch-course-subject tuple. It never creates, clears or raises content/test management flags.

Operations use database transactions and parent row locks. Mapping updates are audited through the existing logger; repeat identical mappings preserve assignment attribution. Deactivation retains relationships and historical data. Reactivation makes retained descendants effective again only if all parents and individual Teacher grants are still valid; later UI must explain this effect.

`app/Services/AssignmentAccessService.php`:

- `studentSubjectIds` / `canStudy`: canonical student batch, matching centre, active account/centre/batch/course and all required active mappings, plus `centre_subjects` availability. Stale individual enrolment alone grants no access in this new model.
- `teacherAssignments` / `teacherSubjectIds` / `canTeach`: exact explicit Teacher batch/course/subject tuple and existing subject grant, matching centre and active parent mappings. Returned grant flags remain separate from batch access.
- Reads allow the account owner or a currently authorized Admin. Foreign users and anonymous callers receive no assignment data.
- Same global subject reached through multiple assigned courses is deduplicated for Student subject IDs.

These are internal services, not new public endpoints. Later phases must call them consistently for listing, direct URLs, media, study, test start/submission, reports and offline sync. Teacher authoring must additionally enforce the existing permission and grant flags. Existing batch/user edit paths must be integrated with the new ownership rules before rollout.

## Legacy mapping review

Saved read-only preview: `docs/GLOBAL_COURSES_LEGACY_PREVIEW.json`.

- 27 active students have no canonical batch.
- 24 students have a single legacy enrolment batch candidate; the preview lists candidate user/batch/centre IDs without applying them.
- Unioning existing enrolment subjects into each batch could create 96 additional student-subject access pairs.
- No course names or legacy catalogue grouping were guessed. No candidate student membership, course or batch-subject mapping was activated.
- The audit's unmatched historical progress row remains untouched. It does not automatically justify an access grant.

Before Phase F switches live access, the owner must review the course grouping, batch membership and full subject lists for each batch. A canonical batch may intentionally give all its students a broader curriculum than their earlier individual enrolments; that requires an explicit mapping decision, not a hidden migration side effect.

## Verification

346 assertions passed in the final relevant suite runs:

| Suite | Assertions |
| --- | ---: |
| `tests/global_courses_migration.php` | 11 |
| `tests/global_courses.php` | 49 |
| `tests/centre_rbac.php` | 33 |
| `tests/centre_rbac_http.php` | 54 |
| `tests/registration_rules.php` | 25 |
| `tests/registration_http.php` | 48 |
| `tests/multiple_subjects.php` | 10 |
| `tests/staff_crud.php` | 17 |
| `tests/student_rules.php` | 24 |
| `tests/student_http_fixture.php` | 32 |
| `tests/run.php` | 43 |

The migration test applied the migration and reran it, verifying counts and `CHECKSUM TABLE` values for 20 existing tables: users, subjects, centres, batches, admin_centres, centre_subjects, teacher_subjects, student_subjects, student_week_state, modules, content_items, questions, tests, content_progress, module_progress, test_attempts, test_attempt_answers, certificates, activity_events and audit_logs. All were preserved across migration runs. It also verifies composite foreign keys and non-cascading new relationships.

Assignment tests use transactions and roll back all fixtures. They cover A1 HTML/JS versus B1 HTML/PHP, multi-course subject reuse, foreign Admin denial, Teacher isolation/grant preservation, inactive-parent denial, removal confirmation, reactivation, duplicate mapping protection, actual database FK violations, unchanged legacy enrolment state and backward-compatible existing StudentService behavior.

Post-test checks found zero rows in the six new tables and zero Phase B fixture users/centres. PHP syntax checks passed. No UI was added or browser-tested in this backend-only phase. High-concurrency load tests, production migration and physical-device/offline revocation checks were not performed.

## Files delivered

New:

- `scripts/migrate-global-courses.php`
- `app/Services/CourseAssignmentService.php`
- `app/Services/AssignmentAccessService.php`
- `tests/global_courses_migration.php`
- `tests/global_courses.php`
- `docs/GLOBAL_COURSES_LEGACY_PREVIEW.json`
- `docs/GLOBAL_COURSES_PHASE_B.md`

Updated: `docs/IMPLEMENTATION_PHASES.md` to track this approved workstream. No existing application source file was modified for Phase B.

Phase B stops here. Phase C (Super Admin global course/subject UI) needs approval.
