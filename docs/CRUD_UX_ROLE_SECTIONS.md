# CRUD screen isolation, role navigation and success feedback

Verified 29 October 2026.

## Screens and navigation

The shared management view now renders Add/Edit forms independently from listing controls and records. Read-only details use the new `?detail=<id>` route and shared record_detail view. Details display only schema-defined fields, excluding passwords, and reuse scoped reference options. Back to List and Cancel use ordinary GET links, retaining listing query filters while removing new/edit/detail parameters. Saves keep the existing POST/redirect/GET behavior.

Applies to Centres, Batches, Users and role sections, Subjects, Modules, Lessons, Tests, Questions, Teacher subject grants, centre-subject mappings and attendance CRUD. Courses keep their existing `?id=` detail / `?edit=` / `?new=` routes but stop rendering catalogue/search beneath forms or detail screens. Registration details and confirmation screens also stop before listing/search. Course assignments, Student batch assignment and media upload remain dedicated workflows rather than being converted into unrelated CRUD forms.

Generic View links were added to cards/table actions. Student learning report actions are labelled View progress to distinguish account details from learning results. Image controls, preview, compression, backend services and authorizations are retained.

## Users sidebar

Native expandable/collapsible Users group links to All users and dedicated role pages:

- student_accounts.php
- teacher_accounts.php
- centre_admins.php (Super Admin only)
- super_admins.php (Super Admin only)

Each role page reuses the existing Users view/service with a server-side fixed role filter. Add forms set the appropriate role; edits/details reject a mismatched-role record. Save requests cannot change the role through a forged role_id on these pages. Existing global Users functionality remains available. Centre Admin's existing centre scope and role restrictions remain enforced. Admin centre assignment controls are omitted from Student/Teacher role forms. Student batch filtering supports the fixed hidden Student role. No roles/accounts are duplicated and no permissions are granted.

## Success feedback

The shared management-forms.js consumes both data-success-message and flash-success notifications, deduplicates identical text, hides the duplicate inline success and renders one modal. A later success event replaces the existing modal/timer. Close and Escape remain available; the modal automatically closes after 3000 ms, clearing its timer and restoring focus. Error/validation flashes are left visible. Without JavaScript, server-rendered messages remain available.

## Changed files

app/Views/management.php, global_courses.php, content_cards.php; new record_detail.php; app/Support/ui.php; app/Config/ui_hi.php; public/assets/js/management-forms.js; public/assets/css/catalogue.css; public/registrations.php; the four new role entry pages; tests/catalogue_http.php and tests/registration_http.php.

## Verification

PHP suites actually executed: catalogue_http.php **83**, centre_rbac_http.php **54**, registration_http.php **49**, staff_crud.php **17**: **203 checks passed**. New assertions cover isolated forms/details, missing search/catalogue controls, preselected course links, separate Student/Teacher role listings and mismatched role IDs. The old registration expectation for subject-only Teacher access was corrected to the already-approved explicit batch requirement before a successful rerun.

PHP syntax checks passed across **103** app/public files; Node --check passed for management-forms.js. Browser checks completed with a disposable Super Admin and disposable Centre: Add, save, visible success modal, automatic dismissal after 3.5 seconds, Edit save, View with saved changes, and the mobile detail layout at 390 × 844. Expand/collapse Users and Teacher-only listing were checked through the sidebar. Student role Add form showed the fixed role and batch selector. Viewport restored, preview session logged out and disposable Centre/account removed. No owner records or schema were modified.

Screenshots: storage/qa/crud-success-auto.png, crud-mobile-detail.png, users-role-sidebar.png. Browser inspection covers representative workflows; every CRUD type was not manually edited. Shared route/authorization tests cover the broader surface.
