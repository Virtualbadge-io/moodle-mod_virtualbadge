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
 * Data generator for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_virtualbadge_generator extends testing_module_generator {
    /**
     * Create an activity with test defaults.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;
        $defaults = [
            'org' => 'main',
            'templateid' => 'cert_test',
            'templatename' => 'Test template',
            'fieldmap' => json_encode(['kurs' => '{course}', 'note' => '{grade}']),
            'triggertype' => 'completion',
            'mingrade' => null,
            'sendemail' => 0,
            'completionissued' => 0,
        ];
        foreach ($defaults as $name => $value) {
            if (!property_exists($record, $name)) {
                $record->$name = $value;
            }
        }
        return parent::create_instance($record, (array) $options);
    }

    /**
     * Create an issue record for a participant.
     *
     * @param array $record virtualbadgeid and userid required
     * @return stdClass
     */
    public function create_issue(array $record): stdClass {
        global $DB;
        $instance = $DB->get_record('virtualbadge', ['id' => $record['virtualbadgeid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('virtualbadge', $instance->id, 0, false, MUST_EXIST);
        $status = $record['status'] ?? \mod_virtualbadge\local\issuer::STATUS_ISSUED;
        $remote = in_array($status, ['issued', 'pending', 'revoked']);
        $issue = (object) array_merge([
            'org' => $instance->org,
            'templateid' => $instance->templateid,
            'reference' => \mod_virtualbadge\local\issuer::reference($cm->id, $record['userid']),
            'status' => $status,
            'source' => 'completion',
            'remoteid' => $remote ? 'recp_' . random_string(10) : null,
            'certificateurl' => $remote ? 'https://app.example.org/certificate?token=test' : null,
            'validationurl' => $remote ? 'https://www.example.org/validate' : null,
            'completedat' => time(),
            'attempts' => 1,
            'lasterror' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'timeissued' => $remote ? time() : null,
        ], $record);
        $issue->id = $DB->insert_record('virtualbadge_issues', $issue);
        return $issue;
    }
}
