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

use cm_info;
use completion_info;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/virtualbadge/lib.php');

/**
 * Queues, issues and tracks certificates.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issuer {
    /** Waiting for the issue task. */
    public const STATUS_QUEUED = 'queued';
    /** Accepted by Virtualbadge, document not yet generated. */
    public const STATUS_PENDING = 'pending';
    /** Issued. */
    public const STATUS_ISSUED = 'issued';
    /** Revoked in Virtualbadge. */
    public const STATUS_REVOKED = 'revoked';
    /** Deleted in Virtualbadge. */
    public const STATUS_DELETED = 'deleted';
    /** Temporary error, the task is retried. */
    public const STATUS_RETRYING = 'retrying';
    /** Permanent error or no attempts left. */
    public const STATUS_FAILED = 'failed';

    /** Emails Virtualbadge sends at most per certificate. */
    public const MAX_EMAILS = 10;

    /** Attempts before an issue is marked as failed. */
    public const MAX_ATTEMPTS = 10;

    /** Delays in seconds between status checks of pending issues. */
    public const STATUS_DELAYS = [30, 60, 120, 300, 900, 1800, 3600, 3600, 3600, 3600];

    /** Seconds after which an issued certificate is checked again. */
    public const VERIFY_INTERVAL = DAYSECS;

    /** Checks per run of the verify task if an organisation has no hourly limit. */
    public const VERIFY_UNLIMITED = 100;

    /**
     * Activities in a course with a given trigger.
     *
     * @param int $courseid
     * @param string $trigger
     * @return array list of [stdClass instance, cm_info cm]
     */
    public static function instances(int $courseid, string $trigger): array {
        global $DB;
        $cms = get_fast_modinfo($courseid)->get_instances_of('virtualbadge');
        if (!$cms) {
            return [];
        }
        $records = $DB->get_records_list('virtualbadge', 'id', array_keys($cms));
        $result = [];
        foreach ($records as $record) {
            if ($record->triggertype === $trigger) {
                $result[] = [$record, $cms[$record->id]];
            }
        }
        return $result;
    }

    /**
     * Queue an issue for a user unless one exists.
     *
     * @param stdClass $instance
     * @param cm_info $cm
     * @param int $userid
     * @param string $source completion, approval, grade, manual or retroactive
     * @param int $completedat time the condition was met
     * @return stdClass|null the new issue record
     */
    public static function queue(stdClass $instance, cm_info $cm, int $userid, string $source, int $completedat): ?stdClass {
        global $DB;
        if ($DB->record_exists('virtualbadge_issues', ['virtualbadgeid' => $instance->id, 'userid' => $userid])) {
            return null;
        }
        if (!conditions::is_eligible($cm, $userid)) {
            self::skip($cm, $userid, 'noteligible');
            return null;
        }
        if (trim((string) $DB->get_field('user', 'email', ['id' => $userid])) === '') {
            self::skip($cm, $userid, 'noemail');
            return null;
        }
        $now = time();
        $issue = (object) [
            'virtualbadgeid' => $instance->id,
            'userid' => $userid,
            'org' => $instance->org,
            'templateid' => $instance->templateid,
            'reference' => self::reference($cm->id, $userid),
            'status' => self::STATUS_QUEUED,
            'source' => $source,
            'completedat' => $completedat ?: $now,
            'attempts' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        try {
            $issue->id = $DB->insert_record('virtualbadge_issues', $issue);
        } catch (\dml_write_exception $e) {
            return null;
        }
        self::schedule_issue($issue->id);
        return $issue;
    }

    /**
     * Log that a certificate was not issued.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param string $reason noteligible, noemail or gradebelow
     */
    public static function skip(cm_info $cm, int $userid, string $reason): void {
        \mod_virtualbadge\event\issue_skipped::create([
            'relateduserid' => $userid,
            'context' => \context_module::instance($cm->id),
            'other' => ['reason' => $reason],
        ])->trigger();
    }

    /**
     * Queue the issue task.
     *
     * @param int $issueid
     */
    public static function schedule_issue(int $issueid): void {
        $task = new \mod_virtualbadge\task\issue_certificate();
        $task->set_custom_data(['issueid' => $issueid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue a status check.
     *
     * @param int $issueid
     * @param int $check number of the check, starting at 0
     */
    public static function schedule_status_check(int $issueid, int $check): void {
        if ($check >= count(self::STATUS_DELAYS)) {
            return;
        }
        $task = new \mod_virtualbadge\task\check_status();
        $task->set_custom_data(['issueid' => $issueid, 'check' => $check]);
        $task->set_next_run_time(time() + self::STATUS_DELAYS[$check]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Reference stored with the recipient in Virtualbadge, unique per site, activity and user.
     *
     * @param int $cmid
     * @param int $userid
     * @return string
     */
    public static function reference(int $cmid, int $userid): string {
        return substr(sha1(get_site_identifier()), 0, 12) . ':' . $cmid . ':' . $userid;
    }

    /**
     * Send an issue to Virtualbadge. Called by the issue task.
     *
     * @param int $issueid
     * @throws api_exception on temporary errors, so the task is retried
     */
    public static function process(int $issueid): void {
        global $DB;
        $issue = $DB->get_record('virtualbadge_issues', ['id' => $issueid]);
        if (!$issue || !in_array($issue->status, [self::STATUS_QUEUED, self::STATUS_RETRYING])) {
            return;
        }
        $instance = $DB->get_record('virtualbadge', ['id' => $issue->virtualbadgeid]);
        $user = $DB->get_record('user', ['id' => $issue->userid, 'deleted' => 0]);
        if (!$instance || !$user) {
            self::fail($issue, 'activity or user no longer exists');
            return;
        }
        $course = get_course($instance->course);
        $cm = get_fast_modinfo($course)->get_instances_of('virtualbadge')[$instance->id];
        $api = \core\di::get(api_client::class);

        try {
            $template = self::find_template($api, $instance);
            if ($template === null) {
                $issue->attempts++;
                self::fail($issue, get_string('templateunavailable', 'mod_virtualbadge', format_string($instance->templatename)));
                return;
            }
            $recipient = self::recipient($instance, $template, $course, $user, $issue);
            if (!empty($issue->replaceremoteid)) {
                try {
                    $result = self::replace($api, $instance, $issue, $recipient);
                } catch (api_exception $e) {
                    if ($e->is_temporary()) {
                        throw $e;
                    }
                    if ($e->status === 404) {
                        $issue->attempts++;
                        $issue->replaceremoteid = null;
                        self::mark_deleted($issue);
                        return;
                    }
                    self::replace_rejected($issue, $e);
                    return;
                }
            } else {
                $result = $issue->attempts > 0 ? $api->find($instance->org, $instance->templateid, $issue->reference) : null;
                $result = $result ?? $api->issue($instance->org, $instance->templateid, $recipient, !empty($instance->sendemail));
            }
        } catch (api_exception $e) {
            self::handle_error($issue, $e);
            return;
        } catch (\moodle_exception $e) {
            $issue->attempts++;
            self::fail($issue, $e->getMessage());
            return;
        }

        $issue->attempts++;
        $replaced = $issue->replaceremoteid ?? null;
        if ($replaced) {
            $issue->previousremoteid = $replaced;
            $issue->replaceremoteid = null;
            $issue->timeissued = time();
            $issue->emailsent = 0;
            $issue->timeemailsent = null;
        }
        self::apply_result($issue, $result, $instance, $cm);
        if ($replaced) {
            \mod_virtualbadge\event\certificate_reissued::create([
                'objectid' => $issue->id,
                'relateduserid' => $issue->userid,
                'context' => \context_module::instance($cm->id),
                'other' => ['oldremoteid' => $replaced, 'newremoteid' => $issue->remoteid],
            ])->trigger();
            if (!empty($instance->sendemail)) {
                try {
                    $api->send_email($instance->org, $issue->remoteid);
                } catch (api_exception $e) {
                    $issue->lasterror = $e->getMessage();
                    $DB->update_record('virtualbadge_issues', $issue);
                }
            }
        }
        if ($issue->status === self::STATUS_PENDING) {
            self::schedule_status_check($issue->id, 0);
        }
    }

    /**
     * Replace the current certificate of an issue. After an interrupted attempt the
     * replacement is looked up first, so that a certificate is never replaced twice.
     *
     * @param api_client $api
     * @param stdClass $instance
     * @param stdClass $issue
     * @param array $recipient
     * @return array issue result of the new certificate
     */
    protected static function replace(api_client $api, stdClass $instance, stdClass $issue, array $recipient): array {
        if ($issue->attempts > 0) {
            $old = $api->status($instance->org, $issue->replaceremoteid);
            if (($old['email_status'] ?? '') === 'voided') {
                $found = $api->find_replacement(
                    $instance->org,
                    $instance->templateid,
                    $recipient['email'],
                    $issue->replaceremoteid
                );
                if ($found) {
                    return $found;
                }
            }
        }
        return $api->replace($instance->org, $issue->replaceremoteid, $recipient);
    }

    /**
     * Virtualbadge refused to replace the certificate. The current certificate stays valid.
     *
     * @param stdClass $issue
     * @param api_exception $e
     */
    protected static function replace_rejected(stdClass $issue, api_exception $e): void {
        global $DB;
        $unchanged = $e->status === 400 && str_contains($e->getMessage(), 'different value');
        $issue->attempts++;
        $issue->replaceremoteid = null;
        $issue->status = empty($issue->timeissued) ? self::STATUS_PENDING : self::STATUS_ISSUED;
        $issue->lasterror = $unchanged
            ? get_string('reissue_unchanged', 'mod_virtualbadge')
            : get_string('reissue_failed', 'mod_virtualbadge', $e->getMessage());
        $issue->timemodified = time();
        $DB->update_record('virtualbadge_issues', $issue);
        if ($issue->status === self::STATUS_PENDING) {
            self::schedule_status_check($issue->id, 0);
        }
    }

    /**
     * Queue a new certificate that replaces the current one. The current one becomes void in Virtualbadge.
     *
     * @param stdClass $issue
     * @return bool
     */
    public static function reissue(stdClass $issue): bool {
        global $DB;
        if (!in_array($issue->status, [self::STATUS_ISSUED, self::STATUS_PENDING]) || empty($issue->remoteid)) {
            return false;
        }
        $issue->replaceremoteid = $issue->remoteid;
        $issue->status = self::STATUS_QUEUED;
        $issue->attempts = 0;
        $issue->lasterror = null;
        $issue->timemodified = time();
        $DB->update_record('virtualbadge_issues', $issue);
        self::schedule_issue($issue->id);
        return true;
    }

    /**
     * Ask Virtualbadge to send the email with the certificate link again.
     *
     * @param stdClass $issue
     * @return bool false if the certificate cannot be emailed
     * @throws api_exception
     */
    public static function resend_email(stdClass $issue): bool {
        global $DB;
        if (!in_array($issue->status, [self::STATUS_ISSUED, self::STATUS_PENDING]) || empty($issue->remoteid)) {
            return false;
        }
        if ($issue->emailsent >= self::MAX_EMAILS) {
            return false;
        }
        try {
            \core\di::get(api_client::class)->send_email($issue->org, $issue->remoteid);
        } catch (api_exception $e) {
            if ($e->status !== 404) {
                throw $e;
            }
            self::mark_deleted($issue);
            return false;
        }
        $issue->emailsent++;
        $issue->timeemailsent = time();
        $issue->timemodified = time();
        $DB->update_record('virtualbadge_issues', $issue);
        $cm = get_coursemodule_from_instance('virtualbadge', $issue->virtualbadgeid, 0, false, MUST_EXIST);
        \mod_virtualbadge\event\email_resent::create([
            'objectid' => $issue->id,
            'relateduserid' => $issue->userid,
            'context' => \context_module::instance($cm->id),
        ])->trigger();
        return true;
    }

    /**
     * Record an API error. Temporary errors are thrown again so that the task is retried.
     *
     * @param stdClass $issue
     * @param api_exception $e
     * @throws api_exception
     */
    protected static function handle_error(stdClass $issue, api_exception $e): void {
        global $DB;
        $issue->attempts++;
        if ($e->is_temporary() && $issue->attempts < self::MAX_ATTEMPTS) {
            $issue->status = self::STATUS_RETRYING;
            $issue->lasterror = $e->getMessage();
            $issue->timemodified = time();
            $DB->update_record('virtualbadge_issues', $issue);
            throw $e;
        }
        self::fail($issue, $e->getMessage());
    }

    /**
     * The template of an activity among the active templates of its organisation.
     *
     * @param api_client $api
     * @param stdClass $instance
     * @return array|null
     */
    public static function find_template(api_client $api, stdClass $instance): ?array {
        foreach ($api->templates($instance->org) as $template) {
            if ($template['id'] === $instance->templateid) {
                return $template;
            }
        }
        return null;
    }

    /**
     * Refresh a pending issue. Called by the status task.
     *
     * @param int $issueid
     * @param int $check number of this check
     */
    public static function check_status(int $issueid, int $check): void {
        global $DB;
        $issue = $DB->get_record('virtualbadge_issues', ['id' => $issueid]);
        if (!$issue || $issue->status !== self::STATUS_PENDING || empty($issue->remoteid)) {
            return;
        }
        $instance = $DB->get_record('virtualbadge', ['id' => $issue->virtualbadgeid], '*', MUST_EXIST);
        $cm = get_fast_modinfo($instance->course)->get_instances_of('virtualbadge')[$instance->id];
        try {
            $result = \core\di::get(api_client::class)->status($instance->org, $issue->remoteid);
        } catch (api_exception $e) {
            if ($e->status === 404) {
                self::mark_deleted($issue);
                return;
            }
            $issue->lasterror = $e->getMessage();
            $issue->timemodified = time();
            $DB->update_record('virtualbadge_issues', $issue);
            self::schedule_status_check($issue->id, $check + 1);
            return;
        }
        self::apply_result($issue, $result, $instance, $cm);
        if ($issue->status === self::STATUS_PENDING) {
            self::schedule_status_check($issue->id, $check + 1);
        }
    }

    /**
     * Check issued certificates against Virtualbadge, the longest unchecked first.
     * Each organisation gets at most a tenth of its hourly limit per run.
     *
     * @return int number of checked issues
     */
    public static function verify_issued(): int {
        global $DB;
        $api = \core\di::get(api_client::class);
        $orgs = $DB->get_fieldset_sql(
            'SELECT DISTINCT org FROM {virtualbadge_issues} WHERE status = ? AND remoteid IS NOT NULL',
            [self::STATUS_ISSUED]
        );
        $checked = 0;
        foreach ($orgs as $org) {
            if (!$api->is_configured($org)) {
                continue;
            }
            $limit = config::rate_limit($org);
            $issues = $DB->get_records_select(
                'virtualbadge_issues',
                'org = :org AND status = :status AND remoteid IS NOT NULL AND COALESCE(timechecked, 0) < :before',
                ['org' => $org, 'status' => self::STATUS_ISSUED, 'before' => time() - self::VERIFY_INTERVAL],
                'COALESCE(timechecked, 0), id',
                '*',
                0,
                $limit > 0 ? max(1, intdiv($limit, 10)) : self::VERIFY_UNLIMITED
            );
            foreach ($issues as $issue) {
                try {
                    self::verify($api, $issue);
                } catch (api_exception $e) {
                    if ($e->is_temporary()) {
                        break;
                    }
                    $issue->timechecked = time();
                    $DB->update_record('virtualbadge_issues', $issue);
                }
                $checked++;
            }
        }
        return $checked;
    }

    /**
     * Check one issued certificate. A certificate replaced in Virtualbadge is followed to its replacement.
     *
     * @param api_client $api
     * @param stdClass $issue
     */
    protected static function verify(api_client $api, stdClass $issue): void {
        global $DB;
        try {
            $result = $api->status($issue->org, $issue->remoteid);
        } catch (api_exception $e) {
            if ($e->status !== 404) {
                throw $e;
            }
            self::mark_deleted($issue);
            return;
        }
        $issue->timechecked = time();
        $instance = $DB->get_record('virtualbadge', ['id' => $issue->virtualbadgeid]);
        $cm = $instance ? (get_fast_modinfo($instance->course)->get_instances_of('virtualbadge')[$instance->id] ?? null) : null;
        if (!$cm) {
            $DB->update_record('virtualbadge_issues', $issue);
            return;
        }
        if (($result['email_status'] ?? '') === 'voided') {
            $email = $DB->get_field('user', 'email', ['id' => $issue->userid]);
            $found = $email ? $api->find_replacement($issue->org, $issue->templateid, $email, $issue->remoteid) : null;
            if ($found) {
                $issue->previousremoteid = $issue->remoteid;
                $result = $found;
            }
        }
        self::apply_result($issue, $result, $instance, $cm);
        if ($issue->status === self::STATUS_PENDING) {
            self::schedule_status_check($issue->id, 0);
        }
    }

    /**
     * Mark an issue whose certificate no longer exists in Virtualbadge.
     *
     * @param stdClass $issue
     */
    protected static function mark_deleted(stdClass $issue): void {
        global $DB;
        $issue->status = self::STATUS_DELETED;
        $issue->lasterror = null;
        $issue->timechecked = time();
        $issue->timemodified = time();
        $DB->update_record('virtualbadge_issues', $issue);
    }

    /**
     * Store an API result and update activity completion.
     *
     * @param stdClass $issue
     * @param array $result
     * @param stdClass $instance
     * @param cm_info $cm
     */
    public static function apply_result(stdClass $issue, array $result, stdClass $instance, cm_info $cm): void {
        global $DB;
        $map = [
            'issued' => self::STATUS_ISSUED,
            'pending' => self::STATUS_PENDING,
            'revoked' => self::STATUS_REVOKED,
            'failed' => self::STATUS_FAILED,
        ];
        $issue->status = $map[$result['status'] ?? ''] ?? self::STATUS_PENDING;
        $issue->remoteid = $result['id'] ?? $issue->remoteid;
        $issue->certificateurl = $result['certificate_url'] ?? $issue->certificateurl ?? null;
        $issue->validationurl = $result['validation_url'] ?? $issue->validationurl ?? null;
        $issue->walleturl = $result['wallet_url'] ?? $issue->walleturl ?? null;
        $issue->lasterror = null;
        $firsttime = empty($issue->timeissued) && $issue->remoteid;
        if ($firsttime) {
            $issue->timeissued = time();
        }
        $issue->timemodified = time();
        $DB->update_record('virtualbadge_issues', $issue);
        if ($firsttime) {
            \mod_virtualbadge\event\certificate_issued::create([
                'objectid' => $issue->id,
                'relateduserid' => $issue->userid,
                'context' => \context_module::instance($cm->id),
                'other' => ['remoteid' => $issue->remoteid],
            ])->trigger();
        }

        if (!empty($instance->completionissued) && $issue->remoteid) {
            $course = get_course($instance->course);
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $issue->userid);
            }
        }
    }

    /**
     * Mark an issue as failed.
     *
     * @param stdClass $issue
     * @param string $error
     */
    protected static function fail(stdClass $issue, string $error): void {
        global $DB;
        $issue->status = self::STATUS_FAILED;
        $issue->lasterror = $error;
        $issue->timemodified = time();
        $DB->update_record('virtualbadge_issues', $issue);
        $cm = get_coursemodule_from_instance('virtualbadge', $issue->virtualbadgeid);
        if ($cm) {
            \mod_virtualbadge\event\issue_failed::create([
                'objectid' => $issue->id,
                'relateduserid' => $issue->userid,
                'context' => \context_module::instance($cm->id),
                'other' => ['error' => \core_text::substr($error, 0, 1000)],
            ])->trigger();
        }
    }

    /**
     * Recipient for the API request.
     *
     * @param stdClass $instance
     * @param array $template template from {@see api_client::templates()}
     * @param stdClass $course
     * @param stdClass $user
     * @param stdClass $issue
     * @return array
     * @throws field_value_missing if a mapped value is empty
     * @throws \moodle_exception if the template has fields without mapping
     */
    public static function recipient(
        stdClass $instance,
        array $template,
        stdClass $course,
        stdClass $user,
        stdClass $issue
    ): array {
        $map = json_decode($instance->fieldmap ?? '', true) ?: [];
        $missing = array_values(array_diff($template['fields'], array_keys($map)));
        if ($missing) {
            throw new \moodle_exception('templatechanged', 'mod_virtualbadge', '', (object) [
                'template' => format_string($template['name']),
                'fields' => implode(', ', $missing),
            ]);
        }
        $values = fieldsources::resolve(array_intersect_key($map, array_flip($template['fields'])), $course, $user, $issue);
        return [
            'email' => $user->email,
            'full_name' => trim(preg_replace('/\s+/u', ' ', $user->firstname . ' ' . $user->lastname)),
            'issue_date' => userdate($issue->completedat, '%Y-%m-%d', \core_date::get_server_timezone(), false),
            'dynamic_fields' => (object) ($values + $template['slots']),
            'metadata' => ['moodle_ref' => $issue->reference],
        ];
    }

    /**
     * Queue issues for all users who already meet the condition.
     *
     * @param stdClass $instance
     * @param cm_info $cm
     * @return int number of queued issues
     */
    public static function issue_retroactively(stdClass $instance, cm_info $cm): int {
        $source = $instance->triggertype === VIRTUALBADGE_TRIGGER_APPROVAL ? 'approval' : 'retroactive';
        $count = 0;
        foreach (conditions::pending_users($instance, $cm) as $userid => $time) {
            if (self::queue($instance, $cm, (int) $userid, $source, (int) $time)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Issue to selected participants: with trigger manual to anyone, otherwise to those who meet the condition.
     *
     * @param stdClass $instance
     * @param cm_info $cm
     * @param int[] $userids
     * @return int number of queued issues
     */
    public static function release_selected(stdClass $instance, cm_info $cm, array $userids): int {
        $manual = $instance->triggertype === VIRTUALBADGE_TRIGGER_MANUAL;
        $pending = $manual ? [] : conditions::pending_users($instance, $cm);
        $source = $instance->triggertype === VIRTUALBADGE_TRIGGER_APPROVAL ? 'approval' : 'retroactive';
        $count = 0;
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            if ($manual) {
                $issue = self::queue($instance, $cm, $userid, VIRTUALBADGE_TRIGGER_MANUAL, time());
            } else {
                $issue = isset($pending[$userid]) ? self::queue($instance, $cm, $userid, $source, (int) $pending[$userid]) : null;
            }
            if ($issue) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Release the certificate of one participant by a trainer.
     *
     * With trigger approval only participants who completed the course are released,
     * the completion date is kept for the certificate.
     *
     * @param stdClass $instance
     * @param cm_info $cm
     * @param int $userid
     * @return stdClass|null queued issue
     */
    public static function release(stdClass $instance, cm_info $cm, int $userid): ?stdClass {
        if ($instance->triggertype === VIRTUALBADGE_TRIGGER_MANUAL) {
            return self::queue($instance, $cm, $userid, VIRTUALBADGE_TRIGGER_MANUAL, time());
        }
        if ($instance->triggertype !== VIRTUALBADGE_TRIGGER_APPROVAL) {
            return null;
        }
        $completed = conditions::pending_users($instance, $cm)[$userid] ?? null;
        return $completed ? self::queue($instance, $cm, $userid, VIRTUALBADGE_TRIGGER_APPROVAL, (int) $completed) : null;
    }

    /**
     * Try a failed issue again, or issue a new certificate for one deleted in Virtualbadge.
     *
     * @param stdClass $issue
     * @return bool
     */
    public static function retry(stdClass $issue): bool {
        global $DB;
        if (!in_array($issue->status, [self::STATUS_FAILED, self::STATUS_DELETED])) {
            return false;
        }
        if ($issue->status === self::STATUS_DELETED) {
            $reset = ['remoteid', 'previousremoteid', 'certificateurl', 'validationurl', 'walleturl', 'timeissued',
                'timeemailsent', 'timechecked'];
            foreach ($reset as $field) {
                $issue->$field = null;
            }
            $issue->emailsent = 0;
        }
        $issue->status = self::STATUS_QUEUED;
        $issue->attempts = 0;
        $issue->lasterror = null;
        $issue->timemodified = time();
        $DB->update_record('virtualbadge_issues', $issue);
        self::schedule_issue($issue->id);
        return true;
    }
}
