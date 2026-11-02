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
use local_webhookengine\oauth2_client;
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
        if ($hook && $hook->status !== 'deleted') {
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
        $hook = hook_repository::get_hook($id);
        if ($hook && $hook->status !== 'deleted' && in_array($targetstatus, ['active', 'paused'])) {
            hook_repository::set_hook_status($id, $targetstatus);
        }
        redirect(new moodle_url('/local/webhookengine/manage.php'));
        break;

    case 'rotate_secret':
        require_capability('local/webhookengine:manage', $context);
        $hook = hook_repository::get_hook($id);
        if ($hook && $hook->status !== 'deleted') {
            $newsecret = hook_repository::rotate_secret($id);
            $whsec = hook_repository::format_whsec($newsecret);

            $event = secret_rotated::create([
                'context' => $context,
                'objectid' => $id,
            ]);
            $event->trigger();

            $token = hook_repository::create_secret_reveal_token($id, $whsec, (int) $USER->id);

            redirect(new moodle_url('/local/webhookengine/secret_reveal.php', [
                'id' => $id,
                'token' => $token,
                'rotated' => 1,
            ]));
        }
        redirect(new moodle_url('/local/webhookengine/manage.php'));
        break;

    case 'reset_circuit':
        require_capability('local/webhookengine:manage', $context);
        $hook = hook_repository::get_hook($id);
        if ($hook && $hook->status !== 'deleted') {
            \local_webhookengine\circuit_breaker::reset_circuit($id);
            \local_webhookengine\rate_limiter::release_all_hook_slots($id);
            redirect(
                new moodle_url('/local/webhookengine/manage.php'),
                get_string('circuit_reset_success', 'local_webhookengine', $hook->name)
            );
        }
        redirect(new moodle_url('/local/webhookengine/manage.php'));
        break;

    case 'send_test':
        require_capability('local/webhookengine:manage', $context);
        if ((bool) get_config('local_webhookengine', 'killswitch')) {
            $manageurl = new moodle_url('/local/webhookengine/manage.php');
            redirect(
                $manageurl,
                get_string('error_killswitch_active', 'local_webhookengine'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
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
            'User-Agent: ' . \local_webhookengine\http_client::get_user_agent(),
            'X-Webhook-Event: webhook.test',
            'webhook-id: ' . $uuid,
            'webhook-timestamp: ' . $timestamp,
            'webhook-signature: ' . $signature,
        ];

        // Attach custom headers if configured.
        if (!empty($hook->headers)) {
            $customheaders = hook_repository::decrypt_headers($hook->headers);
            if (is_array($customheaders)) {
                foreach ($customheaders as $name => $val) {
                    $headers[] = $name . ': ' . $val;
                }
            }
        }

        // OAuth2 Client Credentials flow.
        if (!empty($hook->authmode) && $hook->authmode === 'oauth2') {
            try {
                $accesstoken = oauth2_client::get_access_token($hook);
                $hasauthheader = false;
                foreach ($headers as $hdr) {
                    if (stripos($hdr, 'Authorization:') === 0) {
                        $hasauthheader = true;
                        break;
                    }
                }
                if (!$hasauthheader) {
                    $headers[] = 'Authorization: Bearer ' . $accesstoken;
                }
            } catch (\Throwable $e) {
                $manageurl = new moodle_url('/local/webhookengine/manage.php');
                $errmsg = get_string('error_oauth2_failed', 'local_webhookengine') . ': ' . $e->getMessage();
                redirect($manageurl, $errmsg, null, \core\output\notification::NOTIFY_ERROR);
            }
        }

        $validation = url_validator::resolve_and_validate_for_delivery($hook->url);
        if (!$validation['valid']) {
            $manageurl = new moodle_url('/local/webhookengine/manage.php');
            $errkey = !empty($validation['error']) ? $validation['error'] : 'error_blocked_url';
            redirect(
                $manageurl,
                get_string($errkey, 'local_webhookengine'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        $client = new http_client();
        $usemtls = !empty($hook->usemtls);
        $res = $client->send($hook->url, $testpayload, $headers, min(10, $hook->timeout ?: 10), $usemtls);

        $event = test_sent::create([
            'context' => $context,
            'objectid' => $id,
        ]);
        $event->trigger();

        $msg = get_string('test_sent_result', 'local_webhookengine', [
            'status' => $res->status ?: '0',
            'duration' => $res->duration,
            'error' => !empty($res->error) ? s($res->error) : 'none',
        ]);
        $type = ($res->status >= 200 && $res->status < 300)
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING;

        redirect(new moodle_url('/local/webhookengine/manage.php'), $msg, null, $type);
        break;

    case 'replay':
        require_capability('local/webhookengine:replay', $context);
        if ((bool) get_config('local_webhookengine', 'killswitch')) {
            $redirecturl = new moodle_url('/local/webhookengine/deliveries.php', ['hookid' => $hookid ?: null]);
            redirect(
                $redirecturl,
                get_string('error_killswitch_active', 'local_webhookengine'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $delivery = $DB->get_record('local_webhookengine_delivery', ['id' => $deliveryid]);
        if (!$delivery) {
            redirect(new moodle_url('/local/webhookengine/deliveries.php'));
        }

        if (empty($delivery->payload)) {
            $msg = get_string('error_replay_payload_purged', 'local_webhookengine');
            redirect(
                new moodle_url('/local/webhookengine/deliveries.php', ['hookid' => $delivery->hookid]),
                $msg,
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        $delivery->status = 'queued';
        $delivery->attempts = 0; // Reset attempts counter for clean retry cycle.
        $delivery->nextattempt = time();
        $DB->update_record('local_webhookengine_delivery', $delivery);

        $task = new \local_webhookengine\task\send_delivery();
        $task->set_custom_data((object) ['deliveryid' => (int) $delivery->id]);
        \core\task\manager::queue_adhoc_task($task, true);

        $event = delivery_replayed::create([
            'context' => $context,
            'objectid' => $delivery->id,
        ]);
        $event->trigger();

        redirect(new moodle_url('/local/webhookengine/deliveries.php', ['hookid' => $delivery->hookid]));
        break;

    case 'replay_all_failed':
        require_capability('local/webhookengine:replay', $context);
        if ((bool) get_config('local_webhookengine', 'killswitch')) {
            redirect(
                new moodle_url('/local/webhookengine/deliveries.php', ['hookid' => $hookid]),
                get_string('error_killswitch_active', 'local_webhookengine'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $failedrecords = $DB->get_records_select(
            'local_webhookengine_delivery',
            'hookid = :hookid AND status IN (\'failed\', \'dropped\') AND payload IS NOT NULL',
            ['hookid' => $hookid],
            'id ASC',
            'id',
            0,
            1000
        );

        $replayedcount = 0;
        foreach ($failedrecords as $rec) {
            $rec->status = 'queued';
            $rec->attempts = 0; // Reset attempts counter.
            $rec->nextattempt = time();
            $DB->update_record('local_webhookengine_delivery', $rec);

            $task = new \local_webhookengine\task\send_delivery();
            $task->set_custom_data((object) ['deliveryid' => (int) $rec->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            $replayedcount++;
        }

        redirect(
            new moodle_url('/local/webhookengine/deliveries.php', ['hookid' => $hookid]),
            get_string('replayed_count_notice', 'local_webhookengine', $replayedcount)
        );
        break;

    case 'replay_selected':
        require_capability('local/webhookengine:replay', $context);
        $deliveryids = optional_param_array('deliveryids', [], PARAM_INT);
        $returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
        $redirecturl = !empty($returnurl)
            ? new moodle_url($returnurl)
            : new moodle_url('/local/webhookengine/deliveries.php');

        if ((bool) get_config('local_webhookengine', 'killswitch')) {
            redirect(
                $redirecturl,
                get_string('error_killswitch_active', 'local_webhookengine'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        if (empty($deliveryids)) {
            redirect(
                $redirecturl,
                get_string('no_deliveries_selected', 'local_webhookengine'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        [$insql, $inparams] = $DB->get_in_or_equal($deliveryids, SQL_PARAMS_NAMED);
        $select = "id $insql AND status IN ('failed', 'dropped', 'retrying') AND payload IS NOT NULL";
        $records = $DB->get_records_select('local_webhookengine_delivery', $select, $inparams);

        $replayedcount = 0;
        foreach ($records as $rec) {
            $rec->status = 'queued';
            $rec->attempts = 0; // Reset attempts counter for clean cycle.
            $rec->nextattempt = time();
            $DB->update_record('local_webhookengine_delivery', $rec);

            $task = new \local_webhookengine\task\send_delivery();
            $task->set_custom_data((object) ['deliveryid' => (int) $rec->id]);
            \core\task\manager::queue_adhoc_task($task, true);

            $event = delivery_replayed::create([
                'context' => $context,
                'objectid' => $rec->id,
            ]);
            $event->trigger();
            $replayedcount++;
        }

        redirect(
            $redirecturl,
            get_string('replayed_count_notice', 'local_webhookengine', $replayedcount),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
        break;

    default:
        throw new \moodle_exception('error_invalid_action', 'local_webhookengine');
}
