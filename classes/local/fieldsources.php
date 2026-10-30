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

use context_course;
use core_date;
use core_text;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Sources for the values of template fields.
 *
 * A mapping entry is ['source' => string, 'value' => string]. Sources:
 * - text: fixed text with placeholders, same for every participant
 * - user:<field>: standard user field
 * - profile:<shortname>: custom user profile field
 * - course:<field>: standard course field
 * - coursefield:<shortname>: custom course field
 * - issue:<field>: completion date, grade in percent or course total in points
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fieldsources {
    /** Fixed text. */
    public const TEXT = 'text';

    /** Standard user fields that can be mapped. */
    public const USER_FIELDS = ['firstname', 'lastname', 'fullname', 'email', 'idnumber', 'institution', 'department',
        'city', 'country'];

    /** Field names that suggest a source, in lower case. */
    protected const SUGGESTIONS = [
        'vorname' => 'user:firstname',
        'firstname' => 'user:firstname',
        'nachname' => 'user:lastname',
        'lastname' => 'user:lastname',
        'name' => 'user:fullname',
        'fullname' => 'user:fullname',
        'e-mail' => 'user:email',
        'email' => 'user:email',
        'firma' => 'user:institution',
        'institution' => 'user:institution',
        'abteilung' => 'user:department',
        'department' => 'user:department',
        'ort' => 'user:city',
        'stadt' => 'user:city',
        'city' => 'user:city',
        'kurs id' => 'course:idnumber',
        'kurs-id' => 'course:idnumber',
        'kursid' => 'course:idnumber',
        'course id' => 'course:idnumber',
        'start' => 'course:startdate',
        'beginn' => 'course:startdate',
        'kursbeginn' => 'course:startdate',
        'startdatum' => 'course:startdate',
        'ende' => 'course:enddate',
        'kursende' => 'course:enddate',
        'enddatum' => 'course:enddate',
        'kurs' => 'course:fullname',
        'course' => 'course:fullname',
        'note' => 'issue:grade',
        'grade' => 'issue:grade',
        'punkte' => 'issue:points',
        'points' => 'issue:points',
        'maximale punkte' => 'issue:maxpoints',
        'max punkte' => 'issue:maxpoints',
        'punkte maximal' => 'issue:maxpoints',
        'maximum points' => 'issue:maxpoints',
        'max points' => 'issue:maxpoints',
        'datum' => 'issue:completiondate',
        'abschlussdatum' => 'issue:completiondate',
        'date' => 'issue:completiondate',
    ];

    /**
     * Custom user profile fields that are visible to someone. Fields hidden from everyone are not offered.
     *
     * @return array shortname => record with shortname, name, datatype
     */
    public static function profile_fields(): array {
        global $DB;
        $fields = [];
        $records = $DB->get_records_select(
            'user_info_field',
            'visible <> ?',
            [PROFILE_VISIBLE_NONE],
            'sortorder',
            'id, shortname, name, datatype'
        );
        foreach ($records as $field) {
            $fields[$field->shortname] = $field;
        }
        return $fields;
    }

    /**
     * Custom course fields.
     *
     * @return array shortname => \core_customfield\field_controller
     */
    public static function course_fields(): array {
        $fields = [];
        foreach (\core_course\customfield\course_handler::create()->get_fields() as $field) {
            $fields[$field->get('shortname')] = $field;
        }
        return $fields;
    }

    /**
     * Options for the source select, grouped.
     *
     * @param int|null $courseid adds the grade items of this course
     * @return array group label => [source => label]
     */
    public static function options(?int $courseid = null): array {
        $user = [];
        foreach (self::USER_FIELDS as $field) {
            $user['user:' . $field] = self::label('user:' . $field);
        }
        foreach (self::profile_fields() as $field) {
            $user['profile:' . $field->shortname] = format_string($field->name);
        }
        $course = [];
        $sources = ['course:fullname', 'course:shortname', 'course:idnumber', 'course:category', 'course:startdate',
            'course:enddate'];
        foreach ($sources as $source) {
            $course[$source] = self::label($source);
        }
        foreach (self::course_fields() as $shortname => $field) {
            $course['coursefield:' . $shortname] = $field->get_formatted_name();
        }
        foreach (['issue:completiondate', 'issue:grade', 'issue:points', 'issue:maxpoints'] as $source) {
            $course[$source] = self::label($source);
        }
        $options = [
            get_string('sourcegroup_text', 'mod_virtualbadge') => [self::TEXT => self::label(self::TEXT)],
            get_string('sourcegroup_user', 'mod_virtualbadge') => $user,
            get_string('sourcegroup_course', 'mod_virtualbadge') => $course,
        ];
        $grades = [];
        foreach ($courseid ? conditions::grade_items($courseid) : [] as $id => $name) {
            $grades['itempoints:' . $id] = get_string('source_itempoints', 'mod_virtualbadge', format_string($name));
            $grades['itempercent:' . $id] = get_string('source_itempercent', 'mod_virtualbadge', format_string($name));
        }
        if ($grades) {
            $options[get_string('sourcegroup_grades', 'mod_virtualbadge')] = $grades;
        }
        return $options;
    }

    /**
     * Whether a source exists.
     *
     * @param string $source
     * @param int|null $courseid course whose grade items are valid sources
     * @return bool
     */
    public static function is_valid(string $source, ?int $courseid = null): bool {
        return isset(self::sources($courseid)[$source]);
    }

    /**
     * All selectable sources.
     *
     * @param int|null $courseid course whose grade items are valid sources
     * @return array source => label
     */
    protected static function sources(?int $courseid): array {
        return array_merge(...array_values(self::options($courseid)));
    }

    /**
     * Human readable name of a source.
     *
     * @param string $source
     * @return string
     */
    public static function label(string $source): string {
        [$type, $name] = array_pad(explode(':', $source, 2), 2, '');
        switch ($type) {
            case self::TEXT:
                return get_string('source_text', 'mod_virtualbadge');
            case 'user':
                return $name === 'fullname' ? get_string('fullnameuser') : get_string($name);
            case 'profile':
                $fields = self::profile_fields();
                return isset($fields[$name]) ? format_string($fields[$name]->name) : $name;
            case 'coursefield':
                $fields = self::course_fields();
                return isset($fields[$name]) ? $fields[$name]->get_formatted_name() : $name;
            case 'itempoints':
            case 'itempercent':
                $item = \grade_item::fetch(['id' => (int) $name]);
                return get_string('source_' . $type, 'mod_virtualbadge', $item ? format_string($item->get_name()) : $name);
            default:
                return get_string('source_' . $type . '_' . $name, 'mod_virtualbadge');
        }
    }

    /**
     * Source preselected for a template field, based on its name.
     *
     * @param string $field
     * @return string
     */
    public static function suggest(string $field): string {
        $needle = core_text::strtolower(trim($field));
        foreach (self::course_fields() as $shortname => $coursefield) {
            $name = core_text::strtolower(strip_tags($coursefield->get_formatted_name()));
            if ($needle === core_text::strtolower($shortname) || $needle === $name) {
                return 'coursefield:' . $shortname;
            }
        }
        foreach (self::profile_fields() as $profile) {
            if ($needle === core_text::strtolower($profile->shortname) || $needle === core_text::strtolower($profile->name)) {
                return 'profile:' . $profile->shortname;
            }
        }
        return self::SUGGESTIONS[$needle] ?? self::TEXT;
    }

    /**
     * Mapping entry in its current form. Plain strings from older versions are fixed text.
     *
     * @param mixed $entry
     * @return array ['source' => string, 'value' => string]
     */
    public static function normalize($entry): array {
        if (is_array($entry) && isset($entry['source'])) {
            return ['source' => (string) $entry['source'], 'value' => (string) ($entry['value'] ?? '')];
        }
        return ['source' => self::TEXT, 'value' => (string) $entry];
    }

    /**
     * Values of all template fields for one participant.
     *
     * @param array $map field => mapping entry
     * @param stdClass $course
     * @param stdClass $user
     * @param stdClass $issue
     * @return array field => value
     * @throws field_value_missing if a participant or course value is empty
     * @throws \moodle_exception if a field uses a source that is not selectable
     */
    public static function resolve(array $map, stdClass $course, stdClass $user, stdClass $issue): array {
        $profile = self::profile_values($user->id);
        $placeholders = self::placeholders($course, $user, $issue, $profile);
        $sources = self::sources((int) $course->id);
        $values = [];
        foreach ($map as $field => $entry) {
            $entry = self::normalize($entry);
            if ($entry['source'] === self::TEXT) {
                $values[$field] = trim(strtr($entry['value'], $placeholders));
                continue;
            }
            if (!isset($sources[$entry['source']])) {
                throw new \moodle_exception('invalidsource', 'mod_virtualbadge', '', (string) $field);
            }
            $value = trim(self::value($entry['source'], $course, $user, $issue, $profile));
            if (trim($value) === '') {
                $incourse = self::is_course_source($entry['source']);
                throw new field_value_missing((string) $field, self::label($entry['source']), $incourse);
            }
            $values[$field] = $value;
        }
        return $values;
    }

    /**
     * Whether a source takes its value from the course rather than the participant.
     *
     * @param string $source
     * @return bool
     */
    public static function is_course_source(string $source): bool {
        return str_starts_with($source, 'course:') || str_starts_with($source, 'coursefield:');
    }

    /**
     * Value of a source for one participant.
     *
     * @param string $source
     * @param stdClass $course
     * @param stdClass $user
     * @param stdClass $issue
     * @param array $profile shortname => formatted value
     * @return string
     */
    public static function value(string $source, stdClass $course, stdClass $user, stdClass $issue, array $profile): string {
        [$type, $name] = array_pad(explode(':', $source, 2), 2, '');
        $context = context_course::instance($course->id);
        switch ($type) {
            case 'user':
                if ($name === 'fullname') {
                    return fullname($user);
                }
                if ($name === 'country') {
                    $countries = get_string_manager()->get_list_of_countries();
                    return $countries[$user->country] ?? (string) $user->country;
                }
                return (string) ($user->$name ?? '');
            case 'profile':
                return $profile[$name] ?? '';
            case 'course':
                if ($name === 'startdate' || $name === 'enddate') {
                    return self::date((int) $course->$name);
                }
                if ($name === 'category') {
                    $category = \core_course_category::get($course->category, IGNORE_MISSING, true);
                    return $category ? $category->get_formatted_name() : '';
                }
                return format_string($course->$name ?? '', true, ['context' => $context, 'escape' => false]);
            case 'coursefield':
                return self::course_field_values($course->id)[$name] ?? '';
            case 'issue':
                if ($name === 'completiondate') {
                    return self::date((int) $issue->completedat);
                }
                if ($name === 'points' || $name === 'maxpoints') {
                    $points = conditions::course_points($course->id, $user->id);
                    return $points === null ? '' : format_float($points[$name === 'points' ? 0 : 2], 2, true, true);
                }
                $grade = conditions::course_grade_percent($course->id, $user->id);
                return $grade === null ? '' : format_float($grade, 0) . ' %';
            case 'itempoints':
                $points = conditions::course_points($course->id, $user->id, (int) $name);
                return $points === null ? '' : format_float($points[0], 2, true, true);
            case 'itempercent':
                $grade = conditions::course_grade_percent($course->id, $user->id, (int) $name);
                return $grade === null ? '' : format_float($grade, 0) . ' %';
        }
        return '';
    }

    /**
     * Placeholders available in fixed text.
     *
     * @param stdClass $course
     * @param stdClass $user
     * @param stdClass $issue
     * @param array $profile shortname => formatted value
     * @return array placeholder => value
     */
    public static function placeholders(stdClass $course, stdClass $user, stdClass $issue, array $profile): array {
        $values = [
            '{firstname}' => self::value('user:firstname', $course, $user, $issue, $profile),
            '{lastname}' => self::value('user:lastname', $course, $user, $issue, $profile),
            '{fullname}' => self::value('user:fullname', $course, $user, $issue, $profile),
            '{email}' => self::value('user:email', $course, $user, $issue, $profile),
            '{course}' => self::value('course:fullname', $course, $user, $issue, $profile),
            '{courseshortname}' => self::value('course:shortname', $course, $user, $issue, $profile),
            '{completiondate}' => self::value('issue:completiondate', $course, $user, $issue, $profile),
            '{grade}' => self::value('issue:grade', $course, $user, $issue, $profile),
            '{points}' => self::value('issue:points', $course, $user, $issue, $profile),
            '{maxpoints}' => self::value('issue:maxpoints', $course, $user, $issue, $profile),
        ];
        foreach (['idnumber', 'startdate', 'enddate'] as $name) {
            $values['{course' . $name . '}'] = self::value('course:' . $name, $course, $user, $issue, $profile);
        }
        foreach ($profile as $shortname => $value) {
            $values['{profile:' . $shortname . '}'] = $value;
        }
        foreach (self::course_field_values($course->id) as $shortname => $value) {
            $values['{coursefield:' . $shortname . '}'] = $value;
        }
        return $values;
    }

    /**
     * Custom course field values as plain text.
     *
     * @param int $courseid
     * @return array shortname => value
     */
    public static function course_field_values(int $courseid): array {
        $values = [];
        foreach (\core_course\customfield\course_handler::create()->get_instance_data($courseid, true) as $data) {
            $field = $data->get_field();
            if ($field->get('type') === 'date') {
                $value = self::date((int) $data->get_value());
            } else {
                $value = trim(html_to_text((string) $data->export_value(), 0, false));
            }
            $values[$field->get('shortname')] = $value;
        }
        return $values;
    }

    /**
     * Custom profile field values of a user as plain text.
     *
     * @param int $userid
     * @return array shortname => value
     */
    public static function profile_values(int $userid): array {
        $fields = self::profile_fields();
        $raw = (array) profile_user_record($userid, false);
        $values = [];
        foreach ($fields as $shortname => $field) {
            $value = $raw[$shortname] ?? '';
            if ($field->datatype === 'datetime') {
                $value = self::date((int) $value);
            } else if ($field->datatype === 'checkbox') {
                $value = $value === '' || $value === null ? '' : get_string($value ? 'yes' : 'no');
            } else {
                $value = trim(html_to_text((string) $value, 0, false));
            }
            $values[$shortname] = (string) $value;
        }
        return $values;
    }

    /**
     * Date as DD.MM.YYYY in the site timezone, empty for 0.
     *
     * @param int $timestamp
     * @return string
     */
    protected static function date(int $timestamp): string {
        return $timestamp ? userdate($timestamp, '%d.%m.%Y', core_date::get_server_timezone(), false) : '';
    }
}
