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
 * Unit tests verifying mTLS and OAuth2 client credentials authentication modes (Task T9).
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\http_client
 * @covers     \local_webhookengine\oauth2_client
 * @covers     \local_webhookengine\hook_repository
 * @covers     \local_webhookengine\delivery_service
 */
final class auth_modes_test extends \advanced_testcase {
    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test mTLS pre-flight checks fail safely when certificates are missing or unreadable.
     */
    public function test_mtls_preflight_missing_files(): void {
        set_config('mtlscertpath', '/non/existent/client.crt', 'local_webhookengine');
        set_config('mtlskeypath', '/non/existent/client.key', 'local_webhookengine');

        $client = new http_client();
        $response = $client->send('https://example.com/webhook', '{}', [], 10, true);

        $this->assertSame(0, $response->status);
        $this->assertSame(58, $response->errno);
        $this->assertStringContainsString('mTLS certificate or private key missing', $response->error);
    }

    /**
     * Test OAuth2 token caching in MUC.
     */
    public function test_oauth2_muc_token_caching(): void {
        $hook = (object) [
            'id' => 999,
            'authmode' => 'oauth2',
            'oauth2tokenurl' => 'https://example.com/oauth/token',
            'oauth2clientid' => 'client_test_id',
            'oauth2clientsecret' => hook_repository::encrypt_secret('super_secret_key'),
            'oauth2scope' => 'read write',
        ];

        // Seed MUC cache with synthetic token.
        $cache = \cache::make('local_webhookengine', 'ratelimit');
        $cache->set('oauth2_token_999', [
            'token' => 'cached_bearer_jwt_xyz',
            'expiresat' => time() + 3600,
        ]);

        $token = oauth2_client::get_access_token($hook);
        $this->assertSame('cached_bearer_jwt_xyz', $token);

        // Test cache clearing.
        oauth2_client::clear_token_cache(999);
        $cached = $cache->get('oauth2_token_999');
        $this->assertFalse($cached);
    }

    /**
     * Test OAuth2 token acquisition blocks SSRF token URLs.
     */
    public function test_oauth2_ssrf_protection_on_token_url(): void {
        $hook = (object) [
            'id' => 1001,
            'authmode' => 'oauth2',
            'oauth2tokenurl' => 'http://169.254.169.254/latest/meta-data/',
            'oauth2clientid' => 'client_id',
            'oauth2clientsecret' => hook_repository::encrypt_secret('secret'),
        ];

        $this->expectException(\moodle_exception::class);
        oauth2_client::get_access_token($hook);
    }

    /**
     * Test hook repository persistence and encryption of OAuth2 credentials.
     */
    public function test_hook_repository_oauth2_and_mtls_persistence(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'OAuth2 Enterprise Webhook',
            'url' => 'https://example.com/api/v1/events',
            'enabled' => 1,
            'events' => ['\core\event\course_created'],
            'filters' => ['courseids' => []],
            'payloadopts' => [],
            'headers' => null,
            'timeout' => 12,
            'maxattempts' => 5,
            'usemtls' => 1,
            'authmode' => 'oauth2',
            'oauth2tokenurl' => 'https://example.com/oauth/token',
            'oauth2clientid' => 'lms_client_id_001',
            'oauth2clientsecret' => 'confidential_secret_plaintext',
            'oauth2scope' => 'events.publish',
        ];

        $hookid = hook_repository::create_hook($data, $rawsecret);
        $this->assertGreaterThan(0, $hookid);

        $hook = hook_repository::get_hook($hookid);
        $this->assertEquals(1, $hook->usemtls);
        $this->assertSame('oauth2', $hook->authmode);
        $this->assertSame('https://example.com/oauth/token', $hook->oauth2tokenurl);
        $this->assertSame('lms_client_id_001', $hook->oauth2clientid);
        $this->assertSame('events.publish', $hook->oauth2scope);

        // Verify secret is NOT stored in plaintext in DB.
        $rawdb = $DB->get_record('local_webhookengine_hook', ['id' => $hookid]);
        $this->assertNotSame('confidential_secret_plaintext', $rawdb->oauth2clientsecret);
        $decrypted = hook_repository::decrypt_secret($rawdb->oauth2clientsecret);
        $this->assertSame('confidential_secret_plaintext', $decrypted);

        // Test update without changing secret.
        $updatedata = (object) [
            'name' => 'OAuth2 Webhook Renamed',
            'url' => 'https://example.com/api/v1/events',
            'enabled' => 1,
            'events' => ['\core\event\course_created'],
            'filters' => ['courseids' => []],
            'payloadopts' => [],
            'timeout' => 15,
            'maxattempts' => 5,
            'usemtls' => 0,
            'authmode' => 'oauth2',
            'oauth2tokenurl' => 'https://example.com/oauth/v2/token',
            'oauth2clientid' => 'lms_client_id_002',
            'oauth2scope' => 'events.readwrite',
        ];

        hook_repository::update_hook($hookid, $updatedata);
        $hookafter = hook_repository::get_hook($hookid);
        $this->assertEquals(0, $hookafter->usemtls);
        $this->assertSame('https://example.com/oauth/v2/token', $hookafter->oauth2tokenurl);
        $this->assertSame('lms_client_id_002', $hookafter->oauth2clientid);
        $this->assertSame('events.readwrite', $hookafter->oauth2scope);

        // Secret should remain preserved and decrypted correctly.
        $rawdbafter = $DB->get_record('local_webhookengine_hook', ['id' => $hookid]);
        $decryptedafter = hook_repository::decrypt_secret($rawdbafter->oauth2clientsecret);
        $this->assertSame('confidential_secret_plaintext', $decryptedafter);
    }
}
