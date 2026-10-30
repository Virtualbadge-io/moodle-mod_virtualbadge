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

namespace mod_virtualbadge\admin;

use mod_virtualbadge\local\config;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting for the mapping of course categories to organisations.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_categorymap extends \admin_setting_configtextarea {
    /**
     * Validate category references and organisation keys.
     *
     * @param string $data
     * @return bool|string true or error message
     */
    public function validate($data) {
        global $DB;
        $orgs = config::orgs();
        $lines = array_filter(array_map('trim', preg_split('/\R/', (string) $data)));
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([^=]+)=(.+)$/', $line, $m)) {
                return get_string('categorymap_invalidline', 'mod_virtualbadge', s($line));
            }
            $category = trim($m[1]);
            $org = trim($m[2]);
            $exists = $DB->record_exists('course_categories', ['idnumber' => $category])
                || (ctype_digit($category) && $DB->record_exists('course_categories', ['id' => (int) $category]));
            if (!$exists) {
                return get_string('categorymap_unknowncategory', 'mod_virtualbadge', s($category));
            }
            if (!isset($orgs[$org])) {
                return get_string('categorymap_unknownorg', 'mod_virtualbadge', s($org));
            }
        }
        return true;
    }
}
