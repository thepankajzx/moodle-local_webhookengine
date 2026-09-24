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
 * Standard Webhooks compatible signature generator and validator.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signer {
    /**
     * Generates a secure random 32-byte signing secret formatted with whsec_ prefix.
     *
     * @return string
     */
    public static function generate_secret(): string {
        $bytes = random_bytes(32);
        return 'whsec_' . base64_encode($bytes);
    }

    /**
     * Extracts raw binary secret bytes from a secret string.
     *
     * @param string $secret Secret string (with or without whsec_ prefix)
     * @return string Raw secret bytes
     */
    public static function get_raw_secret_bytes(string $secret): string {
        if (str_starts_with($secret, 'whsec_')) {
            $b64 = substr($secret, 6);
            $decoded = base64_decode($b64, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $secret;
    }

    /**
     * Computes the Standard Webhooks signature header string.
     *
     * @param string $webhookid Delivery UUID
     * @param int $timestamp Unix timestamp
     * @param string $payload JSON body
     * @param string $secret Signing secret
     * @return string Signature header value, e.g. "v1,base64..."
     */
    public static function sign(string $webhookid, int $timestamp, string $payload, string $secret): string {
        $rawsecret = self::get_raw_secret_bytes($secret);
        $signedpayload = $webhookid . '.' . $timestamp . '.' . $payload;
        $hmac = hash_hmac('sha256', $signedpayload, $rawsecret, true);
        return 'v1,' . base64_encode($hmac);
    }

    /**
     * Verifies a signature string against the expected payload and secret.
     *
     * @param string $signatureheader The webhook-signature header
     * @param string $webhookid The webhook-id header
     * @param int $timestamp The webhook-timestamp header
     * @param string $payload The raw body
     * @param string $secret The secret
     * @return bool True if valid, false otherwise
     */
    public static function verify(
        string $signatureheader,
        string $webhookid,
        int $timestamp,
        string $payload,
        string $secret
    ): bool {
        $expected = self::sign($webhookid, $timestamp, $payload, $secret);
        return hash_equals($expected, $signatureheader);
    }
}
