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

use core_course_category;

/**
 * Access to the plugin settings.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** Key of the main organisation, whose API key is the plugin setting apikey. */
    public const MAIN_ORG = 'main';

    /** Default API base URL. */
    public const DEFAULT_API_URL = 'https://api.virtualbadge.io';

    /** Default calls per API key and hour. */
    public const DEFAULT_RATE_LIMIT = 200;

    /**
     * Configured organisations: the main organisation and any further ones.
     *
     * @return array org key => display name
     */
    public static function orgs(): array {
        $orgs = [self::MAIN_ORG => get_string('mainorg', 'mod_virtualbadge')];
        foreach (self::parse_orgs((string) get_config('mod_virtualbadge', 'orgs')) as $key => $name) {
            if ($key !== self::MAIN_ORG) {
                $orgs[$key] = $name;
            }
        }
        return $orgs;
    }

    /**
     * Base URL of the Virtualbadge API, without trailing slash.
     *
     * @return string
     */
    public static function api_url(): string {
        $url = trim((string) get_config('mod_virtualbadge', 'apiurl'));
        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    /**
     * API key of an organisation. Keys entered in the settings are stored encrypted,
     * keys forced in config.php may be plain text.
     *
     * @param string $org
     * @return string empty if not configured or not readable
     */
    public static function api_key(string $org): string {
        $value = trim((string) get_config('mod_virtualbadge', self::api_key_setting($org)));
        if (!self::is_encrypted($value)) {
            return $value;
        }
        try {
            return trim(\core\encryption::decrypt($value));
        } catch (\moodle_exception $e) {
            debugging('The Virtualbadge API key cannot be decrypted, enter it again in the plugin settings.', DEBUG_NORMAL);
            return '';
        }
    }

    /**
     * Name of the setting that holds the API key of an organisation.
     *
     * @param string $org
     * @return string
     */
    public static function api_key_setting(string $org): string {
        return $org === self::MAIN_ORG ? 'apikey' : 'apikey_' . $org;
    }

    /**
     * Whether a stored value was encrypted by Moodle.
     *
     * @param string $value
     * @return bool
     */
    public static function is_encrypted(string $value): bool {
        return str_starts_with($value, \core\encryption::METHOD_SODIUM . ':');
    }

    /**
     * Calls per hour the plugin sends with the API key of an organisation.
     *
     * @param string $org
     * @return int 0 for no limit in the plugin
     */
    public static function rate_limit(string $org): int {
        $name = $org === self::MAIN_ORG ? 'ratelimit' : 'ratelimit_' . $org;
        $value = trim((string) get_config('mod_virtualbadge', $name));
        return $value === '' ? self::DEFAULT_RATE_LIMIT : max(0, (int) $value);
    }

    /**
     * Parse the organisation setting, one "key|name" per line.
     *
     * @param string $value
     * @return array org key => display name
     */
    public static function parse_orgs(string $value): array {
        $orgs = [];
        foreach (preg_split('/\R/', $value) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $name] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
                continue;
            }
            $orgs[$key] = $name !== '' ? $name : $key;
        }
        return $orgs;
    }

    /**
     * Parse the category mapping setting, one "category = org" per line.
     *
     * The category is given by its ID number or its numeric id.
     *
     * @param string $value
     * @return array category reference => org key
     */
    public static function parse_categorymap(string $value): array {
        $map = [];
        foreach (preg_split('/\R/', $value) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$category, $org] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            $map[$category] = $org;
        }
        return $map;
    }

    /**
     * Organisation mapped to the category of a course, searching from the course category upwards.
     *
     * @param int $categoryid
     * @return string|null
     */
    public static function org_for_category(int $categoryid): ?string {
        $map = self::parse_categorymap((string) get_config('mod_virtualbadge', 'categorymap'));
        if (!$map) {
            return null;
        }
        $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
        while ($category && $category->id) {
            foreach ([(string) $category->idnumber, (string) $category->id] as $ref) {
                if ($ref !== '' && isset($map[$ref])) {
                    return $map[$ref];
                }
            }
            $category = $category->parent ? core_course_category::get($category->parent, IGNORE_MISSING, true) : null;
        }
        return null;
    }

    /**
     * Organisation of a course: the one mapped to its category, otherwise the main organisation.
     *
     * @param int $courseid
     * @return string
     */
    public static function course_org(int $courseid): string {
        global $DB;
        $org = self::org_for_category((int) $DB->get_field('course', 'category', ['id' => $courseid]));
        return $org !== null && isset(self::orgs()[$org]) ? $org : self::MAIN_ORG;
    }

    /**
     * Organisations a user may issue with in a course. Only with the capability
     * mod/virtualbadge:chooseorg all organisations, otherwise the one of the course
     * and the one already stored in the activity.
     *
     * @param int $courseid
     * @param string|null $current organisation of the activity being edited
     * @param int|null $userid defaults to the current user
     * @return array org key => name
     */
    public static function allowed_orgs(int $courseid, ?string $current = null, ?int $userid = null): array {
        $orgs = self::orgs();
        if (has_capability('mod/virtualbadge:chooseorg', \context_course::instance($courseid), $userid)) {
            return $orgs;
        }
        $allowed = array_intersect_key($orgs, [self::course_org($courseid) => true]);
        if ($current !== null && isset($orgs[$current])) {
            $allowed[$current] = $orgs[$current];
        }
        return $allowed;
    }
}
