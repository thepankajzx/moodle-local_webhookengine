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

namespace local_webhookengine\task;

/**
 * Scheduled maintenance task to purge expired logs and clear retained payloads.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_logs extends \core\task\scheduled_task {
    /**
     * Get task name for administration interface.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purge_logs', 'local_webhookengine');
    }

    /**
     * Execute scheduled purge and retention cleanup.
     */
    public function execute(): void {
        global $DB;

        $logdays = (int) get_config('local_webhookengine', 'logretention');
        if ($logdays <= 0) {
            $logdays = 30;
        }

        $payloaddays = (int) get_config('local_webhookengine', 'payloadretention');
        if ($payloaddays <= 0) {
            $payloaddays = 14;
        }

        $now = time();
        $logcutoff = $now - ($logdays * 86400);
        $payloadcutoff = $now - ($payloaddays * 86400);
        $timelimit = $now + 50; // 50 seconds execution budget.

        // 1. Clear payload content for old terminal deliveries to conserve storage and respect privacy.
        $select = "payload IS NOT NULL AND timecreated < :cutoff AND status IN ('success', 'failed', 'dropped')";
        while (time() < $timelimit) {
            $records = $DB->get_records_select(
                'local_webhookengine_delivery',
                $select,
                ['cutoff' => $payloadcutoff],
                'id ASC',
                'id',
                0,
                500
            );
            if (empty($records)) {
                break;
            }
            $ids = array_keys($records);
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->execute("UPDATE {local_webhookengine_delivery} SET payload = NULL WHERE id $insql", $inparams);
        }

        // 2. Delete full delivery log records older than retention threshold.
        while (time() < $timelimit) {
            $records = $DB->get_records_select(
                'local_webhookengine_delivery',
                'timecreated < :cutoff',
                ['cutoff' => $logcutoff],
                'id ASC',
                'id',
                0,
                500
            );
            if (empty($records)) {
                break;
            }
            $ids = array_keys($records);
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {local_webhookengine_delivery} WHERE id $insql", $inparams);
        }
    }
}
