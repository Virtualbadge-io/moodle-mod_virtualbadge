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
        $course = $gen->create_course(['fullname' => 'Grundlagen QM', 'shortname' => 'QM1']);
        $user = $gen->create_and_enrol($course, 'student', ['firstname' => 'Maria', 'lastname' => 'Beispiel'] + $userfields);
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
            'datatype' => 'datetime', 'shortname' => 'geburtsdatum', 'name' => 'Geburtsdatum',
        ]);
        $this->assertSame('profile:geburtsdatum', fieldsources::suggest('Geburtsdatum'));
        $this->assertSame('user:firstname', fieldsources::suggest('Vorname'));
        $this->assertSame('course:fullname', fieldsources::suggest('Kurs'));
        $this->assertSame('text', fieldsources::suggest('Beruf'));
        $this->assertTrue(fieldsources::is_valid('profile:geburtsdatum'));
        $this->assertFalse(fieldsources::is_valid('profile:unknown'));
    }

    public function test_resolve_mixed_sources(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $gen->create_custom_profile_field(['datatype' => 'datetime', 'shortname' => 'geburtsdatum', 'name' => 'Geburtsdatum']);
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'personalnr', 'name' => 'Personalnummer']);
        [$course, $user, $issue] = $this->setup_participant(['institution' => 'Musterwerk GmbH']);
        profile_save_custom_fields($user->id, [
            'geburtsdatum' => make_timestamp(1990, 3, 12, 12),
            'personalnr' => 'P-4711',
        ]);

        $values = fieldsources::resolve([
            'Beruf' => ['source' => 'text', 'value' => 'Fachkraft ({course})'],
            'Geburtsdatum' => ['source' => 'profile:geburtsdatum', 'value' => ''],
            'Firma' => ['source' => 'user:institution', 'value' => ''],
            'Datum' => ['source' => 'issue:completiondate', 'value' => ''],
            'Kurs' => ['source' => 'course:shortname', 'value' => ''],
            'Hinweis' => 'Nr. {profile:personalnr}',
        ], $course, $user, $issue);

        $this->assertSame([
            'Beruf' => 'Fachkraft (Grundlagen QM)',
            'Geburtsdatum' => '12.03.1990',
            'Firma' => 'Musterwerk GmbH',
            'Datum' => '24.10.2026',
            'Kurs' => 'QM1',
            'Hinweis' => 'Nr. P-4711',
        ], $values);
    }

    public function test_empty_participant_value_is_reported(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'datetime', 'shortname' => 'geburtsdatum', 'name' => 'Geburtsdatum',
        ]);
        [$course, $user, $issue] = $this->setup_participant();

        try {
            fieldsources::resolve(['Geburtsdatum' => ['source' => 'profile:geburtsdatum', 'value' => '']], $course, $user, $issue);
            $this->fail('Exception expected');
        } catch (field_value_missing $e) {
            $this->assertSame('Geburtsdatum', $e->field);
            $this->assertStringContainsString('Geburtsdatum', $e->getMessage());
        }
    }

    public function test_course_sources(): void {
        $this->resetAfterTest();
        $cfgen = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgen->create_category(['component' => 'core_course', 'area' => 'course', 'itemid' => 0]);
        $cfgen->create_field(['categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'ort', 'name' => 'Ort']);
        $cfgen->create_field(['categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'dauer', 'name' => 'Dauer']);
        $course = $this->getDataGenerator()->create_course([
            'idnumber' => ' ZPP-2026-11 ',
            'startdate' => make_timestamp(2026, 11, 3, 9),
            'enddate' => make_timestamp(2026, 11, 5, 17),
            'customfield_ort' => 'Hamburg',
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $issue = (object) ['completedat' => time()];

        $this->assertSame('coursefield:ort', fieldsources::suggest('Ort'));
        $this->assertSame('course:idnumber', fieldsources::suggest('Kurs ID'));
        $this->assertSame('course:startdate', fieldsources::suggest('Start'));
        $this->assertSame('course:enddate', fieldsources::suggest('Ende'));
        $this->assertTrue(fieldsources::is_valid('coursefield:dauer'));

        $values = fieldsources::resolve([
            'Kurs ID' => ['source' => 'course:idnumber', 'value' => ''],
            'Start' => ['source' => 'course:startdate', 'value' => ''],
            'Ende' => ['source' => 'course:enddate', 'value' => ''],
            'Ort' => ['source' => 'coursefield:ort', 'value' => ''],
            'Zeitraum' => ['source' => 'text', 'value' => '{coursestartdate} bis {courseenddate} in {coursefield:ort}'],
        ], $course, $user, $issue);
        $this->assertSame([
            'Kurs ID' => 'ZPP-2026-11',
            'Start' => '03.11.2026',
            'Ende' => '05.11.2026',
            'Ort' => 'Hamburg',
            'Zeitraum' => '03.11.2026 bis 05.11.2026 in Hamburg',
        ], $values);

        try {
            fieldsources::resolve(['Dauer' => ['source' => 'coursefield:dauer', 'value' => '']], $course, $user, $issue);
            $this->fail('Exception expected');
        } catch (field_value_missing $e) {
            $this->assertSame('fieldvaluemissing_course', $e->errorcode);
            $this->assertSame('Dauer', $e->field);
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
            'Ende' => ['source' => 'course:enddate', 'value' => ''],
            'Datum' => ['source' => 'issue:completiondate', 'value' => ''],
        ], $course, $user, $issue);
        $this->assertSame(['Start' => '31.12.2026', 'Ende' => '31.12.2026', 'Datum' => '31.12.2026'], $values);
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
