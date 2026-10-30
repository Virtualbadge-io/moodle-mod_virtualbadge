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

namespace mod_virtualbadge\local;

/**
 * A mapped template field has no value for a participant.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class field_value_missing extends \moodle_exception {
    /** @var string template field */
    public string $field;

    /**
     * Constructor.
     *
     * @param string $field template field
     * @param string $source label of the mapped source
     * @param bool $course the source belongs to the course, not the participant
     */
    public function __construct(string $field, string $source, bool $course = false) {
        $this->field = $field;
        $identifier = $course ? 'fieldvaluemissing_course' : 'fieldvaluemissing';
        parent::__construct($identifier, 'mod_virtualbadge', '', (object) ['field' => $field, 'source' => $source]);
    }
}
