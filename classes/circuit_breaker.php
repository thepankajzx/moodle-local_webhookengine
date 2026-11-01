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
 * Circuit breaker managing webhook endpoint failure degradation and half-open probes.
 *
 * Prevents network socket exhaustion and cron saturation when downstream endpoints degrade.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class circuit_breaker {
    /** @var string Normal operational state. */
    public const STATE_CLOSED = 'closed';

    /** @var string Tripped state due to consecutive delivery failures. */
    public const STATE_OPEN = 'open';

    /** @var string Cooldown expired, testing with single probe delivery. */
    public const STATE_HALF_OPEN = 'half_open';

    /**
     * Get configured failure streak required to trip circuit breaker.
     *
     * @return int
     */
    public static function get_trip_streak(): int {
        $val = get_config('local_webhookengine', 'circuitbreakertripstreak');
        return ($val !== false && $val !== '' && (int) $val > 0) ? (int) $val : 10;
    }

    /**
     * Get configured cooldown period in seconds before attempting half-open probe.
     *
     * @return int
     */
    public static function get_cooldown_period(): int {
        $val = get_config('local_webhookengine', 'circuitbreakercooldown');
        return ($val !== false && $val !== '' && (int) $val > 0) ? (int) $val : 300;
    }

    /**
     * Determine current effective circuit breaker state for a hook.
     *
     * Transitions from OPEN to HALF_OPEN automatically if cooldown timestamp has elapsed.
     *
     * @param object $hook Hook database record.
     * @return string Current state (closed, open, or half_open).
     */
    public static function get_state(object $hook): string {
        global $DB;

        $state = !empty($hook->circuitstate) ? $hook->circuitstate : self::STATE_CLOSED;
        $cooldownuntil = !empty($hook->circuitcooldownuntil) ? (int) $hook->circuitcooldownuntil : 0;

        if ($state === self::STATE_OPEN) {
            if (time() >= $cooldownuntil) {
                // Cooldown has elapsed: transition automatically to half_open probe mode.
                $state = self::STATE_HALF_OPEN;
                $hook->circuitstate = self::STATE_HALF_OPEN;
                $DB->update_record('local_webhookengine_hook', (object) [
                    'id' => $hook->id,
                    'circuitstate' => self::STATE_HALF_OPEN,
                ]);
            }
        }

        return $state;
    }

    /**
     * Check whether an outbound delivery or event queueing is permitted.
     *
     * @param object $hook Hook database record.
     * @return bool True if allowed to dispatch, false if blocked by open circuit.
     */
    public static function can_dispatch(object $hook): bool {
        $state = self::get_state($hook);
        return ($state === self::STATE_CLOSED || $state === self::STATE_HALF_OPEN);
    }

    /**
     * Record a delivery failure and evaluate circuit tripping conditions.
     *
     * @param object $hook Hook database record.
     */
    public static function record_failure(object $hook): void {
        global $DB;

        $tripthreshold = self::get_trip_streak();
        $cooldownduration = self::get_cooldown_period();
        $now = time();

        $newstreak = ((int) $hook->failstreak) + 1;
        $currentstate = self::get_state($hook);

        $tripped = false;
        if ($currentstate === self::STATE_HALF_OPEN || $newstreak >= $tripthreshold) {
            $currentstate = self::STATE_OPEN;
            $cooldownuntil = $now + $cooldownduration;
            $tripped = true;
        } else {
            $cooldownuntil = 0;
        }

        $update = (object) [
            'id' => $hook->id,
            'failstreak' => $newstreak,
            'circuitstate' => $currentstate,
            'circuitcooldownuntil' => $cooldownuntil,
            'lastfailure' => $now,
            'timemodified' => $now,
        ];

        $DB->update_record('local_webhookengine_hook', $update);

        // Update passed hook object reference.
        $hook->failstreak = $newstreak;
        $hook->circuitstate = $currentstate;
        $hook->circuitcooldownuntil = $cooldownuntil;
        $hook->lastfailure = $now;

        self::release_probe_claim((int) $hook->id);

        if ($tripped) {
            self::trigger_circuit_alert($hook, $newstreak, $cooldownduration);
        }
    }

    /**
     * Record a successful delivery attempt and restore clean closed state.
     *
     * @param object $hook Hook database record.
     */
    public static function record_success(object $hook): void {
        global $DB;
        $now = time();

        $update = (object) [
            'id' => $hook->id,
            'failstreak' => 0,
            'circuitstate' => self::STATE_CLOSED,
            'circuitcooldownuntil' => 0,
            'lastsuccess' => $now,
            'timemodified' => $now,
        ];

        $DB->update_record('local_webhookengine_hook', $update);

        // Update passed hook object reference.
        $hook->failstreak = 0;
        $hook->circuitstate = self::STATE_CLOSED;
        $hook->circuitcooldownuntil = 0;
        $hook->lastsuccess = $now;

        self::release_probe_claim((int) $hook->id);
    }

    /**
     * Manually reset a tripped circuit breaker back to closed state.
     *
     * @param int $hookid Hook ID.
     * @return bool True on success.
     */
    public static function reset_circuit(int $hookid): bool {
        global $DB;
        $hook = $DB->get_record('local_webhookengine_hook', ['id' => $hookid]);
        if (!$hook) {
            return false;
        }

        $now = time();
        $update = (object) [
            'id' => $hookid,
            'failstreak' => 0,
            'circuitstate' => self::STATE_CLOSED,
            'circuitcooldownuntil' => 0,
            'timemodified' => $now,
        ];

        self::release_probe_claim($hookid);
        return $DB->update_record('local_webhookengine_hook', $update);
    }

    /**
     * Atomically claim the single in-flight probe slot for a hook in half-open state.
     *
     * @param int $hookid Hook database ID.
     * @return bool True if probe claim was acquired, false if a probe is already in-flight.
     */
    public static function claim_half_open_probe(int $hookid): bool {
        if ($hookid <= 0) {
            return false;
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $key = 'probe_claim_' . $hookid;
            $existing = $cache->get($key);

            if (!empty($existing) && (time() - (int) $existing) < 60) {
                // A probe is already in flight (claim valid for 60s).
                return false;
            }

            $cache->set($key, time());
            return true;
        } catch (\Throwable $e) {
            debugging('local_webhookengine: failed to claim probe slot: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Release the in-flight probe claim for a hook.
     *
     * @param int $hookid Hook database ID.
     */
    public static function release_probe_claim(int $hookid): void {
        if ($hookid <= 0) {
            return;
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $cache->delete('probe_claim_' . $hookid);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Dispatch an administrative notification alert when a circuit breaker trips.
     *
     * @param object $hook Hook database record.
     * @param int $failstreak Number of consecutive failures.
     * @param int $cooldown Cooldown period in seconds.
     */
    public static function trigger_circuit_alert(object $hook, int $failstreak, int $cooldown): void {
        $cooldownmin = (int) ceil($cooldown / 60);
        $subject = get_string('circuit_breaker_alert_subject', 'local_webhookengine', $hook->name);
        $message = get_string('circuit_breaker_alert_body', 'local_webhookengine', (object) [
            'name' => $hook->name,
            'url' => $hook->url,
            'streak' => $failstreak,
            'cooldown' => $cooldownmin,
        ]);

        alert_dispatcher::dispatch('circuit_breaker_alert', $subject, $message);
    }

    /**
     * Dispatch an administrative notification alert when a permanent 4xx misconfiguration occurs.
     *
     * @param object $hook Hook database record.
     * @param int $httpstatus HTTP status code.
     */
    public static function trigger_misconfig_alert(object $hook, int $httpstatus): void {
        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $cooldownkey = 'alert_misconfig_' . $hook->id . '_' . $httpstatus;
            if ($cache->get($cooldownkey) !== false) {
                return;
            }
            // Cooldown for 15 minutes.
            $cache->set($cooldownkey, 1);

            $subject = get_string('misconfig_alert_subject', 'local_webhookengine', (object) [
                'name' => $hook->name,
                'status' => $httpstatus,
            ]);
            $message = get_string('misconfig_alert_body', 'local_webhookengine', (object) [
                'name' => $hook->name,
                'url' => $hook->url,
                'status' => $httpstatus,
                'time' => userdate(time()),
            ]);

            alert_dispatcher::dispatch('circuit_breaker_alert', $subject, $message);
        } catch (\Throwable $e) {
            debugging('local_webhookengine: failed to dispatch misconfig alert: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
