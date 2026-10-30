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

namespace mod_virtualbadge\output;

use cm_info;
use mod_virtualbadge\local\api_client;
use mod_virtualbadge\local\conditions;
use mod_virtualbadge\local\issuer;
use mod_virtualbadge\local\template_check;
use moodle_url;
use single_button;
use stdClass;

/**
 * Renderer for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /** @var array status => bootstrap badge class */
    protected const STATUS_CLASSES = [
        'none' => 'bg-secondary text-dark',
        issuer::STATUS_QUEUED => 'mod_virtualbadge-progress',
        issuer::STATUS_PENDING => 'mod_virtualbadge-progress',
        issuer::STATUS_RETRYING => 'bg-warning text-dark',
        issuer::STATUS_ISSUED => 'bg-success',
        issuer::STATUS_REVOKED => 'bg-dark',
        issuer::STATUS_DELETED => 'bg-dark',
        issuer::STATUS_FAILED => 'bg-danger',
    ];

    /**
     * Own certificate of a learner.
     *
     * @param stdClass $instance
     * @param int $userid
     * @return string
     */
    public function learner_view(stdClass $instance, int $userid): string {
        global $DB;
        $issue = $DB->get_record('virtualbadge_issues', ['virtualbadgeid' => $instance->id, 'userid' => $userid]);
        $status = $issue->status ?? 'none';
        $hasurl = $issue && !empty($issue->certificateurl)
            && in_array($status, [issuer::STATUS_ISSUED, issuer::STATUS_PENDING]);

        return $this->render_from_template('mod_virtualbadge/learner_view', [
            'templatename' => format_string($instance->templatename),
            'condition' => $this->condition($instance),
            'status' => $this->status($status),
            'hasurl' => $hasurl,
            'certificateurl' => $hasurl ? api_client::https_url($issue->certificateurl) : null,
            'validationurl' => $hasurl ? api_client::https_url($issue->validationurl) : null,
            'walleturl' => $hasurl ? api_client::https_url($issue->walleturl) : null,
            'inprogress' => in_array($status, [issuer::STATUS_QUEUED, issuer::STATUS_RETRYING, issuer::STATUS_PENDING]),
            'timeissued' => !empty($issue->timeissued)
                ? userdate($issue->timeissued, get_string('strftimedatetime', 'langconfig'))
                : null,
        ]);
    }

    /**
     * Status list for trainers.
     *
     * @param stdClass $instance
     * @param cm_info $cm
     * @param moodle_url $url
     * @param bool $canmanage show release and issue actions
     * @return string
     */
    public function trainer_view(stdClass $instance, cm_info $cm, moodle_url $url, bool $canmanage = true): string {
        global $DB;
        $context = \context_module::instance($cm->id);
        $users = get_enrolled_users($context, 'mod/virtualbadge:receive', 0, 'u.*', 'u.lastname, u.firstname', 0, 0, true);
        $issues = [];
        foreach ($DB->get_records('virtualbadge_issues', ['virtualbadgeid' => $instance->id]) as $issue) {
            $issues[$issue->userid] = $issue;
        }
        $manual = $instance->triggertype === VIRTUALBADGE_TRIGGER_MANUAL;
        $approval = $instance->triggertype === VIRTUALBADGE_TRIGGER_APPROVAL;
        $eligible = $manual ? [] : conditions::pending_users($instance, $cm);
        $showemail = in_array('email', \core_user\fields::get_identity_fields($context, false));

        $rows = [];
        $counts = [];
        $selectable = false;
        foreach ($users as $user) {
            $issue = $issues[$user->id] ?? null;
            $status = $issue->status ?? 'none';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $releasable = $manual || ($approval && isset($eligible[$user->id]));
            $action = $canmanage ? $this->actions($issue, $releasable, $url, (int) $user->id) : '';
            $canselect = $canmanage && !$issue && ($manual || isset($eligible[$user->id]));
            $selectable = $selectable || $canselect;
            $rows[] = [
                'userid' => $user->id,
                'selectable' => $canselect,
                'fullname' => fullname($user),
                'profileurl' => (new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $cm->course]))->out(false),
                'email' => $showemail ? $user->email : '',
                'status' => $this->status($status),
                'eligible' => !$issue && isset($eligible[$user->id]) ? ($approval
                    ? get_string('coursecompletedon', 'mod_virtualbadge', userdate(
                        $eligible[$user->id],
                        get_string('strftimedatefullshort', 'langconfig')
                    ))
                    : get_string('conditionmet', 'mod_virtualbadge')) : '',
                'timeissued' => !empty($issue->timeissued)
                    ? userdate($issue->timeissued, get_string('strftimedatetimeshort', 'langconfig'))
                    : '',
                'validationurl' => $issue && $issue->status === issuer::STATUS_ISSUED
                    ? api_client::https_url($issue->validationurl)
                    : null,
                'error' => $issue->lasterror ?? '',
                'emailinfo' => !empty($issue->emailsent) ? get_string('emailsentinfo', 'mod_virtualbadge', (object) [
                    'count' => $issue->emailsent,
                    'time' => userdate($issue->timeemailsent, get_string('strftimedatetimeshort', 'langconfig')),
                ]) : '',
                'replaced' => !empty($issue->previousremoteid),
                'action' => $action,
            ];
        }

        $summary = [];
        foreach (array_keys(self::STATUS_CLASSES) as $status) {
            if (!empty($counts[$status])) {
                $summary[] = ['status' => $this->status($status), 'count' => $counts[$status]];
            }
        }

        return $this->render_from_template('mod_virtualbadge/trainer_view', [
            'warning' => $this->template_warning($instance),
            'editurl' => $canmanage
                ? (new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]))->out(false)
                : null,
            'condition' => $this->condition($instance),
            'templatename' => format_string($instance->templatename),
            'summary' => $summary,
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'showemail' => $showemail,
            'selectform' => $selectable ? [
                'formid' => \html_writer::random_id('mod_virtualbadge-select'),
                'url' => $url->out(false),
                'sesskey' => sesskey(),
                'label' => get_string($manual || $approval ? 'releaseselected' : 'issueselected', 'mod_virtualbadge'),
            ] : null,
            'retroactiveinfo' => get_string(
                $approval ? 'releaseall_info' : 'retroactive_info',
                'mod_virtualbadge',
                count($eligible)
            ),
            'retroactive' => $eligible && $canmanage
                ? $this->button($url, 'retroactive', [], get_string(
                    $approval ? 'releaseall' : 'retroactive',
                    'mod_virtualbadge',
                    count($eligible)
                ))
                : '',
        ]);
    }

    /**
     * Warning if the template changed since the activity was set up.
     *
     * @param stdClass $instance
     * @return string|null
     */
    protected function template_warning(stdClass $instance): ?string {
        $problems = template_check::problems($instance);
        if ($problems === null) {
            return null;
        }
        $template = format_string($instance->templatename);
        if (!empty($problems['unavailable'])) {
            return get_string('templateunavailable', 'mod_virtualbadge', $template);
        }
        return get_string('templatechanged', 'mod_virtualbadge', (object) [
            'template' => $template,
            'fields' => implode(', ', $problems['missing']),
        ]);
    }

    /**
     * Action buttons for one participant.
     *
     * @param stdClass|null $issue
     * @param bool $releasable a trainer can release the certificate
     * @param moodle_url $url
     * @param int $userid
     * @return string
     */
    protected function actions(?stdClass $issue, bool $releasable, moodle_url $url, int $userid): string {
        if (!$issue) {
            return $releasable ? $this->button($url, 'release', ['userid' => $userid]) : '';
        }
        if ($issue->status === issuer::STATUS_FAILED) {
            return $this->button($url, 'retry', ['issueid' => $issue->id]);
        }
        if ($issue->status === issuer::STATUS_DELETED) {
            return $this->button($url, 'retry', ['issueid' => $issue->id], get_string('action_issuenew', 'mod_virtualbadge'));
        }
        if (!in_array($issue->status, [issuer::STATUS_ISSUED, issuer::STATUS_PENDING]) || empty($issue->remoteid)) {
            return '';
        }
        $html = '';
        if ($issue->emailsent < issuer::MAX_EMAILS) {
            $html .= $this->button($url, 'resendemail', ['issueid' => $issue->id]);
        }
        $target = new moodle_url($url, ['action' => 'reissue', 'issueid' => $issue->id]);
        $button = new single_button($target, get_string('action_reissue', 'mod_virtualbadge'), 'post');
        $button->add_confirm_action(get_string('reissue_confirm', 'mod_virtualbadge'));
        return $html . $this->render($button);
    }

    /**
     * Status label and CSS class.
     *
     * @param string $status
     * @return array
     */
    protected function status(string $status): array {
        return [
            'label' => get_string('status_' . $status, 'mod_virtualbadge'),
            'class' => self::STATUS_CLASSES[$status] ?? 'bg-secondary text-dark',
        ];
    }

    /**
     * Description of the trigger.
     *
     * @param stdClass $instance
     * @return string
     */
    protected function condition(stdClass $instance): string {
        if ($instance->triggertype === VIRTUALBADGE_TRIGGER_GRADE) {
            $grade = format_float((float) $instance->mingrade, 0);
            $item = empty($instance->gradeitemid) ? null : \grade_item::fetch(['id' => $instance->gradeitemid]);
            if ($item) {
                return get_string('condition_gradeitem', 'mod_virtualbadge', (object) [
                    'item' => format_string($item->get_name()),
                    'grade' => $grade,
                ]);
            }
            return get_string('condition_grade', 'mod_virtualbadge', $grade);
        }
        return get_string('condition_' . $instance->triggertype, 'mod_virtualbadge');
    }

    /**
     * POST button for an action of this page.
     *
     * @param moodle_url $url
     * @param string $action
     * @param array $params
     * @param string|null $label
     * @return string
     */
    protected function button(moodle_url $url, string $action, array $params, ?string $label = null): string {
        $target = new moodle_url($url, ['action' => $action] + $params);
        $button = new single_button($target, $label ?? get_string('action_' . $action, 'mod_virtualbadge'), 'post');
        return $this->render($button);
    }
}
