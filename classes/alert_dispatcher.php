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
 * Service managing dual-channel alert dispatching via Moodle messages and direct email.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class alert_dispatcher {
    /**
     * Dispatch an alert notification across Moodle messages and direct email channels.
     *
     * @param string $eventname Message provider event name (e.g. circuit_breaker_alert, ratelimit_alert, hookpaused).
     * @param string $subject Alert email/message subject line.
     * @param string $message Plaintext alert message body.
     * @param string|null $messagehtml Optional HTML formatted message body.
     * @return bool True if at least one delivery channel dispatched successfully.
     */
    public static function dispatch(
        string $eventname,
        string $subject,
        string $message,
        ?string $messagehtml = null
    ): bool {
        $sent = false;
        $noreply = \core_user::get_noreply_user();

        // 1. Dispatch via Moodle Message Provider to Site Administrators.
        try {
            $admins = get_admins();
            $adminuser = reset($admins);

            if ($adminuser) {
                $eventdata = new \core\message\message();
                $eventdata->component = 'local_webhookengine';
                $eventdata->name = $eventname;
                $eventdata->userfrom = $noreply;
                $eventdata->userto = $adminuser;
                $eventdata->subject = $subject;
                $eventdata->fullmessage = $message;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml = $messagehtml ?: '<p>' . s($message) . '</p>';
                $eventdata->smallmessage = $subject;
                $eventdata->notification = 1;

                message_send($eventdata);
                $sent = true;
            }
        } catch (\Throwable $e) {
            debugging('local_webhookengine: failed to dispatch Moodle alert message: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // 2. Direct Email Dispatch to Configured Alert Email / DevOps Distribution List.
        $customemail = get_config('local_webhookengine', 'alertemail');
        if (!empty($customemail) && validate_email(trim($customemail))) {
            try {
                $customemail = trim($customemail);
                $recipient = (object) [
                    'id' => -99,
                    'email' => $customemail,
                    'firstname' => 'Webhook',
                    'lastname' => 'Admin',
                    'maildisplay' => 1,
                    'mailformat' => 1,
                ];

                email_to_user(
                    $recipient,
                    $noreply,
                    $subject,
                    $message,
                    $messagehtml ?: '<p>' . s($message) . '</p>'
                );
                $sent = true;
            } catch (\Throwable $e) {
                debugging('local_webhookengine: failed to dispatch direct alert email: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $sent;
    }
}
