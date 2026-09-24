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
 * Lightweight HTTP client wrapping Moodle's curl helper with dependency injection support.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client {
    /**
     * Send an HTTP POST request.
     *
     * @param string $url Target endpoint URL.
     * @param string $body Raw JSON request body.
     * @param array $headers List of HTTP headers in "Header: Value" format.
     * @param int $timeout Request timeout in seconds.
     * @return object Object with status, errno, error, duration, retryafter.
     */
    public function send(string $url, string $body, array $headers, int $timeout = 10): object {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, min(30, $timeout)));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Strict: do not follow redirects.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $starttime = microtime(true);
        $response = curl_exec($ch);
        $duration = (int) round((microtime(true) - $starttime) * 1000);

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headersize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $retryafter = null;
        if ($response !== false && $headersize > 0) {
            $headertext = substr($response, 0, $headersize);
            if (preg_match('/^retry-after:\s*([0-9]+)/im', $headertext, $matches)) {
                $retryafter = (int) $matches[1];
            }
        }

        unset($ch);

        return (object) [
            'status' => $status,
            'errno' => $errno,
            'error' => $error,
            'duration' => $duration,
            'retryafter' => $retryafter,
        ];
    }
}
