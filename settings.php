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

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_course_reminder', get_string('pluginname', 'local_course_reminder'));

    $ADMIN->add('localplugins', $settings);

    // Global enable/disable — master switch for all features.
    $settings->add(new admin_setting_configcheckbox(
        'local_course_reminder/enable',
        get_string('enable', 'local_course_reminder'),
        get_string('enable_desc', 'local_course_reminder'),
        0
    ));

    // -------------------------------------------------------------------------
    // Manager Escalation Settings
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_course_reminder_managersettings',
        get_string('managersettings', 'local_course_reminder'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_course_reminder/manager_enable',
        get_string('manager_enable', 'local_course_reminder'),
        get_string('manager_enable_desc', 'local_course_reminder'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/manager_days',
        get_string('manager_days', 'local_course_reminder'),
        get_string('manager_days_desc', 'local_course_reminder'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/manager_cycledays',
        get_string('manager_cycledays', 'local_course_reminder'),
        get_string('manager_cycledays_desc', 'local_course_reminder'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_course_reminder/manager_emailtype',
        get_string('manager_emailtype', 'local_course_reminder'),
        get_string('manager_emailtype_desc', 'local_course_reminder'),
        'individual',
        [
            'individual' => get_string('emailtype_individual', 'local_course_reminder'),
            'consolidated' => get_string('emailtype_consolidated', 'local_course_reminder')
        ]
    ));

    $settings->add(new admin_setting_heading(
        'local_course_reminder_manageremailsettings',
        get_string('manager_emailsettings', 'local_course_reminder'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/manager_emailsubjectindividual',
        get_string('manager_emailsubjectindividual', 'local_course_reminder'),
        get_string('manager_emailsubjectindividual_desc', 'local_course_reminder'),
        'Course Escalation Reminder: {coursename}',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_course_reminder/manager_emailbodyindividual',
        get_string('manager_emailbodyindividual', 'local_course_reminder'),
        get_string('manager_emailbodyindividual_desc', 'local_course_reminder'),
        'Dear {managername},

This is a reminder that {username} has been enrolled in the course "{coursename}" for {days} days but has not yet completed it.

Please follow up with the learner to ensure they complete their training.

This is an automated message from {sitename}.

Best regards,
Learning Management System',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/manager_emailsubjectconsolidated',
        get_string('manager_emailsubjectconsolidated', 'local_course_reminder'),
        get_string('manager_emailsubjectconsolidated_desc', 'local_course_reminder'),
        'Course Escalation Reminder',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_course_reminder/manager_emailbodyconsolidated',
        get_string('manager_emailbodyconsolidated', 'local_course_reminder'),
        get_string('manager_emailbodyconsolidated_desc', 'local_course_reminder'),
        'Dear {managername},

The following employees have incomplete courses:

{employeelist}

Please follow up with them to ensure they complete their training.

This is an automated message from {sitename}.

Best regards,
Learning Management System',
        PARAM_RAW
    ));

    // -------------------------------------------------------------------------
    // Student Reminder Settings
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_course_reminder_studentremindersettings',
        get_string('studentremindersettings', 'local_course_reminder'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_course_reminder/student_enable',
        get_string('student_enable', 'local_course_reminder'),
        get_string('student_enable_desc', 'local_course_reminder'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/student_days',
        get_string('student_days', 'local_course_reminder'),
        get_string('student_days_desc', 'local_course_reminder'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/student_cycledays',
        get_string('student_cycledays', 'local_course_reminder'),
        get_string('student_cycledays_desc', 'local_course_reminder'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_course_reminder/student_emailtype',
        get_string('student_emailtype', 'local_course_reminder'),
        get_string('student_emailtype_desc', 'local_course_reminder'),
        'individual',
        [
            'individual' => get_string('emailtype_individual', 'local_course_reminder'),
            'consolidated' => get_string('emailtype_consolidated', 'local_course_reminder')
        ]
    ));

    $settings->add(new admin_setting_heading(
        'local_course_reminder_studentemailsettings',
        get_string('student_emailsettings', 'local_course_reminder'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/student_emailsubjectindividual',
        get_string('student_emailsubjectindividual', 'local_course_reminder'),
        get_string('student_emailsubjectindividual_desc', 'local_course_reminder'),
        'Reminder: Complete Your Course - {coursename}',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_course_reminder/student_emailbodyindividual',
        get_string('student_emailbodyindividual', 'local_course_reminder'),
        get_string('student_emailbodyindividual_desc', 'local_course_reminder'),
        'Dear {username},

The following course requires your attention:

{coursename}

The course is part of our employee training and awareness programme and contains important information relevant to your role.
Please log in to the <a href="#" target="_blank">LMS</a> and complete the course(s) at the earliest to ensure timely compliance.
If you have already completed the course, please ignore this message.

For any access-related issues, you may contact the IT support team.

Regards,
LMS Administration Team',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'local_course_reminder/student_emailsubjectconsolidated',
        get_string('student_emailsubjectconsolidated', 'local_course_reminder'),
        get_string('student_emailsubjectconsolidated_desc', 'local_course_reminder'),
        'Reminder: Complete Your Courses',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_course_reminder/student_emailbodyconsolidated',
        get_string('student_emailbodyconsolidated', 'local_course_reminder'),
        get_string('student_emailbodyconsolidated_desc', 'local_course_reminder'),
        'Dear {username},

The following courses require your attention:

{courselist}

Each course listed above is part of our employee training and awareness programme and contains important information relevant to your role.
Please log in to the <a href="#" target="_blank">LMS</a> and complete the course(s) at the earliest to ensure timely compliance.
If you have already completed the course(s), please ignore this message.

For any access-related issues, you may contact the IT support team.

Regards,
LMS Administration Team',
        PARAM_RAW
    ));
}
