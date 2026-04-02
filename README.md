# Course Escalation Reminder

A Moodle local plugin that sends automated email reminders when enrolled courses are not completed within a configurable number of days. It has two independent reminder features:

- **Manager Escalation** — notifies the employee's reporting manager about incomplete courses
- **Student Reminder** — notifies the student directly when they have shown no activity in a course

## Requirements

- Moodle 4.04 or later (up to 5.00)
- Course completion tracking enabled on target courses
- Custom user profile fields (required for Manager Escalation):
  - `reporting_manager_email` — manager's email address
  - `reporting_manager_name` — manager's display name (optional, defaults to "Manager")
- The manager must exist as a Moodle user for escalation emails to be delivered

## Installation

1. Copy the `course_reminder` folder into `local/` within your Moodle installation.
2. Visit **Site administration > Notifications** to trigger the plugin installation.
3. Configure the plugin under **Site administration > Plugins > Local plugins > Course Escalation Reminder**.

## Configuration

### Global

| Setting | Description | Default |
|---|---|---|
| Enable Plugin | Master switch — disables all features when off | Off |

### Manager Escalation

| Setting | Description | Default |
|---|---|---|
| Enable Manager Escalation Reminders | Turn this feature on or off independently | Off |
| Manager Reminder Days | Days after enrollment before an escalation email is sent | 7 |
| Email Type | `Individual` (one email per learner) or `Consolidated` (one email per manager) | Individual |
| Email Subject/Body | Customizable templates for both email types | See below |

**Template variables — Individual:** `{coursename}`, `{username}`, `{managername}`, `{days}`, `{sitename}`

**Template variables — Consolidated:** `{managername}`, `{employeelist}`, `{sitename}`

> **HTML in templates:** Body fields accept HTML. Use standard anchor tags to add clickable links, e.g. `<a href="https://your-infohub-url">log in to the Infohub</a>`. Plain-text email clients automatically receive a tag-stripped fallback.

### Student Reminder

| Setting | Description | Default |
|---|---|---|
| Enable Student Reminders | Turn this feature on or off independently | Off |
| Student Reminder Days | Days after enrollment without activity before a reminder is sent | 7 |
| Email Type | `Individual` (one email per course) or `Consolidated` (one email listing all incomplete courses) | Individual |
| Email Subject/Body | Customizable templates for both email types | See below |

**Template variables — Individual:** `{coursename}`, `{username}`, `{days}`, `{sitename}`

**Template variables — Consolidated:** `{username}`, `{courselist}`, `{days}`, `{sitename}`

> **HTML in templates:** Body fields accept HTML. Use standard anchor tags to add clickable links, e.g. `<a href="https://your-infohub-url">log in to the Infohub</a>`. Plain-text email clients automatically receive a tag-stripped fallback.

## How It Works

1. A scheduled task runs daily at 17:00 server time.
2. If the global **Enable Plugin** setting is off, the task exits immediately.

### Manager Escalation

3. Finds all active enrollments older than the configured **Manager Reminder Days**.
4. For each enrollment, it skips if:
   - The course does not have completion tracking enabled.
   - The learner has already completed the course.
   - The learner has no `reporting_manager_email` profile field set.
   - The manager does not exist as a Moodle user.
5. Depending on the **Email Type**, sends either individual emails per learner or one consolidated email per manager listing all incomplete learners.

### Student Reminder

3. Finds all active enrollments older than the configured **Student Reminder Days**.
4. For each enrollment, it skips if:
   - The learner has already completed the course.
   - The learner has any activity recorded in `logstore_standard_log` since enrollment (falls back to `user_lastaccess` if the standard logstore is unavailable).
5. Depending on the **Email Type**, sends either one email per incomplete course or one consolidated email per student listing all courses requiring attention.

## File Structure

```
course_reminder/
├── classes/task/send_reminder_task.php   # Scheduled task logic
├── db/tasks.php                          # Task registration (daily at 17:00)
├── lang/en/local_course_reminder.php     # Language strings
├── settings.php                          # Admin settings page
└── version.php                           # Plugin metadata
```

## License

This plugin is licensed under the [GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html).
