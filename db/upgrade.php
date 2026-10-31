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
 * Upgrade logic for local_webhookengine.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_webhookengine upgrade from the given old version.
 *
 * @param int $oldversion Old installed plugin version.
 * @return bool True on success.
 */
function xmldb_local_webhookengine_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102800) {
        $table = new xmldb_table('local_webhookengine_delivery');

        // Composite index on status and timecreated for high-volume delivery log filtering.
        $index = new xmldb_index('status_timecreated', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Single index on timecreated for date-based purge and chronological sorting.
        $index2 = new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->index_exists($table, $index2)) {
            $dbman->add_index($table, $index2);
        }

        upgrade_plugin_savepoint(true, 2026102800, 'local', 'webhookengine');
    }

    if ($oldversion < 2026110100) {
        $table = new xmldb_table('local_webhookengine_drop_ledger');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('hookid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('minutetime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slot', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('dropcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('hook_reason_minute_slot', XMLDB_INDEX_UNIQUE, ['hookid', 'reason', 'minutetime', 'slot']);
        $table->add_index('minutetime', XMLDB_INDEX_NOTUNIQUE, ['minutetime']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Purge eventmap cache for map_v2 transition.
        try {
            $cache = \cache::make('local_webhookengine', 'eventmap');
            $cache->purge();
        } catch (\Throwable $e) {
            unset($e);
        }

        upgrade_plugin_savepoint(true, 2026110100, 'local', 'webhookengine');
    }

    return true;
}
