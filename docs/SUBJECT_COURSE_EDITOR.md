# Subject course selection

29 October 2026.

Subject create/edit forms now display course checkboxes. At least one course is required; multiple courses share the same subject record. Editing preselects existing active links, including retained links to an inactive course. New links to unavailable courses are rejected. Confirming removal deactivates only the course_subjects link; batch access may consequently be revoked, while content/progress/attempts remain intact. The form warns about this before removal.

Actual public subject saves use ContentImageService's transaction, including subject details, selected courses and optional cover. Missing/invalid courses roll back the entire save. Older edit clients that omit course_ids preserve existing links, but cannot save a new/unassigned subject without selecting a course. Internal ManagementService fixture/model primitives retain their existing compatibility; public create/edit routes enforce selection. Only Super Admin can change global definitions and links.

Approved existing-data assignments applied through CourseAssignmentService and read back:

| Subject | Course |
|---|---|
| Electrician | B. TECH2 |
| Welding | B. TECH2 |
| Sewing | B. TECH2 |
| Diesel Mechanic | B. TECH2 |
| COPA SUB 02 | COPA HARDWARE |

All eight existing subjects now have an active course link. Existing links were preserved; no batch/course availability, Teacher assignment or Student mapping was manufactured. No schema migration.

Changed: CourseAssignmentService::setSubjectCourses, ContentImageService subject-save integration, management.php course controls, management-forms.js client validation, ui.php asset revision and ui_hi.php labels. Tests: tests/subject_courses.php added; tests/catalogue_http.php extended.

Executed: subject_courses.php 13 checks; catalogue_http.php 68 checks; global_courses.php 68 checks: **149 passed**. PHP syntax checks passed for changed PHP files; management-forms.js passed Node --check. Multipart fixture course fields were corrected to use indexed form names before the successful HTTP rerun. Existing records were queried again after applying the five approved mappings.
