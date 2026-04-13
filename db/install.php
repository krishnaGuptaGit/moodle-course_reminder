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
 * Queues a background adhoc task to seed the local_course_reminder_log table
 * for all currently overdue incomplete enrolments. Running the seeding as an
 * adhoc task keeps the install process lightweight and non-blocking.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Post-install hook — queues the reminder log seed adhoc task.
 *
 * @return void
 */
function xmldb_local_course_reminder_install() {
    \core\task\manager::queue_adhoc_task(new \local_course_reminder\task\seed_reminder_log_task());
}
