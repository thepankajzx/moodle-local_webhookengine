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
 * Unit tests verifying release hygiene, version consistency, and status constants (Task T10).
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_webhookengine\http_client
 * @covers     \local_webhookengine\hook_repository
 * @covers     \local_webhookengine\delivery_service
 */
final class release_hygiene_test extends \advanced_testcase {
    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test dynamic User-Agent construction matches version.php.
     */
    public function test_user_agent_format_and_version(): void {
        global $CFG;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/webhookengine/version.php');
        $expectedrelease = (string) $plugin->release;

        $useragent = http_client::get_user_agent();
        $this->assertSame('EventWebhooksEngine/' . $expectedrelease, $useragent);
        $this->assertMatchesRegularExpression('/^EventWebhooksEngine\/\d+\.\d+\.\d+$/', $useragent);
    }

    /**
     * Test hook repository lifecycle status constants.
     */
    public function test_hook_status_constants(): void {
        $this->assertSame('active', hook_repository::STATUS_ACTIVE);
        $this->assertSame('paused', hook_repository::STATUS_PAUSED);
        $this->assertSame('deleted', hook_repository::STATUS_DELETED);
    }

    /**
     * Test delivery service status constants.
     */
    public function test_delivery_status_constants(): void {
        $this->assertSame('queued', delivery_service::STATUS_QUEUED);
        $this->assertSame('queued', delivery_service::STATUS_PENDING);
        $this->assertSame('retrying', delivery_service::STATUS_RETRYING);
        $this->assertSame('success', delivery_service::STATUS_SUCCESS);
        $this->assertSame('failed', delivery_service::STATUS_FAILED);
        $this->assertSame('dropped', delivery_service::STATUS_DROPPED);
    }

    /**
     * Test documentation files have no unverified claims.
     */
    public function test_documentation_and_security_policy(): void {
        global $CFG;

        $readmepath = $CFG->dirroot . '/local/webhookengine/README.md';
        $docpath = $CFG->dirroot . '/local/webhookengine/documentation.html';
        $securitypath = $CFG->dirroot . '/local/webhookengine/SECURITY.md';

        $this->assertFileExists($readmepath);
        $this->assertFileExists($docpath);
        $this->assertFileExists($securitypath);

        $readmecontent = file_get_contents($readmepath);
        $doccontent = file_get_contents($docpath);
        $securitycontent = file_get_contents($securitypath);

        // Ensure no unverified claims or unsupported marketing terms exist.
        $this->assertStringNotContainsString('Enterprise Certified', $doccontent);
        $this->assertStringNotContainsString('enterprise-grade', $readmecontent);
        $this->assertStringNotContainsString('Enterprise Reliability', $readmecontent);
        $this->assertStringNotContainsString('cryptographic replay guards', $securitycontent);

        // Ensure SECURITY.md defines supported versions table, contact, and realistic SLAs.
        $this->assertStringContainsString('1.0.3', $securitycontent);
        $this->assertStringContainsString('support@thedefinite.one', $securitycontent);
        $this->assertStringContainsString('3 business days', $securitycontent);
        $this->assertStringContainsString('14 days', $securitycontent);

        // Ensure README declares product as Event Webhooks Engine.
        $this->assertStringContainsString('# Event Webhooks Engine', $readmecontent);
    }
}
