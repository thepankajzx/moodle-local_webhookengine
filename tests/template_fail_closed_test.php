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
 * Unit tests for Task T6: Template fail-closed guarantees and exfiltration prevention.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\payload_builder
 * @covers     \local_webhookengine\delivery_service
 */
final class template_fail_closed_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that valid custom JSON templates render correctly with substituted variables.
     */
    public function test_valid_custom_template_renders_successfully(): void {
        $envelope = [
            'id' => '22222222-2222-2222-2222-222222222222',
            'schema' => 1,
            'type' => 'test.event',
            'created' => 1700000000,
            'site' => ['url' => 'https://example.com'],
            'event' => ['userid' => 99, 'courseid' => 10],
            'user' => ['id' => 99, 'firstname' => 'Alice'],
        ];

        $template = '{"event_type": {{type}}, "student_id": {{user.id}}, "user_name": {{user.firstname}}}';
        $rendered = payload_builder::render_custom_template($template, $envelope);

        $decoded = json_decode($rendered, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('test.event', $decoded['event_type']);
        $this->assertEquals(99, $decoded['student_id']);
        $this->assertEquals('Alice', $decoded['user_name']);
    }

    /**
     * Test that malformed or invalid templates fail closed and throw rather than falling back to default envelope.
     */
    public function test_invalid_template_throws_exception_without_envelope_fallback(): void {
        $envelope = [
            'id' => '33333333-3333-3333-3333-333333333333',
            'schema' => 1,
            'type' => 'test.event',
            'created' => 1700000000,
            'site' => ['url' => 'https://example.com'],
            'event' => ['userid' => 42, 'courseid' => 7],
        ];

        // Syntax error in template JSON.
        $badjson = '{"status": "ok", "corrupt": }';
        $this->expectException(\moodle_exception::class);
        payload_builder::render_custom_template($badjson, $envelope);
    }

    /**
     * Test that execute_delivery marks the delivery as failed with errorcode template_invalid and makes no network request.
     */
    public function test_execute_delivery_fails_closed_on_invalid_template(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $hookrecord = (object) [
            'name' => 'Fail Closed Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_created'],
            'filters' => [],
            'payloadopts' => [
                'template' => '{"corrupt_template": }',
            ],
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
            'userid' => 5,
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
        $mockclient->expects($this->never())->method('post');

        $service = new delivery_service();
        $result = $service->execute_delivery($deliveryid, $mockclient);

        $this->assertFalse($result);

        $updateddelivery = $DB->get_record('local_webhookengine_delivery', ['id' => $deliveryid]);
        $this->assertEquals('failed', $updateddelivery->status);
        $this->assertEquals('template_invalid', $updateddelivery->errorcode);

        // Crucial security check: Assert that the payload was NOT updated to the full envelope!
        $this->assertStringNotContainsString('"schema":1', (string) $updateddelivery->payload);

        // Clean up test records.
        $DB->delete_records('local_webhookengine_delivery', ['id' => $deliveryid]);
        $DB->delete_records('local_webhookengine_hook', ['id' => $hookid]);
    }
}
