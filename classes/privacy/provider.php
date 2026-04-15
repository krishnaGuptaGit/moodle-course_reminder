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
 * Privacy API implementation for local_course_reminder.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_reminder\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_course_reminder.
 *
 * This plugin stores reminder log records in local_course_reminder_log, which
 * links a user to a course and records when a reminder email was last sent.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Declares the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_course_reminder_log',
            [
                'userid'       => 'privacy:metadata:local_course_reminder_log:userid',
                'courseid'     => 'privacy:metadata:local_course_reminder_log:courseid',
                'remindertype' => 'privacy:metadata:local_course_reminder_log:remindertype',
                'timesent'     => 'privacy:metadata:local_course_reminder_log:timesent',
            ],
            'privacy:metadata:local_course_reminder_log'
        );

        return $collection;
    }

    /**
     * Returns the list of contexts that contain personal data for the given user.
     *
     * @param int $userid The user ID.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_course_reminder_log} l ON l.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND l.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Exports personal data for the given user in the given contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export data for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $records = $DB->get_records('local_course_reminder_log', [
                'userid'   => $userid,
                'courseid' => $context->instanceid,
            ]);

            if (empty($records)) {
                continue;
            }

            $data = [];
            foreach ($records as $record) {
                $data[] = [
                    'remindertype' => $record->remindertype,
                    'timesent'     => transform::datetime($record->timesent),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_course_reminder')],
                (object) ['reminders' => $data]
            );
        }
    }

    /**
     * Deletes all personal data for all users in the given context.
     *
     * @param \context $context The context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $DB->delete_records('local_course_reminder_log', ['courseid' => $context->instanceid]);
    }

    /**
     * Deletes personal data for the given user in the given contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete data for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $DB->delete_records('local_course_reminder_log', [
                'userid'   => $userid,
                'courseid' => $context->instanceid,
            ]);
        }
    }

    /**
     * Returns the list of users who have data in the given context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT userid FROM {local_course_reminder_log} WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Deletes personal data for a list of users in the given context.
     *
     * @param approved_userlist $userlist The approved list of users to delete data for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $inparams['courseid'] = $context->instanceid;

        $DB->delete_records_select(
            'local_course_reminder_log',
            "courseid = :courseid AND userid {$insql}",
            $inparams
        );
    }
}
