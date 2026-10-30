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

declare(strict_types=1);

namespace mod_virtualbadge\completion;

use core_completion\activity_custom_completion;
use mod_virtualbadge\local\issuer;

/**
 * Custom completion rule: certificate issued.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Completion state of a rule.
     *
     * @param string $rule
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $issued = $DB->record_exists_select(
            'virtualbadge_issues',
            'virtualbadgeid = :id AND userid = :userid AND status IN (:issued, :pending)',
            [
                'id' => $this->cm->instance,
                'userid' => $this->userid,
                'issued' => issuer::STATUS_ISSUED,
                'pending' => issuer::STATUS_PENDING,
            ]
        );
        return $issued ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Rules defined by this module.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionissued'];
    }

    /**
     * Rule descriptions.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return ['completionissued' => get_string('completiondetail:issued', 'mod_virtualbadge')];
    }

    /**
     * Display order of the rules.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionissued'];
    }
}
