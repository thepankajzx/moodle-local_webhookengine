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
 * Data repository for webhook destination configurations.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_repository {
    /** @var string Hook active status. */
    public const STATUS_ACTIVE = 'active';

    /** @var string Hook paused status. */
    public const STATUS_PAUSED = 'paused';

    /** @var string Hook deleted status. */
    public const STATUS_DELETED = 'deleted';

    /** @var array In-process buffer for dropped events: ["hookid:reason:minutetime" => count]. */
    private static array $dropbuffer = [];

    /** @var bool Whether shutdown flush function has been registered. */
    private static bool $shutdownregistered = false;

    /**
     * Generate 32 cryptographically secure random bytes for signing secret.
     *
     * @return string Raw secret bytes.
     */
    public static function generate_raw_secret(): string {
        return random_bytes(32);
    }

    /**
     * Format raw secret bytes into user-facing Standard Webhooks format: whsec_<base64>.
     *
     * @param string $rawsecret Raw binary secret.
     * @return string Formatted secret string.
     */
    public static function format_whsec(string $rawsecret): string {
        return 'whsec_' . base64_encode($rawsecret);
    }

    /**
     * Encrypt sensitive string for database storage using core encryption.
     *
     * @param string $data Plain text.
     * @return string Encrypted string.
     */
    public static function encrypt_secret(string $data): string {
        return \core\encryption::encrypt($data);
    }

    /**
     * Decrypt sensitive string from database storage using core encryption.
     *
     * @param string $ciphertext Encrypted string.
     * @return string|false Plain text, or false on decryption failure.
     */
    public static function decrypt_secret(string $ciphertext) {
        try {
            return \core\encryption::decrypt($ciphertext);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Encrypt custom headers array.
     *
     * @param array $headers Associative array of headers.
     * @return string Encrypted JSON string.
     */
    public static function encrypt_headers(array $headers): string {
        $json = json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::encrypt_secret($json);
    }

    /**
     * Decrypt custom headers array.
     *
     * @param string $encryptedheaders Encrypted JSON string.
     * @return array|null Associative array of headers or null.
     */
    public static function decrypt_headers(string $encryptedheaders): ?array {
        $json = self::decrypt_secret($encryptedheaders);
        if ($json === false || empty($json)) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Normalize an event name to ensure it has a leading backslash.
     *
     * @param string $eventname Event class name.
     * @return string Normalized event name with a leading backslash.
     */
    public static function normalize_event_name(string $eventname): string {
        $trimmed = trim($eventname);
        if ($trimmed === '') {
            return '';
        }
        return '\\' . ltrim($trimmed, '\\');
    }

    /**
     * Encode an array or JSON string of event names into a normalized JSON array.
     *
     * @param array|string $events Event names array or JSON string.
     * @return string Encoded JSON array of normalized event names.
     */
    public static function encode_events_json($events): string {
        if (is_string($events)) {
            $decoded = json_decode($events, true);
            if (is_array($decoded)) {
                $events = $decoded;
            } else {
                return $events;
            }
        }
        $normalized = [];
        if (is_array($events)) {
            foreach ($events as $ev) {
                $norm = self::normalize_event_name((string) $ev);
                if ($norm !== '') {
                    $normalized[] = $norm;
                }
            }
        }
        return json_encode(array_values(array_unique($normalized)));
    }

    /**
     * Invalidate event map cache.
     */
    public static function invalidate_event_map(): void {
        $cache = \cache::make('local_webhookengine', 'eventmap');
        $cache->purge();
    }

    /**
     * Rebuild and return cached event map (eventname => array of hook entry arrays).
     *
     * Each entry contains ['id' => int, 'status' => string ('active'|'paused')].
     *
     * @return array Mapping of event name to list of hook entry arrays.
     */
    public static function get_event_map(): array {
        $cache = \cache::make('local_webhookengine', 'eventmap');
        $cached = $cache->get('map_v2');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        global $DB;
        $records = $DB->get_records_select(
            'local_webhookengine_hook',
            "enabled = 1 AND status IN ('active', 'paused')"
        );
        $map = [];

        foreach ($records as $hook) {
            $events = json_decode($hook->events ?? '[]', true);
            if (is_array($events)) {
                foreach ($events as $eventname) {
                    $eventname = self::normalize_event_name((string) $eventname);
                    if ($eventname !== '') {
                        $map[$eventname][] = [
                            'id' => (int) $hook->id,
                            'status' => (string) $hook->status,
                        ];
                    }
                }
            }
        }

        $cache->set('map_v2', $map);
        return $map;
    }

    /**
     * Get hook by ID with decoded options.
     *
     * @param int $id Hook ID.
     * @return object|null Hook record or null.
     */
    public static function get_hook(int $id): ?object {
        global $DB;
        $record = $DB->get_record('local_webhookengine_hook', ['id' => $id]);
        if (!$record) {
            return null;
        }
        $record->filters = json_decode($record->filters ?? '[]', true) ?: [];
        $record->payloadopts = json_decode($record->payloadopts ?? '[]', true) ?: [];
        return $record;
    }

    /**
     * Create a new hook.
     *
     * @param object $data Form data object.
     * @param string $rawsecret Binary secret.
     * @return int Created hook ID.
     */
    public static function create_hook(object $data, string $rawsecret): int {
        global $DB, $USER;
        $now = time();

        $headersval = $data->headers ?? null;
        if (isset($data->headers) && is_array($data->headers)) {
            $headersval = self::encrypt_headers($data->headers);
        }

        $record = (object) [
            'name' => $data->name,
            'url' => $data->url,
            'secret' => self::encrypt_secret($rawsecret),
            'enabled' => !empty($data->enabled) ? 1 : 0,
            'status' => 'active',
            'events' => self::encode_events_json($data->events ?? []),
            'filters' => is_string($data->filters) ? $data->filters : json_encode($data->filters),
            'payloadopts' => is_string($data->payloadopts) ? $data->payloadopts : json_encode($data->payloadopts),
            'headers' => $headersval,
            'timeout' => $data->timeout ?? 10,
            'maxattempts' => $data->maxattempts ?? 9,
            'usemtls' => !empty($data->usemtls) ? 1 : 0,
            'authmode' => !empty($data->authmode) ? (string) $data->authmode : 'standard',
            'oauth2tokenurl' => !empty($data->oauth2tokenurl) ? (string) $data->oauth2tokenurl : null,
            'oauth2clientid' => !empty($data->oauth2clientid) ? (string) $data->oauth2clientid : null,
            'oauth2clientsecret' => !empty($data->oauth2clientsecret) ? self::encrypt_secret($data->oauth2clientsecret) : null,
            'oauth2scope' => !empty($data->oauth2scope) ? (string) $data->oauth2scope : null,
            'failstreak' => 0,
            'lastsuccess' => null,
            'lastfailure' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => (int) ($USER->id ?? 0),
        ];

        $id = $DB->insert_record('local_webhookengine_hook', $record);
        self::invalidate_event_map();

        $event = \local_webhookengine\event\hook_created::create([
            'context' => \context_system::instance(),
            'objectid' => $id,
            'other' => [
                'name' => $record->name,
                'url' => $record->url,
            ],
        ]);
        $event->trigger();

        return $id;
    }

    /**
     * Get list of active and non-deleted hooks.
     *
     * @param bool $enabledonly If true, only return enabled active hooks.
     * @return array List of hook records indexed by ID.
     */
    public static function get_hooks(bool $enabledonly = false): array {
        global $DB;
        if ($enabledonly) {
            return $DB->get_records_select(
                'local_webhookengine_hook',
                "enabled = 1 AND status = 'active'",
                null,
                'id ASC'
            );
        }
        return $DB->get_records_select(
            'local_webhookengine_hook',
            "status != 'deleted'",
            null,
            'id ASC'
        );
    }

    /**
     * Update an existing hook.
     *
     * @param int $id Hook ID.
     * @param object $data Form data object.
     * @return bool True on success.
     */
    public static function update_hook(int $id, object $data): bool {
        global $DB;
        $before = $DB->get_record('local_webhookengine_hook', ['id' => $id]);
        if (!$before) {
            return false;
        }

        $now = time();

        $record = (object) [
            'id' => $id,
            'name' => $data->name,
            'url' => $data->url,
            'enabled' => !empty($data->enabled) ? 1 : 0,
            'events' => self::encode_events_json($data->events ?? []),
            'filters' => is_string($data->filters) ? $data->filters : json_encode($data->filters),
            'payloadopts' => is_string($data->payloadopts) ? $data->payloadopts : json_encode($data->payloadopts),
            'timeout' => $data->timeout ?? 10,
            'maxattempts' => $data->maxattempts ?? 9,
            'timemodified' => $now,
        ];

        if (isset($data->usemtls)) {
            $record->usemtls = !empty($data->usemtls) ? 1 : 0;
        }
        if (isset($data->authmode)) {
            $record->authmode = (string) $data->authmode;
        }
        if (isset($data->oauth2tokenurl)) {
            $record->oauth2tokenurl = $data->oauth2tokenurl;
        }
        if (isset($data->oauth2clientid)) {
            $record->oauth2clientid = $data->oauth2clientid;
        }
        if (isset($data->oauth2scope)) {
            $record->oauth2scope = $data->oauth2scope;
        }
        if (!empty($data->oauth2clientsecret)) {
            $record->oauth2clientsecret = self::encrypt_secret($data->oauth2clientsecret);
        }

        if (isset($data->status)) {
            $record->status = $data->status;
        }

        if (isset($data->clearheaders) && !empty($data->clearheaders)) {
            $record->headers = null;
        } else if (property_exists($data, 'headers') && is_array($data->headers)) {
            $record->headers = !empty($data->headers) ? self::encrypt_headers($data->headers) : null;
        }

        $res = $DB->update_record('local_webhookengine_hook', $record);
        self::invalidate_event_map();

        if ($res) {
            $after = $DB->get_record('local_webhookengine_hook', ['id' => $id]);
            if ($after) {
                $fields = [
                    'name',
                    'url',
                    'enabled',
                    'status',
                    'events',
                    'filters',
                    'payloadopts',
                    'timeout',
                    'maxattempts',
                    'usemtls',
                    'authmode',
                    'oauth2tokenurl',
                    'oauth2clientid',
                    'oauth2scope',
                ];
                $changes = [];
                foreach ($fields as $f) {
                    if ((string) ($before->$f ?? '') !== (string) ($after->$f ?? '')) {
                        $changes[$f] = [
                            'before' => $before->$f,
                            'after' => $after->$f,
                        ];
                    }
                }
                if (!empty($changes)) {
                    $event = \local_webhookengine\event\hook_updated::create([
                        'context' => \context_system::instance(),
                        'objectid' => $id,
                        'other' => [
                            'changes' => $changes,
                        ],
                    ]);
                    $event->trigger();
                }
            }
        }

        return $res;
    }

    /**
     * Set hook status directly without modifying credentials or configuration.
     *
     * @param int $id Hook ID.
     * @param string $status Target status ('active', 'paused').
     * @return bool True on success.
     * @throws \coding_exception If status is invalid.
     */
    public static function set_hook_status(int $id, string $status): bool {
        global $DB;
        if (!in_array($status, ['active', 'paused'])) {
            throw new \coding_exception('Invalid webhook status: ' . s($status));
        }
        $beforestatus = $DB->get_field('local_webhookengine_hook', 'status', ['id' => $id]);
        $now = time();
        $record = (object) [
            'id' => $id,
            'status' => $status,
            'timemodified' => $now,
        ];
        $res = $DB->update_record('local_webhookengine_hook', $record);
        self::invalidate_event_map();

        if ($res && $beforestatus !== $status) {
            $event = \local_webhookengine\event\hook_updated::create([
                'context' => \context_system::instance(),
                'objectid' => $id,
                'other' => [
                    'changes' => [
                        'status' => [
                            'before' => $beforestatus,
                            'after' => $status,
                        ],
                    ],
                ],
            ]);
            $event->trigger();
        }

        return $res;
    }

    /**
     * Rotate signing secret for a hook.
     *
     * @param int $id Hook ID.
     * @return string New raw binary secret.
     */
    public static function rotate_secret(int $id): string {
        global $DB;
        $hook = $DB->get_record('local_webhookengine_hook', ['id' => $id]);
        if (!$hook) {
            throw new \coding_exception('Hook not found for secret rotation: ' . $id);
        }

        $rawsecret = self::generate_raw_secret();
        $encrypted = self::encrypt_secret($rawsecret);
        $now = time();

        $update = (object) [
            'id' => $id,
            'prevsecret' => $hook->secret,
            'secretrotatedat' => $now,
            'secret' => $encrypted,
            'timemodified' => $now,
        ];
        $DB->update_record('local_webhookengine_hook', $update);
        return $rawsecret;
    }

    /**
     * Soft-delete a hook, disabling it while preserving delivery and audit history.
     *
     * @param int $id Hook ID.
     * @return bool True on success.
     */
    public static function delete_hook(int $id): bool {
        global $DB;
        $now = time();
        $record = (object) [
            'id' => $id,
            'status' => 'deleted',
            'enabled' => 0,
            'timemodified' => $now,
        ];
        $res = $DB->update_record('local_webhookengine_hook', $record);
        self::invalidate_event_map();
        return $res;
    }

    /**
     * Record dropped events in the aggregated drop ledger.
     *
     * Buffers drop counts in-memory to eliminate database writes on the hot event path.
     * Drops are aggregated and flushed to the database on process shutdown or batch threshold.
     *
     * @param int $hookid Hook database ID.
     * @param string $reason Reason code from local_webhookengine\reasons.
     * @param int $count Number of events dropped.
     */
    public static function record_drop(int $hookid, string $reason, int $count = 1): void {
        if ($hookid <= 0 || $count <= 0) {
            return;
        }

        $minutetime = (int) floor(time() / 60) * 60;
        $key = $hookid . ':' . $reason . ':' . $minutetime;

        self::$dropbuffer[$key] = (self::$dropbuffer[$key] ?? 0) + $count;

        if (!self::$shutdownregistered) {
            self::$shutdownregistered = true;
            register_shutdown_function([self::class, 'flush_drop_buffer']);
        }

        // Flush immediately if buffer exceeds 50 distinct bucket keys.
        if (count(self::$dropbuffer) >= 50) {
            self::flush_drop_buffer();
        }
    }

    /**
     * Flush in-process drop counts to the persistent drop ledger database table.
     *
     * Uses atomic slot-based sharding (slots 0-7) to prevent DB row lock contention.
     */
    public static function flush_drop_buffer(): void {
        global $DB;

        if (empty(self::$dropbuffer)) {
            return;
        }

        $pending = self::$dropbuffer;
        self::$dropbuffer = [];
        $now = time();

        foreach ($pending as $key => $dropcount) {
            $parts = explode(':', $key);
            if (count($parts) !== 3) {
                continue;
            }
            $hookid = (int) $parts[0];
            $reason = (string) $parts[1];
            $minutetime = (int) $parts[2];
            $slot = random_int(0, 7);

            try {
                $record = (object) [
                    'hookid' => $hookid,
                    'reason' => $reason,
                    'minutetime' => $minutetime,
                    'slot' => $slot,
                    'dropcount' => $dropcount,
                    'timemodified' => $now,
                ];
                $DB->insert_record('local_webhookengine_drop_ledger', $record);
            } catch (\dml_exception $e) {
                // Row already exists for (hookid, reason, minutetime, slot), atomically increment.
                try {
                    $sql = "UPDATE {local_webhookengine_drop_ledger}
                               SET dropcount = dropcount + :inc,
                                   timemodified = :now
                             WHERE hookid = :hookid
                               AND reason = :reason
                               AND minutetime = :minutetime
                               AND slot = :slot";
                    $DB->execute($sql, [
                        'inc' => $dropcount,
                        'now' => $now,
                        'hookid' => $hookid,
                        'reason' => $reason,
                        'minutetime' => $minutetime,
                        'slot' => $slot,
                    ]);
                } catch (\Throwable $ex) {
                    debugging('local_webhookengine: failed to update drop ledger: ' . $ex->getMessage(), DEBUG_DEVELOPER);
                }
            } catch (\Throwable $e) {
                debugging('local_webhookengine: failed to flush drop ledger: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Get aggregated drop count summary grouped by reason for a specific hook.
     *
     * @param int $hookid Hook ID.
     * @param int $since Minimum minutetime timestamp (0 for all time).
     * @return array Associative array of reason => total count.
     */
    public static function get_hook_drop_summary(int $hookid, int $since = 0): array {
        global $DB;

        self::flush_drop_buffer();

        $sql = "SELECT reason, SUM(dropcount) AS total
                  FROM {local_webhookengine_drop_ledger}
                 WHERE hookid = :hookid AND minutetime >= :since
              GROUP BY reason";
        $records = $DB->get_records_sql($sql, ['hookid' => $hookid, 'since' => $since]);
        $summary = [];
        foreach ($records as $r) {
            $summary[$r->reason] = (int) $r->total;
        }
        return $summary;
    }

    /**
     * Get total dropped events for a specific hook.
     *
     * @param int $hookid Hook ID.
     * @param int $since Minimum minutetime timestamp (0 for all time).
     * @return int Total drops.
     */
    public static function get_hook_total_drops(int $hookid, int $since = 0): int {
        global $DB;

        self::flush_drop_buffer();

        $sql = "SELECT SUM(dropcount) AS total
                  FROM {local_webhookengine_drop_ledger}
                 WHERE hookid = :hookid AND minutetime >= :since";
        $total = $DB->get_field_sql($sql, ['hookid' => $hookid, 'since' => $since]);
        return (int) $total;
    }

    /**
     * Get drop count for a specific hook and reason.
     *
     * @param int $hookid Hook ID.
     * @param string $reason Reason code.
     * @param int $since Minimum minutetime timestamp (0 for all time).
     * @return int Drop count for this reason.
     */
    public static function get_hook_drop_count_by_reason(int $hookid, string $reason, int $since = 0): int {
        global $DB;

        self::flush_drop_buffer();

        $sql = "SELECT SUM(dropcount) AS total
                  FROM {local_webhookengine_drop_ledger}
                 WHERE hookid = :hookid AND reason = :reason AND minutetime >= :since";
        $total = $DB->get_field_sql($sql, ['hookid' => $hookid, 'reason' => $reason, 'since' => $since]);
        return (int) $total;
    }

    /**
     * Get total drop counts for all hooks in a single grouped query.
     *
     * @return array Associative array of hookid => total drops.
     */
    public static function get_all_hooks_total_drops(): array {
        global $DB;

        self::flush_drop_buffer();

        $sql = "SELECT hookid, SUM(dropcount) AS total
                  FROM {local_webhookengine_drop_ledger}
              GROUP BY hookid";
        $records = $DB->get_records_sql($sql);
        $result = [];
        foreach ($records as $r) {
            $result[(int) $r->hookid] = (int) $r->total;
        }
        return $result;
    }
}
