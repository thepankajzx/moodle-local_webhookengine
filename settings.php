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
 * Admin settings definition for local_webhookengine.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/webhookengine/classes/admin_setting_activation.php');

if ($hassiteconfig || has_capability('local/webhookengine:manage', context_system::instance())) {
    $ADMIN->add('localplugins', new admin_category('local_webhookengine_cat', get_string('pluginname', 'local_webhookengine')));

    
    // Manage Webhooks Link.
    $ADMIN->add('local_webhookengine_cat', new admin_externalpage(
        'local_webhookengine_manage',
        get_string('manage_hooks', 'local_webhookengine'),
        new moodle_url('/local/webhookengine/manage.php'),
        'local/webhookengine:manage'
    ));

    // Deliveries Log Link.
    $ADMIN->add('local_webhookengine_cat', new admin_externalpage(
        'local_webhookengine_deliveries',
        get_string('deliveries', 'local_webhookengine'),
        new moodle_url('/local/webhookengine/deliveries.php'),
        'local/webhookengine:viewlogs'
    ));

    // Global Settings Page.
    $settingspage = new admin_settingpage('local_webhookengine_settings', get_string('settings', 'local_webhookengine'));

    
    // Global Kill Switch.
    $settingspage->add(new admin_setting_configcheckbox(
        'local_webhookengine/killswitch',
        get_string('setting_killswitch', 'local_webhookengine'),
        get_string('setting_killswitch_desc', 'local_webhookengine'),
        0
    ));

    // Allow plain HTTP for testing.
    $settingspage->add(new admin_setting_configcheckbox(
        'local_webhookengine/allowinsecurehttp',
        get_string('setting_allowinsecurehttp', 'local_webhookengine'),
        get_string('setting_allowinsecurehttp_desc', 'local_webhookengine'),
        0
    ));

    // Default Request Timeout.
    $settingspage->add(new admin_setting_configtext(
        'local_webhookengine/defaulttimeout',
        get_string('setting_defaulttimeout', 'local_webhookengine'),
        get_string('setting_defaulttimeout_desc', 'local_webhookengine'),
        10,
        PARAM_INT
    ));

    // Rate cap per hook per minute.
    $settingspage->add(new admin_setting_configtext(
        'local_webhookengine/ratecapperhook',
        get_string('setting_ratecapperhook', 'local_webhookengine'),
        get_string('setting_ratecapperhook_desc', 'local_webhookengine'),
        600,
        PARAM_INT
    ));

    // Global Queue Maximum.
    $settingspage->add(new admin_setting_configtext(
        'local_webhookengine/globalqueuemax',
        get_string('setting_globalqueuemax', 'local_webhookengine'),
        get_string('setting_globalqueuemax_desc', 'local_webhookengine'),
        50000,
        PARAM_INT
    ));

    // Log Retention (days).
    $settingspage->add(new admin_setting_configtext(
        'local_webhookengine/logretention',
        get_string('setting_logretention', 'local_webhookengine'),
        get_string('setting_logretention_desc', 'local_webhookengine'),
        30,
        PARAM_INT
    ));

    // Payload Retention (days).
    $settingspage->add(new admin_setting_configtext(
        'local_webhookengine/payloadretention',
        get_string('setting_payloadretention', 'local_webhookengine'),
        get_string('setting_payloadretention_desc', 'local_webhookengine'),
        14,
        PARAM_INT
    ));

    // Security Denylist for Events.
    $settingspage->add(new admin_setting_configtextarea(
        'local_webhookengine/eventdenylist',
        get_string('setting_eventdenylist', 'local_webhookengine'),
        get_string('setting_eventdenylist_desc', 'local_webhookengine'),
        "\\core\\event\\user_login_failed\n\\core\\event\\user_password_updated",
        PARAM_RAW
    ));

    $ADMIN->add('local_webhookengine_cat', $settingspage);
}
