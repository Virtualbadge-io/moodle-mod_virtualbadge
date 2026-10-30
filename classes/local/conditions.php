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

use cm_info;
use completion_info;
use context_course;
use context_module;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/virtualbadge/lib.php');

/**
 * Conditions for issuing a certificate.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conditions {
    /**
     * Whether a user may receive the certificate of an activity.
     *
     * @param cm_info $cm
     * @param int $userid
     * @return bool
     */
    public static function is_eligible(cm_info $cm, int $userid): bool {
        if (!is_enrolled(context_course::instance($cm->course), $userid, '', true)) {
            return false;
        }
        if (!has_capability('mod/virtualbadge:receive', context_module::instance($cm->id), $userid)) {
            return false;
        }
        return get_fast_modinfo($cm->course, $userid)->get_cm($cm->id)->uservisible;
    }

    /**
     * Users with an email address who meet the condition of an activity and have no issue yet.
     *
     * @param stdClass $instance
     * @param cm_info $cm
     * @return array userid => time the condition was met
     */
    public static function pending_users(stdClass $instance, cm_info $cm): array {
        global $DB;
        $context = context_module::instance($cm->id);
        $users = get_enrolled_users($context, 'mod/virtualbadge:receive', 0, 'u.id, u.email', null, 0, 0, true);
        $users = array_filter($users, fn($user) => trim((string) $user->email) !== '');
        $issued = $DB->get_fieldset_select('virtualbadge_issues', 'userid', 'virtualbadgeid = ?', [$instance->id]);
        $candidates = array_diff(array_keys($users), $issued);
        if (!$candidates) {
            return [];
        }

        $result = [];
        if (in_array($instance->triggertype, [VIRTUALBADGE_TRIGGER_COMPLETION, VIRTUALBADGE_TRIGGER_APPROVAL])) {
            [$insql, $params] = $DB->get_in_or_equal($candidates, SQL_PARAMS_NAMED);
            $params['course'] = $cm->course;
            $result = $DB->get_records_select_menu(
                'course_completions',
                "course = :course AND timecompleted IS NOT NULL AND userid $insql",
                $params,
                '',
                'userid, timecompleted'
            );
            if (self::is_own_criterion($cm)) {
                foreach ($candidates as $userid) {
                    if (!isset($result[$userid]) && self::course_complete_except($cm, $userid)) {
                        $result[$userid] = time();
                    }
                }
            }
        } else if ($instance->triggertype === VIRTUALBADGE_TRIGGER_GRADE && $instance->mingrade !== null) {
            $itemid = empty($instance->gradeitemid) ? null : (int) $instance->gradeitemid;
            foreach ($candidates as $userid) {
                $percent = self::course_grade_percent($cm->course, $userid, $itemid);
                if ($percent !== null && $percent >= (float) $instance->mingrade) {
                    $result[$userid] = time();
                }
            }
        }
        return $result;
    }

    /**
     * Grade of a user in percent, course total or a grade item of the course.
     *
     * @param int $courseid
     * @param int $userid
     * @param int|null $itemid grade item, null for the course total
     * @return float|null
     */
    public static function course_grade_percent(int $courseid, int $userid, ?int $itemid = null): ?float {
        $points = self::course_points($courseid, $userid, $itemid);
        if ($points === null) {
            return null;
        }
        [$value, $min, $max] = $points;
        if ($max - $min <= 0) {
            return null;
        }
        return ($value - $min) / ($max - $min) * 100;
    }

    /**
     * Grade of a participant in points, course total or a grade item of the course.
     *
     * @param int $courseid
     * @param int $userid
     * @param int|null $itemid grade item, null for the course total
     * @return array|null [points, minimum, maximum], null without a grade
     */
    public static function course_points(int $courseid, int $userid, ?int $itemid = null): ?array {
        $item = $itemid
            ? \grade_item::fetch(['id' => $itemid, 'courseid' => $courseid])
            : \grade_item::fetch_course_item($courseid);
        if (!$item) {
            return null;
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || $grade->finalgrade === null) {
            return null;
        }
        $grade->grade_item = $item;
        return [(float) $grade->finalgrade, (float) $grade->get_grade_min(), (float) $grade->get_grade_max()];
    }

    /**
     * Grade items of a course except course and category totals.
     *
     * @param int $courseid
     * @return array id => name
     */
    public static function grade_items(int $courseid): array {
        $items = [];
        foreach (\grade_item::fetch_all(['courseid' => $courseid]) ?: [] as $item) {
            if (!in_array($item->itemtype, ['course', 'category'])) {
                $items[(int) $item->id] = $item->get_name();
            }
        }
        return $items;
    }

    /**
     * Whether the activity is a course completion criterion.
     *
     * @param cm_info $cm
     * @return bool
     */
    public static function is_own_criterion(cm_info $cm): bool {
        global $DB;
        return $DB->record_exists('course_completion_criteria', [
            'course' => $cm->course,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'moduleinstance' => $cm->id,
        ]);
    }

    /**
     * Whether a user meets all course completion criteria except the activity itself.
     *
     * Prevents the deadlock where the course needs the certificate activity to be
     * complete and the certificate waits for course completion.
     *
     * @param cm_info $cm
     * @param int $userid
     * @return bool
     */
    public static function course_complete_except(cm_info $cm, int $userid): bool {
        $course = get_course($cm->course);
        $info = new completion_info($course);
        if (!$info->is_enabled()) {
            return false;
        }
        $modinfo = get_fast_modinfo($course, $userid);
        $bytype = [];
        foreach ($info->get_completions($userid) as $completion) {
            $criterion = $completion->get_criteria();
            if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                if ($criterion->moduleinstance == $cm->id) {
                    continue;
                }
                $state = $info->get_data($modinfo->get_cm($criterion->moduleinstance), false, $userid)->completionstate;
                $done = in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS]);
            } else {
                $done = $completion->is_complete();
            }
            $bytype[$criterion->criteriatype][] = $done;
        }
        if (!$bytype) {
            return false;
        }
        $results = [];
        foreach ($bytype as $type => $states) {
            $any = $info->get_aggregation_method($type) == COMPLETION_AGGREGATION_ANY;
            $results[] = $any ? in_array(true, $states, true) : !in_array(false, $states, true);
        }
        $any = $info->get_aggregation_method() == COMPLETION_AGGREGATION_ANY;
        return $any ? in_array(true, $results, true) : !in_array(false, $results, true);
    }
}
