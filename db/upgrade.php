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
 * Upgrade steps for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_virtualbadge_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102401) {
        $table = new xmldb_table('virtualbadge');
        $field = new xmldb_field('completionissued', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sendemail');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('virtualbadge_issues');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('virtualbadgeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('org', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('templateid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reference', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('remoteid', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('certificateurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('validationurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('walleturl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeissued', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('virtualbadgeid', XMLDB_KEY_FOREIGN, ['virtualbadgeid'], 'virtualbadge', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('virtualbadgeid-userid', XMLDB_INDEX_UNIQUE, ['virtualbadgeid', 'userid']);
        $table->add_index('reference', XMLDB_INDEX_UNIQUE, ['reference']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026102401, 'virtualbadge');
    }

    if ($oldversion < 2026102500) {
        unset_config('gatewayurl', 'mod_virtualbadge');
        unset_config('gatewaytoken', 'mod_virtualbadge');
        upgrade_mod_savepoint(true, 2026102500, 'virtualbadge');
    }

    if ($oldversion < 2026102502) {
        $table = new xmldb_table('virtualbadge_issues');
        $fields = [
            new xmldb_field('replaceremoteid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'remoteid'),
            new xmldb_field('previousremoteid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'replaceremoteid'),
            new xmldb_field('emailsent', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0', 'attempts'),
            new xmldb_field('timeemailsent', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'emailsent'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026102502, 'virtualbadge');
    }

    if ($oldversion < 2026102600) {
        $table = new xmldb_table('virtualbadge_issues');
        $field = new xmldb_field('timechecked', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timeissued');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026102600, 'virtualbadge');
    }

    if ($oldversion < 2026102601) {
        $table = new xmldb_table('virtualbadge');
        $field = new xmldb_field('gradeitemid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'mingrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026102601, 'virtualbadge');
    }

    if ($oldversion < 2026102701) {
        // API keys are stored encrypted from now on; plain values in the config log are removed.
        $names = $DB->get_fieldset_select(
            'config_plugins',
            'name',
            "plugin = 'mod_virtualbadge' AND (name = 'apikey' OR " . $DB->sql_like('name', ':prefix') . ')',
            ['prefix' => $DB->sql_like_escape('apikey_') . '%']
        );
        foreach ($names as $name) {
            $value = trim((string) get_config('mod_virtualbadge', $name));
            if ($value !== '' && !\mod_virtualbadge\local\config::is_encrypted($value)) {
                set_config($name, \core\encryption::encrypt($value), 'mod_virtualbadge');
            }
        }
        $DB->execute(
            "UPDATE {config_log} SET value = '', oldvalue = '' WHERE plugin = 'mod_virtualbadge' AND (name = 'apikey' OR "
                . $DB->sql_like('name', ':prefix') . ')',
            ['prefix' => $DB->sql_like_escape('apikey_') . '%']
        );
        upgrade_mod_savepoint(true, 2026102701, 'virtualbadge');
    }

    return true;
}
