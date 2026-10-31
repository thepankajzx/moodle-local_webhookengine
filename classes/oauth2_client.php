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
 * OAuth2 client credentials token manager and cache service.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth2_client {
    /**
     * Retrieve or fetch a valid OAuth2 bearer access token for a webhook destination.
     *
     * @param object $hook The hook record from database.
     * @return string The bearer access token.
     * @throws \moodle_exception If token acquisition fails or parameters are invalid.
     */
    public static function get_access_token(object $hook): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (empty($hook->authmode) || $hook->authmode !== 'oauth2') {
            return '';
        }

        $cache = \cache::make('local_webhookengine', 'ratelimit');
        $cachekey = 'oauth2_token_' . $hook->id;
        $cached = $cache->get($cachekey);

        if (is_array($cached) && !empty($cached['token']) && ($cached['expiresat'] ?? 0) > time()) {
            return (string) $cached['token'];
        }

        if (empty($hook->oauth2tokenurl)) {
            throw new \moodle_exception('error_oauth2_missing_url', 'local_webhookengine');
        }

        $validation = url_validator::resolve_and_validate_for_delivery($hook->oauth2tokenurl);
        if (!$validation['valid']) {
            throw new \moodle_exception(
                'error_oauth2_failed',
                'local_webhookengine',
                '',
                'Blocked by SSRF policy: ' . ($validation['error'] ?? 'blocked_url')
            );
        }

        if (empty($hook->oauth2clientid)) {
            throw new \moodle_exception('error_oauth2_failed', 'local_webhookengine', '', 'Missing OAuth2 client ID');
        }

        if (empty($hook->oauth2clientsecret)) {
            throw new \moodle_exception('error_oauth2_failed', 'local_webhookengine', '', 'Missing OAuth2 client secret');
        }

        $clientsecret = hook_repository::decrypt_secret($hook->oauth2clientsecret);
        if ($clientsecret === false || $clientsecret === '') {
            throw new \moodle_exception('error_oauth2_failed', 'local_webhookengine', '', 'Unreadable OAuth2 client secret');
        }

        $postfields = [
            'grant_type' => 'client_credentials',
            'client_id' => $hook->oauth2clientid,
            'client_secret' => $clientsecret,
        ];

        if (!empty($hook->oauth2scope)) {
            $postfields['scope'] = $hook->oauth2scope;
        }

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: ' . http_client::get_user_agent(),
        ];

        $ignoresecurity = !empty($validation['is_internal_allowed']);
        $curl = new \curl(['ignoresecurity' => $ignoresecurity]);
        $curl->setHeader($headers);

        $options = [
            'CURLOPT_TIMEOUT' => 15,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];

        if (!empty($validation['resolve_entry'])) {
            $options['CURLOPT_RESOLVE'] = [$validation['resolve_entry']];
        }

        $postbody = http_build_query($postfields, '', '&');
        $rawresponse = $curl->post($hook->oauth2tokenurl, $postbody, $options);

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);

        if ($httpcode < 200 || $httpcode >= 300) {
            $errmsg = !empty($curl->error) ? $curl->error : 'HTTP ' . $httpcode;
            throw new \moodle_exception('error_oauth2_failed', 'local_webhookengine', '', $errmsg);
        }

        $data = json_decode($rawresponse, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \moodle_exception(
                'error_oauth2_failed',
                'local_webhookengine',
                '',
                'Invalid JSON or missing access_token in response'
            );
        }

        $token = (string) $data['access_token'];
        $expiresin = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $ttl = max(30, $expiresin - 60);

        $cache->set($cachekey, [
            'token' => $token,
            'expiresat' => time() + $ttl,
        ]);

        return $token;
    }

    /**
     * Invalidate cached access token for a webhook.
     *
     * @param int $hookid The hook database ID.
     */
    public static function clear_token_cache(int $hookid): void {
        $cache = \cache::make('local_webhookengine', 'ratelimit');
        $cache->delete('oauth2_token_' . $hookid);
    }
}
