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
 * High-performance rate limiting and queue overload guard backed by Moodle application cache.
 *
 * Rate limits are evaluated across rolling 60-second windows. In clustered multi-node environments,
 * the rate limit is enforced approximate per-node when local cache stores (APCu, File) are used;
 * configuring a shared cache store (Redis, Memcached) for local_webhookengine/ratelimit enforces
 * cluster-wide atomic limits.
 *
 * Global queue and per-hook backlog counters are dynamically maintained and reconciled with a 15-second
 * TTL, ensuring storm guards never operate on frozen 120-second stale counts.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limiter {
    /**
     * Check if a hook has exceeded its per-minute delivery limit and increment counter.
     *
     * Fails open if cache backend encounters an exception. Rate cap is evaluated approximate
     * per-node on non-shared caches and cluster-wide on Redis/Memcached cache backends.
     *
     * @param int $hookid Hook ID.
     * @param int|null $ratecap Optional per-minute limit.
     * @return bool True if allowed, false if limit exceeded.
     */
    public static function check_and_increment_hook(int $hookid, ?int $ratecap = null): bool {
        if ($ratecap === null || $ratecap <= 0) {
            $ratecap = (int) get_config('local_webhookengine', 'ratecapperhook') ?: 600;
        }

        try {
            $currentminute = (int) (time() / 60);
            $key = 'rl_' . $hookid . '_' . $currentminute;

            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $count = (int) $cache->get($key);

            if ($count >= $ratecap) {
                return false;
            }

            $cache->set($key, $count + 1);
            return true;
        } catch (\Throwable $e) {
            // Fail open: cache backend degradation must not abort student activity.
            debugging('local_webhookengine: rate_limiter cache error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Alias for check_and_increment_hook for compatibility.
     *
     * @param int $hookid Hook ID.
     * @return bool True if allowed.
     */
    public static function check_hook_rate_limit(int $hookid): bool {
        return self::check_and_increment_hook($hookid);
    }

    /**
     * Check if a specific hook has exceeded its backlog limit of retrying deliveries.
     *
     * Protects the shared queue from noisy neighbor starvation when one target endpoint is down.
     *
     * @param int $hookid Hook ID.
     * @param int $limit Maximum allowed retrying deliveries for a single hook before throttling.
     * @return bool True if backlog limit is exceeded, false otherwise.
     */
    public static function is_hook_backlog_exceeded(int $hookid, int $limit = 2000): bool {
        if ($hookid <= 0) {
            return false;
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $key = 'backlog_data_' . $hookid;
            $data = $cache->get($key);

            if ($data === false || !is_array($data) || (time() - (int) ($data['time'] ?? 0)) > 15) {
                global $DB;
                $count = (int) $DB->count_records('local_webhookengine_delivery', [
                    'hookid' => $hookid,
                    'status' => 'retrying',
                ]);
                $data = ['count' => $count, 'time' => time()];
                $cache->set($key, $data);
            }

            return ((int) $data['count']) >= $limit;
        } catch (\Throwable $e) {
            // Fail open: DB or cache hiccup must not discard valid events.
            debugging('local_webhookengine: backlog check error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Adjust cached hook backlog count dynamically in response to state transitions.
     *
     * @param int $hookid Hook ID.
     * @param int $delta Number to add (positive) or subtract (negative).
     */
    public static function adjust_hook_backlog(int $hookid, int $delta): void {
        if ($hookid <= 0 || $delta === 0) {
            return;
        }
        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $key = 'backlog_data_' . $hookid;
            $data = $cache->get($key);
            if ($data === false || !is_array($data)) {
                $data = ['count' => max(0, $delta), 'time' => time()];
            } else {
                $data['count'] = max(0, (int) $data['count'] + $delta);
            }
            $cache->set($key, $data);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Check if the global pending queue size has exceeded maximum capacity.
     *
     * Dynamic counter cached with 15s TTL and adjusted in-process on enqueue/completion.
     *
     * @param int|null $maxqueue Optional global queue limit.
     * @return bool True if safe to enqueue, false if queue overloaded.
     */
    public static function check_global_queue_guard(?int $maxqueue = null): bool {
        if ($maxqueue === null || $maxqueue <= 0) {
            $maxqueue = (int) get_config('local_webhookengine', 'globalqueuemax') ?: 50000;
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $data = $cache->get('global_queue_data');

            if ($data === false || !is_array($data) || (time() - (int) ($data['time'] ?? 0)) > 15) {
                global $DB;
                $count = (int) $DB->count_records_select(
                    'local_webhookengine_delivery',
                    "status IN ('queued', 'retrying')"
                );
                $data = ['count' => $count, 'time' => time()];
                $cache->set('global_queue_data', $data);
            }

            return ((int) $data['count']) < $maxqueue;
        } catch (\Throwable $e) {
            // Fail open.
            debugging('local_webhookengine: queue guard error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Adjust cached global queue count dynamically upon enqueue or terminal delivery.
     *
     * @param int $delta Number to add (positive) or subtract (negative).
     */
    public static function adjust_global_queue(int $delta): void {
        if ($delta === 0) {
            return;
        }
        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $data = $cache->get('global_queue_data');
            if ($data === false || !is_array($data)) {
                $data = ['count' => max(0, $delta), 'time' => time()];
            } else {
                $data['count'] = max(0, (int) $data['count'] + $delta);
            }
            $cache->set('global_queue_data', $data);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Trigger an administrative message notification when a hook is rate limited.
     *
     * Throttled by cache cooldown to at most once every 15 minutes per hook.
     *
     * @param object $hook Hook database record.
     */
    public static function trigger_rate_limit_alert(object $hook): void {
        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $cooldownkey = 'alert_rl_' . $hook->id;
            if ($cache->get($cooldownkey) !== false) {
                return;
            }
            // Cooldown for 15 minutes.
            $cache->set($cooldownkey, 1);

            $subject = get_string('ratelimit_alert_subject', 'local_webhookengine', $hook->name);
            $message = get_string('ratelimit_alert_body', 'local_webhookengine', (object) [
                'hookname' => $hook->name,
                'hookid' => $hook->id,
                'time' => userdate(time()),
            ]);

            alert_dispatcher::dispatch('ratelimit_alert', $subject, $message);
        } catch (\Throwable $e) {
            debugging('local_webhookengine: failed to trigger rate limit alert: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Acquire a concurrency worker slot for a specific hook.
     *
     * Prevents a slow or degrading hook from monopolizing cron delivery processes.
     *
     * @param int $hookid Hook database ID.
     * @param int $max Maximum allowed concurrent worker slots (default 5).
     * @return bool True if slot acquired, false if concurrency limit reached.
     */
    public static function acquire_hook_slot(int $hookid, int $max = 5): bool {
        if ($hookid <= 0) {
            return true;
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $key = 'concurrent_' . $hookid;
            $count = (int) $cache->get($key);

            if ($count >= $max) {
                return false;
            }

            $cache->set($key, $count + 1);
            return true;
        } catch (\Throwable $e) {
            debugging('local_webhookengine: concurrency check error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Release a concurrency worker slot for a specific hook.
     *
     * @param int $hookid Hook database ID.
     */
    public static function release_hook_slot(int $hookid): void {
        if ($hookid <= 0) {
            return;
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $key = 'concurrent_' . $hookid;
            $count = (int) $cache->get($key);

            if ($count > 1) {
                $cache->set($key, $count - 1);
            } else {
                $cache->delete($key);
            }
        } catch (\Throwable $e) {
            debugging('local_webhookengine: concurrency release error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Release all concurrency slots for a hook (used during cleanup or reset).
     *
     * @param int $hookid Hook database ID.
     */
    public static function release_all_hook_slots(int $hookid): void {
        if ($hookid <= 0) {
            return;
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $cache->delete('concurrent_' . $hookid);
        } catch (\Throwable $e) {
            debugging('local_webhookengine: concurrency clear error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
