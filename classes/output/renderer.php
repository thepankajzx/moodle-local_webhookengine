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
     * @param int $totaldrops Total dropped events recorded across all hooks.
     * @param array $telemetry Optional extended telemetry data.
     * @return string Rendered HTML.
     */
    public function render_health_panel(
        int $queuedcount,
        ?int $oldestage,
        bool $killswitch,
        int $totaldrops = 0,
        array $telemetry = []
    ): string {
        $hascircuittrip = !empty($telemetry['has_tripped_circuits']);
        $isdelayed = ($oldestage !== null && $oldestage > 900);

        if ($killswitch) {
            $herostatus = 'danger';
            $herotitle = get_string('dashboard_hero_paused_title', 'local_webhookengine');
            $herodesc = get_string('dashboard_hero_paused_desc', 'local_webhookengine');
            $herobadge = get_string('killswitch_active', 'local_webhookengine');
        } else if ($hascircuittrip) {
            $herostatus = 'warning';
            $herotitle = get_string('dashboard_hero_warning_title', 'local_webhookengine');
            $herodesc = get_string('dashboard_hero_warning_desc', 'local_webhookengine');
            $herobadge = get_string('card_circuits_badge_tripped', 'local_webhookengine');
        } else {
            $herostatus = 'success';
            $herotitle = get_string('dashboard_hero_operational_title', 'local_webhookengine');
            $herodesc = get_string('dashboard_hero_operational_desc', 'local_webhookengine');
            $herobadge = get_string('system_operational', 'local_webhookengine');
        }

        $oldestdisplay = null;
        if (!empty($telemetry['oldestage'])) {
            $oldestdisplay = $telemetry['oldestage'];
        } else if ($oldestage !== null && $oldestage > 0) {
            $oldestdisplay = format_time($oldestage);
        } else {
            $oldestdisplay = get_string('card_oldest_healthy', 'local_webhookengine');
        }

        // Dynamically compute success rate styling and badges.
        $successrate = $telemetry['successrate_24h'] ?? null;
        $successsubtitle = get_string('card_success_subtitle', 'local_webhookengine');
        $successcardborder = 'webhookengine-card-emerald';
        $successicon = '📈';
        $successiconbg = 'webhookengine-icon-green';

        if ($successrate === null) {
            $successbadge = get_string('card_success_no_data', 'local_webhookengine');
            $successcolor = 'text-muted';
            $successbadgeclass = 'webhookengine-pill-gray';
            $successcardborder = 'webhookengine-card-gray';
            $successicon = '📊';
            $successiconbg = 'webhookengine-icon-gray';
        } else if ($successrate >= 98.0) {
            $successbadge = get_string('card_success_optimal', 'local_webhookengine');
            $successcolor = 'text-success';
            $successbadgeclass = 'webhookengine-pill-green';
            $successcardborder = 'webhookengine-card-emerald';
            $successicon = '📈';
            $successiconbg = 'webhookengine-icon-green';
        } else if ($successrate >= 90.0) {
            $successbadge = get_string('card_success_good', 'local_webhookengine');
            $successcolor = 'text-primary';
            $successbadgeclass = 'webhookengine-pill-blue';
            $successcardborder = 'webhookengine-card-blue';
            $successicon = '📈';
            $successiconbg = 'webhookengine-icon-blue';
        } else if ($successrate >= 75.0) {
            $successbadge = get_string('card_success_degraded', 'local_webhookengine');
            $successcolor = 'text-warning';
            $successbadgeclass = 'webhookengine-pill-amber';
            $successcardborder = 'webhookengine-card-amber';
            $successicon = '⚠️';
            $successiconbg = 'webhookengine-icon-amber';
        } else {
            // High error rate (e.g. 9.5%).
            $successbadge = get_string('card_success_critical', 'local_webhookengine');
            $successcolor = 'text-danger';
            $successbadgeclass = 'webhookengine-pill-red';
            $successcardborder = 'webhookengine-card-red';
            $successsubtitle = get_string('card_success_critical_subtitle', 'local_webhookengine');
            $successicon = '⚠️';
            $successiconbg = 'webhookengine-icon-red';
        }

        $context = array_merge([
            'queuedcount' => $queuedcount,
            'queuedcount_formatted' => number_format($queuedcount),
            'has_queued' => ($queuedcount > 0),
            'queued_url' => (new \moodle_url('/local/webhookengine/deliveries.php', ['status' => 'queued']))->out(false),
            'totaldrops' => $totaldrops,
            'totaldrops_formatted' => number_format($totaldrops),
            'hasdrops' => ($totaldrops > 0),
            'drops_url' => (new \moodle_url('/local/webhookengine/deliveries.php', ['status' => 'dropped']))->out(false),
            'success_url' => (new \moodle_url('/local/webhookengine/deliveries.php', ['status' => 'success']))->out(false),
            'deliveries_url' => (new \moodle_url('/local/webhookengine/deliveries.php'))->out(false),
            'add_hook_url' => (new \moodle_url('/local/webhookengine/edit.php'))->out(false),
            'settings_url' => (new \moodle_url('/admin/settings.php', ['section' => 'local_webhookengine_settings']))->out(false),
            'circuits_url' => '#hooks_table',
            'oldestage' => $oldestdisplay,
            'isdelayed' => $isdelayed,
            'killswitch' => $killswitch,
            'herostatus' => $herostatus,
            'herotitle' => $herotitle,
            'herodesc' => $herodesc,
            'herobadge' => $herobadge,
            'is_operational' => ($herostatus === 'success'),
            'is_warning' => ($herostatus === 'warning'),
            'is_danger' => ($herostatus === 'danger'),
            'successrate_display' => $telemetry['successrate_display'] ?? '-',
            'successrate_badge' => $successbadge,
            'successrate_badge_class' => $successbadgeclass,
            'successrate_color_class' => $successcolor,
            'successrate_card_border' => $successcardborder,
            'successrate_subtitle' => $successsubtitle,
            'successicon' => $successicon,
            'successicon_bg' => $successiconbg,
            'p95_display' => $telemetry['p95_display'] ?? '-',
            'circuits_status_display' => $telemetry['circuits_status_display'] ?? '-',
            'has_tripped_circuits' => $hascircuittrip,
        ], $telemetry);
        return $this->render_from_template('local_webhookengine/health_panel', $context);
    }

    /**
     * Render a status badge for a hook or delivery.
     *
     * @param string $status Status string (active, paused, queued, success, retrying, failed, dropped).
     * @return string Rendered HTML badge.
     */
    public function render_status_badge(string $status): string {
        $context = [
            'status' => $status,
            'label' => get_string('status_' . $status, 'local_webhookengine'),
            'isactive' => ($status === 'active' || $status === 'success'),
            'iswarning' => ($status === 'paused' || $status === 'retrying'),
            'isdanger' => ($status === 'failed' || $status === 'dropped'),
            'isinfo' => ($status === 'queued'),
        ];
        return $this->render_from_template('local_webhookengine/status_badge', $context);
    }
}
