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
 * Unit and negative authorization tests for local_webhookengine_pluginfile callback.
 *
 * @package    local_webhookengine
 * @category   test
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_webhookengine_pluginfile
 */
final class pluginfile_test extends \advanced_testcase {
    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that pluginfile rejects non-system contexts.
     */
    public function test_pluginfile_rejects_non_system_context(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/webhookengine/lib.php');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $result = local_webhookengine_pluginfile(
            $course,
            null,
            $coursecontext,
            'archives',
            [1, 'test.csv.gz'],
            false
        );

        $this->assertFalse($result);
    }

    /**
     * Test that pluginfile rejects invalid file areas.
     */
    public function test_pluginfile_rejects_invalid_filearea(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/webhookengine/lib.php');

        $this->setAdminUser();
        $systemcontext = \context_system::instance();

        $result = local_webhookengine_pluginfile(
            null,
            null,
            $systemcontext,
            'unauthorized_area',
            [1, 'test.csv.gz'],
            false
        );

        $this->assertFalse($result);
    }

    /**
     * Test that pluginfile denies users without exportlogs capability.
     */
    public function test_pluginfile_requires_exportlogs_capability(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/webhookengine/lib.php');

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $systemcontext = \context_system::instance();

        $this->expectException(\required_capability_exception::class);

        local_webhookengine_pluginfile(
            null,
            null,
            $systemcontext,
            'archives',
            [1, 'test.csv.gz'],
            false
        );
    }

    /**
     * Test that pluginfile returns false for missing archive files.
     */
    public function test_pluginfile_returns_false_for_missing_file(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/webhookengine/lib.php');

        $this->setAdminUser();
        $systemcontext = \context_system::instance();

        $result = local_webhookengine_pluginfile(
            null,
            null,
            $systemcontext,
            'archives',
            [99999, 'nonexistent_archive.csv.gz'],
            false
        );

        $this->assertFalse($result);
    }
}
