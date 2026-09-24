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
 * URL validation and security policy enforcer.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_validator {
    /**
     * Validates a webhook destination URL for configuration forms.
     *
     * @param string $url The URL to validate.
     * @return string|null String error key if invalid, null if valid.
     */
    public static function validate_for_config(string $url): ?string {
        if (strlen($url) > 2048) {
            return 'url_too_long';
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return 'url_invalid';
        }

        // Reject credentials in URL.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'http_credentials_rejected';
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $allowhttp = (bool) get_config('local_webhookengine', 'allowinsecurehttp');

        if ($scheme === 'http') {
            if (!$allowhttp) {
                return 'http_not_allowed';
            }
        } else if ($scheme !== 'https') {
            return 'http_not_allowed';
        }

        $host = trim($parts['host'], '[]');
        $loweredhost = strtolower($host);

        // Reject localhost and local domains unless plain HTTP is permitted.
        if (!$allowhttp) {
            $islocal = ($loweredhost === 'localhost'
                || str_ends_with($loweredhost, '.localhost')
                || str_ends_with($loweredhost, '.local'));
            if ($islocal) {
                return 'http_not_allowed';
            }
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return 'http_not_allowed';
                }
            }
        }

        // Delegate to Moodle's core curl security helper if available (strictly when plain HTTP/localhost is not permitted).
        if (!$allowhttp) {
            global $CFG;
            if (!empty($CFG->libdir) && file_exists($CFG->libdir . '/filelib.php')) {
                require_once($CFG->libdir . '/filelib.php');
                if (class_exists('\core\files\curl_security_helper')) {
                    $helper = new \core\files\curl_security_helper();
                    if ($helper->url_is_blocked($url)) {
                        return 'http_not_allowed';
                    }
                }
            }
        }

        return null;
    }

    /**
     * Validates a webhook destination URL according to security policy.
     *
     * @param string $url The URL to validate
     * @return bool True if valid and permitted, false otherwise
     */
    public static function validate(string $url): bool {
        return self::validate_for_config($url) === null;
    }

    /**
     * Validates a webhook destination URL right before outbound delivery attempt.
     *
     * @param string $url The URL to validate.
     * @return bool True if permitted, false if blocked.
     */
    public static function validate_for_delivery(string $url): bool {
        return self::validate($url);
    }
}
