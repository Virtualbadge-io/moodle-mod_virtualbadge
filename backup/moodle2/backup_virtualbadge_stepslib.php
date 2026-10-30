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
 * Backup structure for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure of the activity and, with user data, its issued certificates.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_virtualbadge_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $virtualbadge = new backup_nested_element('virtualbadge', ['id'], [
            'name', 'intro', 'introformat', 'org', 'templateid', 'templatename', 'fieldmap', 'triggertype',
            'mingrade', 'gradeitemid', 'sendemail', 'completionissued', 'timecreated', 'timemodified',
        ]);
        $issues = new backup_nested_element('issues');
        $issue = new backup_nested_element('issue', ['id'], [
            'userid', 'org', 'templateid', 'reference', 'status', 'source', 'remoteid', 'previousremoteid', 'certificateurl',
            'validationurl', 'walleturl', 'completedat', 'attempts', 'emailsent', 'timeemailsent', 'lasterror',
            'timecreated', 'timemodified', 'timeissued', 'timechecked',
        ]);
        $virtualbadge->add_child($issues);
        $issues->add_child($issue);

        $virtualbadge->set_source_table('virtualbadge', ['id' => backup::VAR_ACTIVITYID]);
        if ($this->get_setting_value('userinfo')) {
            $issue->set_source_table('virtualbadge_issues', ['virtualbadgeid' => backup::VAR_PARENTID], 'id ASC');
        }
        $issue->annotate_ids('user', 'userid');
        $virtualbadge->annotate_files('mod_virtualbadge', 'intro', null);

        return $this->prepare_activity_structure($virtualbadge);
    }
}
