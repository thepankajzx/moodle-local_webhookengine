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
 * Deliveries log and safe export interface.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_webhookengine\output\deliveries_table;
use local_webhookengine\hook_repository;

require_login();
$context = context_system::instance();
require_capability('local/webhookengine:viewlogs', $context);

$hookid = optional_param('hookid', 0, PARAM_INT);
$pageparams = $hookid ? ['hookid' => $hookid] : [];
$pageurl = new moodle_url('/local/webhookengine/deliveries.php', $pageparams);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('deliveries', 'local_webhookengine'));
$PAGE->set_heading(get_string('deliveries', 'local_webhookengine'));

$download = optional_param('download', '', PARAM_ALPHA);
$includepersonal = optional_param('includepersonal', 0, PARAM_BOOL);

$canexport = has_capability('local/webhookengine:exportlogs', $context);
$canexportpersonal = has_capability('local/webhookengine:exportpersonaldata', $context);
$allowpersonal = ($includepersonal && $canexportpersonal);

$table = new deliveries_table('local_webhookengine_deliveries', $pageurl, $hookid ?: null, $allowpersonal);
$table->is_downloading($download, 'webhook_deliveries_' . date('Ymd_His'));

if (!$table->is_downloading()) {
    echo $OUTPUT->header();

    // Hook filter selector or header.
    if ($hookid > 0) {
        $hook = hook_repository::get_hook($hookid);
        if ($hook) {
            echo $OUTPUT->heading(get_string('deliveries_for_hook', 'local_webhookengine', s($hook->name)), 3);

            // Replay all failed button for this hook.
            if (has_capability('local/webhookengine:replay', $context)) {
                $actionurl = new moodle_url('/local/webhookengine/action.php');
                $hiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'replay_all_failed'])
                    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'hookid', 'value' => $hookid])
                    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                $confirmmsg = json_encode(get_string('confirm_replay_all', 'local_webhookengine'));
                $submitbtn = html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string('replay_all_failed', 'local_webhookengine'),
                    'class' => 'btn btn-outline-warning mb-3',
                    'onclick' => 'return confirm(' . $confirmmsg . ');',
                ]);
                $replayallform = $hiddens . $submitbtn;
                $formattrs = ['method' => 'post', 'action' => $actionurl->out(false), 'class' => 'mb-3'];
                echo html_writer::tag('form', $replayallform, $formattrs);
            }
        }
    }

    // Render personal data export toggle if user has the high-privilege personal data export capability.
    if ($canexportpersonal && $canexport) {
        $toggleparams = $hookid ? ['hookid' => $hookid] : [];
        $toggleparams['includepersonal'] = $includepersonal ? 0 : 1;
        $toggleurl = new moodle_url('/local/webhookengine/deliveries.php', $toggleparams);

        $checkboxattrs = [
            'type' => 'checkbox',
            'id' => 'includepersonal_toggle',
            'style' => 'width: 1.35rem; height: 1.35rem; cursor: pointer; margin: 0; position: static;',
            'onchange' => "window.location.href='" . $toggleurl->out(false) . "';",
        ];
        if ($includepersonal) {
            $checkboxattrs['checked'] = 'checked';
        }
        $checkbox = html_writer::empty_tag('input', $checkboxattrs);

        $labeltext = get_string('include_personal_data', 'local_webhookengine');
        $label = html_writer::tag('label', $labeltext, [
            'for' => 'includepersonal_toggle',
            'class' => 'mb-0 font-weight-bold fw-bold text-dark',
            'style' => 'cursor: pointer;',
        ]);

        $statusbadge = $includepersonal
            ? html_writer::span(
                get_string('enabled', 'local_webhookengine'),
                'badge badge-warning bg-warning text-dark ms-2 ml-2'
            )
            : '';

        $rightcontent = html_writer::tag('div', $checkbox . $statusbadge, [
            'class' => 'd-flex align-items-center ms-3 ml-3',
            'style' => 'gap: 0.5rem;',
        ]);

        $togglebox = html_writer::tag('div', $label . $rightcontent, [
            'class' => 'd-flex align-items-center justify-content-between p-3 mb-3 bg-light border rounded shadow-sm',
            'style' => 'max-width: 580px;',
        ]);
        echo html_writer::div($togglebox, 'mb-2');
    }
}

$table->out(50, true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
