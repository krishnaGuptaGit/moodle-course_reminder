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

$string['pluginname'] = 'Course escalation reminder';
$string['taskname'] = 'Send Course Escalation Reminder';
$string['enable'] = 'Enable Plugin';
$string['enable_desc'] = 'Enable or disable the course reminder feature';
$string['days'] = 'Reminder Days';
$string['days_desc'] = 'Number of days after enrollment to send reminder';
$string['emailtype'] = 'Email Type';
$string['emailtype_desc'] = 'Choose whether to send individual emails or consolidated email per manager';
$string['emailtype_individual'] = 'Individual Email';
$string['emailtype_consolidated'] = 'Consolidated Email';
$string['emailsettings'] = 'Email Settings';
$string['emailsubjectindividual'] = 'Individual Email Subject';
$string['emailsubjectindividual_desc'] = 'Available variables: {coursename}, {username}, {managername}, {days}, {sitename}';
$string['emailbodyindividual'] = 'Individual Email Body';
$string['emailbodyindividual_desc'] = 'Available variables: {coursename}, {username}, {managername}, {days}, {sitename}';
$string['emailsubjectconsolidated'] = 'Consolidated Email Subject';
$string['emailsubjectconsolidated_desc'] = 'Available variables: {managername}, {sitename}';
$string['emailbodyconsolidated'] = 'Consolidated Email Body';
$string['emailbodyconsolidated_desc'] = 'Available variables: {managername}, {employeelist}, {sitename}';
