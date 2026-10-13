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

        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader($headers);

        $options = [
            'CURLOPT_TIMEOUT' => max(1, min(30, $timeout)),
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];

        $starttime = microtime(true);
        $response = $curl->post($url, $body, $options);
        $duration = (int) round((microtime(true) - $starttime) * 1000);

        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        $errno = (int) $curl->get_errno();
        $error = (string) $curl->error;

        $retryafter = null;
        $responseheaders = $curl->getResponse();
        if (is_array($responseheaders)) {
            foreach ($responseheaders as $headername => $headervalue) {
                if (strcasecmp($headername, 'retry-after') === 0) {
                    $retryval = is_array($headervalue) ? end($headervalue) : $headervalue;
                    if (is_numeric(trim($retryval))) {
                        $retryafter = (int) trim($retryval);
                    }
                    break;
                }
            }
        }

        return (object) [
            'status' => $status,
            'errno' => $errno,
            'error' => $error,
            'duration' => $duration,
            'retryafter' => $retryafter,
        ];
    }
}
