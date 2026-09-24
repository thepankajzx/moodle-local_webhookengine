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
 * Deliveries log and replay interface.
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
$pageurl = new moodle_url('/local/webhookengine/deliveries.php', $hookid ? ['hookid' => $hookid] : []);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('deliveries', 'local_webhookengine'));
$PAGE->set_heading(get_string('deliveries', 'local_webhookengine'));

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

$table = new deliveries_table('local_webhookengine_deliveries', $pageurl, $hookid ?: null);
$table->out(50, true);

echo $OUTPUT->footer();
