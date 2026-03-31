# Changelog

All notable changes to the Course Escalation Reminder plugin will be documented in this file.

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
