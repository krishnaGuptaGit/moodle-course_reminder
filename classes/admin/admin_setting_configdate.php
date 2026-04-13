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
 * Custom admin setting — date picker (HTML5 input[type=date]).
 *
 * Stores the selected value as a YYYY-MM-DD string.
 * Leaving the field blank disables the setting (empty string stored).
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_reminder\admin;

/**
 * Admin setting that renders an HTML5 date picker.
 */
class admin_setting_configdate extends \admin_setting_configtext {

    /**
     * Constructor.
     *
     * @param string $name    Config key (e.g. 'myplugin/mysetting').
     * @param string $visiblename Localised label.
     * @param string $description Localised hint text.
     * @param string $defaultsetting Default value ('').
     */
    public function __construct($name, $visiblename, $description, $defaultsetting = '') {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_TEXT, 12);
    }

    /**
     * Validate the submitted value.
     *
     * Accepts an empty string (disables the guard) or a strict YYYY-MM-DD date.
     *
     * @param string $data Submitted value.
     * @return true|string True on success, localised error string on failure.
     */
    public function validate($data) {
        $data = trim($data);
        if ($data === '') {
            return true;
        }
        $parsed = \DateTime::createFromFormat('Y-m-d', $data);
        if (!$parsed || $parsed->format('Y-m-d') !== $data) {
            return get_string('processing_start_date_invalid', 'local_course_reminder');
        }
        return true;
    }

    /**
     * Render the HTML5 date picker input.
     *
     * @param string $data  Current saved value.
     * @param string $query Search query (for highlighting).
     * @return string HTML fragment.
     */
    public function output_html($data, $query = '') {
        $default = $this->get_defaultsetting();

        $mindate = date('Y-m-d', strtotime('-2 years'));
        $maxdate = date('Y-m-d', strtotime('+1 year'));

        $attributes = [
            'type'  => 'date',
            'id'    => $this->get_id(),
            'name'  => $this->get_full_name(),
            'class' => 'form-control',
            'style' => 'width: 170px',
            'min'   => $mindate,
            'max'   => $maxdate,
        ];
        if ((string) $data !== '') {
            $attributes['value'] = $data;
        }
        if ($this->is_readonly()) {
            $attributes['disabled'] = 'disabled';
        }

        $element = \html_writer::empty_tag('input', $attributes);

        return format_admin_setting(
            $this,
            $this->visiblename,
            $element,
            $this->description,
            true,
            '',
            $default,
            $query
        );
    }
}
