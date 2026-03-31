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
        global $DB;

        // Global master switch — exits immediately if off.
        $enabled = get_config('local_course_reminder', 'enable');
        if (!$enabled) {
            mtrace("Course reminder plugin is disabled. Exiting.");
            return;
        }

        // -----------------------------------------------------------------
        // Manager escalation reminders
        // -----------------------------------------------------------------
        $managerenabled = get_config('local_course_reminder', 'manager_enable');
        if ($managerenabled) {
            $days = get_config('local_course_reminder', 'manager_days');
            if (empty($days)) {
                $days = 7;
            }

            $emailtype = get_config('local_course_reminder', 'manager_emailtype');
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

                    $this->send_consolidated_email($manager, $enrollmentslist);
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

        // -----------------------------------------------------------------
        // Student reminders — independent of manager reminders above.
        // -----------------------------------------------------------------
        $studentreminderenabled = get_config('local_course_reminder', 'student_enable');
        if ($studentreminderenabled) {
            $studentdays = get_config('local_course_reminder', 'student_days');
            if (empty($studentdays)) {
                $studentdays = 7;
            }
            $this->process_student_reminders($studentdays);
        }
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
        global $DB;

        if (empty($manager->manager_email) || empty($manager->manager_name)) {
            return;
        }

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'manager_emailsubjectindividual');
        if (empty($subjecttemplate)) {
            $subjecttemplate = 'Course Escalation Reminder: {coursename}';
        }

        $bodytemplate = get_config('local_course_reminder', 'manager_emailbodyindividual');
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

    private function send_consolidated_email($manager, $enrollments) {
        global $DB;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'manager_emailsubjectconsolidated');
        if (empty($subjecttemplate)) {
            $subjecttemplate = 'Course Escalation Reminder';
        }

        $bodytemplate = get_config('local_course_reminder', 'manager_emailbodyconsolidated');
        if (empty($bodytemplate)) {
            $bodytemplate = "Dear {managername},\n\nThe following employees have incomplete courses:\n\n{employeelist}\n\nPlease follow up with them to ensure they complete their training.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
        }

        $employeelist = '';
        $counter = 1;
        foreach ($enrollments as $enrollment) {
            $employeelist .= "{$counter}. {$enrollment->employeename} - {$enrollment->coursename}\n";
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

    private function process_student_reminders($studentdays) {
        global $DB;

        $emailtype = get_config('local_course_reminder', 'student_emailtype');
        if (empty($emailtype)) {
            $emailtype = 'individual';
        }

        $daysAgo = strtotime("-{$studentdays} days");
        $today = strtotime('today');

        $sql = "SELECT ue.id, ue.userid, ue.enrolid, e.courseid, c.fullname as coursename,
                       u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, ue.timestart
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                JOIN {user} u ON u.id = ue.userid
                WHERE ue.timestart <= :timestart
                  AND (ue.timeend = 0 OR ue.timeend > :timeend)
                  AND u.deleted = 0
                  AND u.suspended = 0";

        $params = ['timestart' => $daysAgo, 'timeend' => $today];
        $enrollments = $DB->get_recordset_sql($sql, $params);

        $processed = 0;
        $emailssent = 0;
        $skippedcompleted = 0;
        $skippedengaged = 0;

        $pendingstudentreminders = [];

        foreach ($enrollments as $enrollment) {
            try {
                if ($this->is_course_completed($enrollment->userid, $enrollment->courseid)) {
                    $skippedcompleted++;
                    $processed++;
                    continue;
                }

                if ($this->has_student_engaged($enrollment->userid, $enrollment->courseid, $enrollment->timestart)) {
                    $skippedengaged++;
                    $processed++;
                    continue;
                }

                $enrollment->enrolleddays = $enrollment->timestart > 0
                    ? floor((time() - $enrollment->timestart) / 86400)
                    : $studentdays;
                $enrollment->employeename = fullname($enrollment);

                $studentuser = \core_user::get_user($enrollment->userid);
                if (!$studentuser || $studentuser->deleted || $studentuser->suspended) {
                    $processed++;
                    continue;
                }

                if ($emailtype === 'consolidated') {
                    $key = $enrollment->userid;
                    if (!isset($pendingstudentreminders[$key])) {
                        $pendingstudentreminders[$key] = [
                            'studentuser' => $studentuser,
                            'enrollments' => []
                        ];
                    }
                    $pendingstudentreminders[$key]['enrollments'][] = $enrollment;
                } else {
                    $this->send_student_email($studentuser, $enrollment, $studentdays);
                    $emailssent++;
                }
                $processed++;

            } catch (\Exception $e) {
                mtrace("Error processing student reminder for enrollment {$enrollment->id}: " . $e->getMessage());
                $processed++;
            }
        }

        $enrollments->close();

        if ($emailtype === 'consolidated' && !empty($pendingstudentreminders)) {
            foreach ($pendingstudentreminders as $key => $data) {
                $studentuser = $data['studentuser'];
                $enrollmentslist = $data['enrollments'];

                usort($enrollmentslist, function($a, $b) {
                    return strcasecmp($a->coursename, $b->coursename);
                });

                $this->send_student_consolidated_email($studentuser, $enrollmentslist, $studentdays);
                $emailssent++;
            }
        }

        mtrace("Student reminder task completed.");
        mtrace("Total processed: {$processed}");
        mtrace("Student emails sent: {$emailssent}");
        mtrace("Skipped (already completed): {$skippedcompleted}");
        mtrace("Skipped (student engaged): {$skippedengaged}");
    }

    private function has_student_engaged($userid, $courseid, $enrolltime) {
        global $DB;

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('logstore_standard_log')) {
            return $DB->record_exists_select(
                'logstore_standard_log',
                'userid = :userid AND courseid = :courseid AND timecreated >= :enrolltime',
                ['userid' => $userid, 'courseid' => $courseid, 'enrolltime' => $enrolltime]
            );
        }

        // Fallback: user_lastaccess is always present in Moodle core.
        return $DB->record_exists('user_lastaccess', ['userid' => $userid, 'courseid' => $courseid]);
    }

    private function send_student_email($studentuser, $enrollment, $studentdays) {
        global $DB;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'student_emailsubjectindividual');
        if (empty($subjecttemplate)) {
            $subjecttemplate = 'Reminder: Complete Your Course - {coursename}';
        }

        $bodytemplate = get_config('local_course_reminder', 'student_emailbodyindividual');
        if (empty($bodytemplate)) {
            $bodytemplate = "Dear {username},\n\nThis is a reminder that you have been enrolled in the course \"{coursename}\" for {days} days but have not yet completed it and we have not seen any recent activity from you.\n\nPlease log in and continue your training at your earliest convenience.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
        }

        $replacements = [
            '{coursename}' => $enrollment->coursename,
            '{username}'   => $enrollment->employeename,
            '{days}'       => $studentdays,
            '{sitename}'   => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $noreplyuser = \core_user::get_noreply_user();

        email_to_user(
            $studentuser,
            $noreplyuser,
            $subject,
            $message,
            '',
            '',
            true
        );
    }

    private function send_student_consolidated_email($studentuser, $enrollments, $studentdays) {
        global $DB;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'student_emailsubjectconsolidated');
        if (empty($subjecttemplate)) {
            $subjecttemplate = 'Reminder: Complete Your Courses';
        }

        $bodytemplate = get_config('local_course_reminder', 'student_emailbodyconsolidated');
        if (empty($bodytemplate)) {
            $bodytemplate = "Dear {username},\n\nThe following courses require your attention:\n\n{courselist}\n\nPlease log in and complete your training at your earliest convenience.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
        }

        $courselist = '';
        $counter = 1;
        foreach ($enrollments as $enrollment) {
            $courselist .= "{$counter}. {$enrollment->coursename}\n";
            $counter++;
        }

        $username = !empty($enrollments) ? $enrollments[0]->employeename : fullname($studentuser);

        $replacements = [
            '{username}'   => $username,
            '{courselist}' => $courselist,
            '{days}'       => $studentdays,
            '{sitename}'   => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $noreplyuser = \core_user::get_noreply_user();

        email_to_user(
            $studentuser,
            $noreplyuser,
            $subject,
            $message,
            '',
            '',
            true
        );
    }
}
