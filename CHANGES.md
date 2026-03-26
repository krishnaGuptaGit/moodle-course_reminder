# Changelog

All notable changes to the Course Escalation Reminder plugin will be documented in this file.

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
