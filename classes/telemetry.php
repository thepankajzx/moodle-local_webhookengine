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
 * Service calculating real-time telemetry, 24-hour success rates, p95 latencies, and queue depth.
 *
 * Backed by Moodle Application Cache (MUC) with short TTL to avoid production DB strain.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telemetry {
    /** @var int Cache TTL in seconds for metrics calculations. */
    const CACHE_TTL = 60;

    /**
     * Compute system-wide telemetry metrics for the administrative health dashboard.
     *
     * @param bool $skipcache Set true to force real-time recalculation.
     * @return array
     */
    public static function get_system_metrics(bool $skipcache = false): array {
        global $DB;

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $cachekey = 'sys_metrics';
            if (!$skipcache) {
                $cached = $cache->get($cachekey);
                if ($cached !== false && is_array($cached)) {
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        $now = time();
        $cutoff24h = $now - 86400;

        // 1. Pending queue count (queued + retrying).
        $queuedcount = $DB->count_records_select(
            'local_webhookengine_delivery',
            "status IN ('queued', 'retrying')"
        );

        // 2. Oldest queued delivery age.
        $oldestsql = "SELECT MIN(nextattempt) AS oldest
                        FROM {local_webhookengine_delivery}
                       WHERE status IN ('queued', 'retrying')";
        $oldestrecord = $DB->get_record_sql($oldestsql);
        $oldest = $oldestrecord ? $oldestrecord->oldest : null;

        $isdelayed = false;
        $oldestage = null;
        if (!empty($oldest) && $oldest > 0) {
            $ageinseconds = $now - (int) $oldest;
            if ($ageinseconds > 900) { // Over 15 minutes.
                $isdelayed = true;
            }
            if ($ageinseconds > 0) {
                $oldestage = format_time($ageinseconds);
            } else {
                $oldestage = get_string('now');
            }
        }

        // 3. Drop counts.
        $totaldrops = hook_repository::get_all_hooks_total_drops();
        $sumdrops = array_sum($totaldrops);

        // 4. Rolling 24-hour success and failure counts.
        $statsql = "SELECT status, COUNT(1) AS cnt
                      FROM {local_webhookengine_delivery}
                     WHERE timecreated >= :cutoff
                       AND status IN ('success', 'failed')
                  GROUP BY status";
        $statrecords = $DB->get_records_sql($statsql, ['cutoff' => $cutoff24h]);

        $successcount = isset($statrecords['success']) ? (int) $statrecords['success']->cnt : 0;
        $failedcount = isset($statrecords['failed']) ? (int) $statrecords['failed']->cnt : 0;
        $totalterminal = $successcount + $failedcount;

        $successrate = null;
        $successratedisplay = '-';
        if ($totalterminal > 0) {
            $successrate = round(($successcount / $totalterminal) * 100, 1);
            $successratedisplay = $successrate . '%';
        }

        // 5. p95 delivery duration (last 24 hours of successful deliveries, max 500 samples).
        $durationsql = "SELECT duration
                          FROM {local_webhookengine_delivery}
                         WHERE status = 'success'
                           AND timecreated >= :cutoff
                           AND duration IS NOT NULL
                           AND duration > 0
                      ORDER BY id DESC";
        $durations = $DB->get_fieldset_sql($durationsql, ['cutoff' => $cutoff24h], 0, 500);

        $p95 = self::calculate_p95((array) $durations);
        $p95display = ($p95 !== null) ? $p95 . ' ms' : '-';

        // 6. Tripped circuit breakers count.
        $trippedcircuits = $DB->count_records('local_webhookengine_hook', [
            'circuitstate' => circuit_breaker::STATE_OPEN,
        ]);

        $metrics = [
            'queuedcount' => $queuedcount,
            'totaldrops' => $sumdrops,
            'hasdrops' => ($sumdrops > 0),
            'oldestage' => $oldestage,
            'isdelayed' => $isdelayed,
            'killswitch' => (bool) get_config('local_webhookengine', 'killswitch'),
            'successrate_24h' => $successrate,
            'successrate_display' => $successratedisplay,
            'p95_duration_ms' => $p95,
            'p95_display' => $p95display,
            'tripped_circuits_count' => $trippedcircuits,
            'has_tripped_circuits' => ($trippedcircuits > 0),
            'circuits_status_display' => ($trippedcircuits > 0)
                ? get_string('circuits_tripped_count', 'local_webhookengine', $trippedcircuits)
                : get_string('circuits_all_healthy', 'local_webhookengine'),
        ];

        try {
            if (isset($cache)) {
                $cache->set($cachekey, $metrics);
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        return $metrics;
    }

    /**
     * Compute telemetry metrics for a single hook record.
     *
     * @param int $hookid Hook database ID.
     * @param bool $skipcache Set true to force real-time recalculation.
     * @return array
     */
    public static function get_hook_metrics(int $hookid, bool $skipcache = false): array {
        global $DB;

        if ($hookid <= 0) {
            return [
                'successrate_24h' => null,
                'successrate_display' => '-',
                'p95_duration_ms' => null,
                'p95_display' => '-',
                'queued_count' => 0,
                'lasterror' => null,
                'lasterrortime' => null,
                'lasterror_display' => '-',
            ];
        }

        try {
            $cache = \cache::make('local_webhookengine', 'ratelimit');
            $cachekey = 'hook_metrics_' . $hookid;
            if (!$skipcache) {
                $cached = $cache->get($cachekey);
                if ($cached !== false && is_array($cached)) {
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        $now = time();
        $cutoff24h = $now - 86400;

        // 1. Success rate in last 24h.
        $statsql = "SELECT status, COUNT(1) AS cnt
                      FROM {local_webhookengine_delivery}
                     WHERE hookid = :hookid
                       AND timecreated >= :cutoff
                       AND status IN ('success', 'failed')
                  GROUP BY status";
        $statrecords = $DB->get_records_sql($statsql, ['hookid' => $hookid, 'cutoff' => $cutoff24h]);

        $successcount = isset($statrecords['success']) ? (int) $statrecords['success']->cnt : 0;
        $failedcount = isset($statrecords['failed']) ? (int) $statrecords['failed']->cnt : 0;
        $totalterminal = $successcount + $failedcount;

        $successrate = null;
        $successratedisplay = '-';
        if ($totalterminal > 0) {
            $successrate = round(($successcount / $totalterminal) * 100, 1);
            $successratedisplay = $successrate . '%';
        }

        // 2. p95 delivery duration for this hook.
        $durationsql = "SELECT duration
                          FROM {local_webhookengine_delivery}
                         WHERE hookid = :hookid
                           AND status = 'success'
                           AND timecreated >= :cutoff
                           AND duration IS NOT NULL
                           AND duration > 0
                      ORDER BY id DESC";
        $durations = $DB->get_fieldset_sql($durationsql, ['hookid' => $hookid, 'cutoff' => $cutoff24h], 0, 200);

        $p95 = self::calculate_p95((array) $durations);
        $p95display = ($p95 !== null) ? $p95 . ' ms' : '-';

        // 3. Queued deliveries count for this hook.
        $queuedcount = $DB->count_records_select(
            'local_webhookengine_delivery',
            "hookid = :hookid AND status IN ('queued', 'retrying')",
            ['hookid' => $hookid]
        );

        // 4. Last error details.
        $errorsql = "SELECT errorcode, timelastattempt
                       FROM {local_webhookengine_delivery}
                      WHERE hookid = :hookid
                        AND status IN ('failed', 'retrying')
                        AND errorcode IS NOT NULL
                   ORDER BY id DESC";
        $lastfailed = $DB->get_records_sql($errorsql, ['hookid' => $hookid], 0, 1);

        $lasterror = null;
        $lasterrortime = null;
        $lasterrordisplay = '-';
        if (!empty($lastfailed)) {
            $rec = reset($lastfailed);
            $lasterror = $rec->errorcode;
            $lasterrortime = (int) $rec->timelastattempt;
            $lasterrordisplay = s($lasterror);
            if ($lasterrortime > 0) {
                $lasterrordisplay .= ' (' . format_time($now - $lasterrortime) . ' ago)';
            }
        }

        $metrics = [
            'successrate_24h' => $successrate,
            'successrate_display' => $successratedisplay,
            'p95_duration_ms' => $p95,
            'p95_display' => $p95display,
            'queued_count' => $queuedcount,
            'lasterror' => $lasterror,
            'lasterrortime' => $lasterrortime,
            'lasterror_display' => $lasterrordisplay,
        ];

        try {
            if (isset($cache)) {
                $cache->set($cachekey, $metrics);
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        return $metrics;
    }

    /**
     * Calculate 95th percentile duration from a sample array.
     *
     * @param array $durations Array of numeric durations in milliseconds.
     * @return int|null p95 value in ms, or null if no samples available.
     */
    public static function calculate_p95(array $durations): ?int {
        if (empty($durations)) {
            return null;
        }

        $values = array_values(array_filter(array_map('intval', $durations), function ($v) {
            return $v > 0;
        }));

        $count = count($values);
        if ($count === 0) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        // Calculate 95th percentile rank index.
        $index = (int) ceil($count * 0.95) - 1;
        $index = max(0, min($index, $count - 1));

        return $values[$index];
    }
}
