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
 * Unit tests for deferred deliveries, adhoc task deduplication, and orphaned sweeper (F2).
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\task\check_health
 * @covers     \local_webhookengine\task\send_delivery
 * @covers     \local_webhookengine\delivery_service
 */
final class deferred_queue_test extends \advanced_testcase {
    /**
     * Set up testing environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Helper to create a test hook.
     *
     * @param array $overrides Property overrides.
     * @return \stdClass Hook record.
     */
    private function create_test_hook(array $overrides = []): \stdClass {
        global $DB;

        $record = (object) array_merge([
            'name' => 'F2 Test Hook ' . uniqid(),
            'url' => 'https://example.com/webhook',
            'events' => json_encode(['\core\event\course_completed']),
            'secret' => 'testsecret123456789012345678901234',
            'enabled' => 1,
            'status' => 'active',
            'timeout' => 10,
            'maxattempts' => 5,
            'failstreak' => 0,
            'circuitstate' => circuit_breaker::STATE_CLOSED,
            'circuitcooldownuntil' => 0,
            'createdby' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides);

        $id = $DB->insert_record('local_webhookengine_hook', $record);
        return $DB->get_record('local_webhookengine_hook', ['id' => $id]);
    }

    /**
     * Test that adhoc task custom_data shape is strictly (object) ['deliveryid' => (int) $id] and deduplication works.
     */
    public function test_adhoc_custom_data_shape_deduplication(): void {
        global $DB;

        $dummyid = 998877;
        $DB->delete_records_select('task_adhoc', "classname = :cls AND " . $DB->sql_like('customdata', ':pattern'), [
            'cls' => '\\local_webhookengine\\task\\send_delivery',
            'pattern' => '%' . $dummyid . '%',
        ]);

        $task1 = new \local_webhookengine\task\send_delivery();
        $task1->set_custom_data((object) ['deliveryid' => (int) $dummyid]);
        $queued1 = \core\task\manager::queue_adhoc_task($task1, true);

        $task2 = new \local_webhookengine\task\send_delivery();
        $task2->set_custom_data((object) ['deliveryid' => (int) $dummyid]);
        $queued2 = \core\task\manager::queue_adhoc_task($task2, true);

        $this->assertNotEmpty($queued1);
        $this->assertFalse($queued2, 'Second task with identical object shape must be deduplicated by checkforexisting.');
    }

    /**
     * Test that the sweeper picks up deliveries overdue by a few minutes (e.g. 5 minutes) rather than 1 hour.
     */
    public function test_sweeper_picks_up_deliveries_overdue_by_few_minutes(): void {
        global $DB;

        $hook = $this->create_test_hook();
        $overduetime = time() - 400; // Overdue by 400 seconds (> 300s, < 3600s).

        $delivid = $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => $hook->id,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\course_completed',
            'status' => 'queued',
            'attempts' => 0,
            'nextattempt' => $overduetime,
            'payload' => json_encode(['test' => 'sweeper']),
            'timecreated' => $overduetime,
        ]);

        $sweeper = new \local_webhookengine\task\check_health();
        $swept = $sweeper->sweep_orphaned_deliveries(100, 300);

        $this->assertGreaterThanOrEqual(1, $swept);

        $taskexists = $DB->record_exists_select(
            'task_adhoc',
            "classname = :cls AND " . $DB->sql_like('customdata', ':pattern'),
            [
                'cls' => '\\local_webhookengine\\task\\send_delivery',
                'pattern' => '%' . $delivid . '%',
            ]
        );
        $this->assertTrue($taskexists, 'Adhoc task must be queued for swept overdue delivery.');

        $updated = $DB->get_record('local_webhookengine_delivery', ['id' => $delivid]);
        $this->assertGreaterThanOrEqual(
            time() - 10,
            (int) $updated->nextattempt,
            'Nextattempt must be refreshed to now upon sweeping.'
        );
    }

    /**
     * Test that the sweeper drains a large batch of deferred rows in a bounded number of runs.
     */
    public function test_sweeper_drains_deferred_rows_in_bounded_runs(): void {
        global $DB;

        $hook = $this->create_test_hook();
        $now = time();
        $overduetime = $now - 600;

        // Insert 500 deferred records in test environment.
        $totalrecords = 500;
        $batch = [];
        for ($i = 0; $i < $totalrecords; $i++) {
            $batch[] = (object) [
                'hookid' => $hook->id,
                'uuid' => \core\uuid::generate(),
                'eventname' => '\core\event\course_completed',
                'status' => 'queued',
                'attempts' => 0,
                'nextattempt' => $overduetime - $i,
                'payload' => null,
                'timecreated' => $overduetime,
            ];
        }
        $DB->insert_records('local_webhookengine_delivery', $batch);

        $sweeper = new \local_webhookengine\task\check_health();
        $limitperrun = 100;
        $runs = 0;
        $maxruns = 10;

        while ($runs < $maxruns) {
            $runs++;
            $sweeper->sweep_orphaned_deliveries($limitperrun, 300, 50);
            $remaining = $DB->count_records_select(
                'local_webhookengine_delivery',
                'hookid = :hid AND status IN (\'queued\', \'retrying\') AND nextattempt < :od',
                ['hid' => $hook->id, 'od' => $now - 300]
            );
            if ($remaining === 0) {
                break;
            }
        }

        $this->assertEquals(0, $remaining, 'All deferred rows must be drained.');
        $this->assertLessThanOrEqual(5, $runs, 'Drain must complete in bounded runs (<= 5 runs for 500 records at 100/run).');
    }

    /**
     * Test that early return paths (circuit open, concurrency busy) queue adhoc tasks with set_next_run_time.
     */
    public function test_early_return_paths_queue_adhoc_with_next_run_time(): void {
        global $DB;

        // Hook with OPEN circuit.
        $hook = $this->create_test_hook([
            'circuitstate' => circuit_breaker::STATE_OPEN,
            'circuitcooldownuntil' => time() + 300,
        ]);

        $delivid = $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => $hook->id,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\course_completed',
            'status' => 'queued',
            'attempts' => 0,
            'nextattempt' => time(),
            'payload' => json_encode(['test' => 'circuit_open_defer']),
            'timecreated' => time(),
        ]);

        $ds = new delivery_service();
        $res = $ds->execute_delivery($delivid);
        $this->assertFalse($res);

        // Verify adhoc task was queued with next_run_time matching nextattempt.
        $taskrecord = $DB->get_record_select(
            'task_adhoc',
            "classname = :cls AND " . $DB->sql_like('customdata', ':pattern'),
            [
                'cls' => '\\local_webhookengine\\task\\send_delivery',
                'pattern' => '%' . $delivid . '%',
            ]
        );

        $this->assertNotEmpty($taskrecord);
        $delivery = $DB->get_record('local_webhookengine_delivery', ['id' => $delivid]);
        $this->assertEquals((int) $delivery->nextattempt, (int) $taskrecord->nextruntime);
    }
}
