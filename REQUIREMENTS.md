# Requirements Specification

**Plugin:** `local_course_reminder` — Course Escalation Reminder
**Version:** 1.4.6 (`2026040701`)
**Type:** Moodle Local Plugin
**Last Updated:** 2026-04-07

---

## Table of Contents

1. [Purpose](#1-purpose)
2. [System Requirements](#2-system-requirements)
3. [Installation Requirements](#3-installation-requirements)
4. [Functional Requirements](#4-functional-requirements)
5. [Technical Requirements](#5-technical-requirements)
6. [Configuration Reference](#6-configuration-reference)
7. [Default Email Templates](#7-default-email-templates)
8. [Data Model](#8-data-model)
9. [File Structure](#9-file-structure)
10. [Known Limitations](#10-known-limitations)

---

## 1. Purpose

The plugin automatically sends email reminders when Moodle users have been enrolled in a course for a configurable number of days without completing it. It supports two independent reminder channels:

1. **Manager Escalation** — notifies the employee's reporting manager about the incomplete course
2. **Student Reminder** — notifies the student directly (covers both started-but-incomplete and never-started learners)

Both channels are independently configurable and controlled by a single global on/off switch. All emails are dispatched via Moodle's `email_to_user()` API, meaning they route through whatever outbound mail system Moodle is configured to use (SMTP, Moodle's built-in mailer, or a plugin such as `local_msgraph_api_mailer`).

---

## 2. System Requirements

| Requirement | Value |
|---|---|
| Moodle version | 4.4 (build `2024043000`) to 5.0 (build `2024100700`) |
| PHP version | As required by the target Moodle version |
| Database | Any Moodle-supported DB: MySQL 8+, MariaDB 10.6+, PostgreSQL 13+, MSSQL 2017+ |
| Moodle outbound email | Must be configured in Site administration → Server → Email → Outgoing mail configuration |
| Server timezone | UTC strongly recommended (`php.ini date.timezone = UTC`) |
| Custom profile fields | Required for Manager Escalation: `reporting_manager_email`, `reporting_manager_name` |

---

## 3. Installation Requirements

### Fresh Install
1. Copy the `course_reminder` folder into `{moodle_root}/local/`.
2. Navigate to **Site administration → Notifications** to trigger plugin installation.
3. Installation creates the `mdl_local_course_reminder_log` table via `db/install.xml`.
4. All settings default to disabled (off). No reminders fire until explicitly enabled.

### Upgrade from v1.3 (no log table)
`db/upgrade.php` savepoint `2026040601`:
1. Creates the `mdl_local_course_reminder_log` table.
2. Seeds it with `timesent = now()` for all currently overdue, incomplete enrollments. This prevents an email burst on the first post-upgrade cron run.

### Uninstall
Standard Moodle plugin uninstall. The `mdl_local_course_reminder_log` table and all plugin config values are deleted automatically by Moodle.

---

## 4. Functional Requirements

### 4.1 Global Control

| ID | Requirement |
|---|---|
| F-01 | The plugin must provide a master on/off switch (`enable`). When off, the scheduled task exits immediately without processing any reminders. |
| F-02 | Manager Escalation and Student Reminder must each have their own independent enable toggle (`manager_enable`, `student_enable`). |
| F-03 | Both features must operate independently — disabling one must not affect the other. |

---

### 4.2 Enrollment Eligibility

An enrollment is eligible for a reminder only when **all** of the following conditions are true:

| ID | Condition | SQL / API Check |
|---|---|---|
| F-04 | Enrollment has a recorded start date | `ue.timestart > 0` |
| F-05 | Enrollment is old enough (threshold elapsed) | `ue.timestart < :cutoffend` |
| F-06 | Enrollment has not expired | `ue.timeend = 0 OR ue.timeend > :now` |
| F-07 | User's enrolment status is active | `ue.status = 0` (`ENROL_USER_ACTIVE`) |
| F-08 | Enrolment method instance is enabled | `e.status = 0` (`ENROL_INSTANCE_ENABLED`) |
| F-09 | User account is not deleted | `u.deleted = 0` |
| F-10 | User account is not suspended | `u.suspended = 0` |
| F-11 | User account is confirmed | `u.confirmed = 1` |
| F-12 | Course is visible to students | `c.visible = 1` |
| F-13 | Course is not the Moodle site course | `c.id != 1` |
| F-14 | Course category is visible | `cc.visible = 1` |
| F-15 | Course has started (or has no start date) | `c.startdate = 0 OR c.startdate <= :nowstart` |
| F-16 | Course has not ended (or has no end date) | `c.enddate = 0 OR c.enddate > :nowend` |
| F-17 | Course has completion tracking enabled | `course.enablecompletion != 0` |
| F-18 | Learner has not already completed the course | `course_completions.timecompleted` is null or 0 |

---

### 4.3 Day Counting — First Reminder

| ID | Requirement |
|---|---|
| F-19 | The enrollment day itself must be excluded from the day count (exclusion-based formula). |
| F-20 | The first reminder fires on the Nth day after enrollment, where N equals the configured Reminder Days value and the enrollment day is day 0. |
| F-21 | Example: Reminder Days = 3, enrolled 1 Apr → first reminder fires **4 Apr**. |
| F-22 | Reminder Days of 0 or negative must fall back to the default of 7. |

**Implementation formula:**
```
cutoffend = midnight(today) − (days − 1) × 86400
Query condition: ue.timestart < cutoffend
```

---

### 4.4 Repeat Reminder Cycle

| ID | Requirement |
|---|---|
| F-23 | After the first reminder, follow-up reminders must repeat on a configurable cycle (Reminder Cycle Days). |
| F-24 | Setting Cycle Days to 1 must produce daily reminders. |
| F-25 | The day the previous reminder was sent counts as day 1 of the new cycle. |
| F-26 | Example: Cycle Days = 2, last reminder sent 4 Apr → next fires **6 Apr**. |
| F-27 | Cycle Days of 0 or negative must fall back to the default of 7. |
| F-28 | Reminders must continue repeating until the learner completes the course or is unenrolled. |

**Implementation formula:**
```
dayssince = (midnight(today) − midnight(timesent)) / 86400
Send when: dayssince >= cycledays
```

---

### 4.5 Manager Escalation — Specific Requirements

| ID | Requirement |
|---|---|
| F-29 | The manager's email must be read from the custom user profile field `reporting_manager_email`. |
| F-30 | If `reporting_manager_email` is missing or empty for a user, that enrollment must be silently skipped (counted in "Skipped — no manager"). |
| F-31 | The manager email must be validated with `validate_email()`. Invalid values are treated the same as missing. |
| F-32 | The manager must exist as a registered Moodle user (looked up via `core_user::get_user_by_email()`). If not found, the enrollment must be skipped. |
| F-33 | The manager's display name is read from the `reporting_manager_name` custom profile field. Defaults to `"Manager"` if absent or empty. |
| F-34 | **Individual mode:** one email per incomplete learner is sent to their manager. |
| F-35 | **Consolidated mode:** one summary email per manager is sent listing all their incomplete subordinates. |
| F-36 | In consolidated mode, the employee list must be sorted alphabetically by employee full name. |
| F-37 | In consolidated mode, duplicate enrollments (same manager, user, and course via different enrolment methods) must be deduplicated. Dedup key: `(manager_email, userid, courseid)`. |
| F-38 | A failed `email_to_user()` call must be logged via `mtrace()` as a Warning. The reminder log must NOT be updated on failure. |

---

### 4.6 Student Reminder — Specific Requirements

| ID | Requirement |
|---|---|
| F-39 | Reminders must target all incomplete learners — both those who have started the course and those who have never opened it. |
| F-40 | **Individual mode:** one email per incomplete course is sent to the student. |
| F-41 | **Consolidated mode:** one summary email per student is sent listing all their incomplete courses. |
| F-42 | In consolidated mode, the course list must be sorted alphabetically by course full name. |
| F-43 | In consolidated mode, duplicate enrollments (same student, same course via different enrolment methods) must be deduplicated. Dedup key: `(userid, courseid)`. |
| F-44 | Before sending, the student user object must be re-checked in memory to confirm they are not deleted or suspended (defence against race conditions between query and send time). |

---

### 4.7 Email Requirements

| ID | Requirement |
|---|---|
| F-45 | All emails must be sent using Moodle's `email_to_user()` API. |
| F-46 | Every email must include both an HTML body and a plain-text fallback. HTML body: `nl2br($message)`; plain-text: `strip_tags($message)`. |
| F-47 | Email subject and body templates must be fully configurable via the admin settings page. |
| F-48 | Body textarea fields must accept HTML (`PARAM_RAW`). Subject text fields must use `PARAM_TEXT`. |
| F-49 | Templates must support placeholder variables (listed in §4.8) that are replaced at send time using `str_replace()`. |
| F-50 | The reminder log must only be updated when `email_to_user()` returns `true`. |
| F-51 | The sending user (From) must be the Moodle no-reply user (`core_user::get_noreply_user()`). |

---

### 4.8 Template Placeholder Variables

#### Manager — Individual Email

| Variable | Resolves to |
|---|---|
| `{coursename}` | Course full name |
| `{username}` | Employee's full name (`firstname lastname`) |
| `{managername}` | Manager's display name (from profile field or `"Manager"`) |
| `{days}` | Configured Reminder Days threshold |
| `{enrolleddays}` | Actual number of days since the enrollment start date |
| `{sitename}` | Moodle site full name |

#### Manager — Consolidated Email

| Variable | Resolves to |
|---|---|
| `{managername}` | Manager's display name |
| `{employeelist}` | Numbered list: `1. EmployeeName - CourseName` (one per line, sorted by name) |
| `{sitename}` | Moodle site full name |

#### Student — Individual Email

| Variable | Resolves to |
|---|---|
| `{coursename}` | Course full name |
| `{username}` | Student's full name |
| `{days}` | Configured Reminder Days threshold |
| `{enrolleddays}` | Actual number of days since the enrollment start date |
| `{sitename}` | Moodle site full name |

#### Student — Consolidated Email

| Variable | Resolves to |
|---|---|
| `{username}` | Student's full name |
| `{courselist}` | Numbered list: `1. CourseName` (one per line, sorted alphabetically) |
| `{days}` | Configured Reminder Days threshold |
| `{sitename}` | Moodle site full name |

---

### 4.9 Observability / Logging

At the end of each task run, `mtrace()` must output a summary including:

| Counter | Description |
|---|---|
| Emails sent | Total successful `email_to_user()` calls |
| Skipped — already completed | Learner has `course_completions.timecompleted > 0` |
| Skipped — completion not enabled | `course.enablecompletion = 0` |
| Skipped — no manager | No `reporting_manager_email` profile field value |
| Skipped — manager not in Moodle | Manager email not found in `mdl_user` |
| Skipped — reminder not yet due | Cycle days not elapsed since last send |

Individual skip events with identifiable data (no manager, manager not found, send failure) must include the relevant email addresses in the `mtrace()` output for debugging.

---

## 5. Technical Requirements

### 5.1 Scheduled Task

| ID | Requirement |
|---|---|
| T-01 | Must register one Moodle scheduled task extending `\core\task\scheduled_task`. |
| T-02 | Default schedule: daily at **17:00 server time** (`minute=0, hour=17`). |
| T-03 | `blocking = 0` (non-blocking). |
| T-04 | Must use `$DB->get_recordset_sql()` for enrollment queries and call `$recordset->close()` after processing. |
| T-05 | All per-enrollment processing must be wrapped in `try/catch` so that a single bad row does not abort the entire run. |
| T-06 | Must call `mtrace()` throughout execution so progress is visible in task logs. |

---

### 5.2 Database

| ID | Requirement |
|---|---|
| T-07 | Must create `mdl_local_course_reminder_log` on fresh install via `db/install.xml`. |
| T-08 | The table must have a unique composite index on `(userid, courseid, remindertype)`. |
| T-09 | `remindertype` must be `CHAR(20)`, values: `'manager'` or `'student'`. |
| T-10 | `timesent` must be `INT(10)` Unix timestamp. |
| T-11 | On upgrade from v1.3 (savepoint `2026040601`): create the table if absent, then seed `timesent = now()` for all currently overdue incomplete enrollments to prevent email burst. |
| T-12 | Log upsert logic: `get_record()` → if exists `update_record()`, else `insert_record()`. |

---

### 5.3 Moodle API Compliance

| ID | Requirement |
|---|---|
| T-13 | Database access must use Moodle's `$DB` API exclusively (no raw PDO/mysqli). |
| T-14 | Named SQL parameters must be unique within each query (MSSQL compatibility). |
| T-15 | Email sending must use `email_to_user()`. |
| T-16 | User lookups must use `core_user::get_user()` and `core_user::get_user_by_email()`. |
| T-17 | No-reply sender must use `core_user::get_noreply_user()`. |
| T-18 | Config reads must use `get_config('local_course_reminder', $key)`. |
| T-19 | Language strings must use `get_string($key, 'local_course_reminder')`. |
| T-20 | All PHP files must include `defined('MOODLE_INTERNAL') \|\| die();`. |
| T-21 | No raw PHP curl, no direct `$_POST`/`$_GET` access, no external HTTP calls. |

---

### 5.4 Settings Page

| ID | Requirement |
|---|---|
| T-22 | Settings must be registered under `localplugins` in the Moodle admin tree. |
| T-23 | Admin path: **Site administration → Plugins → Local plugins → Course Escalation Reminder**. |
| T-24 | Settings page must be visible only to users with `$hassiteconfig`. |
| T-25 | Integer fields (`_days`, `_cycledays`) must use `PARAM_INT`. |
| T-26 | Subject fields must use `PARAM_TEXT`. |
| T-27 | Body textarea fields must use `PARAM_RAW` (HTML allowed). |
| T-28 | Email type selects must offer exactly two options: `individual` and `consolidated`. |

---

### 5.5 Prerequisite: Custom Profile Fields (Manager Escalation only)

These fields must be created manually before enabling manager escalation:

| Shortname | Type | Purpose |
|---|---|---|
| `reporting_manager_email` | Text | Manager's email address — used to look up the manager in `mdl_user` |
| `reporting_manager_name` | Text | Manager's display name — used in email templates as `{managername}` |

The plugin does not create these fields automatically. If they do not exist, the manager escalation path silently skips all enrollments.

---

## 6. Configuration Reference

All config keys under component `local_course_reminder`:

| Key | Type | Default | Valid Values | Notes |
|---|---|---|---|---|
| `enable` | checkbox | `0` | `0` or `1` | Master switch |
| `manager_enable` | checkbox | `0` | `0` or `1` | |
| `manager_days` | int | `7` | ≥ 1 | 0 falls back to 7 |
| `manager_cycledays` | int | `7` | ≥ 1 | 1 = daily; 0 falls back to 7 |
| `manager_emailtype` | select | `individual` | `individual` / `consolidated` | |
| `manager_emailsubjectindividual` | text | `Course Escalation Reminder: {coursename}` | Any text | `PARAM_TEXT` |
| `manager_emailbodyindividual` | textarea | See §7 | HTML allowed | `PARAM_RAW` |
| `manager_emailsubjectconsolidated` | text | `Course Escalation Reminder` | Any text | `PARAM_TEXT` |
| `manager_emailbodyconsolidated` | textarea | See §7 | HTML allowed | `PARAM_RAW` |
| `student_enable` | checkbox | `0` | `0` or `1` | |
| `student_days` | int | `7` | ≥ 1 | 0 falls back to 7 |
| `student_cycledays` | int | `7` | ≥ 1 | 1 = daily; 0 falls back to 7 |
| `student_emailtype` | select | `individual` | `individual` / `consolidated` | |
| `student_emailsubjectindividual` | text | `Reminder: Complete Your Course - {coursename}` | Any text | `PARAM_TEXT` |
| `student_emailbodyindividual` | textarea | See §7 | HTML allowed | `PARAM_RAW` |
| `student_emailsubjectconsolidated` | text | `Reminder: Complete Your Courses` | Any text | `PARAM_TEXT` |
| `student_emailbodyconsolidated` | textarea | See §7 | HTML allowed | `PARAM_RAW` |

---

## 7. Default Email Templates

### Manager — Individual (default body)
```
Dear {managername},

This is a reminder that {username} has been enrolled in the course "{coursename}" for {days} days but has not yet completed it.

Please follow up with the learner to ensure they complete their training.

This is an automated message from {sitename}.

Best regards,
Learning Management System
```

### Manager — Consolidated (default body)
```
Dear {managername},

The following employees have incomplete courses:

{employeelist}

Please follow up with them to ensure they complete their training.

This is an automated message from {sitename}.

Best regards,
Learning Management System
```

### Student — Individual (default body)
```
Dear {username},

The following course requires your attention:

{coursename}

The course is part of our employee training and awareness programme and contains important information relevant to your role.
Please log in to the <a href="#" target="_blank">LMS</a> and complete the course(s) at the earliest to ensure timely compliance.
If you have already completed the course, please ignore this message.

For any access-related issues, you may contact the IT support team.

Regards,
LMS Administration Team
```

### Student — Consolidated (default body)
```
Dear {username},

The following courses require your attention:

{courselist}

Each course listed above is part of our employee training and awareness programme and contains important information relevant to your role.
Please log in to the <a href="#" target="_blank">LMS</a> and complete the course(s) at the earliest to ensure timely compliance.
If you have already completed the course(s), please ignore this message.

For any access-related issues, you may contact the IT support team.

Regards,
LMS Administration Team
```

---

## 8. Data Model

### Table: `local_course_reminder_log`

Tracks the last time each reminder type was sent per (user, course) pair.

| Field | Type | Nullable | Notes |
|---|---|---|---|
| `id` | INT(10) PK | NOT NULL | Auto-increment |
| `userid` | INT(10) | NOT NULL | References `mdl_user.id` |
| `courseid` | INT(10) | NOT NULL | References `mdl_course.id` |
| `remindertype` | CHAR(20) | NOT NULL | `'manager'` or `'student'` |
| `timesent` | INT(10) | NOT NULL | Unix timestamp of last successful send |

**Indexes:**
- Primary key: `id`
- Unique index: `(userid, courseid, remindertype)`

### Moodle Tables Read (never written to by this plugin)

| Table | Fields used | Purpose |
|---|---|---|
| `mdl_user` | `id`, `email`, `firstname`, `lastname`, `deleted`, `suspended`, `confirmed` | Student and manager lookups |
| `mdl_user_enrolments` | `userid`, `enrolid`, `timestart`, `timeend`, `status` | Enrolment eligibility |
| `mdl_enrol` | `id`, `courseid`, `status` | Enrolment method status |
| `mdl_course` | `id`, `fullname`, `visible`, `startdate`, `enddate`, `enablecompletion` | Course eligibility |
| `mdl_course_categories` | `id`, `visible` | Category visibility |
| `mdl_course_completions` | `userid`, `course`, `timecompleted` | Completion check |
| `mdl_user_info_data` | `userid`, `fieldid`, `data` | Custom profile field values |
| `mdl_user_info_field` | `id`, `shortname` | Profile field ID lookup |

---

## 9. File Structure

```
course_reminder/
├── classes/
│   └── task/
│       └── send_reminder_task.php     All reminder logic (manager + student pipelines)
├── db/
│   ├── install.xml                    DB schema: local_course_reminder_log
│   ├── tasks.php                      Scheduled task registration (daily 17:00)
│   └── upgrade.php                    v1.3→v1.4 migration + log seeding
├── lang/
│   └── en/
│       └── local_course_reminder.php  All language strings
├── settings.php                       Admin settings page (all 16 config keys)
├── version.php                        Plugin metadata
├── CHANGES.md                         Version history
├── README.md                          User-facing documentation
├── REQUIREMENTS.md                    This document
└── CLAUDE.md                          Developer guidance for AI-assisted development
```

---

## 10. Known Limitations

| ID | Limitation | Impact | Workaround / Future Fix |
|---|---|---|---|
| L-01 | N+1 query pattern (~4–5 DB queries per enrollment row) | Performance degrades linearly above ~5,000 active incomplete enrollments per run | Monitor task duration; batch query optimisation is a candidate future enhancement |
| L-02 | Day boundaries use server local timezone via `strtotime('today midnight')` | Reminders may fire a day early or late during DST transitions | Set `date.timezone = UTC` in `php.ini` |
| L-03 | No Moodle capability system | Plugin performs only automated actions via cron — no user-facing pages exist | No action required; by design |
| L-04 | Manager lookup requires manually created custom profile fields | Manager escalation silently skips all enrollments if fields are absent | Create `reporting_manager_email` and `reporting_manager_name` profile fields before enabling manager escalation |
| L-05 | Email send failures cause indefinite retry each cycle | If Moodle email is misconfigured, the same reminder re-fires every cycle | Monitor task logs for `Warning:` messages; fix outbound mail configuration |
| L-06 | No per-course or per-category exclusion list | All visible, active courses with completion enabled are included | Not currently configurable; would require a future UI and additional eligibility filters |
| L-07 | `{enrolleddays}` is unavailable in consolidated templates | Consolidated email lists multiple courses with different enrolled-since dates | Use individual mode if per-course enrolled-days data is needed in the email |
