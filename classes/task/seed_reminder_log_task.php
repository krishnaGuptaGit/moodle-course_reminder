<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Adhoc task that seeds the reminder log after install or upgrade.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_reminder\task;

/**
 * Seeds local_course_reminder_log for all currently overdue incomplete enrolments.
 *
 * Queued automatically by db/install.php and db/upgrade.php so the seeding
 * runs in the background rather than blocking the install or upgrade process.
 * timesent is set to (now - cycledays) so the first post-install cron run
 * sends reminders immediately without any additional cycle delay.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seed_reminder_log_task extends \core\task\adhoc_task {
    /**
     * Executes the seed task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $now = time();

        $studentdays = (int) get_config('local_course_reminder', 'student_days');
        if ($studentdays <= 0) {
            $studentdays = 7;
        }
        $managerdays = (int) get_config('local_course_reminder', 'manager_days');
        if ($managerdays <= 0) {
            $managerdays = 7;
        }
        $studentcycledays = (int) get_config('local_course_reminder', 'student_cycledays');
        if ($studentcycledays <= 0) {
            $studentcycledays = 7;
        }
        $managercycledays = (int) get_config('local_course_reminder', 'manager_cycledays');
        if ($managercycledays <= 0) {
            $managercycledays = 7;
        }

        $studentseeded = 0;
        $managerseeded = 0;

        // Student seed — non-completed enrolments older than student_days.
        $cutoffstudent = $now - ($studentdays * 86400);
        $sql = "SELECT DISTINCT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
             LEFT JOIN {course_completions} cc
                    ON cc.userid = ue.userid AND cc.course = e.courseid AND cc.timecompleted > 0
                 WHERE COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) < :cutoff
                   AND (ue.timeend = 0 OR ue.timeend > :now)
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND cc.id IS NULL";
        $rows = $DB->get_records_sql($sql, ['cutoff' => $cutoffstudent, 'now' => $now]);
        foreach ($rows as $row) {
            $exists = $DB->record_exists('local_course_reminder_log', [
                'userid'       => $row->userid,
                'courseid'     => $row->courseid,
                'remindertype' => 'student',
            ]);
            if (!$exists) {
                $rec = new \stdClass();
                $rec->userid       = $row->userid;
                $rec->courseid     = $row->courseid;
                $rec->remindertype = 'student';
                $rec->timesent     = $now - ($studentcycledays * 86400);
                $DB->insert_record('local_course_reminder_log', $rec);
                $studentseeded++;
            }
        }

        // Manager seed — same enrolments, seeded as 'manager' type.
        $cutoffmanager = $now - ($managerdays * 86400);
        $sql = "SELECT DISTINCT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
             LEFT JOIN {course_completions} cc
                    ON cc.userid = ue.userid AND cc.course = e.courseid AND cc.timecompleted > 0
                 WHERE COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) < :cutoff
                   AND (ue.timeend = 0 OR ue.timeend > :now)
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND cc.id IS NULL";
        $rows = $DB->get_records_sql($sql, ['cutoff' => $cutoffmanager, 'now' => $now]);
        foreach ($rows as $row) {
            $exists = $DB->record_exists('local_course_reminder_log', [
                'userid'       => $row->userid,
                'courseid'     => $row->courseid,
                'remindertype' => 'manager',
            ]);
            if (!$exists) {
                $rec = new \stdClass();
                $rec->userid       = $row->userid;
                $rec->courseid     = $row->courseid;
                $rec->remindertype = 'manager';
                $rec->timesent     = $now - ($managercycledays * 86400);
                $DB->insert_record('local_course_reminder_log', $rec);
                $managerseeded++;
            }
        }

        mtrace("Reminder log seeding complete. Student records: {$studentseeded}, Manager records: {$managerseeded}.");
    }
}
