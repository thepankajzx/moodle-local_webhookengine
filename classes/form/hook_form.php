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
use local_webhookengine\hook_repository;
use local_webhookengine\payload_builder;
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

        // Selectable in autocomplete with custom event tagging support.
        $eventoptions = event_catalog::get_event_options();
        $placeholder = get_string('search_events', 'local_webhookengine');

        $mform->addElement('autocomplete', 'events', get_string('select_events', 'local_webhookengine'), $eventoptions, [
            'multiple' => true,
            'placeholder' => $placeholder,
            'tags' => true,
        ]);
        $mform->addRule('events', null, 'required', null, 'client');

        $hinthtml = html_writer::div(
            get_string('custom_events_hint', 'local_webhookengine'),
            'small text-muted mt-1 fst-italic'
        );
        $mform->addElement('html', $hinthtml);

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

        // Authentication & Security Header.
        $mform->addElement('header', 'securityhdr', get_string('auth_and_security', 'local_webhookengine'));

        $authoptions = [
            'standard' => get_string('auth_mode_standard', 'local_webhookengine'),
            'oauth2' => get_string('auth_mode_oauth2', 'local_webhookengine'),
        ];
        $mform->addElement('select', 'authmode', get_string('auth_mode', 'local_webhookengine'), $authoptions);
        $mform->setDefault('authmode', 'standard');

        // OAuth2 fields.
        $mform->addElement('text', 'oauth2tokenurl', get_string('oauth2_token_url', 'local_webhookengine'), ['size' => '60']);
        $mform->setType('oauth2tokenurl', PARAM_URL);
        $mform->addHelpButton('oauth2tokenurl', 'oauth2_token_url', 'local_webhookengine');
        $mform->hideIf('oauth2tokenurl', 'authmode', 'neq', 'oauth2');

        $mform->addElement('text', 'oauth2clientid', get_string('oauth2_client_id', 'local_webhookengine'), ['size' => '50']);
        $mform->setType('oauth2clientid', PARAM_TEXT);
        $mform->addHelpButton('oauth2clientid', 'oauth2_client_id', 'local_webhookengine');
        $mform->hideIf('oauth2clientid', 'authmode', 'neq', 'oauth2');

        $mform->addElement(
            'passwordunmask',
            'oauth2clientsecret',
            get_string('oauth2_client_secret', 'local_webhookengine'),
            ['size' => '50']
        );
        $mform->setType('oauth2clientsecret', PARAM_RAW);
        $mform->addHelpButton('oauth2clientsecret', 'oauth2_client_secret', 'local_webhookengine');
        $mform->hideIf('oauth2clientsecret', 'authmode', 'neq', 'oauth2');

        $mform->addElement('text', 'oauth2scope', get_string('oauth2_scope', 'local_webhookengine'), ['size' => '40']);
        $mform->setType('oauth2scope', PARAM_TEXT);
        $mform->addHelpButton('oauth2scope', 'oauth2_scope', 'local_webhookengine');
        $mform->hideIf('oauth2scope', 'authmode', 'neq', 'oauth2');

        // Mutual TLS (mTLS) client certificate toggle.
        $mform->addElement('advcheckbox', 'usemtls', get_string('use_mtls', 'local_webhookengine'));
        $mform->addHelpButton('usemtls', 'use_mtls', 'local_webhookengine');
        $mform->setDefault('usemtls', 0);

        // Network & Delivery Header.
        $mform->addElement('header', 'networkhdr', get_string('network_delivery', 'local_webhookengine'));

        $mform->addElement('textarea', 'customheaders', get_string('custom_headers', 'local_webhookengine'), 'rows="4" cols="50"');
        $mform->setType('customheaders', PARAM_RAW);
        $mform->addHelpButton('customheaders', 'custom_headers', 'local_webhookengine');

        if ($hook && !empty($hook->headers)) {
            $mform->addElement('advcheckbox', 'clearheaders', get_string('clear_headers', 'local_webhookengine'));
            $mform->setDefault('clearheaders', 0);
        }

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
                $normalized = hook_repository::normalize_event_name((string) $eventname);
                if (event_catalog::is_denylisted($normalized)) {
                    $errors['events'] = get_string('error_event_denylisted', 'local_webhookengine', $normalized);
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

        // 5. OAuth2 validation.
        if (!empty($data['authmode']) && $data['authmode'] === 'oauth2') {
            if (empty($data['oauth2tokenurl'])) {
                $errors['oauth2tokenurl'] = get_string('error_oauth2_missing_url', 'local_webhookengine');
            } else {
                $tokenurlerr = url_validator::validate_for_config($data['oauth2tokenurl']);
                if ($tokenurlerr !== null) {
                    $errors['oauth2tokenurl'] = get_string($tokenurlerr, 'local_webhookengine');
                }
            }

            if (empty($data['oauth2clientid'])) {
                $errors['oauth2clientid'] = get_string('required');
            }

            $hook = $this->_customdata['hook'] ?? null;
            $hasexistingsecret = $hook && !empty($hook->oauth2clientsecret);
            if (empty($data['oauth2clientsecret']) && !$hasexistingsecret) {
                $errors['oauth2clientsecret'] = get_string('required');
            }
        }

        // 6. Timeout validation.
        $timeout = (int) ($data['timeout'] ?? 10);
        if ($timeout < 1 || $timeout > 30) {
            $errors['timeout'] = get_string('error_timeout_range', 'local_webhookengine');
        }

        // 7. Max attempts validation.
        $maxattempts = (int) ($data['maxattempts'] ?? 9);
        if ($maxattempts < 1 || $maxattempts > 10) {
            $errors['maxattempts'] = get_string('error_maxattempts_range', 'local_webhookengine');
        }

        return $errors;
    }
}
