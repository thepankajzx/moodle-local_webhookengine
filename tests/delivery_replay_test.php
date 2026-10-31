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
 * Unit tests for safe delivery replay guards and payload lifecycle.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\delivery_service
 */
final class delivery_replay_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that deliveries with purged (NULL) payloads are rejected and never dispatched as empty bodies.
     */
    public function test_purged_payload_is_rejected_without_dispatch(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'Replay Guard Test',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_loggedin'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 5,
            'maxattempts' => 3,
        ];
        $hookid = hook_repository::create_hook($data, $rawsecret);

        $deliveryrecord = (object) [
            'hookid' => $hookid,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\user_loggedin',
            'status' => 'failed',
            'attempts' => 3,
            'payload' => null,
            'httpstatus' => 500,
            'errorcode' => 'server_error',
            'timecreated' => time(),
            'timelastattempt' => time(),
        ];
        $deliveryid = $DB->insert_record('local_webhookengine_delivery', $deliveryrecord);

        $service = new delivery_service();
        $result = $service->execute_delivery($deliveryid);

        $this->assertFalse($result);

        $updated = $DB->get_record('local_webhookengine_delivery', ['id' => $deliveryid]);
        $this->assertEquals('failed', $updated->status);
        $this->assertEquals('payload_purged_cannot_replay', $updated->errorcode);
    }
}
