<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_webhookengine\form;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use local_webhookengine\event_catalog;
use local_webhookengine\payload_builder;
use local_webhookengine\pro_unlock;
use local_webhookengine\url_validator;
use moodleform;

require_once($CFG->libdir . '/formslib.php');

/**
 * Webhook configuration edit/create moodleform.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_form extends moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $hook = $this->_customdata['hook'] ?? null;

        $mform->addElement('hidden', 'id', $hook ? $hook->id : 0);
        $mform->setType('id', PARAM_INT);

        // General Header.
        $mform->addElement('header', 'generalhdr', get_string('general'));

        $mform->addElement('text', 'name', get_string('hook_name', 'local_webhookengine'), ['size' => '50']);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        $mform->addElement('text', 'url', get_string('hook_url', 'local_webhookengine'), ['size' => '60']);
        $mform->addRule('url', null, 'required', null, 'client');
        $mform->setType('url', PARAM_URL);
        $mform->addHelpButton('url', 'hook_url', 'local_webhookengine');

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_webhookengine'));
        $mform->setDefault('enabled', 1);

        // Events Subscription Header.
        $mform->addElement('header', 'eventshdr', get_string('events_subscription', 'local_webhookengine'));

        

        // Free/Pro events: selectable in autocomplete with custom event tagging support in Pro.
        $ispro = pro_unlock::is_pro();
        if ($ispro) {
            $eventoptions = event_catalog::get_event_options();
            $placeholder = get_string('search_all_events', 'local_webhookengine');
        } else {
            $tiered = event_catalog::get_event_options_with_tier();
            $eventoptions = $tiered['free'];
            $placeholder = get_string('search_events', 'local_webhookengine');
        }

        $mform->addElement('autocomplete', 'events', get_string('select_events', 'local_webhookengine'), $eventoptions, [
            'multiple' => true,
            'placeholder' => $placeholder,
            'tags' => $ispro,
        ]);
        $mform->addRule('events', null, 'required', null, 'client');

        if ($ispro) {
            $hinthtml = html_writer::div(
                get_string('custom_events_hint', 'local_webhookengine'),
                'small text-muted mt-1 fst-italic'
            );
            $mform->addElement('html', $hinthtml);
        }

        

        // Filters Header.
        $mform->addElement('header', 'filtershdr', get_string('filters', 'local_webhookengine'));

        $mform->addElement('text', 'filter_courseids', get_string('filter_courseids', 'local_webhookengine'), ['size' => '40']);
        $mform->setType('filter_courseids', PARAM_SEQUENCE);
        $mform->addHelpButton('filter_courseids', 'filter_courseids', 'local_webhookengine');

        $mform->addElement('text', 'filter_categoryids', get_string('filter_categoryids', 'local_webhookengine'), ['size' => '40']);
        $mform->setType('filter_categoryids', PARAM_SEQUENCE);
        $mform->addHelpButton('filter_categoryids', 'filter_categoryids', 'local_webhookengine');

        $mform->addElement('advcheckbox', 'filter_includesubcats', get_string('filter_includesubcats', 'local_webhookengine'));
        $mform->setDefault('filter_includesubcats', 1);

        $mform->addElement('advcheckbox', 'filter_realusersonly', get_string('filter_realusersonly', 'local_webhookengine'));
        $mform->setDefault('filter_realusersonly', 1);
        $mform->addHelpButton('filter_realusersonly', 'filter_realusersonly', 'local_webhookengine');

        // Payload Options Header.
        $mform->addElement('header', 'payloadhdr', get_string('payload_options', 'local_webhookengine'));

        $mform->addElement('advcheckbox', 'opt_includeother', get_string('payload_includeother', 'local_webhookengine'));
        $mform->addElement('text', 'opt_otherkeys', get_string('payload_otherkeys', 'local_webhookengine'), ['size' => '40']);
        $mform->setType('opt_otherkeys', PARAM_TEXT);
        $mform->hideIf('opt_otherkeys', 'opt_includeother', 'notchecked');

        $mform->addElement('advcheckbox', 'opt_includeuser', get_string('payload_includeuser', 'local_webhookengine'));
        $mform->addElement('advcheckbox', 'opt_includeemail', get_string('payload_includeemail', 'local_webhookengine'));
        $mform->hideIf('opt_includeemail', 'opt_includeuser', 'notchecked');

        $mform->addElement('advcheckbox', 'opt_includecourse', get_string('payload_includecourse', 'local_webhookengine'));

        $mform->addElement('textarea', 'opt_template', get_string('payload_template', 'local_webhookengine'), 'rows="5" cols="60"');
        $mform->setType('opt_template', PARAM_RAW);
        $mform->addHelpButton('opt_template', 'payload_template', 'local_webhookengine');

        // Network & Delivery Header.
        $mform->addElement('header', 'networkhdr', get_string('network_delivery', 'local_webhookengine'));

        $mform->addElement('textarea', 'customheaders', get_string('custom_headers', 'local_webhookengine'), 'rows="4" cols="50"');
        $mform->setType('customheaders', PARAM_RAW);
        $mform->addHelpButton('customheaders', 'custom_headers', 'local_webhookengine');

        $mform->addElement('text', 'timeout', get_string('timeout_seconds', 'local_webhookengine'), ['size' => '5']);
        $mform->setType('timeout', PARAM_INT);
        $mform->setDefault('timeout', 10);

        $mform->addElement('text', 'maxattempts', get_string('max_attempts', 'local_webhookengine'), ['size' => '5']);
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 9);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Form validation.
     *
     * @param array $data Submitted form data.
     * @param array $files Uploaded files.
     * @return array Validation errors array.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // 0. Free tier hook limit validation.
        $hook = $this->_customdata['hook'] ?? null;
        if (empty($hook) && !pro_unlock::can_create_hook()) {
            $errors['name'] = get_string('free_hook_limit_reached', 'local_webhookengine', pro_unlock::FREE_MAX_HOOKS);
        }

        // 1. URL validation.
        if (empty($data['url'])) {
            $errors['url'] = get_string('required');
        } else {
            $urlerr = url_validator::validate_for_config($data['url']);
            if ($urlerr !== null) {
                $errors['url'] = get_string($urlerr, 'local_webhookengine');
            }
        }

        // 2. Events validation.
        if (empty($data['events']) || !is_array($data['events'])) {
            $errors['events'] = get_string('error_no_events_selected', 'local_webhookengine');
        } else {
            foreach ($data['events'] as $eventname) {
                if (event_catalog::is_denylisted($eventname)) {
                    $errors['events'] = get_string('error_event_denylisted', 'local_webhookengine', $eventname);
                    break;
                }
            }
        }

        // 3. Custom template validation.
        if (!empty($data['opt_template'])) {
            if (strlen($data['opt_template']) > 16384) {
                $errors['opt_template'] = get_string('error_template_too_large', 'local_webhookengine');
            } else {
                $tmplerr = payload_builder::validate_template($data['opt_template']);
                if ($tmplerr !== null) {
                    $errors['opt_template'] = $tmplerr;
                }
            }
        }

        // 4. Custom headers validation.
        if (!empty($data['customheaders'])) {
            $lines = preg_split('/[\r\n]+/', trim($data['customheaders']));
            if (count($lines) > 10) {
                $errors['customheaders'] = get_string('error_too_many_headers', 'local_webhookengine');
            } else {
                $reserved = [
                    'host',
                    'content-type',
                    'content-length',
                    'user-agent',
                    'webhook-id',
                    'webhook-timestamp',
                    'webhook-signature',
                    'x-webhook-event',
                ];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }
                    if (!preg_match('/^([A-Za-z0-9-]+)\s*:\s*(.+)$/', $line, $matches)) {
                        $errors['customheaders'] = get_string('error_invalid_header_format', 'local_webhookengine', s($line));
                        break;
                    }
                    $headername = strtolower($matches[1]);
                    $isreserved = in_array($headername, $reserved)
                        || str_starts_with($headername, 'webhook-')
                        || str_starts_with($headername, 'x-webhook-');
                    if ($isreserved) {
                        $errors['customheaders'] = get_string('error_reserved_header', 'local_webhookengine', s($matches[1]));
                        break;
                    }
                }
            }
        }

        // 5. Timeout validation.
        $timeout = (int) ($data['timeout'] ?? 10);
        if ($timeout < 1 || $timeout > 30) {
            $errors['timeout'] = get_string('error_timeout_range', 'local_webhookengine');
        }

        // 6. Max attempts validation.
        $maxattempts = (int) ($data['maxattempts'] ?? 9);
        if ($maxattempts < 1 || $maxattempts > 10) {
            $errors['maxattempts'] = get_string('error_maxattempts_range', 'local_webhookengine');
        }

        return $errors;
    }
}
