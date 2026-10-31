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

namespace local_webhookengine\task;

use local_webhookengine\delivery_service;

/**
 * Ad-hoc task to dispatch a single webhook delivery asynchronously.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_delivery extends \core\task\adhoc_task {
    /**
     * Get task name for administration interface.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_delivery', 'local_webhookengine');
    }

    /**
     * Execute the adhoc delivery task.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data) || empty($data->deliveryid)) {
            return;
        }

        $service = new delivery_service();
        $service->execute_delivery((int) $data->deliveryid);
    }
}
