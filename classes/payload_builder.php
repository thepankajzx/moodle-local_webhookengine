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
 * Payload construction, enrichment, and custom JSON template rendering service.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class payload_builder {
    /**
     * Extracts dot-separated event type from fully-qualified class name.
     *
     * @param string $eventname Full event class name.
     * @return string Normalized event type.
     */
    public static function extract_event_type(string $eventname): string {
        $trimmed = ltrim($eventname, '\\');
        $parts = explode('\\', $trimmed);
        if (count($parts) >= 3 && $parts[1] === 'event') {
            return $parts[0] . '.' . $parts[2];
        }
        return str_replace('\\', '.', $trimmed);
    }

    /**
     * Builds standard JSON envelope (Schema 1) from raw event data.
     *
     * @param string $uuid Delivery UUID.
     * @param array $rawdata Event get_data() array.
     * @param array $payloadopts Hook payload options.
     * @return string Valid JSON string.
     */
    public static function render_envelope(string $uuid, array $rawdata, array $payloadopts = []): string {
        global $CFG;

        $component = $rawdata['component'] ?? 'core';
        $action = $rawdata['action'] ?? 'unknown';
        $target = $rawdata['target'] ?? 'unknown';
        $eventname = $rawdata['eventname'] ?? '';
        $type = self::extract_event_type($eventname);

        $eventobj = [
            'component' => $component,
            'action' => $action,
            'target' => $target,
            'objectid' => $rawdata['objectid'] ?? null,
            'crud' => $rawdata['crud'] ?? 'r',
            'edulevel' => $rawdata['edulevel'] ?? 0,
            'contextid' => $rawdata['contextid'] ?? 0,
            'contextlevel' => $rawdata['contextlevel'] ?? 0,
            'contextinstanceid' => $rawdata['contextinstanceid'] ?? 0,
            'courseid' => $rawdata['courseid'] ?? 0,
            'userid' => $rawdata['userid'] ?? null,
            'relateduserid' => $rawdata['relateduserid'] ?? null,
            'timecreated' => $rawdata['timecreated'] ?? time(),
        ];

        // 1. Opt-in: includeother.
        if (!empty($payloadopts['includeother']) && isset($rawdata['other']) && is_array($rawdata['other'])) {
            if (!empty($payloadopts['otherkeys']) && is_array($payloadopts['otherkeys'])) {
                $filteredother = [];
                foreach ($payloadopts['otherkeys'] as $k) {
                    if (array_key_exists($k, $rawdata['other'])) {
                        $filteredother[$k] = $rawdata['other'][$k];
                    }
                }
                $eventobj['other'] = $filteredother;
            } else {
                $eventobj['other'] = $rawdata['other'];
            }
        }

        $envelope = [
            'id' => $uuid,
            'schema' => 1,
            'type' => $type,
            'eventname' => $eventname,
            'created' => $rawdata['timecreated'] ?? time(),
            'site' => ['url' => $CFG->wwwroot],
            'event' => $eventobj,
        ];

        // 2. Opt-in: includeuser.
        if (!empty($payloadopts['includeuser'])) {
            $userid = (int) ($rawdata['userid'] ?? 0);
            if ($userid > 0) {
                $envelope['user'] = self::get_user_summary($userid, !empty($payloadopts['includeemail']));
            }
            $reluserid = (int) ($rawdata['relateduserid'] ?? 0);
            if ($reluserid > 0 && $reluserid !== $userid) {
                $envelope['relateduser'] = self::get_user_summary($reluserid, !empty($payloadopts['includeemail']));
            }
        }

        // 3. Opt-in: includecourse.
        if (!empty($payloadopts['includecourse'])) {
            $courseid = (int) ($rawdata['courseid'] ?? 0);
            if ($courseid > 0) {
                $envelope['course'] = self::get_course_summary($courseid);
            }
        }

        // 4. Custom JSON template if specified.
        if (!empty($payloadopts['template'])) {
            return self::render_custom_template($payloadopts['template'], $envelope);
        }

        return json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Alias for render_envelope for backwards compatibility.
     *
     * @param string $uuid Delivery UUID.
     * @param array $rawdata Event get_data() array.
     * @param array $payloadopts Hook payload options.
     * @return string Valid JSON string.
     */
    public static function build(string $uuid, array $rawdata, array $payloadopts = []): string {
        return self::render_envelope($uuid, $rawdata, $payloadopts);
    }

    /**
     * Extracts user summary object with caching.
     *
     * @param int $userid User ID.
     * @param bool $includeemail Whether to include email address.
     * @return array User summary array.
     */
    protected static function get_user_summary(int $userid, bool $includeemail): array {
        global $DB;
        static $usercache = [];
        if (!isset($usercache[$userid])) {
            $u = $DB->get_record('user', ['id' => $userid], 'id, username, firstname, lastname, email, deleted');
            $usercache[$userid] = $u ?: null;
        }

        $u = $usercache[$userid];
        if (!$u || !empty($u->deleted)) {
            return ['id' => $userid, 'deleted' => true];
        }

        $res = [
            'id' => (int) $u->id,
            'username' => $u->username,
            'firstname' => $u->firstname,
            'lastname' => $u->lastname,
        ];
        if ($includeemail) {
            $res['email'] = $u->email;
        }
        return $res;
    }

    /**
     * Extracts course summary object with caching.
     *
     * @param int $courseid Course ID.
     * @return array Course summary array.
     */
    protected static function get_course_summary(int $courseid): array {
        global $DB;
        static $coursecache = [];
        if (!isset($coursecache[$courseid])) {
            $c = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname, category');
            $coursecache[$courseid] = $c ?: null;
        }

        $c = $coursecache[$courseid];
        if (!$c) {
            return ['id' => $courseid];
        }

        return [
            'id' => (int) $c->id,
            'shortname' => $c->shortname,
            'fullname' => $c->fullname,
            'categoryid' => (int) $c->category,
        ];
    }

    /**
     * Renders a custom JSON template with safe parameter replacement.
     *
     * @param string $template Custom template JSON string.
     * @param array $envelope Envelope data array.
     * @return string Rendered valid JSON string.
     */
    public static function render_custom_template(string $template, array $envelope): string {
        $flat = self::flatten_array($envelope);
        $result = $template;

        foreach ($flat as $k => $v) {
            $tag = '{{' . $k . '}}';
            if (str_contains($result, $tag)) {
                $encoded = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $result = str_replace($tag, $encoded, $result);
            }
        }

        // Validate final template is valid JSON.
        $decoded = @json_decode($result, true);
        if ($decoded === null) {
            return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    /**
     * Validates a custom template at save time.
     *
     * @param string $template Custom template string.
     * @return string|null Error message string or null if valid.
     */
    public static function validate_template(string $template): ?string {
        if (!preg_match_all('/\{\{([a-zA-Z0-9_.]+)\}\}/', $template, $matches)) {
            $test = json_decode($template, true);
            return ($test !== null) ? null : get_string('error_invalid_json_template', 'local_webhookengine');
        }

        $allowedroots = ['event', 'user', 'relateduser', 'course', 'site', 'id', 'type', 'created', 'schema'];
        foreach ($matches[1] as $path) {
            $root = explode('.', $path)[0];
            if (!in_array($root, $allowedroots)) {
                return get_string('error_unknown_placeholder', 'local_webhookengine', s($path));
            }
        }

        // Render with sample mock data and test JSON decoding.
        $sample = [
            'id' => '00000000-0000-0000-0000-000000000000',
            'schema' => 1,
            'type' => 'sample.event',
            'created' => time(),
            'site' => ['url' => 'https://example.com'],
            'event' => ['objectid' => 1, 'courseid' => 1, 'userid' => 1, 'action' => 'test'],
            'user' => ['id' => 1, 'firstname' => 'Test', 'lastname' => 'User', 'email' => 'test@example.com'],
            'course' => ['id' => 1, 'shortname' => 'TST', 'fullname' => 'Test Course'],
        ];

        $rendered = self::render_custom_template($template, $sample);
        $decoded = @json_decode($rendered, true);
        if ($decoded === null) {
            return get_string('error_invalid_json_template', 'local_webhookengine');
        }

        return null;
    }

    /**
     * Flattens a nested associative array into dot notation.
     *
     * @param array $array Input array.
     * @param string $prefix Prefix for keys.
     * @return array Flattened map.
     */
    protected static function flatten_array(array $array, string $prefix = ''): array {
        $result = [];
        foreach ($array as $key => $value) {
            $newkey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $result = array_merge($result, self::flatten_array($value, $newkey));
            } else {
                $result[$newkey] = $value;
            }
        }
        return $result;
    }
}
