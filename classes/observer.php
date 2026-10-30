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

namespace mod_virtualbadge;

use mod_virtualbadge\local\conditions;
use mod_virtualbadge\local\issuer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/virtualbadge/lib.php');

/**
 * Event observers.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Course completed.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;
        $userid = (int) $event->relateduserid;
        $instances = issuer::instances($event->courseid, VIRTUALBADGE_TRIGGER_COMPLETION);
        if (!$instances) {
            return;
        }
        $completed = (int) $DB->get_field(
            'course_completions',
            'timecompleted',
            ['userid' => $userid, 'course' => $event->courseid]
        );
        foreach ($instances as [$instance, $cm]) {
            issuer::queue($instance, $cm, $userid, VIRTUALBADGE_TRIGGER_COMPLETION, $completed ?: $event->timecreated);
        }
    }

    /**
     * Activity completion changed.
     *
     * Handles activities with trigger course completion that are themselves a course
     * completion criterion: they issue as soon as all other criteria are met.
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event): void {
        $instances = issuer::instances($event->courseid, VIRTUALBADGE_TRIGGER_COMPLETION);
        if (!$instances) {
            return;
        }
        $userid = (int) $event->relateduserid;
        foreach ($instances as [$instance, $cm]) {
            if ($cm->id == $event->contextinstanceid || !conditions::is_own_criterion($cm)) {
                continue;
            }
            if (conditions::course_complete_except($cm, $userid)) {
                issuer::queue($instance, $cm, $userid, VIRTUALBADGE_TRIGGER_COMPLETION, $event->timecreated);
            }
        }
    }

    /**
     * Grade changed. Evaluates the course total or the grade item chosen in the activity.
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event): void {
        global $DB;
        $instances = issuer::instances($event->courseid, VIRTUALBADGE_TRIGGER_GRADE);
        if (!$instances) {
            return;
        }
        $item = $event->get_grade()->grade_item;
        $userid = (int) $event->relateduserid;
        foreach ($instances as [$instance, $cm]) {
            $itemid = empty($instance->gradeitemid) ? null : (int) $instance->gradeitemid;
            if ($itemid ? (int) $item->id !== $itemid : $item->itemtype !== 'course') {
                continue;
            }
            $percent = conditions::course_grade_percent($event->courseid, $userid, $itemid);
            if ($percent === null || $instance->mingrade === null) {
                continue;
            }
            if ($percent >= (float) $instance->mingrade) {
                issuer::queue($instance, $cm, $userid, VIRTUALBADGE_TRIGGER_GRADE, $event->timecreated);
            } else if (
                !$DB->record_exists('virtualbadge_issues', ['virtualbadgeid' => $instance->id, 'userid' => $userid])
                && conditions::is_eligible($cm, $userid)
            ) {
                issuer::skip($cm, $userid, 'gradebelow');
            }
        }
    }
}
