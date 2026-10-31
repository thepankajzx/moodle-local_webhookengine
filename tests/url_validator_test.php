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
}
