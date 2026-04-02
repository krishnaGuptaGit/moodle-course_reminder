# Changelog

All notable changes to the Course Escalation Reminder plugin will be documented in this file.

## [1.3] - 2026-04-01

### Added
- **HTML email support** — all four email methods now send both a plain-text and an HTML body. HTML tags (e.g. `<a href="...">text</a>`) written in the body templates are rendered by the email client; plain-text clients receive a tag-stripped fallback.

### Changed
- Body textarea admin settings changed from `PARAM_TEXT` to `PARAM_RAW` so HTML content (links, tags) is accepted and saved without error.
- Default student individual email body updated to match company branding, including an Infohub link placeholder and Infohub/LMS access instructions.
- Default student consolidated email body updated:
  - Singular/plural issue resolved — "The course is part of..." rewritten as "Each course listed above is part of..." to read correctly whether one or multiple courses are listed.
  - "complete the course" and "completed the course" updated to "complete the course(s)" / "completed the course(s)".
  - Infohub link placeholder added.

## [1.2] - 2025-07-26

### Changed
- **Breaking:** Renamed manager-related config keys (`days` → `manager_days`, `emailtype` → `manager_emailtype`, `emailsubjectindividual` → `manager_emailsubjectindividual`, etc.). Previously saved settings will need to be reconfigured after upgrading.
- Global `enable` setting is now a master switch that gates both features; each feature also has its own independent enable toggle.
- Removed days count from consolidated email list entries (manager and student).

### Added
- **Global enable/disable** — single master switch that disables all reminder features when off.
- **Manager Escalation** is now a named, independently togglable feature with its own `manager_enable`, `manager_days`, `manager_emailtype`, and email templates.
- **Student Reminder** feature — sends reminder emails directly to students who have not engaged with or completed their enrolled course:
  - Independent `student_enable`, `student_days`, and `student_emailtype` settings.
  - Individual mode: one email per incomplete course with variables `{coursename}`, `{username}`, `{days}`, `{sitename}`.
  - Consolidated mode: one email per student listing all incomplete courses with variable `{courselist}`.
  - Engagement detection via `logstore_standard_log` (falls back to `user_lastaccess` if unavailable).

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
- Automatic skipping of:
  - Completed courses.
  - Courses without completion tracking enabled.
  - Learners without an assigned manager.
  - Managers who are not registered Moodle users.
- Admin settings page under Site administration > Plugins > Local plugins.
- Detailed task execution logging with counts for processed, sent, and skipped enrollments.
