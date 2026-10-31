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
 * Unit tests for circuit breaker state machine, half-open probes, and concurrency throttling.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\circuit_breaker
 * @covers     \local_webhookengine\rate_limiter
 */
final class circuit_breaker_test extends \advanced_testcase {
    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Helper to create a test hook record.
     *
     * @param array $overrides
     * @return object
     */
    private function create_test_hook(array $overrides = []): object {
        global $DB;
        $now = time();
        $record = (object) array_merge([
            'name' => 'Circuit Breaker Test Hook',
            'url' => 'https://api.example.com/webhook',
            'secret' => hook_repository::encrypt_secret(random_bytes(32)),
            'enabled' => 1,
            'status' => 'active',
            'events' => json_encode(['\core\event\course_completed']),
            'timeout' => 10,
            'maxattempts' => 5,
            'failstreak' => 0,
            'circuitstate' => circuit_breaker::STATE_CLOSED,
            'circuitcooldownuntil' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => 2,
        ], $overrides);

        $id = $DB->insert_record('local_webhookengine_hook', $record);
        return $DB->get_record('local_webhookengine_hook', ['id' => $id]);
    }

    /**
     * Test circuit breaker state transitions: closed -> open -> half_open -> closed.
     */
    public function test_circuit_state_transitions(): void {
        global $DB;
        $hook = $this->create_test_hook();

        // 1. Initial state must be closed and dispatch allowed.
        $this->assertEquals(circuit_breaker::STATE_CLOSED, circuit_breaker::get_state($hook));
        $this->assertTrue(circuit_breaker::can_dispatch($hook));

        // 2. Simulate 9 consecutive failures (below default threshold of 10).
        for ($i = 1; $i <= 9; $i++) {
            circuit_breaker::record_failure($hook);
            $hook = $DB->get_record('local_webhookengine_hook', ['id' => $hook->id]);
            $this->assertEquals($i, (int) $hook->failstreak);
            $this->assertEquals(circuit_breaker::STATE_CLOSED, circuit_breaker::get_state($hook));
            $this->assertTrue(circuit_breaker::can_dispatch($hook));
        }

        // 3. 10th failure should trip the circuit to open.
        circuit_breaker::record_failure($hook);
        $hook = $DB->get_record('local_webhookengine_hook', ['id' => $hook->id]);
        $this->assertEquals(10, (int) $hook->failstreak);
        $this->assertEquals(circuit_breaker::STATE_OPEN, circuit_breaker::get_state($hook));
        $this->assertFalse(circuit_breaker::can_dispatch($hook));
        $this->assertGreaterThan(time(), (int) $hook->circuitcooldownuntil);

        // 4. Fast forward time past cooldown: state must transition to half_open.
        $cooldowntime = (int) $hook->circuitcooldownuntil;
        $hook->circuitcooldownuntil = time() - 10;
        $DB->update_record('local_webhookengine_hook', $hook);

        $state = circuit_breaker::get_state($hook);
        $this->assertEquals(circuit_breaker::STATE_HALF_OPEN, $state);
        $this->assertTrue(circuit_breaker::can_dispatch($hook));

        // 5. Successful probe delivery closes the circuit and resets streak.
        circuit_breaker::record_success($hook);
        $hook = $DB->get_record('local_webhookengine_hook', ['id' => $hook->id]);
        $this->assertEquals(circuit_breaker::STATE_CLOSED, circuit_breaker::get_state($hook));
        $this->assertEquals(0, (int) $hook->failstreak);
        $this->assertEquals(0, (int) $hook->circuitcooldownuntil);
        $this->assertTrue(circuit_breaker::can_dispatch($hook));
    }

    /**
     * Test that a failed probe in half_open immediately trips the circuit back to open.
     */
    public function test_half_open_failure_re_opens_circuit(): void {
        global $DB;
        $hook = $this->create_test_hook([
            'failstreak' => 10,
            'circuitstate' => circuit_breaker::STATE_HALF_OPEN,
            'circuitcooldownuntil' => time() - 5,
        ]);

        $this->assertTrue(circuit_breaker::can_dispatch($hook));

        // Probe fails.
        circuit_breaker::record_failure($hook);
        $hook = $DB->get_record('local_webhookengine_hook', ['id' => $hook->id]);

        $this->assertEquals(circuit_breaker::STATE_OPEN, $hook->circuitstate);
        $this->assertFalse(circuit_breaker::can_dispatch($hook));
        $this->assertGreaterThan(time(), (int) $hook->circuitcooldownuntil);
    }

    /**
     * Test manual administrator reset of tripped circuit breaker.
     */
    public function test_admin_manual_reset(): void {
        global $DB;
        $hook = $this->create_test_hook([
            'failstreak' => 15,
            'circuitstate' => circuit_breaker::STATE_OPEN,
            'circuitcooldownuntil' => time() + 300,
        ]);

        $this->assertFalse(circuit_breaker::can_dispatch($hook));

        // Administrator clicks reset.
        $result = circuit_breaker::reset_circuit((int) $hook->id);
        $this->assertTrue($result);

        $hook = $DB->get_record('local_webhookengine_hook', ['id' => $hook->id]);
        $this->assertEquals(circuit_breaker::STATE_CLOSED, $hook->circuitstate);
        $this->assertEquals(0, (int) $hook->failstreak);
        $this->assertEquals(0, (int) $hook->circuitcooldownuntil);
        $this->assertTrue(circuit_breaker::can_dispatch($hook));
    }

    /**
     * Test per-hook concurrency slot throttling.
     */
    public function test_per_hook_concurrency_slots(): void {
        $hookid = 999;
        $maxslots = 3;

        // Clear any previous slots.
        rate_limiter::release_all_hook_slots($hookid);

        $this->assertTrue(rate_limiter::acquire_hook_slot($hookid, $maxslots));
        $this->assertTrue(rate_limiter::acquire_hook_slot($hookid, $maxslots));
        $this->assertTrue(rate_limiter::acquire_hook_slot($hookid, $maxslots));

        // 4th slot must be rejected when limit is 3.
        $this->assertFalse(rate_limiter::acquire_hook_slot($hookid, $maxslots));

        // Release one slot and acquire again.
        rate_limiter::release_hook_slot($hookid);
        $this->assertTrue(rate_limiter::acquire_hook_slot($hookid, $maxslots));
        $this->assertFalse(rate_limiter::acquire_hook_slot($hookid, $maxslots));

        // Clean up.
        rate_limiter::release_all_hook_slots($hookid);
    }
}
