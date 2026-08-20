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
 * Custom admin setting — multi-select rendered as a searchable dropdown.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_reminder\admin;

/**
 * Multi-select admin setting enhanced into a searchable tag-style dropdown.
 *
 * Storage behaviour is inherited unchanged from admin_setting_configmultiselect —
 * the value is still a comma-separated string of selected option keys. The only
 * difference is presentation: Moodle's core/form-autocomplete AMD module replaces
 * the plain scrolling list box with a dropdown that has a search field and shows
 * each choice as a removable chip.
 *
 * This mirrors the core admin_setting_configselect_autocomplete pattern, which
 * provides the same enhancement for single-value selects only.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configmultiselect_autocomplete extends \admin_setting_configmultiselect {
    /** @var bool Whether the user may enter values that are not in the list. */
    protected $tags = false;

    /** @var string Name of an AMD module handling ajax lookups; empty for a static list. */
    protected $ajax = '';

    /** @var bool Whether suggestion matching is case sensitive. */
    protected $casesensitive = false;

    /** @var bool Whether the suggestion list is offered. */
    protected $showsuggestions = true;

    /**
     * Renders the multi-select field and attaches the autocomplete enhancement.
     *
     * @param array  $data  Currently selected option keys.
     * @param string $query Admin settings search query, used for highlighting.
     * @return string HTML fragment.
     */
    public function output_html($data, $query = '') {
        global $PAGE;

        $html = parent::output_html($data, $query);

        // The parent returns an empty string when there are no choices to render.
        if ($html === '') {
            return $html;
        }

        $PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', [
            '#' . $this->get_id(),
            $this->tags,
            $this->ajax,
            get_string('search'),
            $this->casesensitive,
            $this->showsuggestions,
            get_string('noselection', 'form'),
        ]);

        return $html;
    }
}
