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
 * Filter evaluator for matching events against configured hook criteria.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_matcher {
    /**
     * Determines whether an event satisfies the hook's filter rules.
     * Overloaded signature supports ($filters, $courseid, $userid) and ($event, $filters).
     *
     * @param mixed $first Either array of filters or \core\event\base object.
     * @param mixed $second Either course ID int or array of filters.
     * @param int|null $third User ID int if first param is filters array.
     * @return bool True if matched, false otherwise.
     */
    public static function matches($first, $second = null, ?int $third = null): bool {
        if ($first instanceof \core\event\base) {
            $event = $first;
            $filters = (array) $second;
            $courseid = (int) ($event->courseid ?? 0);
            $userid = (int) ($event->userid ?? 0);
        } else {
            $filters = (array) $first;
            $courseid = (int) ($second ?? 0);
            $userid = (int) ($third ?? 0);
        }

        // 1. Real users only filter.
        if (!empty($filters['realusersonly'])) {
            if ($userid <= 0 || ($userid == 1 && isguestuser($userid))) {
                return false;
            }
        }

        // 2. Course ID filter.
        if (!empty($filters['courseids']) && is_array($filters['courseids'])) {
            if ($courseid <= 0 || !in_array($courseid, $filters['courseids'])) {
                return false;
            }
        }

        // 3. Course category filter.
        if (!empty($filters['categoryids']) && is_array($filters['categoryids'])) {
            if ($courseid <= 0) {
                return false;
            }
            $catid = self::get_course_category_id($courseid);
            if ($catid === null) {
                return false;
            }

            if (!empty($filters['includesubcats'])) {
                $categorypath = self::get_category_path($catid);
                $matched = false;
                foreach ($filters['categoryids'] as $allowedcat) {
                    if (in_array((int) $allowedcat, $categorypath)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            } else {
                if (!in_array($catid, $filters['categoryids'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Returns the category ID for a course with static caching.
     *
     * @param int $courseid Course ID.
     * @return int|null Category ID or null if not found.
     */
    protected static function get_course_category_id(int $courseid): ?int {
        global $DB;
        static $cache = [];
        if (isset($cache[$courseid])) {
            return $cache[$courseid];
        }
        $catid = $DB->get_field('course', 'category', ['id' => $courseid]);
        $cache[$courseid] = ($catid !== false) ? (int) $catid : null;
        return $cache[$courseid];
    }

    /**
     * Returns the list of parent category IDs including the category itself.
     *
     * @param int $categoryid Category ID.
     * @return array List of category IDs in hierarchy path.
     */
    protected static function get_category_path(int $categoryid): array {
        global $DB;
        static $pathcache = [];
        if (isset($pathcache[$categoryid])) {
            return $pathcache[$categoryid];
        }

        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if ($path === false || empty($path)) {
            $pathcache[$categoryid] = [$categoryid];
            return $pathcache[$categoryid];
        }

        $parts = array_filter(explode('/', $path));
        $ids = array_map('intval', $parts);
        $pathcache[$categoryid] = $ids;
        return $ids;
    }
}
