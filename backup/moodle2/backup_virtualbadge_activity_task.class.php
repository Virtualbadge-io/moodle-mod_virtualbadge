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
 * Backup task for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/backup_virtualbadge_stepslib.php');

/**
 * Backup task for one Virtualbadge activity.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_virtualbadge_activity_task extends backup_activity_task {
    /**
     * No specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_virtualbadge_activity_structure_step('virtualbadge_structure', 'virtualbadge.xml'));
    }

    /**
     * Encode links to this module in content.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot . '/mod/virtualbadge/', '/');
        $content = preg_replace('/(' . $base . 'index.php\?id\=)([0-9]+)/', '$@VIRTUALBADGEINDEX*$2@$', $content);
        return preg_replace('/(' . $base . 'view.php\?id\=)([0-9]+)/', '$@VIRTUALBADGEVIEWBYID*$2@$', $content);
    }
}
