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

namespace mod_virtualbadge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_virtualbadge.
 *
 * Deleting data in Moodle does not revoke certificates in Virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored and transferred data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('virtualbadge_issues', [
            'userid' => 'privacy:metadata:issues:userid',
            'status' => 'privacy:metadata:issues:status',
            'certificateurl' => 'privacy:metadata:issues:certificateurl',
            'validationurl' => 'privacy:metadata:issues:validationurl',
            'completedat' => 'privacy:metadata:issues:completedat',
            'timeissued' => 'privacy:metadata:issues:timeissued',
        ], 'privacy:metadata:issues');

        $collection->add_external_location_link('virtualbadge', [
            'full_name' => 'privacy:metadata:virtualbadge:full_name',
            'email' => 'privacy:metadata:virtualbadge:email',
            'issue_date' => 'privacy:metadata:virtualbadge:issue_date',
            'dynamic_fields' => 'privacy:metadata:virtualbadge:dynamic_fields',
            'metadata' => 'privacy:metadata:virtualbadge:metadata',
        ], 'privacy:metadata:virtualbadge');
        return $collection;
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {virtualbadge_issues} i ON i.virtualbadgeid = cm.instance
                 WHERE i.userid = :userid";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'virtualbadge',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT i.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {virtualbadge_issues} i ON i.virtualbadgeid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'virtualbadge', 'cmid' => $context->instanceid]);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $instanceid = self::instance_id($context);
            if (!$instanceid) {
                continue;
            }
            $issue = $DB->get_record('virtualbadge_issues', ['virtualbadgeid' => $instanceid, 'userid' => $userid]);
            if (!$issue) {
                continue;
            }
            $data = helper::get_context_data($context, $contextlist->get_user());
            $data->issue = (object) [
                'status' => $issue->status,
                'certificateurl' => $issue->certificateurl,
                'validationurl' => $issue->validationurl,
                'completedat' => transform::datetime($issue->completedat),
                'timeissued' => $issue->timeissued ? transform::datetime($issue->timeissued) : null,
            ];
            writer::with_context($context)->export_data([], $data);
        }
    }

    /**
     * Delete data of all users in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($instanceid = self::instance_id($context)) {
            $DB->delete_records('virtualbadge_issues', ['virtualbadgeid' => $instanceid]);
        }
    }

    /**
     * Delete data of one user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($instanceid = self::instance_id($context)) {
                $DB->delete_records('virtualbadge_issues', ['virtualbadgeid' => $instanceid, 'userid' => $userid]);
            }
        }
    }

    /**
     * Delete data of several users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $instanceid = self::instance_id($userlist->get_context());
        if (!$instanceid || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['instanceid'] = $instanceid;
        $DB->delete_records_select('virtualbadge_issues', "virtualbadgeid = :instanceid AND userid $insql", $params);
    }

    /**
     * Activity instance id of a module context.
     *
     * @param \context $context
     * @return int|null
     */
    protected static function instance_id(\context $context): ?int {
        if (!$context instanceof \context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('virtualbadge', $context->instanceid);
        return $cm ? (int) $cm->instance : null;
    }
}
