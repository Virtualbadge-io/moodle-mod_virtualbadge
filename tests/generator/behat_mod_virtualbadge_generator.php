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
 * Behat data generator for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_virtualbadge_generator extends behat_generator_base {
    /**
     * Entities that can be created.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'issues' => [
                'singular' => 'issue',
                'datagenerator' => 'issue',
                'required' => ['virtualbadge', 'user'],
                'switchids' => ['virtualbadge' => 'virtualbadgeid', 'user' => 'userid'],
            ],
        ];
    }

    /**
     * Activity instance id from the course module idnumber.
     *
     * @param string $idnumber
     * @return int
     */
    protected function get_virtualbadge_id(string $idnumber): int {
        $cm = $this->get_cm_by_activity_name('virtualbadge', $idnumber);
        return (int) $cm->instance;
    }
}
