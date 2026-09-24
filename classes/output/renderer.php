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

namespace local_webhookengine\output;

use plugin_renderer_base;

/**
 * Output renderer for local_webhookengine plugin.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Render a health panel overview for administrators.
     *
     * @param int $queuedcount Number of queued deliveries.
     * @param int|null $oldestage Seconds since oldest queued delivery.
     * @param bool $killswitch Whether global killswitch is active.
     * @return string Rendered HTML.
     */
    public function render_health_panel(int $queuedcount, ?int $oldestage, bool $killswitch): string {
        $context = [
            'queuedcount' => $queuedcount,
            'oldestage' => $oldestage !== null ? format_time($oldestage) : null,
            'isdelayed' => ($oldestage !== null && $oldestage > 900), // 15 minutes threshold.
            'killswitch' => $killswitch,
        ];
        return $this->render_from_template('local_webhookengine/health_panel', $context);
    }

    /**
     * Render a status badge for a hook or delivery.
     *
     * @param string $status Status string (active, paused, success, retrying, failed, dropped).
     * @return string Rendered HTML badge.
     */
    public function render_status_badge(string $status): string {
        $context = [
            'status' => $status,
            'label' => get_string('status_' . $status, 'local_webhookengine'),
            'isactive' => ($status === 'active' || $status === 'success'),
            'iswarning' => ($status === 'paused' || $status === 'retrying'),
            'isdanger' => ($status === 'failed' || $status === 'dropped'),
        ];
        return $this->render_from_template('local_webhookengine/status_badge', $context);
    }
}
