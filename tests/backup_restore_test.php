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

use mod_virtualbadge\local\issuer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/virtualbadge/lib.php');

/**
 * Tests for backup, restore and course reset.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_virtualbadge_activity_structure_step
 * @covers     \restore_virtualbadge_activity_structure_step
 * @covers     ::virtualbadge_reset_userdata
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_virtualbadge_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_virtualbadge_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('virtualbadge_reset_userdata')]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Issue record for a user.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param string $status
     * @param string|null $remoteid
     * @return int id
     */
    protected function add_issue(\stdClass $instance, int $userid, string $status, ?string $remoteid): int {
        global $DB;
        return $DB->insert_record('virtualbadge_issues', (object) [
            'virtualbadgeid' => $instance->id,
            'userid' => $userid,
            'org' => 'main',
            'templateid' => $instance->templateid,
            'reference' => issuer::reference($instance->cmid, $userid),
            'status' => $status,
            'source' => 'completion',
            'remoteid' => $remoteid,
            'certificateurl' => $remoteid ? 'https://app.example.org/' . $remoteid : null,
            'completedat' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
            'timeissued' => $remoteid ? time() : null,
        ]);
    }

    public function test_duplicate_keeps_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $cfgen = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgen->create_category(['component' => 'core_course', 'area' => 'course', 'itemid' => 0]);
        $cfgen->create_field(['categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'ort', 'name' => 'Ort']);
        $course = $this->getDataGenerator()->create_course();
        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $course->id, 'itemname' => 'Abschlusstest']);
        $fieldmap = json_encode([
            'Ort' => ['source' => 'coursefield:ort', 'value' => ''],
            'Punkte' => ['source' => 'itempoints:' . $item->id, 'value' => ''],
        ]);
        $instance = $this->getDataGenerator()->create_module('virtualbadge', [
            'course' => $course->id,
            'templateid' => 'cert_zpp',
            'templatename' => 'Zentrale Prüfstelle',
            'fieldmap' => $fieldmap,
            'triggertype' => 'grade',
            'mingrade' => 70,
            'gradeitemid' => $item->id,
            'sendemail' => 1,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $modern = class_exists(\core_courseformat\local\cmactions::class)
            && method_exists(\core_courseformat\local\cmactions::class, 'duplicate');
        if ($modern) {
            $newcm = (new \core_courseformat\local\cmactions($course))->duplicate($cm->id);
        } else {
            $newcm = duplicate_module($course, $cm);
        }

        $copy = $DB->get_record('virtualbadge', ['id' => $newcm->instance]);
        $this->assertSame('cert_zpp', $copy->templateid);
        $this->assertSame('Zentrale Prüfstelle', $copy->templatename);
        $this->assertSame($fieldmap, $copy->fieldmap);
        $this->assertSame('grade', $copy->triggertype);
        $this->assertEquals(70, $copy->mingrade);
        $this->assertEquals($item->id, $copy->gradeitemid);
        $this->assertEquals(1, $copy->sendemail);
    }

    /**
     * Back up a course and restore it as a new course.
     *
     * @param \stdClass $course
     * @param bool $users
     * @param int|null $restorer user who restores, default the current user
     * @return int id of the new course
     */
    protected function backup_and_restore(\stdClass $course, bool $users, ?int $restorer = null): int {
        global $DB, $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupid = 'virtualbadge_' . random_string(8);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupid));
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED' . random_string(4), $course->category);
        if ($restorer) {
            $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
            role_assign($role, $restorer, \context_course::instance($newcourseid));
        }
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $restorer ?? $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    public function test_restore_checks_untrusted_data(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $other = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $DB->set_field('virtualbadge', 'org', 'elsewhere', ['id' => $instance->id]);
        $DB->set_field('virtualbadge', 'fieldmap', json_encode([
            'Name' => ['source' => 'user:fullname', 'value' => ''],
            'Hash' => ['source' => 'user:password', 'value' => ''],
            'Kurs' => ['source' => 'course:cacherev', 'value' => ''],
            'Text' => ['source' => 'text', 'value' => 'Teilnahme'],
        ]), ['id' => $instance->id]);
        $id = $this->add_issue($instance, $student->id, issuer::STATUS_ISSUED, 'recp_1');
        $DB->update_record('virtualbadge_issues', (object) [
            'id' => $id,
            'certificateurl' => 'javascript:alert(1)',
            'validationurl' => 'https://www.virtualbadge.io/certificate-validator?credential=x',
        ]);
        $this->add_issue($instance, $other->id, issuer::STATUS_ISSUED, '../certificate');

        $newcourseid = $this->backup_and_restore($course, true);

        $restored = $DB->get_record('virtualbadge', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame('main', $restored->org);
        $this->assertSame(['Name', 'Text'], array_keys(json_decode($restored->fieldmap, true)));
        $issues = array_values($DB->get_records('virtualbadge_issues', ['virtualbadgeid' => $restored->id]));
        $this->assertCount(1, $issues);
        $this->assertEquals($student->id, $issues[0]->userid);
        $this->assertSame('main', $issues[0]->org);
        $this->assertNull($issues[0]->certificateurl);
        $this->assertSame('https://www.virtualbadge.io/certificate-validator?credential=x', $issues[0]->validationurl);
    }

    public function test_restore_keeps_the_organisation_of_the_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        set_config('orgs', 'partner|Partnerakademie', 'mod_virtualbadge');
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $DB->set_field('virtualbadge', 'org', 'partner', ['id' => $instance->id]);

        $byteacher = $this->backup_and_restore($course, false, $teacher->id);
        $this->assertSame('main', $DB->get_field('virtualbadge', 'org', ['course' => $byteacher]));
        $byadmin = $this->backup_and_restore($course, false);
        $this->assertSame('partner', $DB->get_field('virtualbadge', 'org', ['course' => $byadmin]));
    }

    public function test_course_restore_with_user_data(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $issued = $gen->create_and_enrol($course, 'student');
        $queued = $gen->create_and_enrol($course, 'student');
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $this->add_issue($instance, $issued->id, issuer::STATUS_ISSUED, 'recp_1');
        $this->add_issue($instance, $queued->id, issuer::STATUS_QUEUED, null);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupid = 'virtualbadge_' . random_string(8);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupid));
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $newinstance = $DB->get_record('virtualbadge', ['course' => $newcourseid], '*', MUST_EXIST);
        $newcm = get_coursemodule_from_instance('virtualbadge', $newinstance->id);
        $issues = array_values($DB->get_records('virtualbadge_issues', ['virtualbadgeid' => $newinstance->id]));
        $this->assertCount(1, $issues);
        $this->assertEquals($issued->id, $issues[0]->userid);
        $this->assertSame('recp_1', $issues[0]->remoteid);
        $this->assertSame(issuer::reference($newcm->id, $issued->id), $issues[0]->reference);
    }

    public function test_course_reset(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $other = $gen->create_course();
        $user = $gen->create_user();
        $instance = $gen->create_module('virtualbadge', ['course' => $course->id]);
        $otherinstance = $gen->create_module('virtualbadge', ['course' => $other->id]);
        $this->add_issue($instance, $user->id, issuer::STATUS_ISSUED, 'recp_1');
        $this->add_issue($otherinstance, $user->id, issuer::STATUS_ISSUED, 'recp_2');

        $this->assertSame([], virtualbadge_reset_userdata((object) ['courseid' => $course->id]));
        $this->assertSame(2, $DB->count_records('virtualbadge_issues'));

        $status = virtualbadge_reset_userdata((object) ['courseid' => $course->id, 'reset_virtualbadge_issues' => 1]);
        $this->assertCount(1, $status);
        $this->assertFalse($status[0]['error']);
        $this->assertSame(0, $DB->count_records('virtualbadge_issues', ['virtualbadgeid' => $instance->id]));
        $this->assertSame(1, $DB->count_records('virtualbadge_issues', ['virtualbadgeid' => $otherinstance->id]));
    }
}
