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
 * Enterprise URL validation, SSRF defense, network policy enforcer, and DNS pinning manager.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_validator {
    /**
     * Check if a destination host or IP is explicitly authorized via internal allowlist.
     *
     * @param string $host Target hostname or IP.
     * @param string|null $ip Optional resolved IP address.
     * @return bool True if permitted by internal allowlist, false otherwise.
     */
    public static function is_host_or_ip_allowlisted(string $host, ?string $ip = null): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $allowlistconfig = (string) get_config('local_webhookengine', 'allowedinternalhosts');
        if (trim($allowlistconfig) === '') {
            return false;
        }

        $lines = preg_split('/[\r\n,]+/', $allowlistconfig);
        $cleanlines = [];
        foreach ($lines as $line) {
            $trimmed = trim(explode('#', $line)[0]);
            if ($trimmed !== '') {
                $cleanlines[] = $trimmed;
            }
        }

        if (empty($cleanlines)) {
            return false;
        }

        $loweredhost = strtolower($host);
        $iptocheck = $ip ?? (filter_var($host, FILTER_VALIDATE_IP) ? $host : null);

        foreach ($cleanlines as $entry) {
            // 1. CIDR subnet notation (e.g. 10.0.0.0/8, 192.168.0.0/16).
            if (strpos($entry, '/') !== false) {
                if ($iptocheck !== null && \core\ip_utils::is_ip_in_subnet_list($iptocheck, $entry)) {
                    return true;
                }
                continue;
            }

            // 2. Exact IP address match.
            if (filter_var($entry, FILTER_VALIDATE_IP)) {
                if ($iptocheck !== null && $iptocheck === $entry) {
                    return true;
                }
                continue;
            }

            // 3. Domain or wildcard domain match (e.g. *.corp.internal or internal.example.com).
            if (\core\ip_utils::is_domain_in_allowed_list($loweredhost, [$entry])) {
                return true;
            }
            if (strcasecmp($loweredhost, $entry) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if an IP address belongs to loopback, private, link-local, or cloud metadata ranges.
     *
     * @param string $ip IPv4 or IPv6 address.
     * @return bool True if private, reserved, loopback, or cloud metadata; false if public routable.
     */
    public static function is_private_or_reserved_ip(string $ip): bool {
        // Syntax validation.
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Standard filter_var flags for private and reserved ranges.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // Explicit check for loopback and link-local.
        if (str_starts_with($ip, '127.') || $ip === '::1' || $ip === '0.0.0.0' || $ip === '255.255.255.255') {
            return true;
        }

        // Link-local and Cloud metadata endpoint (169.254.0.0/16, fe80::/10).
        if (str_starts_with($ip, '169.254.')) {
            return true;
        }

        // Carrier-grade NAT (100.64.0.0/10: 100.64.0.0 to 100.127.255.255).
        $long = ip2long($ip);
        if ($long !== false) {
            $cgstart = ip2long('100.64.0.0');
            $cgend = ip2long('100.127.255.255');
            if ($long >= $cgstart && $long <= $cgend) {
                return true;
            }
        }

        // IPv6 unique local (fc00::/7) or link-local (fe80::/10).
        $lowered = strtolower($ip);
        if (
            str_starts_with($lowered, 'fe8')
            || str_starts_with($lowered, 'fe9')
            || str_starts_with($lowered, 'fea')
            || str_starts_with($lowered, 'feb')
            || str_starts_with($lowered, 'fc')
            || str_starts_with($lowered, 'fd')
        ) {
            return true;
        }

        // IPv4-mapped IPv6 (::ffff:127.0.0.1, etc.).
        if (str_starts_with($lowered, '::ffff:')) {
            $v4 = substr($lowered, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return self::is_private_or_reserved_ip($v4);
            }
        }

        return false;
    }

    /**
     * Resolve all IPv4 and IPv6 addresses for a hostname.
     *
     * @param string $host Hostname or IP address.
     * @return array List of resolved IP addresses.
     */
    public static function resolve_all_ips(string $host): array {
        $cleanhost = trim($host, '[]');
        if (filter_var($cleanhost, FILTER_VALIDATE_IP)) {
            return [$cleanhost];
        }

        $ips = [];

        // Attempt dns_get_record for comprehensive A and AAAA records.
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($cleanhost, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (isset($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP)) {
                        $ips[] = $r['ip'];
                    } else if (isset($r['ipv6']) && filter_var($r['ipv6'], FILTER_VALIDATE_IP)) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }

        // Fallback to gethostbynamel if dns_get_record returned empty.
        if (empty($ips) && function_exists('gethostbynamel')) {
            $v4s = @gethostbynamel($cleanhost);
            if (is_array($v4s)) {
                foreach ($v4s as $v4) {
                    if (filter_var($v4, FILTER_VALIDATE_IP)) {
                        $ips[] = $v4;
                    }
                }
            }
        }

        // Final fallback to gethostbyname.
        if (empty($ips) && function_exists('gethostbyname')) {
            $v4 = @gethostbyname($cleanhost);
            if ($v4 !== $cleanhost && filter_var($v4, FILTER_VALIDATE_IP)) {
                $ips[] = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Validates a webhook destination URL for configuration forms.
     *
     * Enforces strict scheme checking and SSRF protection unconditionally.
     *
     * @param string $url The URL to validate.
     * @return string|null String error key if invalid, null if valid.
     */
    public static function validate_for_config(string $url): ?string {
        if (strlen($url) > 2048) {
            return 'error_invalid_url';
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return 'error_invalid_url';
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

        // Check if destination is explicitly authorized by internal allowlist.
        if (self::is_host_or_ip_allowlisted($loweredhost)) {
            return null;
        }

        // Enforce SSRF protection against loopback, link-local, and reserved names unconditionally.
        $islocal = ($loweredhost === 'localhost'
            || str_ends_with($loweredhost, '.localhost')
            || str_ends_with($loweredhost, '.local')
            || str_ends_with($loweredhost, '.internal')
            || str_ends_with($loweredhost, '.lan')
            || str_ends_with($loweredhost, '.home.arpa')
            || $loweredhost === 'instance-data'
            || $loweredhost === 'metadata.google.internal');

        if ($islocal) {
            return 'error_blocked_url';
        }

        // Direct IP address check.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::is_private_or_reserved_ip($host)) {
                return 'error_blocked_url';
            }
        }

        // Check against Moodle core curl security helper if available.
        global $CFG;
        if (!empty($CFG->libdir) && file_exists($CFG->libdir . '/filelib.php')) {
            require_once($CFG->libdir . '/filelib.php');
            if (class_exists('\core\files\curl_security_helper')) {
                $helper = new \core\files\curl_security_helper();
                if ($helper->url_is_blocked($url)) {
                    return 'error_blocked_url';
                }
            }
        }

        // Pre-validate resolved IPs if hostname resolves at config time.
        $resolvedips = self::resolve_all_ips($host);
        if (!empty($resolvedips)) {
            foreach ($resolvedips as $ip) {
                if (self::is_private_or_reserved_ip($ip) && !self::is_host_or_ip_allowlisted($loweredhost, $ip)) {
                    return 'error_blocked_url';
                }
            }
        }

        return null;
    }

    /**
     * Pre-flight DNS resolution, anti-SSRF verification, and IP-pinning configuration for delivery dispatch.
     *
     * Defeats DNS rebinding TOCTOU attacks by resolving hostname before connection and pinning the
     * validated IP to cURL via CURLOPT_RESOLVE.
     *
     * @param string $url Destination webhook URL.
     * @return array Associative array with keys:
     *               - valid (bool): whether URL is safe to dispatch.
     *               - error (string|null): error code if invalid.
     *               - ip (string|null): validated IP address.
     *               - resolve_entry (string|null): CURLOPT_RESOLVE entry in format "host:port:ip".
     *               - is_internal_allowed (bool): whether host matched internal allowlist.
     */
    public static function resolve_and_validate_for_delivery(string $url): array {
        if (strlen($url) > 2048) {
            return [
                'valid' => false,
                'error' => 'error_invalid_url',
                'ip' => null,
                'resolve_entry' => null,
                'is_internal_allowed' => false,
            ];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return [
                'valid' => false,
                'error' => 'error_invalid_url',
                'ip' => null,
                'resolve_entry' => null,
                'is_internal_allowed' => false,
            ];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return [
                'valid' => false,
                'error' => 'http_credentials_rejected',
                'ip' => null,
                'resolve_entry' => null,
                'is_internal_allowed' => false,
            ];
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $allowhttp = (bool) get_config('local_webhookengine', 'allowinsecurehttp');

        if ($scheme === 'http') {
            if (!$allowhttp) {
                return [
                    'valid' => false,
                    'error' => 'http_not_allowed',
                    'ip' => null,
                    'resolve_entry' => null,
                    'is_internal_allowed' => false,
                ];
            }
        } else if ($scheme !== 'https') {
            return [
                'valid' => false,
                'error' => 'http_not_allowed',
                'ip' => null,
                'resolve_entry' => null,
                'is_internal_allowed' => false,
            ];
        }

        $host = trim($parts['host'], '[]');
        $loweredhost = strtolower($host);
        $port = !empty($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        // Check internal allowlist.
        $isinternal = self::is_host_or_ip_allowlisted($loweredhost);
        if ($isinternal) {
            $ips = self::resolve_all_ips($host);
            $ip = !empty($ips) ? $ips[0] : (filter_var($host, FILTER_VALIDATE_IP) ? $host : null);
            if ($ip === null) {
                return [
                    'valid' => false,
                    'error' => 'error_unresolvable_host',
                    'ip' => null,
                    'resolve_entry' => null,
                    'is_internal_allowed' => true,
                ];
            }
            $resolveentry = "{$host}:{$port}:{$ip}";
            return [
                'valid' => true,
                'error' => null,
                'ip' => $ip,
                'resolve_entry' => $resolveentry,
                'is_internal_allowed' => true,
            ];
        }

        // Block local names.
        $islocal = ($loweredhost === 'localhost'
            || str_ends_with($loweredhost, '.localhost')
            || str_ends_with($loweredhost, '.local')
            || str_ends_with($loweredhost, '.internal')
            || str_ends_with($loweredhost, '.lan')
            || str_ends_with($loweredhost, '.home.arpa')
            || $loweredhost === 'instance-data'
            || $loweredhost === 'metadata.google.internal');

        if ($islocal) {
            return [
                'valid' => false,
                'error' => 'error_blocked_url',
                'ip' => null,
                'resolve_entry' => null,
                'is_internal_allowed' => false,
            ];
        }

        // Direct IP check.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::is_private_or_reserved_ip($host)) {
                return [
                    'valid' => false,
                    'error' => 'error_blocked_url',
                    'ip' => null,
                    'resolve_entry' => null,
                    'is_internal_allowed' => false,
                ];
            }
            $resolveentry = "{$host}:{$port}:{$host}";
            return [
                'valid' => true,
                'error' => null,
                'ip' => $host,
                'resolve_entry' => $resolveentry,
                'is_internal_allowed' => false,
            ];
        }

        // Resolve DNS and check all resolved IPs.
        $ips = self::resolve_all_ips($host);
        if (empty($ips)) {
            return [
                'valid' => false,
                'error' => 'error_unresolvable_host',
                'ip' => null,
                'resolve_entry' => null,
                'is_internal_allowed' => false,
            ];
        }

        // Anti-DNS rebinding check: ALL resolved IPs must be public.
        foreach ($ips as $ip) {
            if (self::is_private_or_reserved_ip($ip) && !self::is_host_or_ip_allowlisted($loweredhost, $ip)) {
                return [
                    'valid' => false,
                    'error' => 'error_blocked_url',
                    'ip' => null,
                    'resolve_entry' => null,
                    'is_internal_allowed' => false,
                ];
            }
        }

        // Pin connection strictly to first validated IP address.
        $selectedip = $ips[0];
        $resolveentry = "{$host}:{$port}:{$selectedip}";

        return [
            'valid' => true,
            'error' => null,
            'ip' => $selectedip,
            'resolve_entry' => $resolveentry,
            'is_internal_allowed' => false,
        ];
    }

    /**
     * Validates a webhook destination URL according to security policy.
     *
     * @param string $url The URL to validate.
     * @return bool True if valid and permitted, false otherwise.
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
        $check = self::resolve_and_validate_for_delivery($url);
        return $check['valid'];
    }
}
