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
 * Unit tests for Task T8c: Telemetry and metrics computation.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\telemetry
 */
final class telemetry_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test p95 percentile calculation mathematical correctness.
     */
    public function test_calculate_p95(): void {
        // Empty array returns null.
        $this->assertNull(telemetry::calculate_p95([]));

        // Single sample returns exact value.
        $this->assertEquals(42, telemetry::calculate_p95([42]));

        // Exactly 100 samples from 1 to 100: p95 must be 95.
        $samples = range(1, 100);
        $this->assertEquals(95, telemetry::calculate_p95($samples));

        // 20 samples from 10 to 200 (step 10): 20 * 0.95 = index 19 (0-based: 18 -> 190).
        $samples2 = [];
        for ($i = 1; $i <= 20; $i++) {
            $samples2[] = $i * 10;
        }
        $this->assertEquals(190, telemetry::calculate_p95($samples2));
    }

    /**
     * Test system metrics and hook metrics computation with database records.
     */
    public function test_metrics_computation(): void {
        global $DB;
        $now = time();

        // 1. Create a test hook.
        $hookid = $DB->insert_record('local_webhookengine_hook', (object) [
            'name' => 'Telemetry Test Hook',
            'url' => 'https://api.example.com/telemetry',
            'secret' => hook_repository::encrypt_secret(random_bytes(32)),
            'enabled' => 1,
            'status' => 'active',
            'events' => json_encode(['\core\event\course_completed']),
            'circuitstate' => circuit_breaker::STATE_CLOSED,
            'circuitcooldownuntil' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => 2,
        ]);

        // 2. Insert 9 successful deliveries and 1 failed delivery (90% success rate).
        for ($i = 1; $i <= 9; $i++) {
            $DB->insert_record('local_webhookengine_delivery', (object) [
                'hookid' => $hookid,
                'uuid' => \core\uuid::generate(),
                'eventname' => '\core\event\course_completed',
                'status' => 'success',
                'attempts' => 1,
                'httpstatus' => 200,
                'duration' => 100 + ($i * 10), // Range between 110 and 190 milliseconds.
                'timecreated' => $now - ($i * 60),
                'timelastattempt' => $now - ($i * 60),
            ]);
        }

        $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => $hookid,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\course_completed',
            'status' => 'failed',
            'attempts' => 3,
            'httpstatus' => 500,
            'errorcode' => 'http_500',
            'duration' => 50,
            'timecreated' => $now - 30,
            'timelastattempt' => $now - 30,
        ]);

        // Insert 1 queued delivery.
        $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => $hookid,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\course_completed',
            'status' => 'queued',
            'attempts' => 0,
            'timecreated' => $now,
            'nextattempt' => $now,
        ]);

        // Compute hook metrics (bypassing cache).
        $hookmetrics = telemetry::get_hook_metrics($hookid, true);
        $this->assertEquals(90.0, $hookmetrics['successrate_24h']);
        $this->assertEquals('90%', $hookmetrics['successrate_display']);
        $this->assertEquals(1, $hookmetrics['queued_count']);
        $this->assertEquals('http_500', $hookmetrics['lasterror']);
        $this->assertNotNull($hookmetrics['p95_duration_ms']);

        // Compute system metrics (bypassing cache).
        $sysmetrics = telemetry::get_system_metrics(true);
        $this->assertGreaterThanOrEqual(1, $sysmetrics['queuedcount']);
        $this->assertEquals(90.0, $sysmetrics['successrate_24h']);
        $this->assertEquals(0, $sysmetrics['tripped_circuits_count']);
        $this->assertFalse($sysmetrics['has_tripped_circuits']);
    }
}
