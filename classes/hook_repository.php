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
     * Encrypt sensitive string for database storage.
     *
     * @param string $data Plain text.
     * @return string Encrypted string.
     */
    public static function encrypt_secret(string $data): string {
        global $CFG;
        if (class_exists('\core\encryption') && \core\encryption::is_sodium_installed() && \core\encryption::key_exists()) {
            return \core\encryption::encrypt($data);
        }
        $key = substr(hash('sha256', $CFG->passwordsaltmain ?? $CFG->dbname, true), 0, 32);
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return 'enc:' . base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt sensitive string from database storage.
     *
     * @param string $ciphertext Encrypted string.
     * @return string|false Plain text, or false on decryption failure.
     */
    public static function decrypt_secret(string $ciphertext) {
        global $CFG;
        if (str_starts_with($ciphertext, 'enc:')) {
            $raw = base64_decode(substr($ciphertext, 4), true);
            if ($raw !== false && strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $cipher = substr($raw, 16);
                $key = substr(hash('sha256', $CFG->passwordsaltmain ?? $CFG->dbname, true), 0, 32);
                $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
                if ($plain !== false) {
                    return $plain;
                }
            }
        }
        if (class_exists('\core\encryption') && \core\encryption::is_sodium_installed() && \core\encryption::key_exists()) {
            try {
                return \core\encryption::decrypt($ciphertext);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return $ciphertext;
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
     * Invalidate event map cache.
     */
    public static function invalidate_event_map(): void {
        $cache = \cache::make('local_webhookengine', 'eventmap');
        $cache->purge();
    }

    /**
     * Rebuild and return cached event map (eventname => array of hook IDs).
     *
     * @return array Mapping of event name to list of hook IDs.
     */
    public static function get_event_map(): array {
        $cache = \cache::make('local_webhookengine', 'eventmap');
        $cached = $cache->get('map');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        global $DB;
        $records = $DB->get_records('local_webhookengine_hook', ['enabled' => 1, 'status' => 'active']);
        $map = [];

        foreach ($records as $hook) {
            $events = json_decode($hook->events ?? '[]', true);
            if (is_array($events)) {
                foreach ($events as $eventname) {
                    $eventname = trim($eventname);
                    if (!empty($eventname)) {
                        $map[$eventname][] = (int) $hook->id;
                    }
                }
            }
        }

        $cache->set('map', $map);
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

        $record = (object) [
            'name' => $data->name,
            'url' => $data->url,
            'secret' => self::encrypt_secret($rawsecret),
            'enabled' => !empty($data->enabled) ? 1 : 0,
            'status' => 'active',
            'events' => is_string($data->events) ? $data->events : json_encode(array_values($data->events)),
            'filters' => is_string($data->filters) ? $data->filters : json_encode($data->filters),
            'payloadopts' => is_string($data->payloadopts) ? $data->payloadopts : json_encode($data->payloadopts),
            'headers' => (isset($data->headers) && is_array($data->headers)) ? self::encrypt_headers($data->headers) : ($data->headers ?? null),
            'timeout' => $data->timeout ?? 10,
            'maxattempts' => $data->maxattempts ?? 9,
            'failstreak' => 0,
            'lastsuccess' => null,
            'lastfailure' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => (int) ($USER->id ?? 0),
        ];

        $id = $DB->insert_record('local_webhookengine_hook', $record);
        self::invalidate_event_map();
        return $id;
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
        $now = time();

        $record = (object) [
            'id' => $id,
            'name' => $data->name,
            'url' => $data->url,
            'enabled' => !empty($data->enabled) ? 1 : 0,
            'events' => is_string($data->events) ? $data->events : json_encode(array_values($data->events)),
            'filters' => is_string($data->filters) ? $data->filters : json_encode($data->filters),
            'payloadopts' => is_string($data->payloadopts) ? $data->payloadopts : json_encode($data->payloadopts),
            'timeout' => $data->timeout ?? 10,
            'maxattempts' => $data->maxattempts ?? 9,
            'timemodified' => $now,
        ];

        if (isset($data->status)) {
            $record->status = $data->status;
        }

        if (isset($data->headers)) {
            $record->headers = is_array($data->headers) ? self::encrypt_headers($data->headers) : $data->headers;
        }

        $res = $DB->update_record('local_webhookengine_hook', $record);
        self::invalidate_event_map();
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
        $rawsecret = self::generate_raw_secret();
        $encrypted = self::encrypt_secret($rawsecret);

        $update = (object) [
            'id' => $id,
            'secret' => $encrypted,
            'timemodified' => time(),
        ];
        $DB->update_record('local_webhookengine_hook', $update);
        return $rawsecret;
    }

    /**
     * Delete a hook and associated deliveries.
     *
     * @param int $id Hook ID.
     * @return bool True on success.
     */
    public static function delete_hook(int $id): bool {
        global $DB;
        $DB->delete_records('local_webhookengine_delivery', ['hookid' => $id]);
        $res = $DB->delete_records('local_webhookengine_hook', ['id' => $id]);
        self::invalidate_event_map();
        return $res;
    }
}
