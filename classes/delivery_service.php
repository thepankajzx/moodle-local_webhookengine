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
    public const RETRY_SCHEDULE = [60, 300, 900, 3600, 10800, 21600, 43200, 86400];

    /** @var string Delivery queued status in database. */
    public const STATUS_QUEUED = 'queued';

    /** @var string Legacy alias for backward compatibility. */
    public const STATUS_PENDING = 'queued';

    /** @var string Delivery retrying status in database. */
    public const STATUS_RETRYING = 'retrying';

    /** @var string Delivery success status in database. */
    public const STATUS_SUCCESS = 'success';

    /** @var string Delivery failed status in database. */
    public const STATUS_FAILED = 'failed';

    /** @var string Delivery dropped status in database. */
    public const STATUS_DROPPED = 'dropped';

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
        if (in_array($delivery->status, [self::STATUS_SUCCESS, self::STATUS_DROPPED])) {
            return false;
        }

        $killswitch = (bool) get_config('local_webhookengine', 'killswitch');
        if ($killswitch) {
            $delivery->status = self::STATUS_DROPPED;
            $delivery->errorcode = reasons::KILL_SWITCH;
            $delivery->timelastattempt = time();
            $DB->update_record('local_webhookengine_delivery', $delivery);
            hook_repository::record_drop((int) $delivery->hookid, reasons::KILL_SWITCH);
            return false;
        }

        $hook = hook_repository::get_hook($delivery->hookid);
        if (!$hook || !$hook->enabled || $hook->status !== 'active') {
            $delivery->status = self::STATUS_DROPPED;
            $delivery->errorcode = reasons::HOOK_DISABLED_OR_MISSING;
            $delivery->timelastattempt = time();
            $DB->update_record('local_webhookengine_delivery', $delivery);
            hook_repository::record_drop((int) $delivery->hookid, reasons::HOOK_DISABLED_OR_MISSING);
            return false;
        }

        // Circuit breaker check: if circuit is open and in active cooldown, defer delivery.
        $circuitstate = circuit_breaker::get_state($hook);
        if ($circuitstate === circuit_breaker::STATE_OPEN) {
            $nextattempt = max(time() + 30, (int) $hook->circuitcooldownuntil);
            $delivery->nextattempt = $nextattempt;
            $DB->update_record('local_webhookengine_delivery', $delivery);

            $task = new \local_webhookengine\task\send_delivery();
            $task->set_custom_data((object) ['deliveryid' => (int) $delivery->id]);
            $task->set_next_run_time($nextattempt);
            \core\task\manager::queue_adhoc_task($task, true);
            return false;
        }

        $isprobing = false;
        if ($circuitstate === circuit_breaker::STATE_HALF_OPEN) {
            if (!circuit_breaker::claim_half_open_probe((int) $hook->id)) {
                // A probe is already in flight for this hook. Keep this delivery deferred.
                $nextattempt = time() + 30;
                $delivery->nextattempt = $nextattempt;
                $DB->update_record('local_webhookengine_delivery', $delivery);

                $task = new \local_webhookengine\task\send_delivery();
                $task->set_custom_data((object) ['deliveryid' => (int) $delivery->id]);
                $task->set_next_run_time($nextattempt);
                \core\task\manager::queue_adhoc_task($task, true);
                return false;
            }
            $isprobing = true;
        }

        // Concurrency slot check: prevent single degraded endpoint from starving cron worker threads.
        $maxconcurrent = (int) get_config('local_webhookengine', 'maxconcurrenthook') ?: 5;
        if (!rate_limiter::acquire_hook_slot($hook->id, $maxconcurrent)) {
            $nextattempt = time() + random_int(30, 60);
            $delivery->nextattempt = $nextattempt;
            $DB->update_record('local_webhookengine_delivery', $delivery);

            $task = new \local_webhookengine\task\send_delivery();
            $task->set_custom_data((object) ['deliveryid' => (int) $delivery->id]);
            $task->set_next_run_time($nextattempt);
            \core\task\manager::queue_adhoc_task($task, true);

            if ($isprobing) {
                circuit_breaker::release_probe_claim((int) $hook->id);
            }
            return false;
        }

        try {
            // Validate target URL before outbound network attempt.
            if (!url_validator::validate_for_delivery($hook->url)) {
                $delivery->status = self::STATUS_FAILED;
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
                    try {
                        $body = payload_builder::render_envelope($delivery->uuid, $rawdata, $hook->payloadopts);
                    } catch (\Throwable $e) {
                        $delivery->status = 'failed';
                        $delivery->errorcode = 'template_invalid';
                        $delivery->timelastattempt = time();
                        $DB->update_record('local_webhookengine_delivery', $delivery);
                        $this->record_hook_failure($hook);
                        return false;
                    }
                    $delivery->payload = $body;
                    $DB->update_record('local_webhookengine_delivery', $delivery);
                }
            }

            if (empty($body)) {
                // Reject delivery execution with error - never send an empty {} body.
                $delivery->status = 'failed';
                $delivery->errorcode = 'payload_purged_cannot_replay';
                $delivery->timelastattempt = time();
                $DB->update_record('local_webhookengine_delivery', $delivery);
                return false;
            }

            // Fail-closed verification: ensure final payload is valid JSON before network dispatch.
            $bodydecoded = @json_decode($body, true);
            if ($bodydecoded === null) {
                $delivery->status = 'failed';
                $delivery->errorcode = 'template_invalid';
                $delivery->timelastattempt = time();
                $DB->update_record('local_webhookengine_delivery', $delivery);
                $this->record_hook_failure($hook);
                return false;
            }

            // Compute cryptographic SHA-256 hash of the payload body for non-repudiation audit trail.
            $bodyhash = hash('sha256', $body);
            $delivery->bodyhash = $bodyhash;

            // Build standard headers with dual-secret grace period support.
            $timestamp = time();

            $activesecrets = [$secret];
            if (!empty($hook->prevsecret) && !empty($hook->secretrotatedat)) {
                $graceperiod = get_config('local_webhookengine', 'rotationgraceperiod');
                $graceperiod = ($graceperiod !== false && $graceperiod !== '') ? (int) $graceperiod : 86400;
                if ($graceperiod > 0 && ($timestamp - (int) $hook->secretrotatedat) < $graceperiod) {
                    $prevsecret = hook_repository::decrypt_secret($hook->prevsecret);
                    if ($prevsecret !== false && !empty($prevsecret)) {
                        $activesecrets[] = $prevsecret;
                    }
                }
            }

            $signature = signer::sign_multi($delivery->uuid, $timestamp, $body, $activesecrets);

            $eventtype = payload_builder::extract_event_type($delivery->eventname);

            $headers = [
                'Content-Type: application/json',
                'User-Agent: ' . http_client::get_user_agent(),
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
                    $delivery->status = self::STATUS_FAILED;
                    $delivery->errorcode = 'oauth2_token_failed';
                    $delivery->timelastattempt = time();
                    $delivery->attempts++;
                    $DB->update_record('local_webhookengine_delivery', $delivery);
                    $this->record_hook_failure($hook);
                    return false;
                }
            }

            $timeout = $hook->timeout ?: 10;
            $usemtls = !empty($hook->usemtls);
            $response = $httpclient->send($hook->url, $body, $headers, $timeout, $usemtls);

            $delivery->attempts++;
            $delivery->timelastattempt = time();
            $delivery->duration = $response->duration;
            $delivery->httpstatus = $response->status;

            $status = $response->status;

            if ($status >= 200 && $status < 300) {
                // Success.
                $delivery->status = self::STATUS_SUCCESS;
                $delivery->errorcode = null;
                $delivery->payload = null; // Clean up payload upon confirmed delivery.
                $delivery->nextattempt = null;
                $DB->update_record('local_webhookengine_delivery', $delivery);

                rate_limiter::adjust_global_queue(-1);
                if ($delivery->attempts > 1) {
                    rate_limiter::adjust_hook_backlog((int) $hook->id, -1);
                }

                $this->record_hook_success($hook);
                return true;
            }

            // Failure handling.
            if ($status >= 300 && $status < 400) {
                $delivery->status = self::STATUS_FAILED;
                $delivery->errorcode = 'redirect_not_followed';
                $delivery->nextattempt = null;
                $DB->update_record('local_webhookengine_delivery', $delivery);

                rate_limiter::adjust_global_queue(-1);
                if ($delivery->attempts > 1) {
                    rate_limiter::adjust_hook_backlog((int) $hook->id, -1);
                }

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
                $delivery->status = self::STATUS_RETRYING;
                $delivery->errorcode = $response->errno > 0 ? 'network_error_' . $response->errno : 'http_' . $status;
                $delivery->nextattempt = time() + $delay;
                $DB->update_record('local_webhookengine_delivery', $delivery);

                if ($delivery->attempts === 1) {
                    rate_limiter::adjust_hook_backlog((int) $hook->id, 1);
                }

                // Queue ad-hoc task for next run.
                $task = new \local_webhookengine\task\send_delivery();
                $task->set_custom_data((object) ['deliveryid' => (int) $delivery->id]);
                $task->set_next_run_time($delivery->nextattempt);
                \core\task\manager::queue_adhoc_task($task, true);

                $this->record_hook_failure($hook);
                return false;
            }

            // Terminal failure.
            $delivery->status = self::STATUS_FAILED;
            rate_limiter::adjust_global_queue(-1);
            if ($delivery->attempts > 1) {
                rate_limiter::adjust_hook_backlog((int) $hook->id, -1);
            }

            $ispermanent4xx = ($status >= 400 && $status < 500 && !$isretryable);
            if ($ispermanent4xx) {
                $delivery->errorcode = 'http_' . $status;
                $delivery->nextattempt = null;
                $DB->update_record('local_webhookengine_delivery', $delivery);
                circuit_breaker::trigger_misconfig_alert($hook, $status);
                // Do NOT count permanent 4xx toward the breaker.
                return false;
            }

            if ($delivery->attempts >= $hook->maxattempts) {
                $delivery->errorcode = 'max_attempts_exceeded';
            } else {
                $delivery->errorcode = $response->errno > 0 ? 'network_error_' . $response->errno : 'delivery_failed';
            }
            $delivery->nextattempt = null;
            $DB->update_record('local_webhookengine_delivery', $delivery);

            $this->record_hook_failure($hook);
            return false;
        } finally {
            rate_limiter::release_hook_slot($hook->id);
            if (!empty($isprobing)) {
                circuit_breaker::release_probe_claim((int) $hook->id);
            }
        }
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
        circuit_breaker::record_success($hook);
    }

    /**
     * Record hook failure and streak.
     *
     * @param object $hook Hook record.
     */
    private function record_hook_failure(object $hook): void {
        circuit_breaker::record_failure($hook);
    }
}
