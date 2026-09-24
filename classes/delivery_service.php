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

namespace local_webhookengine;

/**
 * Service orchestrating webhook delivery execution, signing, and retry scheduling.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delivery_service {
    /** @var array Base retry backoff schedule in seconds: 1m, 5m, 15m, 1h, 3h, 6h, 12h, 24h. */
    const RETRY_SCHEDULE = [60, 300, 900, 3600, 10800, 21600, 43200, 86400];

    /**
     * Execute a delivery attempt.
     *
     * @param int $deliveryid Delivery database ID.
     * @param http_client|null $httpclient Optional injected HTTP client.
     * @return bool True if terminal success, false if failed/retrying/dropped.
     */
    public function execute_delivery(int $deliveryid, ?http_client $httpclient = null): bool {
        global $DB;

        if ($httpclient === null) {
            $httpclient = new http_client();
        }

        $delivery = $DB->get_record('local_webhookengine_delivery', ['id' => $deliveryid]);
        if (!$delivery) {
            return false;
        }

        // Terminal states should not be re-executed by standard cron.
        if (in_array($delivery->status, ['success', 'dropped'])) {
            return false;
        }

        $killswitch = (bool) get_config('local_webhookengine', 'killswitch');
        if ($killswitch) {
            $delivery->status = 'dropped';
            $delivery->errorcode = 'killswitch_enabled';
            $delivery->timelastattempt = time();
            $DB->update_record('local_webhookengine_delivery', $delivery);
            return false;
        }

        $hook = hook_repository::get_hook($delivery->hookid);
        if (!$hook || !$hook->enabled || $hook->status !== 'active') {
            $delivery->status = 'dropped';
            $delivery->errorcode = 'hook_disabled_or_missing';
            $delivery->timelastattempt = time();
            $DB->update_record('local_webhookengine_delivery', $delivery);
            return false;
        }

        // Validate target URL before outbound network attempt.
        if (!url_validator::validate_for_delivery($hook->url)) {
            $delivery->status = 'failed';
            $delivery->errorcode = 'blocked_or_invalid_url';
            $delivery->timelastattempt = time();
            $DB->update_record('local_webhookengine_delivery', $delivery);
            $this->record_hook_failure($hook);
            return false;
        }

        // Decrypt secret.
        $secret = hook_repository::decrypt_secret($hook->secret);
        if ($secret === false) {
            $delivery->status = 'failed';
            $delivery->errorcode = 'secret_unreadable';
            $delivery->timelastattempt = time();
            $DB->update_record('local_webhookengine_delivery', $delivery);
            $this->record_hook_failure($hook);
            return false;
        }

        // Render final payload body once if not already rendered.
        $body = $delivery->payload;
        if (!empty($body)) {
            $rawdata = json_decode($body, true);
            if (is_array($rawdata) && isset($rawdata['eventname']) && !isset($rawdata['schema'])) {
                // It's raw event data, render final envelope.
                $body = payload_builder::render_envelope($delivery->uuid, $rawdata, $hook->payloadopts);
                $delivery->payload = $body;
                $DB->update_record('local_webhookengine_delivery', $delivery);
            }
        }

        if (empty($body)) {
            $body = '{}';
        }

        // Build standard headers.
        $timestamp = time();
        $signature = signer::sign($delivery->uuid, $timestamp, $body, $secret);

        $eventtype = payload_builder::extract_event_type($delivery->eventname);

        $headers = [
            'Content-Type: application/json',
            'User-Agent: WebhookEngine/1.0.0',
            'X-Webhook-Event: ' . $eventtype,
            'webhook-id: ' . $delivery->uuid,
            'webhook-timestamp: ' . $timestamp,
            'webhook-signature: ' . $signature,
        ];

        // Append custom headers if defined.
        if (!empty($hook->headers)) {
            $customheaders = hook_repository::decrypt_headers($hook->headers);
            if (is_array($customheaders)) {
                foreach ($customheaders as $name => $val) {
                    $headers[] = $name . ': ' . $val;
                }
            }
        }

        $timeout = $hook->timeout ?: 10;
        $response = $httpclient->send($hook->url, $body, $headers, $timeout);

        $delivery->attempts++;
        $delivery->timelastattempt = time();
        $delivery->duration = $response->duration;
        $delivery->httpstatus = $response->status;

        $status = $response->status;

        if ($status >= 200 && $status < 300) {
            // Success.
            $delivery->status = 'success';
            $delivery->errorcode = null;
            $delivery->payload = null; // Clean up payload upon confirmed delivery.
            $delivery->nextattempt = null;
            $DB->update_record('local_webhookengine_delivery', $delivery);

            $this->record_hook_success($hook);
            return true;
        }

        // Failure handling.
        if ($status >= 300 && $status < 400) {
            $delivery->status = 'failed';
            $delivery->errorcode = 'redirect_not_followed';
            $delivery->nextattempt = null;
            $DB->update_record('local_webhookengine_delivery', $delivery);
            $this->record_hook_failure($hook);
            return false;
        }

        // Determine if retryable.
        $isretryable = ($status == 408
            || $status == 425
            || $status == 429
            || $status >= 500
            || $status == 0
            || $response->errno > 0);

        if ($isretryable && $delivery->attempts < $hook->maxattempts) {
            // Schedule retry.
            $delay = $this->calculate_retry_delay($delivery->attempts, $response->retryafter);
            $delivery->status = 'retrying';
            $delivery->errorcode = $response->errno > 0 ? 'network_error_' . $response->errno : 'http_' . $status;
            $delivery->nextattempt = time() + $delay;
            $DB->update_record('local_webhookengine_delivery', $delivery);

            // Queue ad-hoc task for next run.
            $task = new \local_webhookengine\task\send_delivery();
            $task->set_custom_data(['deliveryid' => $delivery->id]);
            $task->set_next_run_time($delivery->nextattempt);
            \core\task\manager::queue_adhoc_task($task, true);

            return false;
        }

        // Terminal failure.
        $delivery->status = 'failed';
        if ($status >= 400 && $status < 500 && !$isretryable) {
            $delivery->errorcode = 'http_' . $status;
        } else if ($delivery->attempts >= $hook->maxattempts) {
            $delivery->errorcode = 'max_attempts_exceeded';
        } else {
            $delivery->errorcode = $response->errno > 0 ? 'network_error_' . $response->errno : 'delivery_failed';
        }
        $delivery->nextattempt = null;
        $DB->update_record('local_webhookengine_delivery', $delivery);

        $this->record_hook_failure($hook);
        return false;
    }

    /**
     * Calculate next retry delay with jitter.
     *
     * @param int $attempt Current attempt number.
     * @param int|null $retryafter Explicit Retry-After header value if present.
     * @return int Delay in seconds.
     */
    public function calculate_retry_delay(int $attempt, ?int $retryafter = null): int {
        if ($retryafter !== null && $retryafter > 0) {
            return min(3600, max(60, $retryafter));
        }

        $index = min(count(self::RETRY_SCHEDULE) - 1, max(0, $attempt - 1));
        $basedelay = self::RETRY_SCHEDULE[$index];

        // Apply +/- 10% random jitter.
        $jitter = (int) round($basedelay * (random_int(-10, 10) / 100.0));
        return max(30, $basedelay + $jitter);
    }

    /**
     * Record hook successful delivery.
     *
     * @param object $hook Hook record.
     */
    private function record_hook_success(object $hook): void {
        global $DB;
        $update = (object) [
            'id' => $hook->id,
            'failstreak' => 0,
            'lastsuccess' => time(),
        ];
        $DB->update_record('local_webhookengine_hook', $update);
    }

    /**
     * Record hook failure and streak.
     *
     * @param object $hook Hook record.
     */
    private function record_hook_failure(object $hook): void {
        global $DB;
        $update = (object) [
            'id' => $hook->id,
            'failstreak' => $hook->failstreak + 1,
            'lastfailure' => time(),
        ];
        $DB->update_record('local_webhookengine_hook', $update);
    }
}
