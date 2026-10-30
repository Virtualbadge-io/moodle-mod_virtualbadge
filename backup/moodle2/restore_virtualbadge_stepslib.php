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
 * Restore structure for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_virtualbadge\local\api_client;
use mod_virtualbadge\local\config;
use mod_virtualbadge\local\fieldsources;
use mod_virtualbadge\local\issuer;

/**
 * Restores the activity and, with user data, certificates already issued in Virtualbadge.
 *
 * Issues that were not yet accepted by Virtualbadge are not restored, the condition
 * triggers them again or a trainer issues them retroactively.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_virtualbadge_activity_structure_step extends restore_activity_structure_step {
    /** @var int[] restored issues still being generated */
    protected array $pending = [];

    /**
     * Define the structure.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [new restore_path_element('virtualbadge', '/activity/virtualbadge')];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('virtualbadge_issue', '/activity/virtualbadge/issues/issue');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity. An organisation the restoring user may not choose is replaced by the one of the course.
     *
     * @param array $data
     */
    protected function process_virtualbadge($data) {
        global $DB;
        $data = (object) $data;
        $data->course = $this->get_courseid();
        $allowed = config::allowed_orgs((int) $data->course, null, (int) $this->task->get_userid());
        if (!isset($allowed[$data->org ?? ''])) {
            $data->org = config::course_org((int) $data->course);
        }
        $newid = $DB->insert_record('virtualbadge', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restore one issued certificate. Ids and links are checked as if they came from the API.
     *
     * @param array $data
     */
    protected function process_virtualbadge_issue($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        if (empty($data->remoteid) || !preg_match('/^[A-Za-z0-9_-]+$/', $data->remoteid)) {
            return;
        }
        foreach (['previousremoteid', 'replaceremoteid'] as $field) {
            if (!empty($data->$field) && !preg_match('/^[A-Za-z0-9_-]+$/', $data->$field)) {
                $data->$field = null;
            }
        }
        foreach (['certificateurl', 'validationurl', 'walleturl'] as $field) {
            $data->$field = api_client::https_url($data->$field ?? null);
        }
        $data->virtualbadgeid = $this->get_new_parentid('virtualbadge');
        $data->org = $DB->get_field('virtualbadge', 'org', ['id' => $data->virtualbadgeid]);
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $data->reference = issuer::reference($this->task->get_moduleid(), $data->userid);
        if ($DB->record_exists('virtualbadge_issues', ['reference' => $data->reference])) {
            return;
        }
        $newid = $DB->insert_record('virtualbadge_issues', $data);
        $this->set_mapping('virtualbadge_issue', $oldid, $newid);
        if ($data->status === issuer::STATUS_PENDING) {
            $this->pending[] = $newid;
        }
    }

    /**
     * Map grade items used as trigger or field source to the restored ones.
     * Field mappings with a source that does not exist here are dropped, the activity then asks for them.
     */
    protected function after_restore() {
        global $DB;
        $instance = $DB->get_record('virtualbadge', ['id' => $this->task->get_activityid()], 'id, course, gradeitemid, fieldmap');
        if (!$instance) {
            return;
        }
        $changed = false;
        if (!empty($instance->gradeitemid)) {
            $itemid = $this->grade_item_id((int) $instance->gradeitemid, (int) $instance->course) ?: null;
            $changed = $itemid !== (int) $instance->gradeitemid;
            $instance->gradeitemid = $itemid;
        }
        $map = json_decode($instance->fieldmap ?? '', true) ?: [];
        foreach ($map as $field => $entry) {
            $entry = fieldsources::normalize($entry);
            [$type, $name] = array_pad(explode(':', $entry['source'], 2), 2, '');
            if ($type === 'itempoints' || $type === 'itempercent') {
                $source = $type . ':' . $this->grade_item_id((int) $name, (int) $instance->course);
                if ($source !== $entry['source']) {
                    $entry['source'] = $source;
                    $map[$field] = $entry;
                    $changed = true;
                }
            }
            if ($entry['source'] !== fieldsources::TEXT && !fieldsources::is_valid($entry['source'], (int) $instance->course)) {
                unset($map[$field]);
                $changed = true;
            }
        }
        if ($changed) {
            $instance->fieldmap = json_encode($map, JSON_UNESCAPED_UNICODE);
            $DB->update_record('virtualbadge', $instance);
        }
    }

    /**
     * Grade item in the target course for a grade item of the backup.
     *
     * @param int $oldid
     * @param int $courseid
     * @return int 0 if the grade item is not in the target course
     */
    protected function grade_item_id(int $oldid, int $courseid): int {
        global $DB;
        $newid = $this->get_mappingid('grade_item', $oldid);
        if ($newid) {
            return (int) $newid;
        }
        return $DB->record_exists('grade_items', ['id' => $oldid, 'courseid' => $courseid]) ? $oldid : 0;
    }

    /**
     * Restore files and resume status checks.
     */
    protected function after_execute() {
        $this->add_related_files('mod_virtualbadge', 'intro', null);
        foreach ($this->pending as $issueid) {
            issuer::schedule_status_check($issueid, 0);
        }
    }
}
