# Course Escalation Reminder

A Moodle local plugin that sends automated email reminders when enrolled courses are not completed within a configurable number of days. It has two independent reminder features:

- **Manager Escalation** — notifies the employee's reporting manager about incomplete courses
- **Student Reminder** — notifies the student directly when they have not completed a course (regardless of whether they have started it or not)

## Requirements

- Moodle 5.0 or later (up to 5.1)
- Course completion tracking enabled on target courses
- Custom user profile fields (required for Manager Escalation only):
  - `reporting_manager_email` — manager's email address
  - `reporting_manager_name` — manager's display name (optional, defaults to "Manager")
- The manager must exist as a Moodle user for escalation emails to be delivered

## Installation

1. Copy the `course_reminder` folder into `local/` within your Moodle installation.
2. Visit **Site administration > Notifications** to trigger the plugin installation (creates the `local_course_reminder_log` database table).
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
| Manager Reminder Days | Days after enrollment before the first escalation email is sent | 7 |
| Manager Reminder Cycle Days | Days between follow-up reminders after the first has been sent | 7 |
| Email Type | `Individual` (one email per learner) or `Consolidated` (one email per manager) | Individual |
| Email Subject/Body | Customizable templates for both email types | See below |

**Template variables — Individual:** `{coursename}`, `{username}`, `{managername}`, `{days}`, `{enrolleddays}`, `{sitename}`

> `{days}` = the configured reminder threshold; `{enrolleddays}` = the actual number of days the learner has been enrolled (useful when the learner enrolled long before the threshold was reached).

**Template variables — Consolidated:** `{managername}`, `{employeelist}`, `{sitename}`

> **HTML in templates:** Body fields accept HTML. The default templates include a generic login link `<a href="#" target="_blank">LMS</a>` — replace `#` with your actual LMS URL before enabling the plugin. Plain-text email clients automatically receive a tag-stripped fallback.

### Student Reminder

| Setting | Description | Default |
|---|---|---|
| Enable Student Reminders | Turn this feature on or off independently | Off |
| Student Reminder Days | Days after enrollment before the first reminder is sent | 7 |
| Student Reminder Cycle Days | Days between follow-up reminders after the first has been sent | 7 |
| Email Type | `Individual` (one email per course) or `Consolidated` (one email listing all incomplete courses) | Individual |
| Email Subject/Body | Customizable templates for both email types | See below |

**Template variables — Individual:** `{coursename}`, `{username}`, `{days}`, `{enrolleddays}`, `{sitename}`

> `{days}` = the configured reminder threshold; `{enrolleddays}` = the actual number of days the learner has been enrolled.

**Template variables — Consolidated:** `{username}`, `{courselist}`, `{days}`, `{sitename}`

> **HTML in templates:** Body fields accept HTML. The default templates include a generic login link `<a href="#" target="_blank">LMS</a>` — replace `#` with your actual LMS URL before enabling the plugin. Plain-text email clients automatically receive a tag-stripped fallback.

## Day Counting Rules (Exclusion-Based)

Both **Reminder Days** and **Cycle Days** use exclusion-based counting — the starting day (enrollment day or previous reminder day) is not counted.

| Scenario | Example |
|---|---|
| Reminder Days = 3, enrolled 1 Apr | First reminder sent on **4 Apr** |
| Cycle Days = 2, first reminder 4 Apr | Second reminder sent on **6 Apr** |
| Cycle Days = 1, first reminder 4 Apr | Second reminder sent on **5 Apr** (daily) |

## How It Works

1. A scheduled task runs daily at 17:00 server time.
2. If the global **Enable Plugin** setting is off, the task exits immediately.

### Manager Escalation

3. Finds all active enrollments where the enrollment date is at least **Manager Reminder Days** before today (exclusion-based).
4. For each enrollment, skips if:
   - The course does not have completion tracking enabled.
   - The learner has already completed the course.
   - The learner has no `reporting_manager_email` profile field set.
   - The manager does not exist as a Moodle user.
   - A reminder was already sent and the **Manager Reminder Cycle Days** interval has not yet elapsed.
5. Depending on **Email Type**, sends individual emails per learner or one consolidated email per manager.
6. After each send, records the timestamp in `local_course_reminder_log`. Subsequent runs use this log to enforce the cycle interval and avoid re-sending before the cycle elapses.

### Student Reminder

3. Finds all active enrollments where the enrollment date is at least **Student Reminder Days** before today (exclusion-based).
4. For each enrollment, skips if:
   - The learner has already completed the course.
   - A reminder was already sent and the **Student Reminder Cycle Days** interval has not yet elapsed.
5. Reminders are sent to **all** incomplete learners — both those who have started the course and those who have not yet opened it.
6. Depending on **Email Type**, sends one email per incomplete course or one consolidated email per student listing all courses requiring attention.
7. After each send, records the timestamp in `local_course_reminder_log`.

> **Duplicate prevention:** When a learner is enrolled in the same course via multiple enrolment methods, the course appears only once in consolidated emails.

## Performance / Scale

The task makes approximately 4–5 database queries per enrollment row processed (course completion check, completion-enabled check, manager profile field lookups, reminder log lookup). For most deployments this is not a concern, but as a guide:

- **Up to ~5,000 active incomplete enrollments per run** — fully acceptable.
- **Above ~5,000** — the total query count grows linearly. Monitor task execution time and consider contacting the plugin maintainer if runtime becomes excessive.

All JOIN conditions in the main enrollment query are covered by standard Moodle indexes. The `local_course_reminder_log` table has a unique composite index on `(userid, courseid, remindertype)`.

## File Structure

```
course_reminder/
├── classes/task/send_reminder_task.php   # Scheduled task logic
├── db/install.xml                        # Database schema (fresh installs)
├── db/tasks.php                          # Task registration (daily at 17:00)
├── db/upgrade.php                        # Database migration (existing installs)
├── lang/en/local_course_reminder.php     # Language strings
├── settings.php                          # Admin settings page
└── version.php                           # Plugin metadata
```

## License

This plugin is licensed under the [GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html).
