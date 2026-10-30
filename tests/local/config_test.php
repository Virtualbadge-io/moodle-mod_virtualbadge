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
 * Tests for the organisations a trainer may issue with.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_virtualbadge\local\config
 */
#[\PHPUnit\Framework\Attributes\CoversClass(config::class)]
final class config_test extends \advanced_testcase {
    public function test_trainer_only_gets_the_organisation_of_the_course(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        set_config('orgs', "partner|Partnerakademie", 'mod_virtualbadge');
        $category = $gen->create_category(['idnumber' => 'pa']);
        set_config('categorymap', 'pa = partner', 'mod_virtualbadge');
        $course = $gen->create_course(['category' => $category->id]);
        $other = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $gen->enrol_user($teacher->id, $other->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertSame('partner', config::course_org($course->id));
        $this->assertSame(['partner'], array_keys(config::allowed_orgs($course->id)));
        $this->assertSame(['main'], array_keys(config::allowed_orgs($other->id)));
        $this->assertSame(['main', 'partner'], array_keys(config::allowed_orgs($other->id, 'partner')));

        $role = $gen->create_role();
        assign_capability('mod/virtualbadge:chooseorg', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $teacher->id, \context_course::instance($other->id));
        $this->assertSame(['main', 'partner'], array_keys(config::allowed_orgs($other->id)));
    }
}
