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

namespace local_webhookengine\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

/**
 * Privacy Subsystem for local_webhookengine implementing metadata, user data export, and deletion.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe all personal data stored by this plugin and sent externally.
     *
     * @param collection $collection The collection of metadata items.
     * @return collection Updated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        // Delivery log table.
        $collection->add_database_table('local_webhookengine_delivery', [
            'userid' => 'privacy:metadata:local_webhookengine_delivery:userid',
            'relateduserid' => 'privacy:metadata:local_webhookengine_delivery:relateduserid',
            'courseid' => 'privacy:metadata:local_webhookengine_delivery:courseid',
            'eventname' => 'privacy:metadata:local_webhookengine_delivery:eventname',
            'payload' => 'privacy:metadata:local_webhookengine_delivery:payload',
            'timecreated' => 'privacy:metadata:local_webhookengine_delivery:timecreated',
        ], 'privacy:metadata:local_webhookengine_delivery');

        // Hook configuration table.
        $collection->add_database_table('local_webhookengine_hook', [
            'name' => 'privacy:metadata:local_webhookengine_hook:name',
            'url' => 'privacy:metadata:local_webhookengine_hook:url',
            'createdby' => 'privacy:metadata:local_webhookengine_hook:createdby',
            'timecreated' => 'privacy:metadata:local_webhookengine_hook:timecreated',
        ], 'privacy:metadata:local_webhookengine_hook');

        // External webhook destination.
        $collection->add_external_location_link('webhook_endpoint', [
            'userid' => 'privacy:metadata:webhook_endpoint:userid',
            'relateduserid' => 'privacy:metadata:webhook_endpoint:relateduserid',
            'courseid' => 'privacy:metadata:webhook_endpoint:courseid',
            'eventname' => 'privacy:metadata:webhook_endpoint:eventname',
            'user_details' => 'privacy:metadata:webhook_endpoint:user_details',
            'course_details' => 'privacy:metadata:webhook_endpoint:course_details',
        ], 'privacy:metadata:webhook_endpoint');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $systemcontext = \context_system::instance();

        global $DB;
        $hasdeliveries = $DB->record_exists_select(
            'local_webhookengine_delivery',
            'userid = :uid1 OR relateduserid = :uid2',
            ['uid1' => $userid, 'uid2' => $userid]
        );
        $hashooks = $DB->record_exists('local_webhookengine_hook', ['createdby' => $userid]);

        if ($hasdeliveries || $hashooks) {
            $contextlist->add_from_sql(
                "SELECT id FROM {context} WHERE id = :contextid",
                ['contextid' => $systemcontext->id]
            );
        }

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add users to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }

        $sql = "SELECT userid FROM {local_webhookengine_delivery} WHERE userid > 0
                UNION
                SELECT relateduserid AS userid FROM {local_webhookengine_delivery} WHERE relateduserid > 0
                UNION
                SELECT createdby AS userid FROM {local_webhookengine_hook} WHERE createdby > 0";
        $userlist->add_from_sql('userid', $sql, []);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        $systemcontext = \context_system::instance();

        if (!in_array($systemcontext->id, $contextlist->get_contextids())) {
            return;
        }

        // Export deliveries.
        $deliveries = $DB->get_records_select(
            'local_webhookengine_delivery',
            'userid = :uid1 OR relateduserid = :uid2',
            ['uid1' => $user->id, 'uid2' => $user->id],
            'timecreated DESC'
        );

        $exportdeliveries = [];
        foreach ($deliveries as $d) {
            $exportdeliveries[] = (object) [
                'uuid' => $d->uuid,
                'eventname' => $d->eventname,
                'status' => $d->status,
                'httpstatus' => $d->httpstatus,
                'timecreated' => transform::datetime($d->timecreated),
            ];
        }

        if (!empty($exportdeliveries)) {
            writer::with_context($systemcontext)->export_data(
                [get_string('pluginname', 'local_webhookengine'), get_string('deliveries', 'local_webhookengine')],
                (object) ['deliveries' => $exportdeliveries]
            );
        }

        // Export hooks created.
        $hooks = $DB->get_records('local_webhookengine_hook', ['createdby' => $user->id], 'timecreated DESC');
        $exporthooks = [];
        foreach ($hooks as $h) {
            $exporthooks[] = (object) [
                'name' => $h->name,
                'url' => $h->url,
                'status' => $h->status,
                'timecreated' => transform::datetime($h->timecreated),
            ];
        }

        if (!empty($exporthooks)) {
            writer::with_context($systemcontext)->export_data(
                [get_string('pluginname', 'local_webhookengine'), get_string('hooks', 'local_webhookengine')],
                (object) ['hooks' => $exporthooks]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_system) {
            global $DB;
            $DB->delete_records('local_webhookengine_delivery');
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        $systemcontext = \context_system::instance();

        if (!in_array($systemcontext->id, $contextlist->get_contextids())) {
            return;
        }

        $DB->delete_records_select(
            'local_webhookengine_delivery',
            'userid = :uid1 OR relateduserid = :uid2',
            ['uid1' => $user->id, 'uid2' => $user->id]
        );

        $DB->set_field('local_webhookengine_hook', 'createdby', 0, ['createdby' => $user->id]);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql1, $params1] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u1');
        [$insql2, $params2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u2');

        $params = array_merge($params1, $params2);
        $DB->delete_records_select(
            'local_webhookengine_delivery',
            "userid $insql1 OR relateduserid $insql2",
            $params
        );

        [$increated, $paramscreated] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'c1');
        $DB->execute("UPDATE {local_webhookengine_hook} SET createdby = 0 WHERE createdby $increated", $paramscreated);
    }
}
