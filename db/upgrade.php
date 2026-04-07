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
 * Upgrade steps for local_course_reminder.
 *
 * @package    local_course_reminder
 * @copyright  2026 Your Organisation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin from an older version.
 *
 * @param int $oldversion The old plugin version.
 * @return bool
 */
function xmldb_local_course_reminder_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026040601) {
        // Create local_course_reminder_log table to track last reminder sent per user/course/type.
        $table = new xmldb_table('local_course_reminder_log');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remindertype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_courseid_type', XMLDB_INDEX_UNIQUE, ['userid', 'courseid', 'remindertype']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Seed the log table for all currently overdue, incomplete enrollments so that the
        // first task run after this upgrade does not send a burst of reminders to every user
        // who was already overdue before v1.4 was installed.
        $now = time();

        $studentdays = (int) get_config('local_course_reminder', 'student_days');
        if ($studentdays <= 0) {
            $studentdays = 7;
        }
        $managerdays = (int) get_config('local_course_reminder', 'manager_days');
        if ($managerdays <= 0) {
            $managerdays = 7;
        }

        // Student seed — non-completed enrollments older than student_days.
        $cutoffstudent = $now - ($studentdays * 86400);
        $sql = "SELECT DISTINCT ue.userid, e.courseid
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {user} u ON u.id = ue.userid
                LEFT JOIN {course_completions} cc
                       ON cc.userid = ue.userid AND cc.course = e.courseid AND cc.timecompleted > 0
                WHERE ue.timestart < :cutoff
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
                $rec->timesent     = $now;
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
                WHERE ue.timestart < :cutoff
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
                $rec->timesent     = $now;
                $DB->insert_record('local_course_reminder_log', $rec);
            }
        }

        upgrade_plugin_savepoint(true, 2026040601, 'local', 'course_reminder');
    }

    return true;
}
