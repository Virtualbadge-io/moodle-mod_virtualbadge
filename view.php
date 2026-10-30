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
 * Activity view: own certificate for learners, status list for trainers.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_virtualbadge\local\issuer;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'virtualbadge');
$instance = $DB->get_record('virtualbadge', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/virtualbadge:view', $context);

$url = new moodle_url('/mod/virtualbadge/view.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$canmanage = has_capability('mod/virtualbadge:manage', $context);
$canviewstatus = $canmanage || has_capability('mod/virtualbadge:viewstatus', $context);

if ($action !== '' && $canmanage) {
    require_sesskey();
    if ($action === 'retroactive') {
        $count = issuer::issue_retroactively($instance, $cm);
        redirect($url, get_string('retroactive_done', 'mod_virtualbadge', $count), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if (in_array($action, ['retry', 'reissue', 'resendemail'])) {
        $issue = $DB->get_record(
            'virtualbadge_issues',
            ['id' => required_param('issueid', PARAM_INT), 'virtualbadgeid' => $instance->id],
            '*',
            MUST_EXIST
        );
        if ($action === 'retry') {
            $done = issuer::retry($issue);
        } else if ($action === 'reissue') {
            $done = issuer::reissue($issue);
        } else {
            try {
                $done = issuer::resend_email($issue);
            } catch (\mod_virtualbadge\local\api_exception $e) {
                redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
            }
        }
        redirect(
            $url,
            get_string($action . ($done ? '_done' : '_notpossible'), 'mod_virtualbadge'),
            null,
            $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
    if ($action === 'releaseselected') {
        $count = issuer::release_selected($instance, $cm, optional_param_array('userids', [], PARAM_INT));
        redirect(
            $url,
            get_string('releaseselected_done', 'mod_virtualbadge', $count),
            null,
            $count ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
    if ($action === 'release') {
        $queued = issuer::release($instance, $cm, required_param('userid', PARAM_INT));
        redirect(
            $url,
            get_string($queued ? 'release_done' : 'release_notpossible', 'mod_virtualbadge'),
            null,
            $queued ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
}

$event = \mod_virtualbadge\event\course_module_viewed::create([
    'objectid' => $instance->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('virtualbadge', $instance);
$event->trigger();

$output = $PAGE->get_renderer('mod_virtualbadge');

echo $OUTPUT->header();
if (has_capability('mod/virtualbadge:receive', $context, null, false)) {
    echo $output->learner_view($instance, $USER->id);
}
if ($canviewstatus) {
    echo $output->trainer_view($instance, $cm, $url, $canmanage);
}
echo $OUTPUT->footer();
