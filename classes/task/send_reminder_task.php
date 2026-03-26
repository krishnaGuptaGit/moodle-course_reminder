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

namespace local_course_reminder\task;

use core\task\scheduled_task;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class send_reminder_task extends scheduled_task {

    public function get_name() {
        return get_string('taskname', 'local_course_reminder');
    }

    public function execute() {
        global $DB, $CFG;

        $enabled = get_config('local_course_reminder', 'enable');
        if (!$enabled) {
            mtrace("Course escalation reminder plugin is disabled. Exiting.");
            return;
        }

        $days = get_config('local_course_reminder', 'days');
        if (empty($days)) {
            $days = 7;
        }

        $emailtype = get_config('local_course_reminder', 'emailtype');
        if (empty($emailtype)) {
            $emailtype = 'individual';
        }

        $daysAgo = strtotime("-{$days} days");
        $today = strtotime('today');

        $sql = "SELECT ue.id, ue.userid, ue.enrolid, e.courseid, c.fullname as coursename, 
                       u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       ue.timestart
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                JOIN {user} u ON u.id = ue.userid
                WHERE ue.timestart <= :timestart
                  AND (ue.timeend = 0 OR ue.timeend > :timeend)
                  AND u.deleted = 0
                  AND u.suspended = 0";

        $params = [
            'timestart' => $daysAgo,
            'timeend' => $today
        ];

        $enrollments = $DB->get_recordset_sql($sql, $params);

        $processed = 0;
        $emailssent = 0;
        $skippedcompleted = 0;
        $skippednocompletion = 0;
        $skippednomanager = 0;
        $skippednotmoodleuser = 0;

        $pendingreminders = [];

        foreach ($enrollments as $enrollment) {
            try {
                if ($this->is_course_completed($enrollment->userid, $enrollment->courseid)) {
                    $skippedcompleted++;
                    $processed++;
                    continue;
                }

                if (!$this->is_completion_enabled($enrollment->courseid)) {
                    $skippednocompletion++;
                    $processed++;
                    continue;
                }

                $manager = $this->get_manager_data($enrollment->userid);

                if (empty($manager) || empty($manager->manager_email)) {
                    mtrace("Debug: No manager - Employee: {$enrollment->email}");
                    $skippednomanager++;
                    $processed++;
                    continue;
                }

                if ($enrollment->timestart > 0) {
                    $enrollment->enrolleddays = floor((time() - $enrollment->timestart) / 86400);
                } else {
                    $enrollment->enrolleddays = $days;
                }
                $enrollment->employeename = fullname($enrollment);

                if ($emailtype === 'consolidated') {
                    $key = $manager->manager_email;
                    if (!isset($pendingreminders[$key])) {
                        $pendingreminders[$key] = [
                            'manager' => $manager,
                            'enrollments' => []
                        ];
                    }
                    $pendingreminders[$key]['enrollments'][] = $enrollment;
                } else {
                    $result = $this->send_individual_email($manager, $enrollment, $days);
                    if ($result === 'notmoodleuser') {
                        $skippednotmoodleuser++;
                    } else {
                        $emailssent++;
                    }
                }
                $processed++;

            } catch (\Exception $e) {
                mtrace("Error processing enrollment {$enrollment->id}: " . $e->getMessage());
                $processed++;
            }
        }

        $enrollments->close();

        if ($emailtype === 'consolidated' && !empty($pendingreminders)) {
            foreach ($pendingreminders as $key => $data) {
                $manager = $data['manager'];
                $enrollmentslist = $data['enrollments'];

                usort($enrollmentslist, function($a, $b) {
                    return strcasecmp($a->employeename, $b->employeename);
                });

                $manageruser = \core_user::get_user_by_email($manager->manager_email);

                if (!$manageruser) {
                    mtrace("Debug: Manager not in Moodle - Employee emails: " . implode(', ', array_map(function($e) { return $e->email; }, $enrollmentslist)));
                    $skippednotmoodleuser += count($enrollmentslist);
                    continue;
                }

                $this->send_consolidated_email($manager, $enrollmentslist, $days);
                $emailssent++;
            }
        }

        mtrace("Course escalation reminder task completed.");
        mtrace("Total processed: {$processed}");
        mtrace("Emails sent: {$emailssent}");
        mtrace("Skipped (already completed): {$skippedcompleted}");
        mtrace("Skipped (completion not enabled): {$skippednocompletion}");
        mtrace("Skipped (no manager): {$skippednomanager}");
        mtrace("Skipped (manager not Moodle user): {$skippednotmoodleuser}");
    }

    private function is_course_completed($userid, $courseid) {
        global $DB;

        $completion = $DB->get_record('course_completions', [
            'userid' => $userid,
            'course' => $courseid
        ]);

        if ($completion && $completion->timecompleted) {
            return true;
        }

        return false;
    }

    private function is_completion_enabled($courseid) {
        global $DB;

        $enablecompletion = $DB->get_field('course', 'enablecompletion', ['id' => $courseid]);

        return !empty($enablecompletion);
    }

    private function get_manager_data($userid) {
        global $DB;

        $managerfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'reporting_manager_email']);

        if (!$managerfieldid) {
            return null;
        }

        $manageremail = $DB->get_field('user_info_data', 'data', [
            'userid' => $userid,
            'fieldid' => $managerfieldid
        ]);

        if (empty($manageremail)) {
            return null;
        }

        $managerfieldidname = $DB->get_field('user_info_field', 'id', ['shortname' => 'reporting_manager_name']);
        $managername = '';

        if ($managerfieldidname) {
            $managername = $DB->get_field('user_info_data', 'data', [
                'userid' => $userid,
                'fieldid' => $managerfieldidname
            ]);
        }

        if (!validate_email($manageremail)) {
            return null;
        }

        $manager = new stdClass();
        $manager->manager_email = $manageremail;
        $manager->manager_name = $managername ?: 'Manager';

        return $manager;
    }

    private function send_individual_email($manager, $enrollment, $days) {
        global $DB, $CFG;

        if (empty($manager->manager_email) || empty($manager->manager_name)) {
            return;
        }

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'emailsubjectindividual');
        if (empty($subjecttemplate)) {
            $subjecttemplate = 'Course Escalation Reminder: {coursename}';
        }

        $bodytemplate = get_config('local_course_reminder', 'emailbodyindividual');
        if (empty($bodytemplate)) {
            $bodytemplate = "Dear {managername},\n\nThis is a reminder that {username} has been enrolled in the course \"{coursename}\" for {days} days but has not yet completed it.\n\nPlease follow up with the learner to ensure they complete their training.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
        }

        $subject = str_replace('{coursename}', $enrollment->coursename, $subjecttemplate);
        $subject = str_replace('{username}', $enrollment->employeename, $subject);
        $subject = str_replace('{managername}', $manager->manager_name, $subject);
        $subject = str_replace('{days}', $days, $subject);
        $subject = str_replace('{sitename}', $sitename, $subject);

        $message = str_replace('{coursename}', $enrollment->coursename, $bodytemplate);
        $message = str_replace('{username}', $enrollment->employeename, $message);
        $message = str_replace('{managername}', $manager->manager_name, $message);
        $message = str_replace('{days}', $days, $message);
        $message = str_replace('{sitename}', $sitename, $message);

        $manageruser = \core_user::get_user_by_email($manager->manager_email);

        if (!$manageruser) {
            mtrace("Debug: Manager not in Moodle - Employee: {$enrollment->email}, Manager: {$manager->manager_email}");
            return 'notmoodleuser';
        }

        $noreplyuser = \core_user::get_noreply_user();

        email_to_user(
            $manageruser,
            $noreplyuser,
            $subject,
            $message,
            '',
            '',
            true
        );

        return 'sent';
    }

    private function send_consolidated_email($manager, $enrollments, $days) {
        global $DB, $CFG;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'emailsubjectconsolidated');
        if (empty($subjecttemplate)) {
            $subjecttemplate = 'Course Escalation Reminder';
        }

        $bodytemplate = get_config('local_course_reminder', 'emailbodyconsolidated');
        if (empty($bodytemplate)) {
            $bodytemplate = "Dear {managername},\n\nThe following employees have incomplete courses:\n\n{employeelist}\n\nPlease follow up with them to ensure they complete their training.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
        }

        $employeelist = '';
        $counter = 1;
        foreach ($enrollments as $enrollment) {
            $employeelist .= "{$counter}. {$enrollment->employeename} - {$enrollment->coursename} ({$enrollment->enrolleddays} days)\n";
            $counter++;
        }

        $subject = str_replace('{managername}', $manager->manager_name, $subjecttemplate);
        $subject = str_replace('{sitename}', $sitename, $subject);

        $message = str_replace('{managername}', $manager->manager_name, $bodytemplate);
        $message = str_replace('{employeelist}', $employeelist, $message);
        $message = str_replace('{sitename}', $sitename, $message);

        $manageruser = \core_user::get_user_by_email($manager->manager_email);

        $noreplyuser = \core_user::get_noreply_user();

        email_to_user(
            $manageruser,
            $noreplyuser,
            $subject,
            $message,
            '',
            '',
            true
        );
    }
}
