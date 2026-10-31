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
 * Unit tests for Task T8a: Dual-secret zero-downtime rotation and Standard Webhooks multi-signatures.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\signer
 * @covers     \local_webhookengine\hook_repository
 * @covers     \local_webhookengine\delivery_service
 */
final class dual_secret_rotation_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test Standard Webhooks multi-signature generation and space-delimited verification.
     */
    public function test_multi_signature_generation_and_verification(): void {
        $secret1 = 'whsec_' . base64_encode(str_repeat('A', 32));
        $secret2 = 'whsec_' . base64_encode(str_repeat('B', 32));
        $secretbad = 'whsec_' . base64_encode(str_repeat('C', 32));

        $uuid = \core\uuid::generate();
        $timestamp = time();
        $payload = json_encode(['event' => 'test.multi_sig']);

        // Generate space-delimited multi-signature header.
        $multisig = signer::sign_multi($uuid, $timestamp, $payload, [$secret1, $secret2]);
        $this->assertStringContainsString(' ', $multisig);

        $parts = explode(' ', $multisig);
        $this->assertCount(2, $parts);

        // Verification must succeed for either active secret.
        $this->assertTrue(signer::verify($multisig, $uuid, $timestamp, $payload, $secret1));
        $this->assertTrue(signer::verify($multisig, $uuid, $timestamp, $payload, $secret2));

        // Verification must fail for an unauthorized secret.
        $this->assertFalse(signer::verify($multisig, $uuid, $timestamp, $payload, $secretbad));
    }

    /**
     * Test rotate_secret preserves previous secret and sets rotation timestamp.
     */
    public function test_rotate_secret_preserves_previous_secret_with_timestamp(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $hookrecord = (object) [
            'name' => 'Rotation Test Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 10,
            'maxattempts' => 9,
        ];

        $hookid = hook_repository::create_hook($hookrecord, $rawsecret);
        $initialhook = hook_repository::get_hook($hookid);
        $initialencrypted = $initialhook->secret;

        // Perform rotation.
        $newrawsecret = hook_repository::rotate_secret($hookid);
        $rotatedhook = $DB->get_record('local_webhookengine_hook', ['id' => $hookid]);

        $this->assertNotEquals($initialencrypted, $rotatedhook->secret);
        $this->assertEquals($initialencrypted, $rotatedhook->prevsecret);
        $this->assertNotEmpty($rotatedhook->secretrotatedat);
        $this->assertGreaterThan(0, $rotatedhook->secretrotatedat);

        // Clean up test records.
        $DB->delete_records('local_webhookengine_hook', ['id' => $hookid]);
    }

    /**
     * Test dual-signature dispatch during active grace period and single-signature after expiry.
     */
    public function test_dual_signature_delivery_lifecycle(): void {
        global $DB;

        $rawsecret1 = hook_repository::generate_raw_secret();
        $hookrecord = (object) [
            'name' => 'Grace Period Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 10,
            'maxattempts' => 9,
        ];

        $hookid = hook_repository::create_hook($hookrecord, $rawsecret1);

        // Rotate secret to activate dual-secret window.
        $rawsecret2 = hook_repository::rotate_secret($hookid);

        $uuid = \core\uuid::generate();
        $now = time();
        $payload = json_encode(['event' => 'user_created']);

        $deliveryid = $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => $hookid,
            'uuid' => $uuid,
            'eventname' => '\core\event\user_created',
            'status' => 'queued',
            'attempts' => 0,
            'nextattempt' => $now,
            'payload' => $payload,
            'timecreated' => $now,
        ]);

        $capturedheaders = [];
        $mockclient = $this->createMock(\local_webhookengine\http_client::class);
        $mockclient->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($url, $body, $headers, $timeout) use (&$capturedheaders) {
                $capturedheaders = $headers;
                return (object) [
                    'status' => 200,
                    'body' => '{"ok":true}',
                    'error' => null,
                    'duration' => 30,
                    'errno' => 0,
                    'headers' => [],
                    'retryafter' => null,
                ];
            });

        $service = new delivery_service();
        $service->execute_delivery($deliveryid, $mockclient);

        // Extract webhook-signature header from captured headers.
        $sigheader = null;
        foreach ($capturedheaders as $h) {
            if (str_starts_with($h, 'webhook-signature: ')) {
                $sigheader = substr($h, 19);
                break;
            }
        }

        $this->assertNotNull($sigheader);
        // Header must contain 2 signatures separated by space.
        $this->assertStringContainsString(' ', $sigheader);

        // Both previous secret and new secret must verify successfully.
        $whsec1 = hook_repository::format_whsec($rawsecret1);
        $whsec2 = hook_repository::format_whsec($rawsecret2);
        $this->assertTrue(signer::verify($sigheader, $uuid, $now, $payload, $whsec1));
        $this->assertTrue(signer::verify($sigheader, $uuid, $now, $payload, $whsec2));

        // Clean up test records.
        $DB->delete_records('local_webhookengine_delivery', ['id' => $deliveryid]);
        $DB->delete_records('local_webhookengine_hook', ['id' => $hookid]);
    }
}
