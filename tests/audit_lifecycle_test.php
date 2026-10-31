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
 * Unit tests for Task T7: Audit lifecycle, soft-delete, before/after diffs, and body hash.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\hook_repository
 * @covers     \local_webhookengine\delivery_service
 * @covers     \local_webhookengine\event\hook_updated
 */
final class audit_lifecycle_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test soft-delete: hook status becomes deleted, enabled is set to 0, deliveries are preserved.
     */
    public function test_soft_delete_preserves_deliveries_and_excludes_from_active(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $hookrecord = (object) [
            'name' => 'Soft Delete Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 10,
            'maxattempts' => 9,
        ];

        $hookid = hook_repository::create_hook($hookrecord, $rawsecret);

        $now = time();
        $deliveryid = $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => $hookid,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\user_created',
            'status' => 'success',
            'attempts' => 1,
            'payload' => '{"sample": 1}',
            'timecreated' => $now,
        ]);

        // Soft-delete hook.
        $res = hook_repository::delete_hook($hookid);
        $this->assertTrue($res);

        // Hook record still exists with status 'deleted' and enabled = 0.
        $hook = hook_repository::get_hook($hookid);
        $this->assertNotNull($hook);
        $this->assertEquals('deleted', $hook->status);
        $this->assertEquals(0, $hook->enabled);

        // Historical deliveries are preserved.
        $this->assertTrue($DB->record_exists('local_webhookengine_delivery', ['id' => $deliveryid]));

        // Excluded from active hooks list and event map.
        $activehooks = hook_repository::get_hooks();
        $this->assertArrayNotHasKey($hookid, $activehooks);

        $map = hook_repository::get_event_map();
        $subscribedids = [];
        if (!empty($map['\core\event\user_created'])) {
            foreach ($map['\core\event\user_created'] as $entry) {
                $subscribedids[] = is_array($entry) ? $entry['id'] : (int) $entry;
            }
        }
        $this->assertNotContains($hookid, $subscribedids);

        // Clean up test records.
        $DB->delete_records('local_webhookengine_delivery', ['id' => $deliveryid]);
        $DB->delete_records('local_webhookengine_hook', ['id' => $hookid]);
    }

    /**
     * Test hook_updated event records granular before/after diff of non-sensitive fields.
     */
    public function test_hook_updated_event_contains_before_after_diff_without_secrets(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $hookrecord = (object) [
            'name' => 'Original Hook Name',
            'url' => 'https://example.com/initial',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'headers' => ['Authorization' => 'Bearer initial-secret-token'],
            'timeout' => 10,
            'maxattempts' => 9,
        ];

        $hookid = hook_repository::create_hook($hookrecord, $rawsecret);

        $sink = $this->redirectEvents();

        $updaterecord = (object) [
            'name' => 'Modified Hook Name',
            'url' => 'https://example.com/modified',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 20,
            'maxattempts' => 5,
        ];

        hook_repository::update_hook($hookid, $updaterecord);

        $events = $sink->get_events();
        $this->assertNotEmpty($events);

        $updatedevent = null;
        foreach ($events as $ev) {
            if ($ev instanceof \local_webhookengine\event\hook_updated && (int) $ev->objectid === $hookid) {
                $updatedevent = $ev;
                break;
            }
        }

        $this->assertNotNull($updatedevent);
        $changes = $updatedevent->other['changes'] ?? [];

        // Non-sensitive fields diff.
        $this->assertArrayHasKey('name', $changes);
        $this->assertEquals('Original Hook Name', $changes['name']['before']);
        $this->assertEquals('Modified Hook Name', $changes['name']['after']);

        $this->assertArrayHasKey('url', $changes);
        $this->assertEquals('https://example.com/initial', $changes['url']['before']);
        $this->assertEquals('https://example.com/modified', $changes['url']['after']);

        $this->assertArrayHasKey('timeout', $changes);
        $this->assertEquals(10, $changes['timeout']['before']);
        $this->assertEquals(20, $changes['timeout']['after']);

        // Strict security assertion: Secrets and headers must NEVER be included in changes diff.
        $this->assertArrayNotHasKey('secret', $changes);
        $this->assertArrayNotHasKey('headers', $changes);

        // Clean up test records.
        $DB->delete_records('local_webhookengine_hook', ['id' => $hookid]);
    }

    /**
     * Test cryptographic bodyhash calculation on delivery and persistence post-purge.
     */
    public function test_bodyhash_stored_on_delivery_and_persists_after_payload_purge(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $hookrecord = (object) [
            'name' => 'Bodyhash Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 10,
            'maxattempts' => 9,
        ];

        $hookid = hook_repository::create_hook($hookrecord, $rawsecret);

        $uuid = \core\uuid::generate();
        $rawdata = [
            'eventname' => '\core\event\user_created',
            'component' => 'core',
            'action' => 'created',
            'target' => 'user',
            'userid' => 42,
        ];

        $now = time();
        $deliveryid = $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => $hookid,
            'uuid' => $uuid,
            'eventname' => '\core\event\user_created',
            'status' => 'queued',
            'attempts' => 0,
            'nextattempt' => $now,
            'payload' => json_encode($rawdata),
            'timecreated' => $now,
        ]);

        $mockclient = $this->createMock(\local_webhookengine\http_client::class);
        $mockclient->expects($this->once())
            ->method('send')
            ->willReturn((object) [
                'status' => 200,
                'body' => '{"status":"ok"}',
                'error' => null,
                'duration' => 45,
                'errno' => 0,
                'headers' => [],
                'retryafter' => null,
            ]);

        $service = new delivery_service();
        $result = $service->execute_delivery($deliveryid, $mockclient);
        $this->assertTrue($result);

        $delivery = $DB->get_record('local_webhookengine_delivery', ['id' => $deliveryid]);
        $this->assertEquals('success', $delivery->status);
        $this->assertNotEmpty($delivery->bodyhash);

        // Payload is purged upon confirmed success under data minimization.
        $this->assertNull($delivery->payload);

        // Body hash is a 64-character SHA-256 hex string and persists permanently.
        $this->assertEquals(64, strlen($delivery->bodyhash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $delivery->bodyhash);

        // Clean up test records.
        $DB->delete_records('local_webhookengine_delivery', ['id' => $deliveryid]);
        $DB->delete_records('local_webhookengine_hook', ['id' => $hookid]);
    }
}
