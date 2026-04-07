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
use core_user;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class send_reminder_task extends scheduled_task {

    public function get_name() {
        return get_string('taskname', 'local_course_reminder');
    }

    public function execute() {
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
            $this->process_manager_reminders();
        }

        // -----------------------------------------------------------------
        // Student reminders — independent of manager reminders above.
        // -----------------------------------------------------------------
        $studentreminderenabled = get_config('local_course_reminder', 'student_enable');
        if ($studentreminderenabled) {
            $studentdays = (int) get_config('local_course_reminder', 'student_days');
            if ($studentdays <= 0) {
                $studentdays = 7;
            }
            $this->process_student_reminders($studentdays);
        }
    }

    // -------------------------------------------------------------------------
    // Manager reminder processing
    // -------------------------------------------------------------------------

    private function process_manager_reminders() {
        global $DB;

        $days = (int) get_config('local_course_reminder', 'manager_days');
        if ($days <= 0) {
            $days = 7;
        }

        $cycledays = (int) get_config('local_course_reminder', 'manager_cycledays');
        if ($cycledays <= 0) {
            $cycledays = 7;
        }

        $emailtype = get_config('local_course_reminder', 'manager_emailtype');
        if (empty($emailtype)) {
            $emailtype = 'individual';
        }

        // Exclusion-based cutoff: enrollment day itself is not counted.
        // e.g. days=3, enrolled 1 Apr → first reminder fires 4 Apr.
        // cutoffend = midnight of (today - days + 1); timestart must be strictly before that.
        //
        // Note: strtotime('today midnight') resolves relative to the server's configured
        // timezone (php.ini date.timezone). Ensure this matches the intended day boundary
        // for your deployment. UTC is recommended to avoid DST-related day-boundary drift.
        $now           = time();
        $todaymidnight = strtotime('today midnight');
        $cutoffend     = $todaymidnight - (($days - 1) * 86400);

        $sql = "SELECT ue.id, ue.userid, ue.enrolid, e.courseid, c.fullname as coursename,
                       u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, ue.timestart
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                JOIN {course_categories} cc ON cc.id = c.category
                JOIN {user} u ON u.id = ue.userid
                WHERE ue.timestart > 0
                  AND ue.timestart < :cutoffend
                  AND (ue.timeend = 0 OR ue.timeend > :now)
                  AND ue.status = 0
                  AND e.status = 0
                  AND u.deleted = 0
                  AND u.suspended = 0
                  AND u.confirmed = 1
                  AND c.visible = 1
                  AND c.id != 1
                  AND cc.visible = 1
                  AND (c.startdate = 0 OR c.startdate <= :nowstart)
                  AND (c.enddate = 0 OR c.enddate > :nowend)";

        $enrollments = $DB->get_recordset_sql($sql, [
            'cutoffend' => $cutoffend,
            'now'       => $now,
            'nowstart'  => $now,
            'nowend'    => $now,
        ]);

        $processed = $emailssent = $skippedcompleted = 0;
        $skippednocompletion = $skippednomanager = $skippednotmoodleuser = $skippednotdue = 0;

        $pendingreminders   = [];
        $seenmanagercourses = [];

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

                $logrecord = $DB->get_record('local_course_reminder_log', [
                    'userid'       => $enrollment->userid,
                    'courseid'     => $enrollment->courseid,
                    'remindertype' => 'manager',
                ]);

                // Cycle check: fires when dayssince >= cycledays.
                // cycledays=1 → daily (fires every 1 day).
                // cycledays=2 → every 2 days. cycledays=7 → weekly.
                // e.g. cycledays=2, last reminder 4 Apr → next fires 6 Apr.
                if ($logrecord) {
                    $lastsentmidnight = strtotime('midnight', $logrecord->timesent);
                    $dayssince = (int)(($todaymidnight - $lastsentmidnight) / 86400);
                    $shouldsend = $dayssince >= $cycledays;
                } else {
                    $shouldsend = true;
                }

                if (!$shouldsend) {
                    $skippednotdue++;
                    $processed++;
                    continue;
                }

                $enrollment->enrolleddays = (int) floor((time() - $enrollment->timestart) / 86400);
                $enrollment->employeename = fullname($enrollment);
                $enrollment->logrecord    = $logrecord;

                if ($emailtype === 'consolidated') {
                    $key = $manager->manager_email;
                    // Dedup on (manager_email, userid, courseid): prevents the same employee+course
                    // appearing twice (e.g. enrolled via manual + cohort), but allows different
                    // employees enrolled in the same course to each appear in the manager's email.
                    if (isset($seenmanagercourses[$key][$enrollment->userid][$enrollment->courseid])) {
                        $processed++;
                        continue;
                    }
                    $seenmanagercourses[$key][$enrollment->userid][$enrollment->courseid] = true;

                    if (!isset($pendingreminders[$key])) {
                        $pendingreminders[$key] = ['manager' => $manager, 'enrollments' => []];
                    }
                    $pendingreminders[$key]['enrollments'][] = $enrollment;
                } else {
                    $result = $this->send_individual_email($manager, $enrollment, $days);
                    if ($result === 'notmoodleuser') {
                        $skippednotmoodleuser++;
                    } elseif ($result === 'sent') {
                        $this->upsert_log($enrollment->userid, $enrollment->courseid, 'manager', $logrecord);
                        $emailssent++;
                    } else {
                        mtrace("Warning: Failed to send manager reminder email to {$manager->manager_email} for employee {$enrollment->email}");
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
            foreach ($pendingreminders as $data) {
                $manager         = $data['manager'];
                $enrollmentslist = $data['enrollments'];

                usort($enrollmentslist, function($a, $b) {
                    return strcasecmp($a->employeename, $b->employeename);
                });

                $manageruser = core_user::get_user_by_email($manager->manager_email);

                if (!$manageruser) {
                    mtrace("Debug: Manager not in Moodle - Employee emails: " .
                        implode(', ', array_map(function($e) { return $e->email; }, $enrollmentslist)));
                    $skippednotmoodleuser += count($enrollmentslist);
                    continue;
                }

                $sent = $this->send_consolidated_email($manager, $enrollmentslist);

                if ($sent) {
                    foreach ($enrollmentslist as $enrollment) {
                        $this->upsert_log($enrollment->userid, $enrollment->courseid, 'manager', $enrollment->logrecord);
                    }
                    $emailssent++;
                }
            }
        }

        mtrace("Manager escalation reminder task completed.");
        mtrace("Total processed: {$processed}");
        mtrace("Emails sent: {$emailssent}");
        mtrace("Skipped (already completed): {$skippedcompleted}");
        mtrace("Skipped (completion not enabled): {$skippednocompletion}");
        mtrace("Skipped (no manager): {$skippednomanager}");
        mtrace("Skipped (manager not Moodle user): {$skippednotmoodleuser}");
        mtrace("Skipped (reminder not yet due): {$skippednotdue}");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function is_course_completed($userid, $courseid) {
        global $DB;

        $completion = $DB->get_record('course_completions', [
            'userid' => $userid,
            'course' => $courseid,
        ]);

        return ($completion && $completion->timecompleted);
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
            'userid'  => $userid,
            'fieldid' => $managerfieldid,
        ]);

        if (empty($manageremail)) {
            return null;
        }

        if (!validate_email($manageremail)) {
            return null;
        }

        $managerfieldidname = $DB->get_field('user_info_field', 'id', ['shortname' => 'reporting_manager_name']);
        $managername = '';

        if ($managerfieldidname) {
            $managername = $DB->get_field('user_info_data', 'data', [
                'userid'  => $userid,
                'fieldid' => $managerfieldidname,
            ]);
        }

        $manager = new stdClass();
        $manager->manager_email = $manageremail;
        $manager->manager_name  = $managername ?: 'Manager';

        return $manager;
    }

    /**
     * Upsert a reminder log record after a reminder is successfully sent.
     *
     * @param int         $userid
     * @param int         $courseid
     * @param string      $remindertype  'manager' or 'student'
     * @param object|null $logrecord     Existing DB record if any, null for first send.
     */
    private function upsert_log($userid, $courseid, $remindertype, $logrecord) {
        global $DB;

        if ($logrecord) {
            $logrecord->timesent = time();
            $DB->update_record('local_course_reminder_log', $logrecord);
        } else {
            $newrecord = new stdClass();
            $newrecord->userid       = $userid;
            $newrecord->courseid     = $courseid;
            $newrecord->remindertype = $remindertype;
            $newrecord->timesent     = time();
            $DB->insert_record('local_course_reminder_log', $newrecord);
        }
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

        $replacements = [
            '{coursename}'   => $enrollment->coursename,
            '{username}'     => $enrollment->employeename,
            '{managername}'  => $manager->manager_name,
            '{days}'         => $days,
            '{enrolleddays}' => $enrollment->enrolleddays,
            '{sitename}'     => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $manageruser = \core_user::get_user_by_email($manager->manager_email);

        if (!$manageruser) {
            mtrace("Debug: Manager not in Moodle - Employee: {$enrollment->email}, Manager: {$manager->manager_email}");
            return 'notmoodleuser';
        }

        $noreplyuser = \core_user::get_noreply_user();

        $sent = email_to_user(
            $manageruser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
            '',
            true
        );

        return $sent ? 'sent' : 'failed';
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

        $replacements = [
            '{managername}' => $manager->manager_name,
            '{employeelist}' => $employeelist,
            '{sitename}'    => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $manageruser = \core_user::get_user_by_email($manager->manager_email);
        $noreplyuser = \core_user::get_noreply_user();

        $sent = email_to_user(
            $manageruser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
            '',
            true
        );

        return $sent;
    }

    // -------------------------------------------------------------------------
    // Student reminder processing
    // -------------------------------------------------------------------------

    private function process_student_reminders($studentdays) {
        global $DB;

        $cycledays = (int) get_config('local_course_reminder', 'student_cycledays');
        if ($cycledays <= 0) {
            $cycledays = 7;
        }

        $emailtype = get_config('local_course_reminder', 'student_emailtype');
        if (empty($emailtype)) {
            $emailtype = 'individual';
        }

        // Exclusion-based cutoff: enrollment day itself is not counted.
        // e.g. days=3, enrolled 1 Apr → first reminder fires 4 Apr.
        //
        // Note: strtotime('today midnight') resolves relative to the server's configured
        // timezone (php.ini date.timezone). Ensure this matches the intended day boundary
        // for your deployment. UTC is recommended to avoid DST-related day-boundary drift.
        $now           = time();
        $todaymidnight = strtotime('today midnight');
        $cutoffend     = $todaymidnight - (($studentdays - 1) * 86400);

        $sql = "SELECT ue.id, ue.userid, ue.enrolid, e.courseid, c.fullname as coursename,
                       u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, ue.timestart
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                JOIN {course_categories} cc ON cc.id = c.category
                JOIN {user} u ON u.id = ue.userid
                WHERE ue.timestart > 0
                  AND ue.timestart < :cutoffend
                  AND (ue.timeend = 0 OR ue.timeend > :now)
                  AND ue.status = 0
                  AND e.status = 0
                  AND u.deleted = 0
                  AND u.suspended = 0
                  AND u.confirmed = 1
                  AND c.visible = 1
                  AND c.id != 1
                  AND cc.visible = 1
                  AND (c.startdate = 0 OR c.startdate <= :nowstart)
                  AND (c.enddate = 0 OR c.enddate > :nowend)";

        $params = [
            'cutoffend' => $cutoffend,
            'now'       => $now,
            'nowstart'  => $now,
            'nowend'    => $now,
        ];

        $enrollments = $DB->get_recordset_sql($sql, $params);

        $processed        = 0;
        $emailssent       = 0;
        $skippedcompleted = 0;
        $skippednocompletion = 0;
        $skippednotdue    = 0;

        // For consolidated mode: accumulate per student, tracking seen courses to avoid duplicates.
        $pendingstudentreminders = [];
        $seenstudentcourses = []; // $seenstudentcourses[$userid][$courseid] = true

        foreach ($enrollments as $enrollment) {
            try {
                // Skip students who have already completed the course.
                if ($this->is_course_completed($enrollment->userid, $enrollment->courseid)) {
                    $skippedcompleted++;
                    $processed++;
                    continue;
                }

                // Skip courses without completion tracking — there is no way to determine
                // completion, so reminders would fire indefinitely.
                if (!$this->is_completion_enabled($enrollment->courseid)) {
                    $skippednocompletion++;
                    $processed++;
                    continue;
                }

                // Reminder logic: send only if first time or cycle has elapsed.
                // Both students with zero activity AND students who started but did not finish are included.
                $logrecord = $DB->get_record('local_course_reminder_log', [
                    'userid'       => $enrollment->userid,
                    'courseid'     => $enrollment->courseid,
                    'remindertype' => 'student',
                ]);

                // Cycle check: fires when dayssince >= cycledays.
                // cycledays=1 → daily (fires every 1 day).
                // cycledays=2 → every 2 days. cycledays=7 → weekly.
                // e.g. cycledays=2, last reminder 4 Apr → next fires 6 Apr.
                if ($logrecord) {
                    $lastsentmidnight = strtotime('midnight', $logrecord->timesent);
                    $dayssince = (int)(($todaymidnight - $lastsentmidnight) / 86400);
                    $shouldsend = $dayssince >= $cycledays;
                } else {
                    $shouldsend = true;
                }

                if (!$shouldsend) {
                    $skippednotdue++;
                    $processed++;
                    continue;
                }

                $enrollment->enrolleddays = $enrollment->timestart > 0
                    ? floor((time() - $enrollment->timestart) / 86400)
                    : $studentdays;
                $enrollment->employeename = fullname($enrollment);
                $enrollment->logrecord    = $logrecord;

                $studentuser = \core_user::get_user($enrollment->userid);
                if (!$studentuser || $studentuser->deleted || $studentuser->suspended) {
                    $processed++;
                    continue;
                }

                if ($emailtype === 'consolidated') {
                    $key = $enrollment->userid;
                    // Dedup: skip if this (userid, courseid) pair was already queued.
                    if (isset($seenstudentcourses[$key][$enrollment->courseid])) {
                        $processed++;
                        continue;
                    }
                    $seenstudentcourses[$key][$enrollment->courseid] = true;

                    if (!isset($pendingstudentreminders[$key])) {
                        $pendingstudentreminders[$key] = [
                            'studentuser' => $studentuser,
                            'enrollments' => [],
                        ];
                    }
                    $pendingstudentreminders[$key]['enrollments'][] = $enrollment;
                } else {
                    $sent = $this->send_student_email($studentuser, $enrollment, $studentdays);
                    if ($sent) {
                        $this->upsert_log($enrollment->userid, $enrollment->courseid, 'student', $logrecord);
                        $emailssent++;
                    }
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
                $studentuser     = $data['studentuser'];
                $enrollmentslist = $data['enrollments'];

                usort($enrollmentslist, function($a, $b) {
                    return strcasecmp($a->coursename, $b->coursename);
                });

                $sent = $this->send_student_consolidated_email($studentuser, $enrollmentslist, $studentdays);

                if ($sent) {
                    // Log each course covered by this consolidated email.
                    foreach ($enrollmentslist as $enrollment) {
                        $this->upsert_log($enrollment->userid, $enrollment->courseid, 'student', $enrollment->logrecord);
                    }
                    $emailssent++;
                }
            }
        }

        mtrace("Student reminder task completed.");
        mtrace("Total processed: {$processed}");
        mtrace("Student emails sent: {$emailssent}");
        mtrace("Skipped (already completed): {$skippedcompleted}");
        mtrace("Skipped (completion not enabled): {$skippednocompletion}");
        mtrace("Skipped (reminder not yet due): {$skippednotdue}");
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
            $bodytemplate = "Dear {username},\n\nThis is a reminder that you have been enrolled in the course \"{coursename}\" for {days} days but have not yet completed it.\n\nPlease log in and continue your training at your earliest convenience.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
        }

        $replacements = [
            '{coursename}'   => $enrollment->coursename,
            '{username}'     => $enrollment->employeename,
            '{days}'         => $studentdays,
            '{enrolleddays}' => $enrollment->enrolleddays,
            '{sitename}'     => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $noreplyuser = \core_user::get_noreply_user();

        return email_to_user(
            $studentuser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
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

        return email_to_user(
            $studentuser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
            '',
            true
        );
    }
}
