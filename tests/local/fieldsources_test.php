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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Tests for the template field sources.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_virtualbadge\local\fieldsources
 */
#[\PHPUnit\Framework\Attributes\CoversClass(fieldsources::class)]
final class fieldsources_test extends \advanced_testcase {
    /**
     * Course, participant and issue for resolving values.
     *
     * @param array $userfields
     * @return array [course, user, issue]
     */
    protected function setup_participant(array $userfields = []): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Quality Management Basics', 'shortname' => 'QM1']);
        $user = $gen->create_and_enrol($course, 'student', ['firstname' => 'Maria', 'lastname' => 'Example'] + $userfields);
        $issue = (object) ['completedat' => make_timestamp(2026, 10, 24, 12)];
        return [$course, $user, $issue];
    }

    public function test_normalize_keeps_old_strings_as_text(): void {
        $this->assertSame(['source' => 'text', 'value' => '{course}'], fieldsources::normalize('{course}'));
        $this->assertSame(
            ['source' => 'user:email', 'value' => ''],
            fieldsources::normalize(['source' => 'user:email'])
        );
    }

    public function test_suggest(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'dateofbirth', 'name' => 'Date of birth',
        ]);
        $this->assertSame('profile:dateofbirth', fieldsources::suggest('Date of birth'));
        $this->assertSame('user:firstname', fieldsources::suggest('First name'));
        $this->assertSame('user:lastname', fieldsources::suggest('Last name'));
        $this->assertSame('user:fullname', fieldsources::suggest('Full name'));
        $this->assertSame('course:fullname', fieldsources::suggest('Course'));
        $this->assertSame('course:enddate', fieldsources::suggest('End date'));
        $this->assertSame('issue:completiondate', fieldsources::suggest('Completion date'));
        $this->assertSame('text', fieldsources::suggest('Occupation'));
        $this->assertTrue(fieldsources::is_valid('profile:dateofbirth'));
        $this->assertFalse(fieldsources::is_valid('profile:unknown'));
    }

    public function test_suggest_german_field_names(): void {
        $this->assertSame('user:firstname', fieldsources::suggest('Vorname'));
        $this->assertSame('course:fullname', fieldsources::suggest('Kurs'));
        $this->assertSame('course:idnumber', fieldsources::suggest('Kurs ID'));
        $this->assertSame('course:enddate', fieldsources::suggest('Ende'));
        $this->assertSame('issue:completiondate', fieldsources::suggest('Abschlussdatum'));
    }

    public function test_resolve_mixed_sources(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $gen->create_custom_profile_field(['datatype' => 'datetime', 'shortname' => 'dateofbirth', 'name' => 'Date of birth']);
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'personalnr', 'name' => 'Personnel number']);
        [$course, $user, $issue] = $this->setup_participant(['institution' => 'Example Works Ltd']);
        profile_save_custom_fields($user->id, [
            'dateofbirth' => make_timestamp(1990, 3, 12, 12),
            'personalnr' => 'P-4711',
        ]);

        $values = fieldsources::resolve([
            'Occupation' => ['source' => 'text', 'value' => 'Specialist ({course})'],
            'Date of birth' => ['source' => 'profile:dateofbirth', 'value' => ''],
            'Company' => ['source' => 'user:institution', 'value' => ''],
            'Date' => ['source' => 'issue:completiondate', 'value' => ''],
            'Course' => ['source' => 'course:shortname', 'value' => ''],
            'Note' => 'No. {profile:personalnr}',
        ], $course, $user, $issue);

        $this->assertSame([
            'Occupation' => 'Specialist (Quality Management Basics)',
            'Date of birth' => '12.03.1990',
            'Company' => 'Example Works Ltd',
            'Date' => '24.10.2026',
            'Course' => 'QM1',
            'Note' => 'No. P-4711',
        ], $values);
    }

    public function test_empty_participant_value_is_reported(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'dateofbirth', 'name' => 'Date of birth',
        ]);
        [$course, $user, $issue] = $this->setup_participant();

        try {
            fieldsources::resolve(['Date of birth' => ['source' => 'profile:dateofbirth', 'value' => '']], $course, $user, $issue);
            $this->fail('Exception expected');
        } catch (field_value_missing $e) {
            $this->assertSame('Date of birth', $e->field);
            $this->assertStringContainsString('Date of birth', $e->getMessage());
        }
    }

    public function test_course_sources(): void {
        $this->resetAfterTest();
        $cfgen = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgen->create_category(['component' => 'core_course', 'area' => 'course', 'itemid' => 0]);
        $cfgen->create_field([
            'categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'location', 'name' => 'Location',
        ]);
        $cfgen->create_field([
            'categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'duration', 'name' => 'Duration',
        ]);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => ' ZPP-2026-11 ',
            'startdate' => make_timestamp(2026, 11, 3, 9),
            'enddate' => make_timestamp(2026, 11, 5, 17),
            'customfield_location' => 'Hamburg',
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $issue = (object) ['completedat' => time()];

        $this->assertSame('coursefield:location', fieldsources::suggest('Location'));
        $this->assertSame('course:idnumber', fieldsources::suggest('Course ID'));
        $this->assertSame('course:startdate', fieldsources::suggest('Start'));
        $this->assertSame('course:enddate', fieldsources::suggest('End'));
        $this->assertTrue(fieldsources::is_valid('coursefield:duration'));

        $values = fieldsources::resolve([
            'Course ID' => ['source' => 'course:idnumber', 'value' => ''],
            'Start' => ['source' => 'course:startdate', 'value' => ''],
            'End' => ['source' => 'course:enddate', 'value' => ''],
            'Location' => ['source' => 'coursefield:location', 'value' => ''],
            'Period' => ['source' => 'text', 'value' => '{coursestartdate} to {courseenddate} in {coursefield:location}'],
        ], $course, $user, $issue);
        $this->assertSame([
            'Course ID' => 'ZPP-2026-11',
            'Start' => '03.11.2026',
            'End' => '05.11.2026',
            'Location' => 'Hamburg',
            'Period' => '03.11.2026 to 05.11.2026 in Hamburg',
        ], $values);

        try {
            fieldsources::resolve(['Duration' => ['source' => 'coursefield:duration', 'value' => '']], $course, $user, $issue);
            $this->fail('Exception expected');
        } catch (field_value_missing $e) {
            $this->assertSame('fieldvaluemissing_course', $e->errorcode);
            $this->assertSame('Duration', $e->field);
        }
    }

    public function test_date_at_year_end(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Berlin');
        $course = $this->getDataGenerator()->create_course([
            'startdate' => make_timestamp(2026, 12, 31, 0, 0),
            'enddate' => make_timestamp(2026, 12, 31, 23, 59),
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $issue = (object) ['completedat' => make_timestamp(2026, 12, 31, 23, 30)];

        $values = fieldsources::resolve([
            'Start' => ['source' => 'course:startdate', 'value' => ''],
            'End' => ['source' => 'course:enddate', 'value' => ''],
            'Date' => ['source' => 'issue:completiondate', 'value' => ''],
        ], $course, $user, $issue);
        $this->assertSame(['Start' => '31.12.2026', 'End' => '31.12.2026', 'Date' => '31.12.2026'], $values);
    }

    public function test_date_format_setting(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Berlin');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $issue = (object) ['completedat' => make_timestamp(2026, 3, 5, 12)];
        $map = ['Date' => ['source' => 'issue:completiondate', 'value' => '']];

        $expected = [
            'dmy' => '05.03.2026',
            'ymd' => '2026-03-05',
            'mdy' => '03/05/2026',
            'lang' => '5 March 2026',
            'other' => '05.03.2026',
        ];
        foreach ($expected as $format => $date) {
            set_config('dateformat', $format, 'mod_virtualbadge');
            $this->assertSame(['Date' => $date], fieldsources::resolve($map, $course, $user, $issue), $format);
        }
    }

    public function test_course_points(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        [$course, $user, $issue] = $this->setup_participant();
        $gen = $this->getDataGenerator();
        $map = [
            'Punkte' => ['source' => 'issue:points', 'value' => ''],
            'Von' => ['source' => 'text', 'value' => '{points} von {maxpoints}'],
        ];

        try {
            fieldsources::resolve($map, $course, $user, $issue);
            $this->fail('Exception expected');
        } catch (field_value_missing $e) {
            $this->assertSame('Punkte', $e->field);
        }

        foreach ([[12, 7.5], [8, 8]] as $i => [$max, $points]) {
            $item = new \grade_item($gen->create_grade_item([
                'courseid' => $course->id, 'itemname' => 'Teil ' . $i, 'grademax' => $max,
            ]), false);
            $item->update_final_grade($user->id, $points, 'test');
        }
        grade_regrade_final_grades($course->id);

        $this->assertSame('issue:points', fieldsources::suggest('Punkte'));
        $this->assertSame('issue:maxpoints', fieldsources::suggest('Maximale Punkte'));
        $this->assertSame(['Punkte' => '15.5', 'Von' => '15.5 von 20'], fieldsources::resolve($map, $course, $user, $issue));

        $quiz = $item->id;
        $this->assertFalse(fieldsources::is_valid('itempoints:' . $quiz));
        $this->assertTrue(fieldsources::is_valid('itempoints:' . $quiz, (int) $course->id));
        $this->assertSame('Teil 1 (points)', fieldsources::label('itempoints:' . $quiz));
        $items = [
            'Test' => ['source' => 'itempoints:' . $quiz, 'value' => ''],
            'Quote' => ['source' => 'itempercent:' . $quiz, 'value' => ''],
        ];
        $this->assertSame(['Test' => '8', 'Quote' => '100 %'], fieldsources::resolve($items, $course, $user, $issue));
    }

    public function test_only_selectable_sources_are_resolved(): void {
        $this->resetAfterTest();
        [$course, $user, $issue] = $this->setup_participant([]);
        foreach (['user:password', 'user:secret', 'course:cacherev', 'profile:unknown'] as $source) {
            try {
                fieldsources::resolve(['Feld' => ['source' => $source, 'value' => '']], $course, $user, $issue);
                $this->fail('Source ' . $source . ' was resolved');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidsource', $e->errorcode);
            }
        }
    }

    public function test_hidden_profile_fields_are_not_offered(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'personalnr', 'name' => 'Personalnummer',
            'visible' => PROFILE_VISIBLE_PRIVATE]);
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'intern', 'name' => 'Intern',
            'visible' => PROFILE_VISIBLE_NONE]);
        [$course, $user, $issue] = $this->setup_participant([]);
        profile_save_custom_fields($user->id, ['personalnr' => 'P-4711', 'intern' => 'geheim']);

        $this->assertTrue(fieldsources::is_valid('profile:personalnr'));
        $this->assertFalse(fieldsources::is_valid('profile:intern'));
        $values = fieldsources::resolve([
            'Text' => ['source' => 'text', 'value' => '{profile:personalnr} {profile:intern}'],
        ], $course, $user, $issue);
        $this->assertSame('P-4711 {profile:intern}', $values['Text']);
    }
}
