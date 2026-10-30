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

use stdClass;

/**
 * Compares an activity with the current state of its template.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_check {
    /**
     * Problems of an activity with its template.
     *
     * @param stdClass $instance
     * @return array|null null if the activity matches the template or Virtualbadge cannot be reached,
     *     ['unavailable' => true] if the template is no longer active,
     *     ['missing' => string[]] with template fields that have no mapping
     */
    public static function problems(stdClass $instance): ?array {
        try {
            $template = issuer::find_template(\core\di::get(api_client::class), $instance);
        } catch (api_exception $e) {
            return null;
        }
        if ($template === null) {
            return ['unavailable' => true];
        }
        $map = json_decode($instance->fieldmap ?? '', true) ?: [];
        $missing = array_values(array_diff($template['fields'], array_keys($map)));
        return $missing ? ['missing' => $missing] : null;
    }
}
