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

namespace mod_virtualbadge\task;

use mod_virtualbadge\local\issuer;

/**
 * Queues issues for many participants at once, started by a trainer.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_issues extends \core\task\adhoc_task {
    /**
     * Queue the task.
     *
     * @param int $virtualbadgeid
     * @param int[]|null $userids selected participants, null for everyone who meets the condition
     */
    public static function schedule(int $virtualbadgeid, ?array $userids = null): void {
        $task = new self();
        $task->set_custom_data(['virtualbadgeid' => $virtualbadgeid, 'userids' => $userids]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $instance = $DB->get_record('virtualbadge', ['id' => (int) $data->virtualbadgeid]);
        if (!$instance) {
            return;
        }
        $cm = get_fast_modinfo($instance->course)->get_instances_of('virtualbadge')[$instance->id] ?? null;
        if (!$cm) {
            return;
        }
        if (isset($data->userids)) {
            $count = issuer::release_selected($instance, $cm, (array) $data->userids);
        } else {
            $count = issuer::issue_retroactively($instance, $cm);
        }
        mtrace("Queued $count certificates.");
    }
}
