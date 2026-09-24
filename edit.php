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

/**
 * Webhook create / edit page.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_webhookengine\form\hook_form;
use local_webhookengine\hook_repository;
use local_webhookengine\pro_unlock;

require_login();
$context = context_system::instance();
require_capability('local/webhookengine:manage', $context);

$id = optional_param('id', 0, PARAM_INT);
$hook = null;

if ($id > 0) {
    $hook = hook_repository::get_hook($id);
    if (!$hook) {
        throw new \moodle_exception('error_hook_not_found', 'local_webhookengine');
    }
} else if (!pro_unlock::can_create_hook()) {
    redirect(
        new moodle_url('/local/webhookengine/manage.php'),
        get_string('free_hook_limit_reached', 'local_webhookengine', pro_unlock::FREE_MAX_HOOKS),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$pageurl = new moodle_url('/local/webhookengine/edit.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_url($pageurl);

$heading = $hook ? get_string('edit_hook', 'local_webhookengine', $hook->name) : get_string('add_new_hook', 'local_webhookengine');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

$mform = new hook_form($pageurl, ['hook' => $hook]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/webhookengine/manage.php'));
} else if ($data = $mform->get_data()) {
    // Process form submission.
    $filters = [
        'courseids' => array_filter(array_map('intval', explode(',', $data->filter_courseids ?? ''))),
        'categoryids' => array_filter(array_map('intval', explode(',', $data->filter_categoryids ?? ''))),
        'includesubcats' => !empty($data->filter_includesubcats),
        'realusersonly' => !empty($data->filter_realusersonly),
    ];

    $payloadopts = [
        'includeother' => !empty($data->opt_includeother),
        'otherkeys' => array_filter(array_map('trim', explode(',', $data->opt_otherkeys ?? ''))),
        'includeuser' => !empty($data->opt_includeuser),
        'includeemail' => !empty($data->opt_includeemail) && !empty($data->opt_includeuser),
        'includecourse' => !empty($data->opt_includecourse),
        'template' => !empty($data->opt_template) ? trim($data->opt_template) : null,
    ];

    // Parse custom headers.
    $headers = [];
    if (!empty($data->customheaders)) {
        $lines = preg_split('/[\r\n]+/', trim($data->customheaders));
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && preg_match('/^([A-Za-z0-9-]+)\s*:\s*(.+)$/', $line, $matches)) {
                $headers[$matches[1]] = trim($matches[2]);
            }
        }
    }

    if ($id == 0) {
        // Create new hook.
        $rawsecret = hook_repository::generate_raw_secret();
        $record = (object) [
            'name' => $data->name,
            'url' => $data->url,
            'enabled' => !empty($data->enabled) ? 1 : 0,
            'events' => $data->events,
            'filters' => $filters,
            'payloadopts' => $payloadopts,
            'headers' => !empty($headers) ? $headers : null,
            'timeout' => $data->timeout,
            'maxattempts' => $data->maxattempts,
        ];

        $newid = hook_repository::create_hook($record, $rawsecret);
        $whsec = hook_repository::format_whsec($rawsecret);

        // Store one-time notification message with the secret.
        $sessionkey = 'local_webhookengine_new_secret_' . $newid;
        $SESSION->$sessionkey = $whsec;

        redirect(
            new moodle_url('/local/webhookengine/manage.php', ['created' => $newid]),
            get_string('hook_created_success', 'local_webhookengine'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        // Update existing hook.
        $record = (object) [
            'name' => $data->name,
            'url' => $data->url,
            'enabled' => !empty($data->enabled) ? 1 : 0,
            'events' => $data->events,
            'filters' => $filters,
            'payloadopts' => $payloadopts,
            'headers' => !empty($headers) ? $headers : null,
            'timeout' => $data->timeout,
            'maxattempts' => $data->maxattempts,
        ];

        hook_repository::update_hook($id, $record);

        redirect(
            new moodle_url('/local/webhookengine/manage.php'),
            get_string('hook_updated_success', 'local_webhookengine'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Pre-fill form if editing.
if ($hook) {
    $toform = (object) [
        'id' => $hook->id,
        'name' => $hook->name,
        'url' => $hook->url,
        'enabled' => $hook->enabled,
        'events' => json_decode($hook->events, true) ?: [],
        'filter_courseids' => !empty($hook->filters['courseids']) ? implode(',', $hook->filters['courseids']) : '',
        'filter_categoryids' => !empty($hook->filters['categoryids']) ? implode(',', $hook->filters['categoryids']) : '',
        'filter_includesubcats' => !empty($hook->filters['includesubcats']) ? 1 : 0,
        'filter_realusersonly' => !empty($hook->filters['realusersonly']) ? 1 : 0,
        'opt_includeother' => !empty($hook->payloadopts['includeother']) ? 1 : 0,
        'opt_otherkeys' => !empty($hook->payloadopts['otherkeys']) ? implode(',', $hook->payloadopts['otherkeys']) : '',
        'opt_includeuser' => !empty($hook->payloadopts['includeuser']) ? 1 : 0,
        'opt_includeemail' => !empty($hook->payloadopts['includeemail']) ? 1 : 0,
        'opt_includecourse' => !empty($hook->payloadopts['includecourse']) ? 1 : 0,
        'opt_template' => $hook->payloadopts['template'] ?? '',
        'timeout' => $hook->timeout,
        'maxattempts' => $hook->maxattempts,
    ];

    if (!empty($hook->headers)) {
        $customheaders = hook_repository::decrypt_headers($hook->headers);
        if (is_array($customheaders)) {
            $headerlines = [];
            foreach ($customheaders as $k => $v) {
                $headerlines[] = "$k: $v";
            }
            $toform->customheaders = implode("\n", $headerlines);
        }
    }

    $mform->set_data($toform);
}

echo $OUTPUT->header();

if ($hook) {
    // Show Secret Rotation Panel.
    $actionurl = new moodle_url('/local/webhookengine/action.php');
    $hiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rotate_secret'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $hook->id])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $confirmmsg = json_encode(get_string('confirm_rotate_secret', 'local_webhookengine'));
    $submitbtn = html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('rotate_secret', 'local_webhookengine'),
        'class' => 'btn btn-outline-warning mb-3',
        'onclick' => 'return confirm(' . $confirmmsg . ');',
    ]);
    $rotateform = $hiddens . $submitbtn;
    $formattrs = ['method' => 'post', 'action' => $actionurl->out(false), 'class' => 'float-right float-end'];
    echo html_writer::tag('form', $rotateform, $formattrs);
}

$mform->display();

echo $OUTPUT->footer();
