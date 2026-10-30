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
 * Activity settings form for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_virtualbadge\local\conditions;
use mod_virtualbadge\local\config;
use mod_virtualbadge\local\fieldsources;
use mod_virtualbadge\local\api_client;
use mod_virtualbadge\local\api_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/virtualbadge/lib.php');

/**
 * Activity settings form.
 *
 * Templates of every configured organisation are loaded once. Selects and field
 * inputs that do not belong to the chosen organisation and template are hidden.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_virtualbadge_mod_form extends moodleform_mod {
    /** @var array org => list of templates */
    protected array $templates = [];

    /** @var array org => error message */
    protected array $errors = [];

    /** @var array|null options of the source selects */
    protected ?array $sourceoptions = null;

    /**
     * Form definition.
     */
    public function definition() {
        global $OUTPUT;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        $mform->addElement('header', 'issuing', get_string('issuing', 'mod_virtualbadge'));
        $mform->setExpanded('issuing');

        $orgs = config::allowed_orgs((int) $this->get_course()->id, $this->current->org ?? null);
        $api = \core\di::get(api_client::class);
        if (!$api->is_configured(config::MAIN_ORG)) {
            $mform->addElement(
                'static',
                'notconfigured',
                '',
                $OUTPUT->notification(get_string('notconfigured', 'mod_virtualbadge'), 'error', false)
            );
            $mform->addElement('hidden', 'org', '');
            $mform->setType('org', PARAM_ALPHANUMEXT);
        } else {
            $this->define_org_elements($orgs, $api);
        }

        $mform->addElement('select', 'triggertype', get_string('triggertype', 'mod_virtualbadge'), [
            VIRTUALBADGE_TRIGGER_COMPLETION => get_string('trigger_completion', 'mod_virtualbadge'),
            VIRTUALBADGE_TRIGGER_APPROVAL => get_string('trigger_approval', 'mod_virtualbadge'),
            VIRTUALBADGE_TRIGGER_GRADE => get_string('trigger_grade', 'mod_virtualbadge'),
            VIRTUALBADGE_TRIGGER_MANUAL => get_string('trigger_manual', 'mod_virtualbadge'),
        ]);
        $mform->addHelpButton('triggertype', 'triggertype', 'mod_virtualbadge');
        $mform->setDefault('triggertype', VIRTUALBADGE_TRIGGER_COMPLETION);

        $items = [0 => get_string('gradeitem_course', 'mod_virtualbadge')] + array_map(
            'format_string',
            conditions::grade_items((int) $this->get_course()->id)
        );
        $mform->addElement('select', 'gradeitemid', get_string('gradeitem', 'mod_virtualbadge'), $items);
        $mform->addHelpButton('gradeitemid', 'gradeitem', 'mod_virtualbadge');
        $mform->hideIf('gradeitemid', 'triggertype', 'neq', VIRTUALBADGE_TRIGGER_GRADE);

        $mform->addElement('text', 'mingrade', get_string('mingrade', 'mod_virtualbadge'), ['size' => 6]);
        $mform->setType('mingrade', PARAM_FLOAT);
        $mform->setDefault('mingrade', 50);
        $mform->addHelpButton('mingrade', 'mingrade', 'mod_virtualbadge');
        $mform->hideIf('mingrade', 'triggertype', 'neq', VIRTUALBADGE_TRIGGER_GRADE);

        $mform->addElement('advcheckbox', 'sendemail', get_string('sendemail', 'mod_virtualbadge'));
        $mform->addHelpButton('sendemail', 'sendemail', 'mod_virtualbadge');
        $mform->setDefault('sendemail', 1);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Organisation select, one template select per organisation and the template fields.
     *
     * @param array $orgs org key => name
     * @param api_client $api
     */
    protected function define_org_elements(array $orgs, api_client $api): void {
        global $OUTPUT;
        $mform = $this->_form;

        $default = config::course_org((int) $this->get_course()->id);
        if (count($orgs) > 1) {
            $mform->addElement('select', 'org', get_string('org', 'mod_virtualbadge'), $orgs);
            $mform->addHelpButton('org', 'org', 'mod_virtualbadge');
            $mform->setDefault('org', isset($orgs[$default]) ? $default : array_key_first($orgs));
        } else {
            $mform->addElement('hidden', 'org', array_key_first($orgs));
            $mform->setType('org', PARAM_ALPHANUMEXT);
        }

        $mform->registerNoSubmitButton('refreshtemplates');
        $mform->addElement('submit', 'refreshtemplates', get_string('refreshtemplates', 'mod_virtualbadge'), null, false);
        $refresh = optional_param('refreshtemplates', '', PARAM_RAW) !== '';

        foreach (array_keys($orgs) as $org) {
            try {
                $this->templates[$org] = $api->templates($org, $refresh);
            } catch (api_exception $e) {
                $this->templates[$org] = [];
                $this->errors[$org] = $e->getMessage();
            }

            if (isset($this->errors[$org])) {
                $mform->addElement(
                    'static',
                    'error_' . $org,
                    '',
                    $OUTPUT->notification(
                        get_string('templatesunavailable', 'mod_virtualbadge', s($this->errors[$org])),
                        'error',
                        false
                    )
                );
                $mform->hideIf('error_' . $org, 'org', 'neq', $org);
            }

            $options = ['' => get_string('choosedots')];
            foreach ($this->templates[$org] as $t) {
                $options[$t['id']] = $t['name'] . ' (' . get_string('type_' . $t['type'], 'mod_virtualbadge') . ')';
            }
            $element = 'template_' . $org;
            $mform->addElement('select', $element, get_string('template', 'mod_virtualbadge'), $options);
            $mform->addHelpButton($element, 'template', 'mod_virtualbadge');
            $mform->hideIf($element, 'org', 'neq', $org);

            foreach ($this->templates[$org] as $t) {
                foreach ($t['fields'] as $field) {
                    $this->define_field_elements($org, $t['id'], $field, $element);
                }
            }
        }
    }

    /**
     * Source select and fixed text input for one template field.
     *
     * @param string $org
     * @param string $templateid
     * @param string $field
     * @param string $templateelement name of the template select
     */
    protected function define_field_elements(string $org, string $templateid, string $field, string $templateelement): void {
        $mform = $this->_form;
        $name = self::field_element($org, $templateid, $field);

        $mform->addElement(
            'selectgroups',
            $name . '_src',
            get_string('templatefield', 'mod_virtualbadge', s($field)),
            $this->sourceoptions ??= fieldsources::options((int) $this->get_course()->id)
        );
        $mform->setDefault($name . '_src', fieldsources::suggest($field));
        $mform->addHelpButton($name . '_src', 'templatefield', 'mod_virtualbadge');

        $mform->addElement('text', $name . '_val', get_string('templatefieldtext', 'mod_virtualbadge', s($field)), ['size' => 48]);
        $mform->setType($name . '_val', PARAM_TEXT);

        foreach ([$name . '_src', $name . '_val'] as $element) {
            $mform->hideIf($element, 'org', 'neq', $org);
            $mform->hideIf($element, $templateelement, 'neq', $templateid);
        }
        $mform->hideIf($name . '_val', $name . '_src', 'neq', fieldsources::TEXT);
    }

    /**
     * Form element name for a template field.
     *
     * @param string $org
     * @param string $templateid
     * @param string $field
     * @return string
     */
    protected static function field_element(string $org, string $templateid, string $field): string {
        return 'field_' . substr(sha1($org . '|' . $templateid . '|' . $field), 0, 12);
    }

    /**
     * The template record for an organisation and template id.
     *
     * @param string $org
     * @param string $templateid
     * @return array|null
     */
    protected function find_template(string $org, string $templateid): ?array {
        foreach ($this->templates[$org] ?? [] as $t) {
            if ($t['id'] === $templateid) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Fill template select and field inputs from the stored instance.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (empty($defaultvalues['org']) || empty($defaultvalues['templateid'])) {
            return;
        }
        $org = $defaultvalues['org'];
        $defaultvalues['template_' . $org] = $defaultvalues['templateid'];
        $map = json_decode($defaultvalues['fieldmap'] ?? '', true) ?: [];
        foreach ($map as $field => $entry) {
            $entry = fieldsources::normalize($entry);
            $name = self::field_element($org, $defaultvalues['templateid'], $field);
            $defaultvalues[$name . '_src'] = $entry['source'];
            $defaultvalues[$name . '_val'] = $entry['value'];
        }
    }

    /**
     * Collect template id, name and field values of the chosen organisation.
     *
     * @param stdClass $data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        $org = $data->org ?? '';
        $data->templateid = (string) ($data->{'template_' . $org} ?? '');
        $template = $this->find_template($org, $data->templateid);
        $data->templatename = $template['name'] ?? '';
        $map = [];
        foreach ($template['fields'] ?? [] as $field) {
            $name = self::field_element($org, $data->templateid, $field);
            $source = (string) ($data->{$name . '_src'} ?? fieldsources::TEXT);
            $value = $source === fieldsources::TEXT ? trim((string) ($data->{$name . '_val'} ?? '')) : '';
            $map[$field] = ['source' => $source, 'value' => $value];
        }
        $data->fieldmap = json_encode($map, JSON_UNESCAPED_UNICODE);

        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $automatic = !empty($data->{'completion' . $suffix})
                && $data->{'completion' . $suffix} == COMPLETION_TRACKING_AUTOMATIC;
            if (!$automatic) {
                $data->{'completionissued' . $suffix} = 0;
            }
        }
    }

    /**
     * Completion rule: activity complete once the certificate is issued.
     *
     * @return array element names
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $name = 'completionissued' . $this->get_suffix();
        $mform->addElement('advcheckbox', $name, '', get_string('completionissued', 'mod_virtualbadge'));
        $mform->addHelpButton($name, 'completionissued', 'mod_virtualbadge');
        return [$name];
    }

    /**
     * Whether the custom completion rule is enabled.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionissued' . $this->get_suffix()]);
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $org = $data['org'] ?? '';
        $orgs = config::allowed_orgs((int) $this->get_course()->id, $this->current->org ?? null);

        if (!isset($orgs[$org])) {
            $errors['org'] = get_string('required');
            return $errors;
        }
        $element = 'template_' . $org;
        $template = $this->find_template($org, (string) ($data[$element] ?? ''));
        if ($template === null) {
            $errors[$element] = get_string('required');
        } else {
            foreach ($template['fields'] as $field) {
                $name = self::field_element($org, $template['id'], $field);
                $source = (string) ($data[$name . '_src'] ?? '');
                if (!fieldsources::is_valid($source, (int) $this->get_course()->id)) {
                    $errors[$name . '_src'] = get_string('required');
                } else if ($source === fieldsources::TEXT && trim((string) ($data[$name . '_val'] ?? '')) === '') {
                    $errors[$name . '_val'] = get_string('required');
                }
            }
        }

        if (($data['triggertype'] ?? '') === VIRTUALBADGE_TRIGGER_GRADE) {
            $grade = (float) ($data['mingrade'] ?? 0);
            if ($grade <= 0 || $grade > 100) {
                $errors['mingrade'] = get_string('mingrade_invalid', 'mod_virtualbadge');
            }
            $itemid = (int) ($data['gradeitemid'] ?? 0);
            if ($itemid && !isset(conditions::grade_items((int) $this->get_course()->id)[$itemid])) {
                $errors['gradeitemid'] = get_string('required');
            }
        }
        return $errors;
    }
}
