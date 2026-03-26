<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_course_reminder', get_string('pluginname', 'local_course_reminder'));
    
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_course_reminder/enable',
        get_string('enable', 'local_course_reminder'),
        get_string('enable_desc', 'local_course_reminder'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/days',
        get_string('days', 'local_course_reminder'),
        get_string('days_desc', 'local_course_reminder'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_course_reminder/emailtype',
        get_string('emailtype', 'local_course_reminder'),
        get_string('emailtype_desc', 'local_course_reminder'),
        'individual',
        [
            'individual' => get_string('emailtype_individual', 'local_course_reminder'),
            'consolidated' => get_string('emailtype_consolidated', 'local_course_reminder')
        ]
    ));

    $settings->add(new admin_setting_heading(
        'local_course_reminder_emailsettings',
        get_string('emailsettings', 'local_course_reminder'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/emailsubjectindividual',
        get_string('emailsubjectindividual', 'local_course_reminder'),
        get_string('emailsubjectindividual_desc', 'local_course_reminder'),
        'Course Escalation Reminder: {coursename}',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_course_reminder/emailbodyindividual',
        get_string('emailbodyindividual', 'local_course_reminder'),
        get_string('emailbodyindividual_desc', 'local_course_reminder'),
        'Dear {managername},

This is a reminder that {username} has been enrolled in the course "{coursename}" for {days} days but has not yet completed it.

Please follow up with the learner to ensure they complete their training.

This is an automated message from {sitename}.

Best regards,
Learning Management System',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/emailsubjectconsolidated',
        get_string('emailsubjectconsolidated', 'local_course_reminder'),
        get_string('emailsubjectconsolidated_desc', 'local_course_reminder'),
        'Course Escalation Reminder',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_course_reminder/emailbodyconsolidated',
        get_string('emailbodyconsolidated', 'local_course_reminder'),
        get_string('emailbodyconsolidated_desc', 'local_course_reminder'),
        'Dear {managername},

The following employees have incomplete courses:

{employeelist}

Please follow up with them to ensure they complete their training.

This is an automated message from {sitename}.

Best regards,
Learning Management System',
        PARAM_TEXT
    ));
}
