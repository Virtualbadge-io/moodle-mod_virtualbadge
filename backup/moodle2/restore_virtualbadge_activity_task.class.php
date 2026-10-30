<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Restore task for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/restore_virtualbadge_stepslib.php');

/**
 * Restore task for one Virtualbadge activity.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_virtualbadge_activity_task extends restore_activity_task {
    /**
     * No specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_virtualbadge_activity_structure_step('virtualbadge_structure', 'virtualbadge.xml'));
    }

    /**
     * Contents with encoded links.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('virtualbadge', ['intro'], 'virtualbadge')];
    }

    /**
     * Rules to decode links.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('VIRTUALBADGEVIEWBYID', '/mod/virtualbadge/view.php?id=$1', 'course_module'),
            new restore_decode_rule('VIRTUALBADGEINDEX', '/mod/virtualbadge/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Legacy log rules, none.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * Legacy course log rules, none.
     *
     * @return array
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
