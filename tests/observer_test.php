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

use completion_completion;
use completion_info;
use mod_virtualbadge\local\conditions;
use mod_virtualbadge\local\issuer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for the event observers.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_virtualbadge\observer
 * @covers     \mod_virtualbadge\local\issuer
 * @covers     \mod_virtualbadge\local\conditions
 * @covers     \mod_virtualbadge\event\issue_skipped
 */
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(issuer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(conditions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_virtualbadge\event\issue_skipped::class)]
final class observer_test extends \advanced_testcase {
    /**
     * Number of queued issue tasks.
     *
     * @return int
     */
    protected function queued_tasks(): int {
        return count(\core\task\manager::get_adhoc_tasks(\mod_virtualbadge\task\issue_certificate::class));
    }

    /**
     * Mark a course as completed for a user.
     *
     * @param \stdClass $course
     * @param int $userid
     */
    protected function complete_course(\stdClass $course, int $userid): void {
        $completion = new completion_completion(['course' => $course->id, 'userid' => $userid]);
        $completion->mark_complete();
    }

    public function test_course_completion_queues_issue(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $gen->create_module('virtualbadge', ['course' => $course->id, 'triggertype' => 'manual']);

        $this->complete_course($course, $student->id);

        $issues = $DB->get_records('virtualbadge_issues');
        $this->assertCount(1, $issues);
        $issue = reset($issues);
        $this->assertEquals($instance->id, $issue->virtualbadgeid);
        $this->assertEquals($student->id, $issue->userid);
        $this->assertSame(issuer::STATUS_QUEUED, $issue->status);
        $this->assertSame(issuer::reference($instance->cmid, $student->id), $issue->reference);
        $this->assertSame(1, $this->queued_tasks());
    }

    public function test_course_completion_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);

        $this->complete_course($course, $student->id);
        $this->assertNull(issuer::queue($instance, $cm, $student->id, 'completion', time()));

        $this->assertSame(1, $DB->count_records('virtualbadge_issues'));
        $this->assertSame(1, $this->queued_tasks());
    }

    public function test_trainer_does_not_receive(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $gen->create_module('virtualbadge', ['course' => $course->id]);

        $this->complete_course($course, $teacher->id);

        $this->assertSame(0, $DB->count_records('virtualbadge_issues'));
    }

    public function test_hidden_activity_does_not_issue(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $gen->create_module('virtualbadge', ['course' => $course->id, 'visible' => 0]);

        $this->complete_course($course, $student->id);

        $this->assertSame(0, $DB->count_records('virtualbadge_issues'));
    }

    public function test_minimum_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $passed = $gen->create_and_enrol($course, 'student');
        $failed = $gen->create_and_enrol($course, 'student');
        $gen->create_module('virtualbadge', ['course' => $course->id, 'triggertype' => 'grade', 'mingrade' => 50]);
        $item = $gen->create_grade_item(['courseid' => $course->id, 'grademax' => 100]);
        $item = \grade_item::fetch(['id' => $item->id]);

        $item->update_final_grade($passed->id, 80, 'test');
        $item->update_final_grade($failed->id, 30, 'test');
        grade_regrade_final_grades($course->id);

        $issues = $DB->get_records('virtualbadge_issues', null, '', 'userid, source');
        $this->assertArrayHasKey($passed->id, $issues);
        $this->assertArrayNotHasKey($failed->id, $issues);
        $this->assertSame('grade', $issues[$passed->id]->source);
    }

    public function test_activity_as_own_completion_criterion(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $badge = $gen->create_module('virtualbadge', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionissued' => 1,
        ]);
        $config = (object) ['id' => $course->id, 'criteria_activity' => [$page->cmid => 1, $badge->cmid => 1]];
        (new \completion_criteria_activity())->update_config($config);

        $completion = new completion_info($course);
        $completion->update_state(get_fast_modinfo($course)->get_cm($page->cmid), COMPLETION_COMPLETE, $student->id);

        $issue = $DB->get_record('virtualbadge_issues', ['virtualbadgeid' => $badge->id, 'userid' => $student->id]);
        $this->assertNotFalse($issue);
        $this->assertSame('completion', $issue->source);
    }

    public function test_retroactive_issue(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $done = $gen->create_and_enrol($course, 'student');
        $gen->create_and_enrol($course, 'student');
        $this->complete_course($course, $done->id);
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);

        $this->assertEquals([$done->id], array_keys(conditions::pending_users($instance, $cm)));
        $this->assertSame(1, issuer::issue_retroactively($instance, $cm));
        $this->assertSame(0, issuer::issue_retroactively($instance, $cm));
        $this->assertSame('retroactive', $DB->get_field('virtualbadge_issues', 'source', ['userid' => $done->id]));
    }

    public function test_release_after_completion(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $done = $gen->create_and_enrol($course, 'student');
        $open = $gen->create_and_enrol($course, 'student');
        $other = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id, 'triggertype' => 'approval']);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);

        $this->complete_course($course, $done->id);
        $this->complete_course($course, $other->id);
        $completed = (int) $DB->get_field('course_completions', 'timecompleted', ['userid' => $done->id]);
        $this->assertSame(0, $DB->count_records('virtualbadge_issues'));
        $this->assertEqualsCanonicalizing([$done->id, $other->id], array_keys(conditions::pending_users($instance, $cm)));

        $this->assertNull(issuer::release($instance, $cm, $open->id));
        $issue = issuer::release($instance, $cm, $done->id);
        $this->assertSame('approval', $issue->source);
        $this->assertSame($completed, (int) $issue->completedat);
        $this->assertNull(issuer::release($instance, $cm, $done->id));

        $this->assertSame(1, issuer::issue_retroactively($instance, $cm));
        $this->assertSame('approval', $DB->get_field('virtualbadge_issues', 'source', ['userid' => $other->id]));
        $this->assertFalse($DB->record_exists('virtualbadge_issues', ['userid' => $open->id]));
    }

    public function test_grade_item_trigger(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $passed = $gen->create_and_enrol($course, 'student');
        $failed = $gen->create_and_enrol($course, 'student');
        $quiz = \grade_item::fetch(['id' => $gen->create_grade_item(['courseid' => $course->id, 'grademax' => 20])->id]);
        $other = \grade_item::fetch(['id' => $gen->create_grade_item(['courseid' => $course->id, 'grademax' => 20])->id]);
        $instance = $gen->create_module('virtualbadge', [
            'course' => $course->id,
            'triggertype' => 'grade',
            'mingrade' => 80,
            'gradeitemid' => $quiz->id,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);

        $other->update_final_grade($failed->id, 20, 'test');
        $quiz->update_final_grade($passed->id, 16, 'test');
        $quiz->update_final_grade($failed->id, 10, 'test');
        grade_regrade_final_grades($course->id);
        $this->assertEquals([$passed->id], $DB->get_fieldset_select('virtualbadge_issues', 'userid', '1 = 1'));

        $grade = \grade_grade::fetch(['itemid' => $quiz->id, 'userid' => $failed->id]);
        $grade->grade_item = $quiz;
        $sink = $this->redirectEvents();
        observer::user_graded(\core\event\user_graded::create_from_grade($grade));
        $skipped = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_virtualbadge\event\issue_skipped
        ));
        $sink->close();
        $this->assertCount(1, $skipped);
        $this->assertEquals($failed->id, $skipped[0]->relateduserid);
        $this->assertSame('gradebelow', $skipped[0]->other['reason']);
        $this->assertSame([], conditions::pending_users($instance, $cm));
    }

    public function test_no_email_is_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $DB->set_field('user', 'email', '', ['id' => $student->id]);
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $messages = $this->redirectMessages();
        $this->complete_course($course, $student->id);
        $messages->close();
        $this->assertSame(0, $DB->count_records('virtualbadge_issues'));

        $sink = $this->redirectEvents();
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $this->assertNull(issuer::queue($instance, $cm, $student->id, 'completion', 1));
        $skipped = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_virtualbadge\event\issue_skipped
        ));
        $sink->close();
        $this->assertCount(1, $skipped);
        $this->assertSame('noemail', $skipped[0]->other['reason']);
        $this->assertStringContainsString('no email address', $skipped[0]->get_description());
        $this->assertSame([], conditions::pending_users($instance, $cm));
    }

    public function test_release_selected(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        [$a, $b, $c] = [
            $gen->create_and_enrol($course, 'student'),
            $gen->create_and_enrol($course, 'student'),
            $gen->create_and_enrol($course, 'student'),
        ];
        $manual = $gen->create_module('virtualbadge', ['course' => $course->id, 'triggertype' => 'manual']);
        $approval = $gen->create_module('virtualbadge', ['course' => $course->id, 'triggertype' => 'approval']);
        $modinfo = get_fast_modinfo($course);

        $this->assertSame(2, issuer::release_selected($manual, $modinfo->get_cm($manual->cmid), [$a->id, $b->id, $b->id]));
        $this->assertSame(0, issuer::release_selected($manual, $modinfo->get_cm($manual->cmid), [$a->id]));

        $this->complete_course($course, $a->id);
        $this->complete_course($course, $c->id);
        $this->assertSame(1, issuer::release_selected($approval, $modinfo->get_cm($approval->cmid), [$a->id, $b->id]));
        $this->assertSame('approval', $DB->get_field('virtualbadge_issues', 'source', [
            'virtualbadgeid' => $approval->id, 'userid' => $a->id,
        ]));
        $this->assertFalse($DB->record_exists('virtualbadge_issues', ['virtualbadgeid' => $approval->id, 'userid' => $c->id]));
    }
}
