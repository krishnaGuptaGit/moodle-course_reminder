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
 * @copyright  2026 Krishna Gupta
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

        // Queue a background adhoc task to seed the reminder log for all currently
        // overdue incomplete enrolments. Running the seeding as an adhoc task keeps
        // the upgrade process lightweight and non-blocking.
        \core\task\manager::queue_adhoc_task(new \local_course_reminder\task\seed_reminder_log_task());

        upgrade_plugin_savepoint(true, 2026040601, 'local', 'course_reminder');
    }

    return true;
}
