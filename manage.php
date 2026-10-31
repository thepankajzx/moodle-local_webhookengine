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
$allhookdrops = \local_webhookengine\hook_repository::get_all_hooks_total_drops();
$totaldrops = array_sum($allhookdrops);

echo $renderer->render_health_panel($queuedcount, $oldestage, $killswitch, $totaldrops);

// Hooks Table.
$hooks = \local_webhookengine\hook_repository::get_hooks();

$addurl = new moodle_url('/local/webhookengine/edit.php');
$deliveriesurl = new moodle_url('/local/webhookengine/deliveries.php');
echo html_writer::div(
    html_writer::link($addurl, get_string('add_new_hook', 'local_webhookengine'), ['class' => 'btn btn-primary me-2']) .
    html_writer::link($deliveriesurl, get_string('deliveries', 'local_webhookengine'), ['class' => 'btn btn-outline-secondary']),
    'mb-3'
);

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

    $fs = get_file_storage();
    $modals = [];

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
        $hookdrops = $allhookdrops[$hook->id] ?? 0;
        if ($hookdrops > 0) {
            $statusdisplay .= ' ' . html_writer::span(
                get_string('drops_badge', 'local_webhookengine', $hookdrops),
                'badge badge-danger bg-danger text-white'
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
        $actions[] = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-outline-secondary text-nowrap']);
        $delivstr = get_string('deliveries', 'local_webhookengine');
        $actions[] = html_writer::link($deliveriesurl, $delivstr, ['class' => 'btn btn-sm btn-outline-info text-nowrap']);

        // Check for archives for this hook.
        $hookfiles = $fs->get_area_files(
            \context_system::instance()->id,
            'local_webhookengine',
            'archives',
            $hook->id,
            'timecreated DESC',
            false
        );
        $archivecount = count($hookfiles);
        $modalid = 'archiveModal_' . $hook->id;
        $archstr = ($archivecount > 0)
            ? get_string('archives', 'local_webhookengine') . ' (' . $archivecount . ')'
            : get_string('archives', 'local_webhookengine');
        $actions[] = html_writer::tag('button', $archstr, [
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-dark text-nowrap',
            'data-toggle' => 'modal',
            'data-target' => '#' . $modalid,
            'data-bs-toggle' => 'modal',
            'data-bs-target' => '#' . $modalid,
        ]);

        if ($archivecount > 0) {
            $modaltable = new html_table();
            $modaltable->head = [
                get_string('time_created', 'local_webhookengine'),
                get_string('filename', 'local_webhookengine'),
                get_string('size', 'moodle'),
                get_string('actions', 'local_webhookengine'),
            ];
            foreach ($hookfiles as $hfile) {
                $downurl = moodle_url::make_pluginfile_url(
                    \context_system::instance()->id,
                    'local_webhookengine',
                    'archives',
                    $hook->id,
                    '/',
                    $hfile->get_filename(),
                    true
                );
                $downbtn = html_writer::link($downurl, get_string('download_archive', 'local_webhookengine'), [
                    'class' => 'btn btn-sm btn-primary',
                ]);
                $modaltable->data[] = [
                    userdate($hfile->get_timecreated(), get_string('strftimedatetimeshort', 'core_langconfig')),
                    s($hfile->get_filename()),
                    display_size($hfile->get_filesize()),
                    $downbtn,
                ];
            }
            $modalbody = html_writer::table($modaltable);
        } else {
            $modalbody = html_writer::div(
                get_string('no_archives_available', 'local_webhookengine'),
                'alert alert-info mb-0 text-center py-4'
            );
        }

        $modals[] = '
        <div class="modal fade" id="' . $modalid . '" tabindex="-1" role="dialog" aria-hidden="true">
          <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">'
                  . s(get_string('archives_for_hook', 'local_webhookengine', $hook->name)) . '</h5>
                <button type="button" class="close btn-close" data-dismiss="modal" '
                  . 'data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">' . $modalbody . '</div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" '
                  . 'data-bs-dismiss="modal">' . get_string('closebuttontitle') . '</button>
              </div>
            </div>
          </div>
        </div>';

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
            'class' => 'btn btn-sm text-nowrap ' . $btnclass,
        ]);
        $pauseform = $pausehiddens . $pausesubmit;
        $actions[] = html_writer::tag('form', $pauseform, [
            'method' => 'post',
            'action' => $actionurl->out(false),
            'class' => 'd-inline m-0 p-0',
        ]);

        // Test button.
        $testhiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'send_test'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $hook->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $testsubmit = html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('send_test', 'local_webhookengine'),
            'class' => 'btn btn-sm btn-outline-primary text-nowrap',
        ]);
        $testform = $testhiddens . $testsubmit;
        $actions[] = html_writer::tag('form', $testform, [
            'method' => 'post',
            'action' => $actionurl->out(false),
            'class' => 'd-inline m-0 p-0',
        ]);

        // Delete button with confirmation.
        $delhiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $hook->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $delconfirm = json_encode(get_string('confirm_delete_hook', 'local_webhookengine', $hook->name));
        $delsubmit = html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('delete'),
            'class' => 'btn btn-sm btn-outline-danger text-nowrap',
            'onclick' => 'return confirm(' . $delconfirm . ');',
        ]);
        $deleteform = $delhiddens . $delsubmit;
        $actions[] = html_writer::tag('form', $deleteform, [
            'method' => 'post',
            'action' => $actionurl->out(false),
            'class' => 'd-inline m-0 p-0',
        ]);

        // Balanced 2-row layout with clean horizontal and vertical spacing.
        $row1 = html_writer::div(
            $actions[0] . $actions[1] . $actions[2],
            'd-flex align-items-center',
            ['style' => 'gap: 6px;']
        );
        $row2 = html_writer::div(
            $actions[3] . $actions[4] . $actions[5],
            'd-flex align-items-center',
            ['style' => 'gap: 6px;']
        );
        $actionsdisplay = html_writer::div(
            $row1 . $row2,
            'd-flex flex-column py-1',
            ['style' => 'gap: 6px; min-width: 320px;']
        );

        // Name & Created date display.
        $namedisplay = html_writer::tag('div', s($hook->name), ['class' => 'fw-bold font-weight-bold text-dark']);
        if (!empty($hook->timecreated)) {
            $createddate = userdate($hook->timecreated, get_string('strftimedatetimeshort', 'core_langconfig'));
            if (get_string_manager()->string_exists('created_date', 'local_webhookengine')) {
                $createdstr = get_string('created_date', 'local_webhookengine', $createddate);
                if (strpos($createdstr, '[[') !== false) {
                    $createdstr = 'Created: ' . $createddate;
                }
            } else {
                $createdstr = 'Created: ' . $createddate;
            }
            $namedisplay .= html_writer::tag('div', $createdstr, ['class' => 'small text-muted mt-1']);
        }

        // Host & Endpoint path display.
        $hosthtml = html_writer::tag('div', s($hostdisplay), ['class' => 'fw-semibold text-secondary']);
        $pathdisplay = (!empty($parsed['path']) && $parsed['path'] !== '/') ? $parsed['path'] : '';
        if ($pathdisplay !== '') {
            $hosthtml .= html_writer::tag('div', s($pathdisplay), [
                'class' => 'small text-muted font-monospace text-truncate',
                'style' => 'max-width: 220px;',
                'title' => s($hook->url),
            ]);
        }

        $table->data[] = [
            $namedisplay,
            $hosthtml,
            $eventscount,
            $statusdisplay,
            $lastsuccess,
            $actionsdisplay,
        ];
    }

    echo html_writer::table($table);
    if (!empty($modals)) {
        echo implode("\n", $modals);
    }
}

// Support & documentation note in footer.
$supportlink = html_writer::link(
    'https://github.com/thepankajzx/moodle-local_webhookengine',
    get_string('support_link_text', 'local_webhookengine'),
    ['target' => '_blank', 'class' => 'text-muted']
);
echo html_writer::div($supportlink, 'mt-5 pt-3 border-top text-center');

echo $OUTPUT->footer();
