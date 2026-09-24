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
 * Event catalog provider to discover and list Moodle events for admin selection.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_catalog {
    /** @var array|null Cached list of popular/standard event classes. */
    private static ?array $cachedcatalog = null;

    /**
     * Get list of supported standard, popular, and all discovered Moodle events with human-readable labels.
     *
     * @return array Array mapping event class name to display label.
     */
    public static function get_event_options(): array {
        if (self::$cachedcatalog !== null) {
            return self::$cachedcatalog;
        }

        // Try reading from MUC application cache first for sub-millisecond response.
        try {
            $cache = \cache::make('local_webhookengine', 'eventmap');
            $cached = $cache->get('full_catalog');
            if (is_array($cached) && !empty($cached)) {
                self::$cachedcatalog = $cached;
                return self::$cachedcatalog;
            }
        } catch (\Throwable $e) {
            // Cache not ready, fallback to in-memory generation.
        }

        $events = [
            // Standard Core & Popular Events.
            '\\mod_quiz\\event\\attempt_submitted' => 'Quiz: Attempt submitted (mod_quiz)',
            '\\mod_quiz\\event\\attempt_started' => 'Quiz: Attempt started (mod_quiz)',
            '\\mod_quiz\\event\\attempt_preview_started' => 'Quiz: Attempt preview started (mod_quiz)',
            '\\mod_quiz\\event\\attempt_abandoned' => 'Quiz: Attempt abandoned (mod_quiz)',
            '\\mod_quiz\\event\\attempt_reviewed' => 'Quiz: Attempt reviewed (mod_quiz)',

            '\\core\\event\\course_completed' => 'Course: Course completed (core)',
            '\\core\\event\\course_created' => 'Course: Course created (core)',
            '\\core\\event\\course_updated' => 'Course: Course updated (core)',
            '\\core\\event\\course_deleted' => 'Course: Course deleted (core)',
            '\\core\\event\\course_module_completed' => 'Course: Activity completed (core)',
            '\\core\\event\\course_viewed' => 'Course: Course viewed (core)',

            '\\core\\event\\user_enrolment_created' => 'Enrolment: User enrolled in course (core)',
            '\\core\\event\\user_enrolment_updated' => 'Enrolment: User enrolment updated (core)',
            '\\core\\event\\user_enrolment_deleted' => 'Enrolment: User unenrolled from course (core)',
            '\\core\\event\\user_created' => 'User: User account created (core)',
            '\\core\\event\\user_updated' => 'User: User profile updated (core)',
            '\\core\\event\\user_deleted' => 'User: User account deleted (core)',
            '\\core\\event\\user_loggedin' => 'User: User logged in (core)',
            '\\core\\event\\user_loggedout' => 'User: User logged out (core)',

            '\\mod_assign\\event\\assessable_submitted' => 'Assignment: Submission submitted (mod_assign)',
            '\\mod_assign\\event\\submission_graded' => 'Assignment: Submission graded (mod_assign)',
            '\\mod_assign\\event\\submission_created' => 'Assignment: Submission created (mod_assign)',

            '\\core\\event\\badge_awarded' => 'Badges: Badge awarded (core)',
            '\\core\\event\\badge_revoked' => 'Badges: Badge revoked (core)',

            '\\mod_forum\\event\\discussion_created' => 'Forum: Discussion topic created (mod_forum)',
            '\\mod_forum\\event\\post_created' => 'Forum: Discussion post created (mod_forum)',

            '\\core\\event\\user_graded' => 'Grade: User graded (core)',
            '\\core\\event\\grade_item_created' => 'Grade: Grade item created (core)',

            '\\mod_feedback\\event\\response_submitted' => 'Feedback: Response submitted (mod_feedback)',
            '\\mod_choice\\event\\answer_submitted' => 'Choice: Answer submitted (mod_choice)',

            '\\core\\event\\cohort_member_added' => 'Cohort: Member added (core)',
            '\\core\\event\\cohort_member_removed' => 'Cohort: Member removed (core)',
            '\\core\\event\\group_member_added' => 'Group: Member added (core)',
            '\\core\\event\\group_member_removed' => 'Group: Member removed (core)',
        ];

        asort($events);
        self::$cachedcatalog = $events;

        try {
            if (isset($cache)) {
                $cache->set('full_catalog', $events);
            }
        } catch (\Throwable $e) {
            // Ignore cache write error.
        }

        return self::$cachedcatalog;
    }

    /**
     * Check if an event is in the security denylist (e.g. password updates).
     *
     * @param string $eventname Event class name.
     * @return bool True if blacklisted.
     */
    public static function is_denylisted(string $eventname): bool {
        $denylist = [
            '\\core\\event\\user_login_failed',
            '\\core\\event\\user_password_updated',
        ];

        $customdeny = get_config('local_webhookengine', 'eventdenylist');
        if (!empty($customdeny)) {
            $lines = preg_split('/[\r\n,]+/', $customdeny);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $denylist[] = $line;
                }
            }
        }

        return in_array($eventname, $denylist);
    }

    /**
     * Return whether an event requires the Pro edition.
     *
     * @param string $eventname Fully-qualified event class name.
     * @return bool True if this event is Pro-only in the current edition.
     */
    public static function is_pro_event(string $eventname): bool {
        if (pro_unlock::is_pro()) {
            return false;
        }
        return !pro_unlock::is_event_allowed($eventname);
    }

    /**
     * Return all event options split into free and pro groups, suitable for
     * rendering a tiered selector in the webhook edit form.
     *
     * @return array[] Associative array with keys 'free' and 'pro', each
     *                 mapping event class name => display label.
     */
    public static function get_event_options_with_tier(): array {
        $all = self::get_event_options();
        $free = [];
        $pro = [];
        foreach ($all as $eventname => $label) {
            if (pro_unlock::is_event_allowed($eventname)) {
                $free[$eventname] = $label;
            } else {
                $pro[$eventname] = $label . ' ' . get_string('pro_badge', 'local_webhookengine');
            }
        }
        return ['free' => $free, 'pro' => $pro];
    }
}
