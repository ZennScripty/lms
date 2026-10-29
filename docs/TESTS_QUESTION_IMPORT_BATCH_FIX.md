# Tests, question import and batch access delivery

Verified: 29 October 2026.

## Delivered behavior

- Published tests are visible according to authorized subject/module access, independently of whether Super Admin, Admin or Teacher created them. Draft status, release rules and explicit Teacher authoring grants still apply.
- New test and question-bank forms suggest English/Hindi text from the selected subject/module. Suggestions remain editable; custom text is preserved. Question bank uses its existing question text fields rather than adding a redundant title column.
- Question bank supports XLSX and UTF-8 CSV imports and an authenticated sample XLSX download. Select the subject/module in the application before importing.
- Student forms display a centre-filtered batch selector. Student batches management replaces public individual subject assignment controls. Assigning a batch updates canonical users.batch_id without creating individual subject enrolments.
- Approved configured-batch rollout is enabled. A canonical batch with persisted batch_subjects records controls access, including when its assignments become inactive. Stale individual grants cannot restore removed access. Batches with no subject configuration retain legacy behavior. The 27 active Students without canonical batches remain unchanged. Global strict mode stays off; no mappings were inferred.
- Shared success dialogs display successful form-save messages, manual attendance actions and test submissions. Background learning heartbeat saves do not interrupt study with dialogs.

## Main changed components

AssignmentAccessService, ManagementService, StudentService, LearningService, ContentImageService, Authorization; staff/student dispatch; student_batches and management views; student dashboard/tests; shared UI/helpers and Hindi labels; management-forms.js, attendance.js, catalogue.css and service worker.

New import service: app/Services/QuestionImportService.php. Sample: storage/templates/question-bank-sample.xlsx. Rollout utility: scripts/enable-managed-batches.php (--preview is read-only; --apply changes only the approved setting). No schema migration was needed for these fixes.

## Import safeguards

Authenticated scoped writes and CSRF are required. Imports validate subject/module relationships, exact headers, file type and limits (2 MB, 500 questions), reject formulas, macros and unsafe XML/ZIP structures, and run atomically. Duplicate English question text in the same subject/module is skipped without overwriting existing records. Missing Hindi falls back to English; no automatic translation is claimed. Uploaded workbooks are not retained. Legacy .xls is unsupported.

## Automated verification

Executed with C:/xampp/php/php.exe tests/<suite>.php:

| Suite | Passed checks |
|---|---:|
| question_import | 16 |
| batch_rollout | 14 |
| global_courses | 68 |
| catalogue_http | 49 |
| centre_rbac | 34 |
| centre_rbac_http | 54 |
| registration_rules | 26 |
| registration_http | 49 |
| staff_crud | 17 |
| multiple_subjects | 10 |
| attendance_rules | 27 |
| attendance_http | 50 |
| student_rules | 24 |
| student_http_fixture | 32 |
| run | 43 |
| service_worker.mjs (Node) | 10 |
| **Total** | **523** |

The old attendance HTTP assertion expected individual bulk subject assignment. It was updated for the new batch workflow and rerun successfully; legacy attendance fixture memberships remain isolated to the test.

Browser verification with disposable local records confirmed contextual bilingual titles, preservation of edited titles, centre-filtered Student batch options, batch-derived subjects after saving, and the visible success dialog. Disposable preview accounts/content were cleaned up. Screenshot: storage/qa/student-batch-success.png.

## Compatibility and remaining boundaries

Existing progress, test attempts and content IDs are preserved. This delivery does not enable strict access for every legacy Student. Review missing canonical batch mappings explicitly before a separate global rollout. Legacy Teacher subject catalogue compatibility remains; managed Student visibility follows explicit assignment checks and existing authoring grants.
