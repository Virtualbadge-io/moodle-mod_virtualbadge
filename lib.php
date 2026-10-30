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
 * Library functions for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Issue on course completion. */
define('VIRTUALBADGE_TRIGGER_COMPLETION', 'completion');

/** Issue when the course total reaches a minimum grade. */
define('VIRTUALBADGE_TRIGGER_GRADE', 'grade');

/** Issue after release by a trainer. */
define('VIRTUALBADGE_TRIGGER_MANUAL', 'manual');

/** Issue after course completion once a trainer has released it. */
define('VIRTUALBADGE_TRIGGER_APPROVAL', 'approval');

/**
 * Supported features.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function virtualbadge_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_OTHER;
        default:
            return null;
    }
}

/**
 * Add an instance.
 *
 * @param stdClass $data form data
 * @param mod_virtualbadge_mod_form|null $mform
 * @return int new instance id
 */
function virtualbadge_add_instance(stdClass $data, $mform = null): int {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    return $DB->insert_record('virtualbadge', virtualbadge_prepare_record($data));
}

/**
 * Update an instance.
 *
 * @param stdClass $data form data
 * @param mod_virtualbadge_mod_form|null $mform
 * @return bool
 */
function virtualbadge_update_instance(stdClass $data, $mform = null): bool {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    return $DB->update_record('virtualbadge', virtualbadge_prepare_record($data));
}

/**
 * Delete an instance.
 *
 * @param int $id instance id
 * @return bool
 */
function virtualbadge_delete_instance($id): bool {
    global $DB;
    if (!$DB->record_exists('virtualbadge', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('virtualbadge_issues', ['virtualbadgeid' => $id]);
    $DB->delete_records('virtualbadge', ['id' => $id]);
    return true;
}

/**
 * Normalise form data for storage.
 *
 * @param stdClass $data
 * @return stdClass
 */
function virtualbadge_prepare_record(stdClass $data): stdClass {
    if (($data->triggertype ?? '') !== VIRTUALBADGE_TRIGGER_GRADE) {
        $data->mingrade = null;
        $data->gradeitemid = null;
    } else {
        $data->gradeitemid = empty($data->gradeitemid) ? null : (int) $data->gradeitemid;
    }
    $data->sendemail = empty($data->sendemail) ? 0 : 1;
    $data->completionissued = empty($data->completionissued) ? 0 : 1;
    return $data;
}

/**
 * Course module information for the course page.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function virtualbadge_get_coursemodule_info($coursemodule) {
    global $DB;
    $instance = $DB->get_record(
        'virtualbadge',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completionissued'
    );
    if (!$instance) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('virtualbadge', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionissued'] = $instance->completionissued;
    }
    return $info;
}

/**
 * Elements for the course reset form.
 *
 * @param MoodleQuickForm $mform
 */
function virtualbadge_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'virtualbadgeheader', get_string('modulenameplural', 'mod_virtualbadge'));
    $mform->addElement('advcheckbox', 'reset_virtualbadge_issues', get_string('resetissues', 'mod_virtualbadge'));
    $mform->addHelpButton('reset_virtualbadge_issues', 'resetissues', 'mod_virtualbadge');
}

/**
 * Defaults for the course reset form.
 *
 * @param stdClass $course
 * @return array
 */
function virtualbadge_reset_course_form_defaults($course) {
    return ['reset_virtualbadge_issues' => 0];
}

/**
 * Delete the issue records of a course on course reset. Certificates in Virtualbadge are not revoked.
 *
 * @param stdClass $data reset form data
 * @return array status
 */
function virtualbadge_reset_userdata($data) {
    global $DB;
    if (empty($data->reset_virtualbadge_issues)) {
        return [];
    }
    $DB->delete_records_select(
        'virtualbadge_issues',
        'virtualbadgeid IN (SELECT id FROM {virtualbadge} WHERE course = ?)',
        [$data->courseid]
    );
    return [[
        'component' => get_string('modulenameplural', 'mod_virtualbadge'),
        'item' => get_string('resetissues', 'mod_virtualbadge'),
        'error' => false,
    ]];
}

/**
 * Checks for Site administration > Reports > System status.
 *
 * @return \core\check\check[]
 */
function virtualbadge_status_checks(): array {
    return [new \mod_virtualbadge\check\issues()];
}
