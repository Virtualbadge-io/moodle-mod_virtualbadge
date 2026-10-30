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

namespace mod_virtualbadge\check;

use core\check\check;
use core\check\result;
use mod_virtualbadge\local\config;
use mod_virtualbadge\local\issuer;

/**
 * Status check: failed and retried certificates.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issues extends check {
    /** Period for recent errors and issues in seconds. */
    public const PERIOD = DAYSECS;

    /** Maximum number of activities listed in the details. */
    public const MAX_ACTIVITIES = 20;

    /** Statuses in the overview, in the order of the flow. */
    public const STATUSES = [
        issuer::STATUS_QUEUED,
        issuer::STATUS_PENDING,
        issuer::STATUS_ISSUED,
        issuer::STATUS_RETRYING,
        issuer::STATUS_FAILED,
        issuer::STATUS_REVOKED,
        issuer::STATUS_DELETED,
    ];

    /**
     * Name of the check.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check_issues', 'mod_virtualbadge');
    }

    /**
     * Result of the check.
     *
     * @return result
     */
    public function get_result(): result {
        global $DB;
        $configured = array_filter(array_keys(config::orgs()), fn($org) => config::api_key($org) !== '');
        if (!$configured) {
            return new result(result::NA, get_string('check_notconfigured', 'mod_virtualbadge'));
        }

        $since = time() - self::PERIOD;
        $count = fn(string $where, array $params) => $DB->count_records_select('virtualbadge_issues', $where, $params);
        $failed = $count('status = ? AND timemodified >= ?', [issuer::STATUS_FAILED, $since]);
        $failedearlier = $count('status = ? AND timemodified < ?', [issuer::STATUS_FAILED, $since]);
        $retrying = $count('status = ?', [issuer::STATUS_RETRYING]);
        $issued = $count('status = ? AND timeissued >= ?', [issuer::STATUS_ISSUED, $since]);

        $status = result::OK;
        $summary = [];
        if ($failed) {
            $status = result::ERROR;
            $summary[] = get_string('check_failed', 'mod_virtualbadge', $failed);
        }
        if ($failedearlier) {
            $status = $status === result::OK ? result::WARNING : $status;
            $summary[] = get_string('check_failedearlier', 'mod_virtualbadge', $failedearlier);
        }
        if ($retrying) {
            $status = $status === result::OK ? result::WARNING : $status;
            $summary[] = get_string('check_retrying', 'mod_virtualbadge', $retrying);
        }
        $summary[] = get_string('check_issued', 'mod_virtualbadge', $issued);

        return new result($status, implode(' ', $summary), $this->overview() . $this->details());
    }

    /**
     * Number of certificates per status on the whole site.
     *
     * @return string HTML
     */
    protected function overview(): string {
        global $DB;
        $counts = $DB->get_records_sql_menu('SELECT status, COUNT(1) FROM {virtualbadge_issues} GROUP BY status');
        $table = new \html_table();
        $table->head = [get_string('status', 'mod_virtualbadge'), get_string('check_count', 'mod_virtualbadge')];
        foreach (self::STATUSES as $status) {
            $table->data[] = [get_string('status_' . $status, 'mod_virtualbadge'), (int) ($counts[$status] ?? 0)];
        }
        return \html_writer::tag('p', get_string('check_overview', 'mod_virtualbadge')) . \html_writer::table($table);
    }

    /**
     * Activities with failed or retried certificates and their latest error.
     *
     * @return string HTML, empty if there are none
     */
    protected function details(): string {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal([issuer::STATUS_FAILED, issuer::STATUS_RETRYING]);
        $rows = $DB->get_records_sql(
            "SELECT virtualbadgeid, COUNT(1) AS issues, MAX(timemodified) AS lastmodified
               FROM {virtualbadge_issues}
              WHERE status $insql
           GROUP BY virtualbadgeid
           ORDER BY MAX(timemodified) DESC",
            $params,
            0,
            self::MAX_ACTIVITIES
        );
        if (!$rows) {
            return '';
        }

        $table = new \html_table();
        $table->head = [
            get_string('course'),
            get_string('activity'),
            get_string('status_failed', 'mod_virtualbadge'),
            get_string('status_retrying', 'mod_virtualbadge'),
            get_string('check_lasterror', 'mod_virtualbadge'),
        ];
        foreach ($rows as $row) {
            $cm = get_coursemodule_from_instance('virtualbadge', $row->virtualbadgeid);
            if (!$cm) {
                continue;
            }
            $counts = $DB->get_records_sql_menu(
                "SELECT status, COUNT(1) FROM {virtualbadge_issues} WHERE virtualbadgeid = ? AND status $insql GROUP BY status",
                array_merge([$row->virtualbadgeid], $params)
            );
            $lasterror = $DB->get_field_sql(
                "SELECT lasterror FROM {virtualbadge_issues}
                  WHERE virtualbadgeid = ? AND status $insql AND lasterror IS NOT NULL
               ORDER BY timemodified DESC",
                array_merge([$row->virtualbadgeid], $params),
                IGNORE_MULTIPLE
            );
            $context = \context_module::instance($cm->id);
            $table->data[] = [
                format_string(get_course($cm->course)->fullname, true, ['context' => \context_course::instance($cm->course)]),
                \html_writer::link(
                    new \moodle_url('/mod/virtualbadge/view.php', ['id' => $cm->id]),
                    format_string($cm->name, true, ['context' => $context])
                ),
                (int) ($counts[issuer::STATUS_FAILED] ?? 0),
                (int) ($counts[issuer::STATUS_RETRYING] ?? 0),
                s((string) $lasterror),
            ];
        }
        return \html_writer::tag('p', get_string('check_details', 'mod_virtualbadge')) . \html_writer::table($table);
    }
}
