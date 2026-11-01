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
 * Unit tests for URL validator, SSRF policy, internal allowlist, and IP pinning.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\url_validator
 * @covers     \local_webhookengine\http_client
 */
final class url_validator_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test standard HTTPS validation on public domain.
     */
    public function test_public_https_is_valid(): void {
        $result = url_validator::validate_for_config('https://example.com/webhook');
        $this->assertNull($result);
        $this->assertTrue(url_validator::validate('https://example.com/webhook'));
    }

    /**
     * Test that plain HTTP is blocked unless allowinsecurehttp is enabled.
     */
    public function test_plain_http_requires_allowinsecurehttp(): void {
        set_config('allowinsecurehttp', 0, 'local_webhookengine');
        $this->assertEquals('http_not_allowed', url_validator::validate_for_config('http://example.com/webhook'));

        set_config('allowinsecurehttp', 1, 'local_webhookengine');
        $this->assertNull(url_validator::validate_for_config('http://example.com/webhook'));
    }

    /**
     * Test that loopback and private IPs are blocked even when allowinsecurehttp is enabled.
     */
    public function test_ssrf_blocked_even_with_allowinsecurehttp(): void {
        set_config('allowinsecurehttp', 1, 'local_webhookengine');

        // Loopback IPv4.
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://127.0.0.1/webhook'));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://127.0.0.2/webhook'));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://localhost/webhook'));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://sub.localhost/webhook'));

        // Cloud metadata & link-local.
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://169.254.169.254/latest/meta-data'));
        $gcpurl = 'http://metadata.google.internal/computeMetadata';
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config($gcpurl));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://instance-data/'));

        // Private RFC 1918 ranges.
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://10.0.0.1/webhook'));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://172.16.0.1/webhook'));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://192.168.1.1/webhook'));

        // IPv6 loopback & link-local.
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://[::1]/webhook'));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://[fe80::1]/webhook'));
    }

    /**
     * Test that internal host allowlist allows authorized destinations while keeping others blocked.
     */
    public function test_internal_hosts_allowlist(): void {
        set_config('allowinsecurehttp', 1, 'local_webhookengine');
        set_config('allowedinternalhosts', "10.20.0.0/16\n*.corp.internal\n192.168.50.10", 'local_webhookengine');

        // Allowlisted CIDR.
        $this->assertNull(url_validator::validate_for_config('http://10.20.1.5/webhook'));

        // Allowlisted wildcard domain.
        $this->assertNull(url_validator::validate_for_config('https://svc.corp.internal/webhook'));

        // Allowlisted specific IP.
        $this->assertNull(url_validator::validate_for_config('http://192.168.50.10/webhook'));

        // Non-allowlisted private IP must still be blocked.
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://10.30.1.5/webhook'));
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://192.168.1.1/webhook'));

        // Loopback must remain blocked unless specifically allowlisted.
        $this->assertEquals('error_blocked_url', url_validator::validate_for_config('http://127.0.0.1/webhook'));
    }

    /**
     * Test pre-dispatch DNS resolution and IP pinning structure.
     */
    public function test_resolve_and_validate_for_delivery(): void {
        // Public domain should resolve and produce a valid CURLOPT_RESOLVE entry.
        $deliverycheck = url_validator::resolve_and_validate_for_delivery('https://example.com/webhook');
        $this->assertTrue($deliverycheck['valid']);
        $this->assertNotEmpty($deliverycheck['ip']);
        $this->assertStringStartsWith('example.com:443:', $deliverycheck['resolve_entry']);

        // Blocked private target should fail delivery check.
        $blockedcheck = url_validator::resolve_and_validate_for_delivery('https://127.0.0.1/webhook');
        $this->assertFalse($blockedcheck['valid']);
        $this->assertEquals('error_blocked_url', $blockedcheck['error']);
    }

    /**
     * Test extended private, reserved, multicast, documentation, NAT64, 6to4, and Teredo ranges (F4).
     */
    public function test_extended_private_and_reserved_ranges(): void {
        // Multicast (224.0.0.0/4 and ff00::/8).
        $this->assertTrue(url_validator::is_private_or_reserved_ip('224.0.0.1'));
        $this->assertTrue(url_validator::is_private_or_reserved_ip('239.255.255.250'));
        $this->assertTrue(url_validator::is_private_or_reserved_ip('ff02::1'));

        // Benchmarking range RFC 2544.
        $this->assertTrue(url_validator::is_private_or_reserved_ip('198.18.0.1'));
        $this->assertTrue(url_validator::is_private_or_reserved_ip('198.19.255.254'));

        // IETF Protocol Assignments (192.0.0.0/24).
        $this->assertTrue(url_validator::is_private_or_reserved_ip('192.0.0.1'));

        // Documentation ranges (TEST-NET-1, TEST-NET-2, TEST-NET-3).
        $this->assertTrue(url_validator::is_private_or_reserved_ip('192.0.2.1'));
        $this->assertTrue(url_validator::is_private_or_reserved_ip('198.51.100.1'));
        $this->assertTrue(url_validator::is_private_or_reserved_ip('203.0.113.1'));

        // NAT64 with embedded private IPv4 (64:ff9b::/96).
        $this->assertTrue(url_validator::is_private_or_reserved_ip('64:ff9b::192.168.1.1'));
        $this->assertTrue(url_validator::is_private_or_reserved_ip('64:ff9b::10.0.0.1'));
        // NAT64 local-use prefix (64:ff9b:1::/48).
        $this->assertTrue(url_validator::is_private_or_reserved_ip('64:ff9b:1::1'));

        // 6to4 with embedded private IPv4 (2002::/16).
        $this->assertTrue(url_validator::is_private_or_reserved_ip('2002:c0a8:0101::')); // 192.168.1.1
        $this->assertTrue(url_validator::is_private_or_reserved_ip('2002:0a00:0001::')); // 10.0.0.1

        // Teredo with embedded private IPv4 (2001::/32).
        // 192.168.1.1 XOR 0xFF = 63.87.254.254 (0x3f57feea).
        $this->assertTrue(url_validator::is_private_or_reserved_ip('2001:0000:4136:e378:8000:63bf:3f57:feea'));

        // Public routable IPs must NOT be blocked.
        $this->assertFalse(url_validator::is_private_or_reserved_ip('8.8.8.8'));
        $this->assertFalse(url_validator::is_private_or_reserved_ip('1.1.1.1'));
        $this->assertFalse(url_validator::is_private_or_reserved_ip('2606:4700:4700::1111'));
    }

    /**
     * Test that metadata IPs are blocked for internal hostnames even if the hostname is allowlisted.
     */
    public function test_internal_hostname_metadata_ip_blocked(): void {
        set_config('allowedinternalhosts', "*.internal\n*.local", 'local_webhookengine');

        // Hostname is in allowlist, but resolved IP is cloud metadata (169.254.169.254 or fd00:ec2::254).
        $this->assertFalse(
            url_validator::verify_internal_resolved_ip('169.254.169.254'),
            'Metadata IP 169.254.169.254 must be blocked for internal hostnames.'
        );
        $this->assertFalse(
            url_validator::verify_internal_resolved_ip('fd00:ec2::254'),
            'AWS IMDSv6 fd00:ec2::254 must be blocked for internal hostnames.'
        );

        // Standard private IP resolved for allowlisted internal host is permitted.
        $this->assertTrue(
            url_validator::verify_internal_resolved_ip('192.168.1.100'),
            'Standard private IP 192.168.1.100 must be permitted for allowlisted internal host.'
        );
        $this->assertTrue(
            url_validator::verify_internal_resolved_ip('10.0.5.20'),
            'Standard private IP 10.0.5.20 must be permitted for allowlisted internal host.'
        );

        // When explicitly typed as an exact IP, it is authorized.
        set_config('allowedinternalhosts', "169.254.169.254\n*.internal", 'local_webhookengine');
        $this->assertTrue(
            url_validator::verify_internal_resolved_ip('169.254.169.254'),
            'Metadata IP explicitly typed as an IP in allowlist is permitted.'
        );
    }

    /**
     * Test outbound proxy detection helper.
     */
    public function test_has_outbound_proxy_detection(): void {
        global $CFG;
        $originalproxy = $CFG->proxyhost ?? null;

        $CFG->proxyhost = null;
        $this->assertFalse(url_validator::has_outbound_proxy());

        $CFG->proxyhost = 'proxy.institution.edu';
        $this->assertTrue(url_validator::has_outbound_proxy());

        $CFG->proxyhost = $originalproxy;
    }
}
