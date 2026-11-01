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
 * Unit tests for drop ledger, event map v2, and rate limiter guards.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\hook_repository
 * @covers     \local_webhookengine\rate_limiter
 * @covers     \local_webhookengine\reasons
 */
final class drop_ledger_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that recording drops increments aggregated drop counts correctly.
     */
    public function test_record_drop_aggregates_properly(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'Drop Ledger Test Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_loggedin'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 5,
            'maxattempts' => 3,
        ];
        $hookid = hook_repository::create_hook($data, $rawsecret);

        // Record multiple drops under same minute.
        hook_repository::record_drop($hookid, reasons::HOOK_PAUSED);
        hook_repository::record_drop($hookid, reasons::HOOK_PAUSED);
        hook_repository::record_drop($hookid, reasons::RATE_LIMITED);

        $pauseddrops = hook_repository::get_hook_drop_count_by_reason($hookid, reasons::HOOK_PAUSED);
        $ratelimiteddrops = hook_repository::get_hook_drop_count_by_reason($hookid, reasons::RATE_LIMITED);
        $totaldrops = hook_repository::get_hook_total_drops($hookid);

        $this->assertEquals(2, $pauseddrops);
        $this->assertEquals(1, $ratelimiteddrops);
        $this->assertEquals(3, $totaldrops);
    }

    /**
     * Test that event map includes paused hooks flagged as paused.
     */
    public function test_event_map_includes_paused_hooks(): void {
        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'Event Map Paused Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\course_completed'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 5,
            'maxattempts' => 3,
        ];
        $hookid = hook_repository::create_hook($data, $rawsecret);
        hook_repository::set_hook_status($hookid, 'paused');

        $map = hook_repository::get_event_map();
        $this->assertArrayHasKey('\core\event\course_completed', $map);

        $found = false;
        foreach ($map['\core\event\course_completed'] as $entry) {
            if ($entry['id'] === $hookid) {
                $this->assertEquals('paused', $entry['status']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * Test that noisy-neighbor retrying backlog cap triggers accurately.
     */
    public function test_noisy_neighbor_backlog_cap(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'Backlog Test Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_loggedin'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 5,
            'maxattempts' => 3,
        ];
        $hookid = hook_repository::create_hook($data, $rawsecret);

        // Before backlog, should not be exceeded.
        $this->assertFalse(rate_limiter::is_hook_backlog_exceeded($hookid, 5));

        // Insert 5 retrying records.
        for ($i = 0; $i < 5; $i++) {
            $delivery = (object) [
                'hookid' => $hookid,
                'uuid' => \core\uuid::generate(),
                'eventname' => '\core\event\user_loggedin',
                'status' => 'retrying',
                'attempts' => 2,
                'timecreated' => time(),
            ];
            $DB->insert_record('local_webhookengine_delivery', $delivery);
        }

        // Now backlog must be exceeded.
        $this->assertTrue(rate_limiter::is_hook_backlog_exceeded($hookid, 5));
    }

    /**
     * Test that record_drop buffers counts in-memory without immediate database writes.
     */
    public function test_record_drop_buffers_in_process(): void {
        global $DB;

        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'Buffer Test Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_loggedin'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 5,
            'maxattempts' => 3,
        ];
        $hookid = hook_repository::create_hook($data, $rawsecret);

        $DB->delete_records('local_webhookengine_drop_ledger', ['hookid' => $hookid]);

        // Record 15 drops.
        for ($i = 0; $i < 15; $i++) {
            hook_repository::record_drop($hookid, reasons::RATE_LIMITED, 1);
        }

        // Before manual flush or read, verify in-memory buffer holds drops.
        $totalbeforeflush = $DB->count_records('local_webhookengine_drop_ledger', ['hookid' => $hookid]);
        $this->assertEquals(0, $totalbeforeflush, 'Drops must be buffered in-memory with zero immediate DB writes.');

        // Flush buffer.
        hook_repository::flush_drop_buffer();

        $totalafterflush = hook_repository::get_hook_drop_count_by_reason($hookid, reasons::RATE_LIMITED);
        $this->assertEquals(15, $totalafterflush, 'Flushed buffer must yield single aggregated count of 15.');
    }

    /**
     * Test that rate limiter per-minute window enforces capacity limits.
     */
    public function test_rate_limiter_window_and_node_semantics(): void {
        $rawsecret = hook_repository::generate_raw_secret();
        $data = (object) [
            'name' => 'Rate Limit Window Hook',
            'url' => 'https://example.com/webhook',
            'enabled' => 1,
            'events' => ['\core\event\user_loggedin'],
            'filters' => [],
            'payloadopts' => [],
            'timeout' => 5,
            'maxattempts' => 3,
        ];
        $hookid = hook_repository::create_hook($data, $rawsecret);

        // Allow 3 requests per minute.
        $this->assertTrue(rate_limiter::check_and_increment_hook($hookid, 3));
        $this->assertTrue(rate_limiter::check_and_increment_hook($hookid, 3));
        $this->assertTrue(rate_limiter::check_and_increment_hook($hookid, 3));

        // 4th request must be rejected.
        $this->assertFalse(rate_limiter::check_and_increment_hook($hookid, 3));
    }

    /**
     * Test dynamic global queue count adjustments.
     */
    public function test_dynamic_queue_and_backlog_counters(): void {
        rate_limiter::adjust_global_queue(7);
        $cache = \cache::make('local_webhookengine', 'ratelimit');
        $data = $cache->get('global_queue_data');

        $this->assertNotEmpty($data);
        $this->assertGreaterThanOrEqual(7, (int) $data['count']);

        rate_limiter::adjust_global_queue(-3);
        $dataafter = $cache->get('global_queue_data');
        $this->assertEquals((int) $data['count'] - 3, (int) $dataafter['count']);
    }
}
