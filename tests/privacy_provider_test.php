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
 * Privacy provider unit tests for local_webhookengine.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\privacy\provider
 */
final class privacy_provider_test extends \advanced_testcase {
    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test metadata declaration covers database tables and external endpoints.
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('local_webhookengine');
        $result = \local_webhookengine\privacy\provider::get_metadata($collection);
        $items = $result->get_collection();

        $tables = [];
        $external = [];
        foreach ($items as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $tables[] = $item->get_name();
            } else if ($item instanceof \core_privacy\local\metadata\types\external_location) {
                $external[] = $item->get_name();
            }
        }

        $this->assertContains('local_webhookengine_delivery', $tables);
        $this->assertContains('local_webhookengine_hook', $tables);
        $this->assertContains('webhook_endpoint', $external);
    }

    /**
     * Test context resolution and user deletion.
     */
    public function test_privacy_lifecycle_and_deletion(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();

        // Initially no contexts.
        $contextlist = \local_webhookengine\privacy\provider::get_contexts_for_userid($user->id);
        $this->assertEmpty($contextlist->get_contextids());

        // Insert delivery record for this user.
        $deliveryid = $DB->insert_record('local_webhookengine_delivery', (object) [
            'hookid' => 1,
            'uuid' => \core\uuid::generate(),
            'eventname' => '\core\event\user_updated',
            'status' => 'success',
            'attempts' => 1,
            'userid' => $user->id,
            'timecreated' => time(),
        ]);

        // Context should now include system context.
        $contextlist = \local_webhookengine\privacy\provider::get_contexts_for_userid($user->id);
        $this->assertContainsEquals($systemcontext->id, $contextlist->get_contextids());

        // Test delete_data_for_user.
        $appcontextlist = new \core_privacy\local\request\approved_contextlist(
            $user,
            'local_webhookengine',
            [$systemcontext->id]
        );

        \local_webhookengine\privacy\provider::delete_data_for_user($appcontextlist);

        $this->assertFalse($DB->record_exists('local_webhookengine_delivery', ['id' => $deliveryid]));
    }
}
