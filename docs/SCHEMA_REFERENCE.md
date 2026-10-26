# Schema reference (columns the code expects)

Reference only — this is **not** a migration. Verify against your actual SQL dump and adapt code if names differ.
Use the same ID type (e.g. `BIGINT UNSIGNED`) for all foreign keys.

| Table | Columns used |
|---|---|
| roles | id, name, slug (`super_admin`, `subject_teacher`, `student`) |
| permissions | id, code (unique), description |
| role_permissions | role_id, permission_id |
| users | id, role_id, centre_id, batch_id, name, email (unique), username (unique), password_hash, status (`active`/`inactive`), preferred_language, created_at |
| batches | id, name |
| subjects | id, name, description, status (`draft`/`published`), created_at |
| modules | id, subject_id, title, description, sort_order, status, created_at |
| module_translations | module_id, language_code, title, description — **unique (module_id, language_code)** |
| content_items (lessons) | id, module_id, title, body, content_type (`lesson`), sort_order, status, created_at |
| content_item_translations | content_item_id, language_code, title, body — **unique (content_item_id, language_code)** |
| languages | id, code (unique), name, is_enabled, is_default, fallback_code, tts_locale, sort_order |
| ui_translations | id, language_code, translation_key, translation_value — **unique (language_code, translation_key)** |
| student_subjects | student_id, subject_id — unique pair |
| teacher_subjects | teacher_id, subject_id — unique pair |
| module_progress | student_id, module_id (**unique pair**), status (`in_progress`/`passed`), started_at, test_due_at, best_score, passed_at |
| content_progress | id, student_id, content_item_id (**unique pair**), status (`in_progress`/`completed`), first_opened_at, completed_at, time_spent_seconds |
| study_sessions | student_id, module_id, content_item_id, started_at, ended_at, duration_seconds |
| activity_events | id, **event_uuid (unique)**, user_id, entity_type, entity_id, action, payload (JSON/TEXT), client_timestamp, schema_version, status (`accepted`/`rejected`), created_at |
| tests | id, module_id, title, status, created_at |
| questions | id, test_id, question_text, marks |
| question_options | id, question_id, option_text, is_correct |
| test_attempts | id, test_id, student_id, started_at, submitted_at, earned_marks, total_marks, score_percent, passed, status, sync_source |
| test_attempt_answers | attempt_id, question_id, option_id (nullable), is_correct |
| notifications | user_id, title, body, is_read, created_at |
| sync_logs | user_id, event_uuid, status, message, created_at |
| audit_logs | user_id, action, entity_type, entity_id, details, ip, created_at |
| app_settings | setting_key (unique/PK), setting_value |
| attendance_sessions / attendance_records | exactly as in section 8 of the specification |

Settings keys read: `module_test_interval_days` (15), `module_test_interval_start` (`first_session`|`lesson_completion`),
`module_pass_percent` (50), `module_pass_rule` (`gte`|`gt`), `lesson_min_seconds` (30), `session_timeout_minutes` (120),
`login_max_attempts` (5), `login_lockout_minutes` (15). Missing keys fall back to the defaults shown.
