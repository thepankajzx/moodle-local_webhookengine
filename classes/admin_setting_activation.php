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

/**
 * Custom admin setting class for Pro activation.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Custom admin setting class for Pro activation.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_webhookengine_admin_setting_activation extends admin_setting_configtext {
    /**
     * Write the setting to the database, but also handle activation logic.
     *
     * @param string $data The user submitted data.
     * @return string Empty string on success, error message on failure.
     */
    public function write_setting($data) {
        // We MUST write an empty string to config so Moodle knows the setting is configured.
        // Otherwise, upgradesettings.php loops infinitely during installation.
        $this->config_write($this->name, '');

        // If empty, do not process activation.
        $data = (string)$data;
        $data = trim($data);
        if ($data === '') {
            return '';
        }

        // If they are already pro, do nothing.
        if (\local_webhookengine\pro_unlock::is_pro()) {
            return '';
        }

        $success = \local_webhookengine\pro_unlock::activate($data);

        if ($success) {
            // Do not actually store the activation code in the config table as a string.
            // The activate() method already sets is_pro = 1.
            return '';
        } else {
            // Return validation error.
            return get_string('activation_invalid', 'local_webhookengine');
        }
    }

    /**
     * Override output_html to show a read-only badge if already activated.
     *
     * @param string $data
     * @param string $query
     * @return string HTML
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        if (\local_webhookengine\pro_unlock::is_pro()) {
            $html = '<div class="alert alert-success d-inline-block px-3 py-2 mb-0" style="background-color: #d4edda; color: #155724; border-color: #c3e6cb;">';
            $html .= '<strong>' . get_string('pro_active', 'local_webhookengine') . '</strong>';
            $html .= '</div>';

            return format_admin_setting(
                $this,
                $this->visiblename,
                $html,
                $this->description,
                true,
                '',
                get_string('pro_active_desc', 'local_webhookengine'),
                $query
            );
        }

        return parent::output_html('', $query);
    }
}
