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
 * Unit tests for Task T8e: Dual-channel alert dispatching.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\alert_dispatcher
 */
final class alert_dispatcher_test extends \advanced_testcase {
    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test alert dispatching via message provider and direct email channel.
     */
    public function test_alert_dispatching(): void {
        // Set an alert email destination.
        set_config('alertemail', 'devops@example.com', 'local_webhookengine');

        $sink = $this->redirectEmails();

        $dispatched = alert_dispatcher::dispatch(
            'circuit_breaker_alert',
            'Test Alert Subject',
            'Test Alert Message'
        );

        $this->assertTrue($dispatched);

        $messages = $sink->get_messages();
        $this->assertNotEmpty($messages);
        $recipients = array_map(function ($m) {
            return $m->to;
        }, $messages);
        $this->assertContains('devops@example.com', $recipients);

        $found = false;
        foreach ($messages as $msg) {
            if ($msg->to === 'devops@example.com') {
                $this->assertEquals('Test Alert Subject', $msg->subject);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        $sink->close();
    }
}
