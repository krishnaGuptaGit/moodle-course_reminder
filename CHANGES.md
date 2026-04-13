# Changelog

All notable changes to the Course Escalation Reminder plugin will be documented in this file.

## [1.4.9] - 2026-04-08

### Fixed
- **Enrollments with `timestart = 0` now included** — many Moodle enrollment methods
  (cohort sync, self-enrolment with no start date, manual enrolment without a start
  restriction) store `timestart = 0` meaning no start restriction. The v1.4.4 filter
  `ue.timestart > 0` was silently dropping all such enrollments before any reminder logic
  ran, resulting in "Total processed: 0" even for long-overdue learners.

  Both SQL queries now use `COALESCE(NULLIF(ue.timestart, 0), ue.timecreated)` as the
  effective enrollment date. When `timestart = 0`, the enrollment record's creation
  timestamp (`timecreated`) is used instead. The reminder threshold, cycle logic, and
  `{enrolleddays}` variable are all based on this effective date.

- **SQL Server compatibility — upgrade seeding queries** — `db/upgrade.php` seeding SQL
  (both student and manager seed blocks) now uses
  `COALESCE(NULLIF(ue.timestart, 0), ue.timecreated)` in the WHERE clause, consistent
  with the main task queries. Previously `ue.timestart < :cutoff` caused all
  `timestart = 0` enrollments to be unconditionally seeded regardless of their actual
  enrollment age. All plugin SQL is now fully ANSI-compatible across MySQL, SQL Server,
  and PostgreSQL.

- **Hard-coded email fallback strings removed** — fallback email subjects and bodies in
  `send_reminder_task.php` (used when admin settings have not yet been saved) are now
  sourced via `get_string()` from the language file instead of being hardcoded PHP strings.
  This enables translation and passes Moodle plugin repository validation.

- **Language file string syntax corrected** — all `$string` assignments in
  `lang/en/local_course_reminder.php` that previously used multi-line concatenation (`.`)
  have been rewritten as single unbroken string literals, satisfying the Moodle plugin
  checker requirement for pure data-assignment lang files.

- **Copyright tags corrected** — all plugin files now carry
  `@copyright 2026 Krishna Gupta`.

### Added
- **Privacy API** (`classes/privacy/provider.php`) — implements
  `\core_privacy\local\metadata\provider`, `\core_privacy\local\request\plugin\provider`,
  and `\core_privacy\local\request\core_userlist_provider`. Declares the
  `local_course_reminder_log` table in the site privacy registry and supports data export
  and deletion per Moodle's GDPR compliance requirements.

- **Processing Start Date** — new global config setting (`processing_start_date`). Renders
  as an HTML5 date picker (selectable range: 2 years back to 1 year forward from today).
  When set, both manager and student SQL queries exclude any enrolment whose effective start
  date falls before this value. Prevents legacy enrolments on open-ended courses from being
  swept into ongoing daily reminder runs. Leave blank (default) to disable the guard and
  process all enrolments regardless of age. Invalid or blank values are handled gracefully —
  the guard is disabled and the task continues normally.

- **Reminder log seeding moved to background adhoc task** (`classes/task/seed_reminder_log_task.php`)
  — `db/install.php` and `db/upgrade.php` no longer run a site-wide enrolment scan inline.
  Instead they queue an adhoc task that seeds the `local_course_reminder_log` table in the
  background after Moodle's cron next runs. This keeps install and upgrade fast and
  non-blocking on large sites.

## [1.4.8] - 2026-04-07

### Fixed
- **Moodle plugin repository precheck resolved** — corrected all 212 PHPCS coding style errors
  and 17 warnings reported by the Moodle plugin checker:
  - Added PHPDoc file, class, and function docblocks to all files that were missing them.
  - Removed `defined('MOODLE_INTERNAL') || die()` from `classes/task/send_reminder_task.php`
    (flagged as unexpected inside a namespaced class file).
  - Replaced `elseif` with `else if` throughout (Moodle coding standard requirement).
  - Fixed all inline comments to start with a capital letter and end with `.`, `!`, or `?`.
  - Removed decorative section-divider comments (`// ---`).
  - Added space after `function` keyword in anonymous functions (`function (` not `function(`).
  - Added trailing commas after the last item in all multi-line arrays.
  - Split all strings exceeding 132 characters across multiple concatenated lines.
  - Extracted long default email body strings in `settings.php` to named variables before use.
  - Fixed `record_exists` calls in `db/upgrade.php` — multi-line array arguments extracted to
    `$exists` variables so the `if` condition is on a single line.
  - Ensured all files use LF line endings (no CRLF).

## [1.4.7] - 2026-04-07

### Changed
- **Student email default templates made generic** — the default body for both student individual and student consolidated emails no longer contains organisation-specific text. The hardcoded Infohub URL and "click on LMS link in Useful Links section" instruction have been replaced with a neutral `<a href="#" target="_blank">LMS</a>` placeholder link. Admins should replace `#` with their actual LMS URL in the admin settings before enabling the plugin. Existing saved templates are not affected — this only changes what new installations see as the pre-filled default.

## [1.4.6] - 2026-04-07

### Fixed
- **Cycle Days off-by-one corrected** — the repeat reminder cycle check now uses `>=` instead of `>`. Previously, `cycledays = 7` fired every 8 days; it now correctly fires every 7 days. `cycledays = 2` now fires every 2 days (was every 3), and so on.
- **`cycledays = 1` now enables daily reminders** — setting Reminder Cycle Days to 1 sends a follow-up reminder every day. This was not achievable with the previous `>` logic.

### Changed
- Cycle Days hint text updated to reflect `1 = daily` and corrected example dates.

## [1.4.5] - 2026-04-06

### Fixed
- **Failed manager individual email sends now logged** — when `email_to_user()` returns false for an individual manager reminder, a `Warning:` line is written to the task log so admins can detect mail delivery problems. The reminder log is intentionally not updated on failure, so the same reminder is retried on the next scheduled cycle.

### Added
- **`{enrolleddays}` variable now visible in admin UI** — the hint text for all four individual email template fields (manager subject, manager body, student subject, student body) now lists `{enrolleddays}` alongside the other available variables. `{enrolleddays}` = actual days since enrollment; `{days}` = configured reminder threshold.
- **README updated** — `{enrolleddays}` added to template variable tables; performance/scale section added documenting the per-enrollment query pattern and recommended upper bound (~5,000 active incomplete enrollments per run).

## [1.4.4] - 2026-04-06

### Fixed
- **Category visibility respected** — both reminder paths now join `course_categories` and filter `cc.visible = 1`. Courses inside hidden categories are excluded from all reminders.
- **Enrollments with `timestart = 0` excluded** — rows where `user_enrolments.timestart` is zero (no recorded start date) are now filtered out at the SQL level. Previously they fell through to a PHP fallback that substituted the configured days value, which was misleading.

### Added
- **`{enrolleddays}` template variable** — available in individual email templates (both manager and student). Resolves to the actual number of days the learner has been enrolled, as opposed to `{days}` which reflects the configured reminder threshold. Use `{enrolleddays}` when you want the email to state the real elapsed time.

### Changed
- **Timezone note documented in code** — a comment has been added near both `strtotime('today midnight')` calls explaining that day-boundary calculations depend on the server's configured timezone (`php.ini date.timezone`). UTC is recommended to avoid DST-related drift.

## [1.4.3] - 2026-04-06

### Fixed
- **Email send failures no longer advance the reminder cycle** — all four send methods (`send_individual_email`, `send_consolidated_email`, `send_student_email`, `send_student_consolidated_email`) now check the return value of `email_to_user()`. The reminder log (`local_course_reminder_log`) is only updated when the email is successfully delivered. Previously, a failed send still wrote the timestamp, causing the next reminder to be skipped for the full cycle period.
- **Student path: courses without completion tracking now skipped** — the student reminder path now checks `is_completion_enabled()` before queuing a reminder. Without completion tracking, there is no way to determine when a student finishes, so reminders would fire indefinitely.
- **Site course (id=1) excluded from both reminder paths** — the Moodle site course is no longer a candidate for reminders in either the manager or student SQL queries.

## [1.4.2] - 2026-04-06

### Added
- **Active course and active user filtering** — both manager and student reminder queries now exclude:
  - Hidden courses (`course.visible = 0`)
  - Courses that have not yet started (`course.startdate > now`)
  - Courses that have ended (`course.enddate != 0 AND course.enddate <= now`)
  - Disabled enrolment methods (`enrol.status != 0`)
  - Suspended user enrolments (`user_enrolments.status != 0`)
  - Unconfirmed user accounts (`user.confirmed = 0`)

## [1.4.1] - 2026-04-06

### Fixed
- **Manager consolidated dedup was too broad** — the dedup guard used `(manager_email, courseid)` as its key, which caused different employees enrolled in the same course under the same manager to be silently dropped from the consolidated email (only the first employee encountered was included). The key is now `(manager_email, userid, courseid)`, correctly deduplicating only the same employee enrolled via multiple methods.
- **Email burst on upgrade from v1.3** — after upgrading, the `local_course_reminder_log` table was created empty. On the first cron run, all historically overdue enrollments had no log record and therefore all fired at once. `db/upgrade.php` now seeds the log table with the current timestamp for all overdue, incomplete enrollments, so the first cycle fires after the configured cycle interval rather than immediately on upgrade day.

## [1.4] - 2026-04-06

### Fixed
- **Duplicate courses in consolidated emails** — when a learner was enrolled in the same course via multiple enrolment methods, the course appeared more than once in the consolidated email list. Fixed by deduplicating on `(userid, courseid)` before queuing, in both the manager and student consolidated paths.
- **Incorrect reminder audience for students** — students who had started but not completed a course were incorrectly skipped. The engagement-check (`logstore_standard_log`) skip has been removed; reminders are now sent to all incomplete learners regardless of activity status.
- **Reminders firing every day for all historic enrollments** — the SQL filter selected all enrollments older than N days, causing re-sends on every daily run. Scheduling is now gated by the `local_course_reminder_log` table: the first reminder fires once at the N-day mark, and subsequent reminders only fire after the configured cycle interval.

### Added
- **Reminder Cycle Days** — new configurable setting for both manager (`manager_cycledays`) and student (`student_cycledays`) paths (default: 7). After the first reminder, follow-up reminders are sent every N cycle days until the course is completed.
- **`local_course_reminder_log` database table** — tracks `(userid, courseid, remindertype, timesent)` to enforce one-time first sends and cycle-based repeat sends. Created automatically on install (`db/install.xml`) and on upgrade from v1.3 (`db/upgrade.php`).
- **Exclusion-based day counting** — both Reminder Days and Cycle Days now use exclusion-based logic. The enrollment day (or previous reminder day) is not counted. Example: Reminder Days = 3, enrolled 1 Apr → first reminder on 4 Apr; Cycle Days = 2 → second reminder on 6 Apr.

## [1.3] - 2026-04-01

### Added
- **HTML email support** — all four email methods now send both a plain-text and an HTML body. HTML tags (e.g. `<a href="...">text</a>`) written in the body templates are rendered by the email client; plain-text clients receive a tag-stripped fallback.

### Changed
- Body textarea admin settings changed from `PARAM_TEXT` to `PARAM_RAW` so HTML content (links, tags) is accepted and saved without error.
- Default student individual email body updated to match company branding, including a site link placeholder and LMS access instructions.
- Default student consolidated email body updated:
  - Singular/plural issue resolved — "The course is part of..." rewritten as "Each course listed above is part of..." to read correctly whether one or multiple courses are listed.
  - "complete the course" / "completed the course" updated to "complete the course(s)" / "completed the course(s)".
  - Site link placeholder added.

## [1.2] - 2025-07-26

### Changed
- **Breaking:** Renamed manager-related config keys (`days` → `manager_days`, `emailtype` → `manager_emailtype`, `emailsubjectindividual` → `manager_emailsubjectindividual`, etc.). Previously saved settings will need to be reconfigured after upgrading.
- Global `enable` setting is now a master switch that gates both features; each feature also has its own independent enable toggle.
- Removed days count from consolidated email list entries (manager and student).

### Added
- **Global enable/disable** — single master switch that disables all reminder features when off.
- **Manager Escalation** is now a named, independently togglable feature with its own `manager_enable`, `manager_days`, `manager_emailtype`, and email templates.
- **Student Reminder** feature — sends reminder emails directly to students who have not completed their enrolled course:
  - Independent `student_enable`, `student_days`, and `student_emailtype` settings.
  - Individual mode: one email per incomplete course with variables `{coursename}`, `{username}`, `{days}`, `{sitename}`.
  - Consolidated mode: one email per student listing all incomplete courses with variable `{courselist}`.

## [1.0] - 2025-07-26

### Added
- Initial release of the Course Escalation Reminder plugin.
- Scheduled task that runs daily at 17:00 to check for incomplete course enrollments.
- Configurable reminder threshold (default: 7 days after enrollment).
- Two email modes:
  - **Individual** — one email per learner sent to their reporting manager.
  - **Consolidated** — one summary email per manager listing all incomplete learners.
- Customizable email subject and body templates with placeholder variables.
- Manager lookup via custom user profile fields (`reporting_manager_email`, `reporting_manager_name`).
- Automatic skipping of completed courses, courses without completion tracking, learners without an assigned manager, and managers not registered as Moodle users.
- Admin settings page under Site administration > Plugins > Local plugins.
- Detailed task execution logging with counts for processed, sent, and skipped enrollments.
