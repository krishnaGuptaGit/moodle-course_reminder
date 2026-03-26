# Course Escalation Reminder

A Moodle local plugin that sends automated email reminders to managers when their employees have not completed enrolled courses within a configurable number of days.

## Requirements

- Moodle 4.04 or later (up to 5.00)
- Course completion tracking enabled on target courses
- Custom user profile fields:
  - `reporting_manager_email` — manager's email address
  - `reporting_manager_name` — manager's display name (optional, defaults to "Manager")
- The manager must exist as a Moodle user for emails to be delivered

## Installation

1. Copy the `course_reminder` folder into `local/` within your Moodle installation.
2. Visit **Site administration > Notifications** to trigger the plugin installation.
3. Configure the plugin under **Site administration > Plugins > Local plugins > Course escalation reminder**.

## Configuration

| Setting | Description | Default |
|---|---|---|
| Enable Plugin | Turn the reminder feature on or off | Off |
| Reminder Days | Days after enrollment before a reminder is sent | 7 |
| Email Type | `Individual` (one email per learner) or `Consolidated` (one email per manager) | Individual |
| Email Subject/Body | Customizable templates for both email types | See below |

### Template Variables

**Individual emails:** `{coursename}`, `{username}`, `{managername}`, `{days}`, `{sitename}`

**Consolidated emails:** `{managername}`, `{employeelist}`, `{sitename}`

## How It Works

1. A scheduled task runs daily at 17:00 server time.
2. It finds all active enrollments older than the configured number of days.
3. For each enrollment, it checks:
   - Whether the course has completion tracking enabled (skips if not).
   - Whether the learner has already completed the course (skips if so).
   - Whether the learner has a reporting manager assigned (skips if not).
   - Whether the manager exists as a Moodle user (skips if not).
4. Depending on the email type setting, it sends either individual emails per learner or a single consolidated email per manager listing all incomplete learners.

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
