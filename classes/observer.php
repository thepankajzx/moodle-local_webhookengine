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
 * Wildcard event observer for intercepting Moodle events with zero database overhead for non-matching events.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Main event observer handler executing post-DB commit.
     *
     * @param \core\event\base $event The fired Moodle event.
     */
    public static function handle(\core\event\base $event): void {
        global $DB;

        try {
            // 1. Return immediately during initial install.
            if (during_initial_install()) {
                return;
            }

            $eventname = hook_repository::normalize_event_name((string) $event->eventname);

            // Loop protection: ignore audit events fired by local_webhookengine.
            if (str_starts_with($eventname, '\\local_webhookengine\\event\\')) {
                return;
            }

            // Global event denylist check.
            if (event_catalog::is_denylisted($eventname)) {
                return;
            }

            // 2. O(1) Cached event map lookup (ZERO DB queries if no hook is subscribed).
            $map = hook_repository::get_event_map();
            if (empty($map) || empty($map[$eventname])) {
                return;
            }

            $hookentries = $map[$eventname];
            if (empty($hookentries)) {
                return;
            }

            $courseid = $event->courseid ? (int) $event->courseid : null;
            $userid = $event->userid ? (int) $event->userid : null;
            $iskillswitch = (bool) get_config('local_webhookengine', 'killswitch');

            // 3. Process each matching hook.
            foreach ($hookentries as $entry) {
                if (is_array($entry)) {
                    $hookid = (int) $entry['id'];
                    $hookstatus = (string) ($entry['status'] ?? 'active');
                } else {
                    $hookid = (int) $entry;
                    $hookstatus = 'active';
                }

                if ($hookid <= 0) {
                    continue;
                }

                $hook = hook_repository::get_hook($hookid);
                if (!$hook || empty($hook->enabled)) {
                    continue;
                }

                // CRITICAL (F3): Evaluate filter rules BEFORE recording paused or killswitch drops!
                // An event outside a hook's scope is not relevant and must never be recorded as a drop.
                if (!filter_matcher::matches($hook->filters, $courseid, $userid)) {
                    continue;
                }

                if ($iskillswitch) {
                    hook_repository::record_drop($hookid, reasons::KILL_SWITCH);
                    continue;
                }

                if ($hookstatus === 'paused' || $hook->status === 'paused') {
                    hook_repository::record_drop($hookid, reasons::HOOK_PAUSED);
                    continue;
                }

                if ($hook->status !== 'active') {
                    continue;
                }

                // Backlog check: protect shared queue from noisy neighbor starvation.
                if (rate_limiter::is_hook_backlog_exceeded($hook->id)) {
                    hook_repository::record_drop($hook->id, reasons::HOOK_BACKLOG_EXCEEDED);
                    continue;
                }

                // Rate limiting & global storm guard check.
                $ratecap = (int) get_config('local_webhookengine', 'ratecapperhook') ?: 600;
                $queuemax = (int) get_config('local_webhookengine', 'globalqueuemax') ?: 50000;

                if (!rate_limiter::check_and_increment_hook($hook->id, $ratecap)) {
                    hook_repository::record_drop($hook->id, reasons::RATE_LIMITED);
                    rate_limiter::trigger_rate_limit_alert($hook);
                    continue;
                }
                if (!rate_limiter::check_global_queue_guard($queuemax)) {
                    hook_repository::record_drop($hook->id, reasons::QUEUE_OVERLOAD);
                    continue;
                }

                // Extract event data.
                $rawdata = $event->get_data();

                // Privacy check: strip userid for anonymous events (e.g. anonymous surveys or feedback).
                if (!empty($event->anonymous)) {
                    $rawdata['userid'] = null;
                }

                // Never include raw 'other' metadata array unless hook explicitly opted in.
                if (empty($hook->payloadopts['includeother'])) {
                    unset($rawdata['other']);
                } else if (!empty($hook->payloadopts['otherkeys']) && isset($rawdata['other']) && is_array($rawdata['other'])) {
                    $allowedkeys = (array) $hook->payloadopts['otherkeys'];
                    $rawdata['other'] = array_intersect_key($rawdata['other'], array_flip($allowedkeys));
                }

                $uuid = \core\uuid::generate();
                $jsonflags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
                $payloadjson = json_encode($rawdata, $jsonflags);

                // Cap raw payload size at 256KB to protect database from oversized events.
                if (strlen($payloadjson) > 262144) {
                    if (isset($rawdata['other'])) {
                        $rawdata['other'] = ['truncated' => true];
                        $payloadjson = json_encode($rawdata, $jsonflags);
                    }
                }

                $now = time();
                $iscircuitopen = (circuit_breaker::get_state($hook) === circuit_breaker::STATE_OPEN);
                $nextattempt = $iscircuitopen ? max($now, (int) $hook->circuitcooldownuntil) : $now;

                $delivery = (object) [
                    'hookid' => (int) $hook->id,
                    'uuid' => $uuid,
                    'eventname' => $eventname,
                    'status' => delivery_service::STATUS_QUEUED,
                    'attempts' => 0,
                    'nextattempt' => $nextattempt,
                    'httpstatus' => null,
                    'errorcode' => null,
                    'duration' => null,
                    'userid' => $rawdata['userid'] ? (int) $rawdata['userid'] : null,
                    'relateduserid' => !empty($rawdata['relateduserid']) ? (int) $rawdata['relateduserid'] : null,
                    'courseid' => $rawdata['courseid'] ? (int) $rawdata['courseid'] : null,
                    'payload' => $payloadjson,
                    'timecreated' => $now,
                    'timelastattempt' => null,
                ];

                $deliveryid = (int) $DB->insert_record('local_webhookengine_delivery', $delivery);
                rate_limiter::adjust_global_queue(1);

                // Queue background adhoc task (custom data contains strictly delivery ID only).
                $task = new \local_webhookengine\task\send_delivery();
                $task->set_custom_data((object) ['deliveryid' => $deliveryid]);
                if ($iscircuitopen) {
                    $task->set_next_run_time($nextattempt);
                }
                \core\task\manager::queue_adhoc_task($task, true);
            }
        } catch (\Throwable $e) {
            // Graceful error isolation: record observer error in drop ledger if target hook was identified.
            if (isset($hook) && is_object($hook) && !empty($hook->id)) {
                hook_repository::record_drop((int) $hook->id, reasons::OBSERVER_ERROR);
            }
            debugging('local_webhookengine: observer error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
