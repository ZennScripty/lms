# Explicit Teacher centre, batch and subject access

Verified 29 October 2026. No schema migration or automatic account mapping was performed.

## Administrator workflow

1. Users: create/edit the Teacher with the correct centre. Existing history prevents unsafe centre transfers.
2. Subjects → Teacher assignments: grant the permitted subjects; enable content/test management only when needed.
3. Course assignments: choose centre, then batch. Assign permitted courses and batch subjects if needed.
4. Teacher batch assignments: select the Teacher for each course/subject combination. Only Teachers in that centre with an existing subject grant can be assigned.
5. Deactivate an assignment to revoke access without deleting history. Multiple explicit batch/subject assignments are supported within the Teacher's centre.

## Access enforcement

Teacher subject visibility always requires an active teacher_batch_subjects tuple and active batch, centre, course, centre-course, batch-course, course-subject and batch-subject relationships plus the centre subject allowlist and existing teacher_subjects grant. Legacy subject-only grants no longer independently authorize Teacher access, regardless of Student rollout flags.

Student details require canonical batch membership in an effective Teacher assignment and matching centre. Student listings, attendance selection, dashboard statistics, progress reports and CSV exports use explicit batch/subject restrictions. Historical attempts on Student detail reports are also filtered by that Student's assigned subject tuple, rather than the union of all subjects taught in other batches. Unmapped legacy Students are inaccessible to Teachers until an administrator explicitly assigns their canonical batch. Their own legacy Student access remains unchanged.

Content/test creation still requires both effective subject access and the existing explicit permission flag. Batch assignments do not elevate authoring permissions. Linked lesson media rechecks current subject access even when the Teacher originally uploaded it. Original IDs, learning records and attempts remain intact.

Teachers with only historical subject grants now see an empty catalogue until administrators configure valid batch assignments. No assignments were inferred or broadened.

## Files changed in this increment

- app/Core/Authorization.php
- app/Services/AssignmentAccessService.php
- app/Services/ManagementService.php
- app/Views/staff_dashboard.php
- app/Views/staff_reports.php
- public/media.php
- tests/batch_rollout.php
- tests/catalogue_http.php
- tests/question_import.php

The existing public/course_assignments.php interface and CourseAssignmentService validation are reused.

## Executed verification

PHP CLI suites: batch_rollout (21), global_courses (68), catalogue_http (53), centre_rbac (34), question_import (17): **193 checks passed**. Fixtures rolled back or cleaned up. The initial import test required its legacy subject-only fixture to be updated with an explicit batch tuple; it passed after that correction. PHP syntax checks passed for the six changed application PHP files.

Coverage includes subject-only denial, explicit tuple access, same-centre other-batch isolation, stale grant denial, unchanged authoring flags, revoked direct lesson/report URLs, CSV export revocation, parent deactivation, scoped assignment UI requests and upload authorization. No manual browser session was used in this increment; HTTP route tests exercised the application.
