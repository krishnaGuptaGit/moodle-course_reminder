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
 * Post-install hook for local_course_reminder.
 *
 * Seeds the local_course_reminder_log table for all currently overdue, incomplete
 * enrollments so that the first cron run after a fresh install does not trigger an
 * email burst. timesent is set to (now - cycledays) so reminders fire on the very
 * first cron run without any additional cycle delay.
 *
 * @package    local_course_reminder
 * @copyright  2026 Your Organisation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Post-install hook — seeds the reminder log to prevent email burst on first cron run.
 *
 * @return void
 */
function xmldb_local_course_reminder_install() {
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

    // Student seed — non-completed enrollments older than student_days.
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
        }
    }

    // Manager seed — same enrollments, seeded as 'manager' type.
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
        }
    }
}
