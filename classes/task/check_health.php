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

use local_webhookengine\hook_repository;

/**
 * Scheduled task to monitor hook health and auto-pause failing endpoints.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_health extends \core\task\scheduled_task {
    /**
     * Get task name for administration interface.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_check_health', 'local_webhookengine');
    }

    /**
     * Execute health check routine.
     */
    public function execute(): void {
        global $DB;

        $hooks = $DB->get_records('local_webhookengine_hook', ['status' => 'active', 'enabled' => 1]);
        $now = time();
        $threedaysago = $now - (3 * 86400);

        foreach ($hooks as $hook) {
            $shouldpause = false;
            $reason = '';

            if ($hook->failstreak >= 100) {
                $shouldpause = true;
                $reason = get_string('autopause_reason_streak', 'local_webhookengine', $hook->failstreak);
            } else if (
                $hook->lastfailure > 0 && $hook->lastfailure < $now &&
                       ($hook->lastsuccess === null || $hook->lastsuccess < $threedaysago) &&
                       $hook->timecreated < $threedaysago
            ) {
                $shouldpause = true;
                $reason = get_string('autopause_reason_duration', 'local_webhookengine');
            }

            if ($shouldpause) {
                $DB->set_field('local_webhookengine_hook', 'status', 'paused', ['id' => $hook->id]);
                $DB->set_field('local_webhookengine_hook', 'timemodified', $now, ['id' => $hook->id]);
                hook_repository::invalidate_event_map();
                $this->notify_admins($hook, $reason);
            }
        }
    }

    /**
     * Notify administrators / managers when a hook is automatically paused.
     *
     * @param object $hook Hook database record.
     * @param string $reason Human-readable explanation.
     */
    private function notify_admins(object $hook, string $reason): void {
        $admins = get_admins();
        if (empty($admins)) {
            return;
        }

        $adminuser = reset($admins);
        $manageurl = new \moodle_url('/local/webhookengine/manage.php');

        $message = new \core\message\message();
        $message->component = 'local_webhookengine';
        $message->name = 'hookpaused';
        $message->userfrom = \core_user::get_noreply_user();
        $message->subject = get_string('message_hookpaused_subject', 'local_webhookengine', $hook->name);
        $message->fullmessage = get_string('message_hookpaused_body', 'local_webhookengine', [
            'name' => $hook->name,
            'reason' => $reason,
            'url' => $manageurl->out(false),
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('message_hookpaused_small', 'local_webhookengine', $hook->name);
        $message->notification = 1;
        $message->contexturl = $manageurl->out(false);
        $message->contexturlname = get_string('manage_hooks', 'local_webhookengine');

        foreach ($admins as $admin) {
            $message->userto = $admin;
            message_send($message);
        }
    }
}
