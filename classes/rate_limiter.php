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
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limiter {
    /**
     * Check if a hook has exceeded its per-minute delivery limit and increment counter.
     *
     * @param int $hookid Hook ID.
     * @param int|null $ratecap Optional per-minute limit.
     * @return bool True if allowed, false if limit exceeded.
     */
    public static function check_and_increment_hook(int $hookid, ?int $ratecap = null): bool {
        if ($ratecap === null || $ratecap <= 0) {
            $ratecap = (int) get_config('local_webhookengine', 'ratecapperhook') ?: 600;
        }

        $currentminute = (int) (time() / 60);
        $key = 'rl_' . $hookid . '_' . $currentminute;

        $cache = \cache::make('local_webhookengine', 'ratelimit');
        $count = (int) $cache->get($key);

        if ($count >= $ratecap) {
            return false;
        }

        $cache->set($key, $count + 1);
        return true;
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
     * Check if the global pending queue size has exceeded maximum capacity.
     *
     * @param int|null $maxqueue Optional global queue limit.
     * @return bool True if safe to enqueue, false if queue overloaded.
     */
    public static function check_global_queue_guard(?int $maxqueue = null): bool {
        if ($maxqueue === null || $maxqueue <= 0) {
            $maxqueue = (int) get_config('local_webhookengine', 'globalqueuemax') ?: 50000;
        }

        $cache = \cache::make('local_webhookengine', 'ratelimit');
        $cachedcount = $cache->get('global_queue_count');

        if ($cachedcount === false) {
            global $DB;
            $count = $DB->count_records('local_webhookengine_delivery', ['status' => 'queued']);
            $cache->set('global_queue_count', $count);
            return $count < $maxqueue;
        }

        return ((int) $cachedcount) < $maxqueue;
    }
}
