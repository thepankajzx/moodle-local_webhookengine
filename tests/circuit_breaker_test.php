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
 * @covers     \local_webhookengine\delivery_service
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
            'url' => 'https://1.1.1.1/webhook',
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
     * Test that an OPEN circuit does NOT drop events at ingest; instead enqueues with nextattempt = cooldown end.
     */
    public function test_open_circuit_enqueues_delivery_without_dropping(): void {
        global $DB;
        $cooldowntarget = time() + 500;
        $hook = $this->create_test_hook([
            'events' => json_encode(['\core\event\course_viewed']),
            'circuitstate' => circuit_breaker::STATE_OPEN,
            'circuitcooldownuntil' => $cooldowntarget,
        ]);

        \cache::make('local_webhookengine', 'eventmap')->purge();

        $course = $this->getDataGenerator()->create_course();
        $event = \core\event\course_viewed::create([
            'context' => \context_course::instance($course->id),
            'courseid' => $course->id,
            'objectid' => $course->id,
        ]);

        observer::handle($event);

        // 1. Must NOT record a drop in the drop ledger.
        $drops = $DB->count_records('local_webhookengine_drop_ledger', [
            'hookid' => $hook->id,
            'reason' => reasons::CIRCUIT_BREAKER_OPEN,
        ]);
        $this->assertEquals(0, $drops, 'Open circuit breaker must not drop events at ingest');

        // 2. Must enqueue delivery with nextattempt set to cooldown expiration.
        $delivery = $DB->get_record('local_webhookengine_delivery', [
            'hookid' => $hook->id,
            'eventname' => '\core\event\course_viewed',
        ]);
        $this->assertNotEmpty($delivery, 'Delivery must be enqueued into delivery table');
        $this->assertEquals('queued', $delivery->status);
        $this->assertEquals($cooldowntarget, (int) $delivery->nextattempt);
    }

    /**
     * Test that every retryable failure increments failstreak (not just terminal failures).
     */
    public function test_retryable_failure_increments_failstreak_on_every_attempt(): void {
        global $DB;
        $hook = $this->create_test_hook(['failstreak' => 0, 'maxattempts' => 5]);

        $now = time();
        $delivery = (object) [
            'hookid' => $hook->id,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\course_completed',
            'status' => 'queued',
            'attempts' => 0,
            'nextattempt' => $now,
            'payload' => json_encode(['test' => 'retryable']),
            'timecreated' => $now,
        ];
        $delivid = $DB->insert_record('local_webhookengine_delivery', $delivery);

        // Mock 500 error (retryable).
        $mockclient = new class extends http_client {
            /**
             * Send mock HTTP request returning 500.
             *
             * @param string $url Target URL.
             * @param string $body Request body.
             * @param array $headers Headers array.
             * @param int $timeout Request timeout.
             * @param bool $usemtls Whether mTLS is enabled.
             * @return object Response object.
             */
            public function send(string $url, string $body, array $headers, int $timeout = 10, bool $usemtls = false): object {
                return (object) [
                    'status' => 500,
                    'errno' => 0,
                    'error' => 'Internal Server Error',
                    'duration' => 50,
                    'retryafter' => null,
                ];
            }
        };

        $ds = new delivery_service();
        $res = $ds->execute_delivery($delivid, $mockclient);
        $this->assertFalse($res);

        // Failstreak must be incremented on attempt 1.
        $updatedhook = $DB->get_record('local_webhookengine_hook', ['id' => $hook->id]);
        $this->assertEquals(1, (int) $updatedhook->failstreak);

        // Delivery must be retrying.
        $updateddeliv = $DB->get_record('local_webhookengine_delivery', ['id' => $delivid]);
        $this->assertEquals('retrying', $updateddeliv->status);
        $this->assertEquals(1, (int) $updateddeliv->attempts);
    }

    /**
     * Test that permanent 4xx responses (400, 401, 403, 404, 410, 422) do NOT increment failstreak or trip breaker.
     */
    public function test_permanent_4xx_does_not_increment_failstreak_or_trip_breaker(): void {
        global $DB;
        $hook = $this->create_test_hook(['failstreak' => 0]);

        $now = time();
        $delivery = (object) [
            'hookid' => $hook->id,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\course_completed',
            'status' => 'queued',
            'attempts' => 0,
            'nextattempt' => $now,
            'payload' => json_encode(['test' => 'misconfig']),
            'timecreated' => $now,
        ];
        $delivid = $DB->insert_record('local_webhookengine_delivery', $delivery);

        // Mock 401 Unauthorized (permanent misconfiguration).
        $mockclient = new class extends http_client {
            /**
             * Send mock HTTP request returning 401.
             *
             * @param string $url Target URL.
             * @param string $body Request body.
             * @param array $headers Headers array.
             * @param int $timeout Request timeout.
             * @param bool $usemtls Whether mTLS is enabled.
             * @return object Response object.
             */
            public function send(string $url, string $body, array $headers, int $timeout = 10, bool $usemtls = false): object {
                return (object) [
                    'status' => 401,
                    'errno' => 0,
                    'error' => 'Unauthorized: Invalid Bearer Token',
                    'duration' => 20,
                    'retryafter' => null,
                ];
            }
        };

        $ds = new delivery_service();
        $res = $ds->execute_delivery($delivid, $mockclient);
        $this->assertFalse($res);

        // Hook failstreak must remain 0!
        $updatedhook = $DB->get_record('local_webhookengine_hook', ['id' => $hook->id]);
        $this->assertEquals(0, (int) $updatedhook->failstreak);
        $this->assertEquals(circuit_breaker::STATE_CLOSED, $updatedhook->circuitstate);

        // Delivery must be terminal failed with http_401 errorcode.
        $updateddeliv = $DB->get_record('local_webhookengine_delivery', ['id' => $delivid]);
        $this->assertEquals('failed', $updateddeliv->status);
        $this->assertEquals('http_401', $updateddeliv->errorcode);
    }

    /**
     * Test half-open atomic probe claim allows exactly one in-flight probe while others remain deferred.
     */
    public function test_half_open_allows_only_one_inflight_probe_atomic_claim(): void {
        global $DB;
        $hook = $this->create_test_hook([
            'failstreak' => 10,
            'circuitstate' => circuit_breaker::STATE_HALF_OPEN,
            'circuitcooldownuntil' => time() - 10,
        ]);

        circuit_breaker::release_probe_claim((int) $hook->id);

        // First worker claims probe.
        $this->assertTrue(circuit_breaker::claim_half_open_probe((int) $hook->id));

        // Second concurrent worker must be denied probe claim.
        $this->assertFalse(circuit_breaker::claim_half_open_probe((int) $hook->id));

        // After releasing, another claim succeeds.
        circuit_breaker::release_probe_claim((int) $hook->id);
        $this->assertTrue(circuit_breaker::claim_half_open_probe((int) $hook->id));

        circuit_breaker::release_probe_claim((int) $hook->id);
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

        // Probe fails with retryable failure.
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
