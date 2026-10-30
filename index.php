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
 * List of all Virtualbadge activities in a course.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);

$event = \mod_virtualbadge\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/virtualbadge/index.php', ['id' => $course->id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_virtualbadge'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('modulenameplural', 'mod_virtualbadge'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_virtualbadge'));

$instances = get_all_instances_in_course('virtualbadge', $course);
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_virtualbadge')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}

$table = new html_table();
$table->head = [get_string('name'), get_string('template', 'mod_virtualbadge')];
foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/virtualbadge/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name),
        $instance->visible ? [] : ['class' => 'dimmed']
    );
    $table->data[] = [$link, s($instance->templatename)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
