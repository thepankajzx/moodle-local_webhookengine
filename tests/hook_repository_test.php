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
 * Unit tests for hook_repository and lifecycle transitions.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\hook_repository
 */
final class hook_repository_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that toggling hook status preserves custom encrypted headers byte-for-byte.
     */
    public function test_toggle_status_preserves_headers(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'Header Preservation Test',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_loggedin'],
            'filters' => [],
            'payloadopts' => [],
            'headers' => [
                ['name' => 'X-API-Key', 'value' => 'whsec_test_secret_key'],
                ['name' => 'Authorization', 'value' => 'Bearer sample_token_123'],
            ],
            'timeout' => 10,
            'maxattempts' => 9,
        ];

        $hookid = hook_repository::create_hook($data, $rawsecret);
        $this->assertGreaterThan(0, $hookid);

        $initialheaders = $DB->get_field('local_webhookengine_hook', 'headers', ['id' => $hookid]);
        $this->assertNotEmpty($initialheaders);

        // Pause hook.
        $res = hook_repository::set_hook_status($hookid, 'paused');
        $this->assertTrue($res);

        $pausedstatus = $DB->get_field('local_webhookengine_hook', 'status', ['id' => $hookid]);
        $pausedheaders = $DB->get_field('local_webhookengine_hook', 'headers', ['id' => $hookid]);

        $this->assertEquals('paused', $pausedstatus);
        $this->assertEquals($initialheaders, $pausedheaders);

        // Resume hook.
        $res2 = hook_repository::set_hook_status($hookid, 'active');
        $this->assertTrue($res2);

        $resumedstatus = $DB->get_field('local_webhookengine_hook', 'status', ['id' => $hookid]);
        $resumedheaders = $DB->get_field('local_webhookengine_hook', 'headers', ['id' => $hookid]);

        $this->assertEquals('active', $resumedstatus);
        $this->assertEquals($initialheaders, $resumedheaders);
    }
}
