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
 * Language strings
 *
 * @package    local_course_reminder
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Course Escalation Reminder';
$string['taskname'] = 'Send Course Escalation Reminder';

// Global settings.
$string['enable'] = 'Enable Plugin';
$string['enable_desc'] = 'Master switch to enable or disable all course reminder features';

// Shared email type options.
$string['emailtype_individual'] = 'Individual Email';
$string['emailtype_consolidated'] = 'Consolidated Email';

// Manager Escalation Settings.
$string['managersettings'] = 'Manager Escalation Settings';
$string['manager_enable'] = 'Enable Manager Escalation Reminders';
$string['manager_enable_desc'] = 'Send reminder emails to managers when their subordinates have not completed enrolled courses';
$string['manager_days'] = 'Manager Reminder Days';
$string['manager_days_desc'] = 'Number of days after enrollment before sending an escalation reminder to the manager';
$string['manager_emailtype'] = 'Email Type';
$string['manager_emailtype_desc'] = 'Choose whether to send individual emails or a consolidated email per manager';
$string['manager_emailsettings'] = 'Manager Email Templates';
$string['manager_emailsubjectindividual'] = 'Individual Email Subject';
$string['manager_emailsubjectindividual_desc'] = 'Available variables: {coursename}, {username}, {managername}, {days}, {sitename}';
$string['manager_emailbodyindividual'] = 'Individual Email Body';
$string['manager_emailbodyindividual_desc'] = 'Available variables: {coursename}, {username}, {managername}, {days}, {sitename}';
$string['manager_emailsubjectconsolidated'] = 'Consolidated Email Subject';
$string['manager_emailsubjectconsolidated_desc'] = 'Available variables: {managername}, {sitename}';
$string['manager_emailbodyconsolidated'] = 'Consolidated Email Body';
$string['manager_emailbodyconsolidated_desc'] = 'Available variables: {managername}, {employeelist}, {sitename}';

// Student Reminder Settings.
$string['studentremindersettings'] = 'Student Reminder Settings';
$string['student_enable'] = 'Enable Student Reminders';
$string['student_enable_desc'] = 'Send reminder emails directly to students who have not engaged with or completed their enrolled course';
$string['student_days'] = 'Student Reminder Days';
$string['student_days_desc'] = 'Number of days after enrollment without activity before sending a reminder to the student';
$string['student_emailtype'] = 'Email Type';
$string['student_emailtype_desc'] = 'Choose whether to send one email per course (individual) or a single consolidated email per student listing all incomplete courses';
$string['student_emailsettings'] = 'Student Email Templates';
$string['student_emailsubjectindividual'] = 'Individual Email Subject';
$string['student_emailsubjectindividual_desc'] = 'Available variables: {coursename}, {username}, {days}, {sitename}';
$string['student_emailbodyindividual'] = 'Individual Email Body';
$string['student_emailbodyindividual_desc'] = 'Available variables: {coursename}, {username}, {days}, {sitename}';
$string['student_emailsubjectconsolidated'] = 'Consolidated Email Subject';
$string['student_emailsubjectconsolidated_desc'] = 'Available variables: {username}, {sitename}';
$string['student_emailbodyconsolidated'] = 'Consolidated Email Body';
$string['student_emailbodyconsolidated_desc'] = 'Available variables: {username}, {courselist}, {days}, {sitename}';
