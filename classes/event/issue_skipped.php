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

namespace mod_virtualbadge\event;

/**
 * A certificate was not issued to a participant.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issue_skipped extends \core\event\base {
    /** @var array reason => description */
    protected const REASONS = [
        'noteligible' => 'the user is not enrolled, may not receive certificates or cannot access the activity',
        'noemail' => 'the user has no email address',
        'gradebelow' => 'the grade is below the minimum grade',
    ];

    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventissueskipped', 'mod_virtualbadge');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        $reason = self::REASONS[$this->other['reason']] ?? $this->other['reason'];
        return "The certificate was not issued to the user with id '$this->relateduserid' "
            . "(activity with course module id '$this->contextinstanceid'): $reason.";
    }

    /**
     * Link to the activity.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/virtualbadge/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validation.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The reason must be set in other.');
        }
    }

    /**
     * Mapping of other for backup and restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
