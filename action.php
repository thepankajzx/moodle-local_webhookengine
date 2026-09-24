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
 * Controller handling state mutations and actions via POST and sesskey.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Strict POST-only enforcement.
if (!data_submitted()) {
    throw new \moodle_exception('error_post_only', 'local_webhookengine');
}

require_sesskey();
require_login();

$context = context_system::instance();

$action = required_param('action', PARAM_ALPHANUMEXT);
$id = optional_param('id', 0, PARAM_INT);
$deliveryid = optional_param('deliveryid', 0, PARAM_INT);
$hookid = optional_param('hookid', 0, PARAM_INT);

use local_webhookengine\delivery_service;
use local_webhookengine\hook_repository;
use local_webhookengine\http_client;
use local_webhookengine\signer;
use local_webhookengine\url_validator;
use local_webhookengine\event\hook_deleted;
use local_webhookengine\event\secret_rotated;
use local_webhookengine\event\delivery_replayed;
use local_webhookengine\event\test_sent;

switch ($action) {
    case 'delete':
        require_capability('local/webhookengine:manage', $context);
        $hook = hook_repository::get_hook($id);
        if ($hook) {
            hook_repository::delete_hook($id);
            $event = hook_deleted::create([
                'context' => $context,
                'objectid' => $id,
                'other' => ['name' => $hook->name],
            ]);
            $event->trigger();
        }
        redirect(new moodle_url('/local/webhookengine/manage.php'), get_string('hook_deleted_success', 'local_webhookengine'));
        break;

    case 'toggle_status':
        require_capability('local/webhookengine:manage', $context);
        $targetstatus = required_param('targetstatus', PARAM_ALPHA);
        if (in_array($targetstatus, ['active', 'paused'])) {
            $hook = hook_repository::get_hook($id);
            if ($hook) {
                $hook->status = $targetstatus;
                hook_repository::update_hook($id, $hook);
            }
        }
        redirect(new moodle_url('/local/webhookengine/manage.php'));
        break;

    case 'rotate_secret':
        require_capability('local/webhookengine:manage', $context);
        $hook = hook_repository::get_hook($id);
        if ($hook) {
            $newsecret = hook_repository::rotate_secret($id);
            $whsec = hook_repository::format_whsec($newsecret);

            $event = secret_rotated::create([
                'context' => $context,
                'objectid' => $id,
            ]);
            $event->trigger();

            \core\notification::add(
                get_string('secret_rotated_alert', 'local_webhookengine', s($whsec)),
                \core\notification::WARNING
            );
        }
        redirect(new moodle_url('/local/webhookengine/manage.php'));
        break;

    case 'send_test':
        require_capability('local/webhookengine:manage', $context);
        $hook = hook_repository::get_hook($id);
        if (!$hook) {
            throw new \moodle_exception('error_hook_not_found', 'local_webhookengine');
        }

        $secret = hook_repository::decrypt_secret($hook->secret);
        if ($secret === false) {
            $manageurl = new moodle_url('/local/webhookengine/manage.php');
            $errmsg = get_string('error_secret_unreadable', 'local_webhookengine');
            redirect($manageurl, $errmsg, null, \core\output\notification::NOTIFY_ERROR);
        }

        $uuid = \core\uuid::generate();
        $timestamp = time();
        $testpayload = json_encode([
            'id' => $uuid,
            'schema' => 1,
            'type' => 'webhook.test',
            'eventname' => 'webhook.test',
            'created' => $timestamp,
            'site' => ['url' => $CFG->wwwroot],
            'event' => [
                'component' => 'local_webhookengine',
                'action' => 'test',
                'target' => 'webhook',
                'userid' => $USER->id,
                'timecreated' => $timestamp,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $signature = signer::sign($uuid, $timestamp, $testpayload, $secret);
        $headers = [
            'Content-Type: application/json',
            'User-Agent: WebhookEngine/1.0.0',
            'X-Webhook-Event: webhook.test',
            'webhook-id: ' . $uuid,
            'webhook-timestamp: ' . $timestamp,
            'webhook-signature: ' . $signature,
        ];

        if (!url_validator::validate_for_delivery($hook->url)) {
            $manageurl = new moodle_url('/local/webhookengine/manage.php');
            redirect(
                $manageurl,
                get_string('error_blocked_url', 'local_webhookengine'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        $client = new http_client();
        $res = $client->send($hook->url, $testpayload, $headers, min(10, $hook->timeout ?: 10));

        $event = test_sent::create([
            'context' => $context,
            'objectid' => $id,
        ]);
        $event->trigger();

        $msg = get_string('test_sent_result', 'local_webhookengine', [
            'status' => $res->status ?: '0',
            'duration' => $res->duration,
            'error' => !empty($res->error) ? $res->error : 'none',
        ]);
        $type = ($res->status >= 200 && $res->status < 300)
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING;

        redirect(new moodle_url('/local/webhookengine/manage.php'), $msg, null, $type);
        break;

    case 'replay':
        require_capability('local/webhookengine:replay', $context);
        $delivery = $DB->get_record('local_webhookengine_delivery', ['id' => $deliveryid]);
        if ($delivery) {
            $delivery->status = 'queued';
            $delivery->nextattempt = time();
            $DB->update_record('local_webhookengine_delivery', $delivery);

            $task = new \local_webhookengine\task\send_delivery();
            $task->set_custom_data(['deliveryid' => $delivery->id]);
            \core\task\manager::queue_adhoc_task($task, true);

            $event = delivery_replayed::create([
                'context' => $context,
                'objectid' => $delivery->id,
            ]);
            $event->trigger();
        }
        redirect(new moodle_url('/local/webhookengine/deliveries.php', $delivery ? ['hookid' => $delivery->hookid] : []));
        break;

    case 'replay_all_failed':
        require_capability('local/webhookengine:replay', $context);
        $failedrecords = $DB->get_records_select(
            'local_webhookengine_delivery',
            'hookid = :hookid AND status IN (\'failed\', \'dropped\')',
            ['hookid' => $hookid],
            'id ASC',
            'id',
            0,
            1000
        );

        $replayedcount = 0;
        foreach ($failedrecords as $rec) {
            $rec->status = 'queued';
            $rec->nextattempt = time();
            $DB->update_record('local_webhookengine_delivery', $rec);

            $task = new \local_webhookengine\task\send_delivery();
            $task->set_custom_data(['deliveryid' => $rec->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            $replayedcount++;
        }

        redirect(
            new moodle_url('/local/webhookengine/deliveries.php', ['hookid' => $hookid]),
            get_string('replayed_count_notice', 'local_webhookengine', $replayedcount)
        );
        break;

    default:
        throw new \moodle_exception('error_invalid_action', 'local_webhookengine');
}
