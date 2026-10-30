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
 * Admin settings for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_virtualbadge\local\config;

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'mod_virtualbadge/apiheading',
        get_string('apiheading', 'mod_virtualbadge'),
        get_string('apiheading_desc', 'mod_virtualbadge')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_virtualbadge/apiurl',
        get_string('apiurl', 'mod_virtualbadge'),
        get_string('apiurl_desc', 'mod_virtualbadge'),
        config::DEFAULT_API_URL,
        PARAM_URL
    ));
    $settings->add(new admin_setting_encryptedpassword(
        'mod_virtualbadge/apikey',
        get_string('apikey', 'mod_virtualbadge'),
        get_string('apikey_desc', 'mod_virtualbadge')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_virtualbadge/ratelimit',
        get_string('ratelimit', 'mod_virtualbadge'),
        get_string('ratelimit_desc', 'mod_virtualbadge'),
        config::DEFAULT_RATE_LIMIT,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'mod_virtualbadge/orgheading',
        get_string('orgheading', 'mod_virtualbadge'),
        get_string('orgheading_desc', 'mod_virtualbadge')
    ));
    $settings->add(new admin_setting_configtextarea(
        'mod_virtualbadge/orgs',
        get_string('orgs', 'mod_virtualbadge'),
        get_string('orgs_desc', 'mod_virtualbadge'),
        '',
        PARAM_RAW_TRIMMED
    ));
    foreach (config::orgs() as $key => $name) {
        if ($key === config::MAIN_ORG) {
            continue;
        }
        $settings->add(new admin_setting_encryptedpassword(
            'mod_virtualbadge/apikey_' . $key,
            get_string('apikeyorg', 'mod_virtualbadge', format_string($name)),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            'mod_virtualbadge/ratelimit_' . $key,
            get_string('ratelimitorg', 'mod_virtualbadge', format_string($name)),
            '',
            config::DEFAULT_RATE_LIMIT,
            PARAM_INT
        ));
    }
    $settings->add(new \mod_virtualbadge\admin\setting_categorymap(
        'mod_virtualbadge/categorymap',
        get_string('categorymap', 'mod_virtualbadge'),
        get_string('categorymap_desc', 'mod_virtualbadge'),
        '',
        PARAM_RAW_TRIMMED
    ));
}
