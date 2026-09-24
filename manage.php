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
 * Manage webhooks administration interface.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('local/webhookengine:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/webhookengine/manage.php'));
$PAGE->set_title(get_string('manage_hooks', 'local_webhookengine'));
$PAGE->set_heading(get_string('manage_hooks', 'local_webhookengine'));

echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('local_webhookengine');

// Health Panel.
$queuedcount = $DB->count_records('local_webhookengine_delivery', ['status' => 'queued']);
$oldestqueued = $DB->get_field_select('local_webhookengine_delivery', 'MIN(timecreated)', "status = 'queued'");
$oldestage = $oldestqueued ? (time() - $oldestqueued) : null;
$killswitch = (bool) get_config('local_webhookengine', 'killswitch');

echo $renderer->render_health_panel($queuedcount, $oldestage, $killswitch);

// Hooks Table.
$hooks = $DB->get_records('local_webhookengine_hook', [], 'id ASC');
$hookscount = count($hooks);
$cancreate = \local_webhookengine\pro_unlock::can_create_hook();

// Add Hook Button and Free Tier Limit Notice.
if (!\local_webhookengine\pro_unlock::is_pro()) {
    $usagedata = (object) ['used' => $hookscount, 'max' => \local_webhookengine\pro_unlock::FREE_MAX_HOOKS];
    $usagemsg = get_string('free_hooks_usage', 'local_webhookengine', $usagedata);
    

    $limitbadge = html_writer::span($usagemsg, 'badge bg-info text-dark py-2 px-3 me-2');
    $upgradelink = "";

    if ($cancreate) {
        $addurl = new moodle_url('/local/webhookengine/edit.php');
        $addbtn = html_writer::link($addurl, get_string('add_new_hook', 'local_webhookengine'), ['class' => 'btn btn-primary me-2']);
        echo html_writer::div($addbtn . $limitbadge . $upgradelink, 'd-flex align-items-center mb-3');
    } else {
        $limitwarning = html_writer::div(
            html_writer::tag('strong', get_string('free_hook_limit_reached', 'local_webhookengine', \local_webhookengine\pro_unlock::FREE_MAX_HOOKS)) .
            ' ' . $upgradelink,
            'alert alert-warning mb-3'
        );
        echo $limitwarning;
    }
} else {
    $addurl = new moodle_url('/local/webhookengine/edit.php');
    echo html_writer::div(
        html_writer::link($addurl, get_string('add_new_hook', 'local_webhookengine'), ['class' => 'btn btn-primary mb-3']),
        'mb-3'
    );
}

if (empty($hooks)) {
    echo $OUTPUT->notification(get_string('no_hooks_configured', 'local_webhookengine'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('hook_name', 'local_webhookengine'),
        get_string('destination_host', 'local_webhookengine'),
        get_string('events_count', 'local_webhookengine'),
        get_string('status', 'local_webhookengine'),
        get_string('last_success', 'local_webhookengine'),
        get_string('actions', 'local_webhookengine'),
    ];
    $table->attributes['class'] = 'table table-striped table-hover align-middle';

    foreach ($hooks as $hook) {
        $events = json_decode($hook->events, true) ?: [];
        $eventscount = count($events);

        // Destination Host display.
        $parsed = parse_url($hook->url);
        $hostdisplay = $parsed['host'] ?? $hook->url;
        if (!empty($parsed['port'])) {
            $hostdisplay .= ':' . $parsed['port'];
        }

        // Status badge.
        $statusdisplay = $renderer->render_status_badge($hook->status);
        if ($hook->failstreak > 0) {
            $statusdisplay .= ' ' . html_writer::span(
                get_string('fail_streak_badge', 'local_webhookengine', $hook->failstreak),
                'badge badge-warning bg-warning text-dark'
            );
        }

        // Last success time.
        $lastsuccess = $hook->lastsuccess
            ? userdate($hook->lastsuccess, get_string('strftimedatetimeshort', 'core_langconfig'))
            : get_string('never', 'local_webhookengine');

        // Action links.
        $editurl = new moodle_url('/local/webhookengine/edit.php', ['id' => $hook->id]);
        $deliveriesurl = new moodle_url('/local/webhookengine/deliveries.php', ['hookid' => $hook->id]);

        $actions = [];
        $actions[] = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-outline-secondary']);
        $delivstr = get_string('deliveries', 'local_webhookengine');
        $actions[] = html_writer::link($deliveriesurl, $delivstr, ['class' => 'btn btn-sm btn-outline-info']);

        // Toggle pause/active button (POST form with sesskey).
        $targetstatus = ($hook->status === 'active') ? 'paused' : 'active';
        $btnlabel = ($hook->status === 'active')
            ? get_string('pause', 'local_webhookengine')
            : get_string('resume', 'local_webhookengine');
        $btnclass = ($hook->status === 'active') ? 'btn-outline-warning' : 'btn-outline-success';

        $actionurl = new moodle_url('/local/webhookengine/action.php');
        $pausehiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'toggle_status'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $hook->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'targetstatus', 'value' => $targetstatus])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $pausesubmit = html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => $btnlabel,
            'class' => 'btn btn-sm ' . $btnclass,
        ]);
        $pauseform = $pausehiddens . $pausesubmit;
        $actions[] = html_writer::tag('form', $pauseform, [
            'method' => 'post',
            'action' => $actionurl->out(false),
            'class' => 'd-inline',
        ]);

        // Test button.
        $testhiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'send_test'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $hook->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $testsubmit = html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('send_test', 'local_webhookengine'),
            'class' => 'btn btn-sm btn-outline-primary',
        ]);
        $testform = $testhiddens . $testsubmit;
        $actions[] = html_writer::tag('form', $testform, [
            'method' => 'post',
            'action' => $actionurl->out(false),
            'class' => 'd-inline',
        ]);

        // Delete button with confirmation.
        $delhiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $hook->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $delconfirm = json_encode(get_string('confirm_delete_hook', 'local_webhookengine', $hook->name));
        $delsubmit = html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('delete'),
            'class' => 'btn btn-sm btn-outline-danger',
            'onclick' => 'return confirm(' . $delconfirm . ');',
        ]);
        $deleteform = $delhiddens . $delsubmit;
        $actions[] = html_writer::tag('form', $deleteform, [
            'method' => 'post',
            'action' => $actionurl->out(false),
            'class' => 'd-inline',
        ]);

        $table->data[] = [
            html_writer::tag('strong', s($hook->name)),
            s($hostdisplay),
            $eventscount,
            $statusdisplay,
            $lastsuccess,
            implode(' ', $actions),
        ];
    }

    echo html_writer::table($table);
}

// Support & documentation note in footer.
$supportlink = html_writer::link(
    'https://thedefinite.one',
    get_string('support_link_text', 'local_webhookengine'),
    ['target' => '_blank', 'class' => 'text-muted']
);
echo html_writer::div($supportlink, 'mt-5 pt-3 border-top text-center');

echo $OUTPUT->footer();
