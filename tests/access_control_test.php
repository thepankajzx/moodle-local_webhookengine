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
 * Unit tests for T5 access control, exfiltration safeguards, domain allowlist, and secret handling.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\url_validator
 * @covers     \local_webhookengine\hook_repository
 */
final class access_control_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that all plugin capabilities default strictly to empty archetypes (admin-only).
     */
    public function test_capabilities_archetypes_are_empty(): void {
        $capabilities = [];
        require(__DIR__ . '/../db/access.php');

        $expectedcaps = [
            'local/webhookengine:manage',
            'local/webhookengine:viewlogs',
            'local/webhookengine:replay',
            'local/webhookengine:exportlogs',
            'local/webhookengine:exportpersonaldata',
        ];

        foreach ($expectedcaps as $cap) {
            $this->assertArrayHasKey($cap, $capabilities);
            $this->assertEmpty($capabilities[$cap]['archetypes'], "Capability {$cap} should have empty archetypes");
        }
    }

    /**
     * Test destination domain allowlist enforcement.
     */
    public function test_destination_domain_allowlist(): void {
        // When no domain allowlist is configured, any valid public URL is accepted.
        set_config('alloweddestinationdomains', '', 'local_webhookengine');
        $this->assertTrue(url_validator::is_destination_domain_allowed('api.example.com'));
        $this->assertTrue(url_validator::is_destination_domain_allowed('partner.org'));

        // Configure destination domain allowlist with exact and wildcard domains.
        set_config('alloweddestinationdomains', "api.example.com\n*.partner.org", 'local_webhookengine');

        // Exact match.
        $this->assertTrue(url_validator::is_destination_domain_allowed('api.example.com'));

        // Wildcard subdomain match.
        $this->assertTrue(url_validator::is_destination_domain_allowed('service.partner.org'));
        $this->assertTrue(url_validator::is_destination_domain_allowed('deep.sub.partner.org'));

        // Non-matching domains are rejected.
        $this->assertFalse(url_validator::is_destination_domain_allowed('other.example.com'));
        $this->assertFalse(url_validator::is_destination_domain_allowed('evil.com'));

        // Validation for config rejects non-matching domains.
        $configerr = url_validator::validate_for_config('https://evil.com/webhook');
        $this->assertEquals('error_domain_not_allowed', $configerr);

        // Validation for delivery rejects non-matching domains.
        $deliveryres = url_validator::resolve_and_validate_for_delivery('https://evil.com/webhook');
        $this->assertFalse($deliveryres['valid']);
        $this->assertEquals('error_domain_not_allowed', $deliveryres['error']);
    }

    /**
     * Test single-use secret reveal token encryption, lifecycle, and replay protection (F5).
     */
    public function test_secret_reveal_cache_token_single_use(): void {
        $hookid = 99;
        $userid = 2;
        $whsec = 'whsec_testtoken1234567890abcdef123456';

        // Create token for unauthorized consumption check.
        $unauthtoken = hook_repository::create_secret_reveal_token($hookid, $whsec, $userid);
        $this->assertNotEmpty($unauthtoken);

        // Consumption by wrong user must return null.
        $wronguser = hook_repository::consume_secret_reveal_token($unauthtoken, $hookid, 9999);
        $this->assertNull($wronguser);

        // Create fresh token for authorized consumption lifecycle and raw cache inspection.
        $token = hook_repository::create_secret_reveal_token($hookid, $whsec, $userid);
        $this->assertNotEmpty($token);

        // Inspect raw cache: verify secret is NOT stored in plaintext!
        $cache = \cache::make('local_webhookengine', 'ratelimit');
        $rawcached = $cache->get('secret_reveal_' . $token);
        $this->assertIsArray($rawcached);
        $this->assertNotEquals($whsec, $rawcached['secret'], 'Secret in MUC cache must be encrypted, not plaintext.');
        $this->assertStringStartsWith('sodium:', $rawcached['secret'], 'Secret must be encrypted with core encryption.');

        // First legitimate consumption succeeds and returns original secret.
        $revealed = hook_repository::consume_secret_reveal_token($token, $hookid, $userid);
        $this->assertEquals($whsec, $revealed);

        // Replay of the same token must return null (single-use destruction).
        $replayed = hook_repository::consume_secret_reveal_token($token, $hookid, $userid);
        $this->assertNull($replayed, 'Replay of consumed secret reveal token must return null.');

        // Raw cache must be empty.
        $this->assertFalse($cache->get('secret_reveal_' . $token));
    }

    /**
     * Test custom header masking, preservation, and explicit clearing.
     */
    public function test_custom_header_lifecycle_and_clearing(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $record = (object) [
            'name' => 'Header Test Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'headers' => ['Authorization' => 'Bearer initial-secret-token'],
            'timeout' => 10,
            'maxattempts' => 9,
        ];

        $hookid = hook_repository::create_hook($record, $rawsecret);
        $hook = hook_repository::get_hook($hookid);
        $this->assertNotNull($hook->headers);

        $decrypted = hook_repository::decrypt_headers($hook->headers);
        $this->assertEquals(['Authorization' => 'Bearer initial-secret-token'], $decrypted);

        // Simulate form edit where value was masked and submitted without headers property.
        $updaterecord = (object) [
            'name' => 'Header Test Hook Updated',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 10,
            'maxattempts' => 9,
        ];
        hook_repository::update_hook($hookid, $updaterecord);

        // Existing encrypted headers must remain untouched.
        $hookafter = hook_repository::get_hook($hookid);
        $decryptedafter = hook_repository::decrypt_headers($hookafter->headers);
        $this->assertEquals(['Authorization' => 'Bearer initial-secret-token'], $decryptedafter);

        // Explicitly clear headers using clearheaders.
        $clearrecord = (object) [
            'name' => 'Header Test Hook Cleared',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 10,
            'maxattempts' => 9,
            'clearheaders' => true,
        ];
        hook_repository::update_hook($hookid, $clearrecord);

        $hookcleared = hook_repository::get_hook($hookid);
        $this->assertNull($hookcleared->headers);

        // Clean up test hook.
        $DB->delete_records('local_webhookengine_hook', ['id' => $hookid]);
    }
}
