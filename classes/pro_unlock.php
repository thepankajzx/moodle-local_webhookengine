<?php
namespace local_webhookengine;

class pro_unlock {
    public static function is_pro(): bool {
        return false; // Hardcoded false for Lite version
    }
    public static function is_event_allowed(string $eventname): bool {
        $free = [
            '\\mod_quiz\\event\\attempt_submitted',
            '\\mod_quiz\\event\\attempt_started',
            '\\core\\event\\course_completed',
            '\\core\\event\\user_enrolment_created',
            '\\core\\event\\user_created',
            '\\core\\event\\user_loggedin',
            '\\mod_assign\\event\\assessable_submitted',
            '\\mod_assign\\event\\submission_graded',
            '\\core\\event\\badge_awarded',
            '\\mod_forum\\event\\discussion_created'
        ];
        return in_array($eventname, $free);
    }
    public static function get_upgrade_url(): string {
        return 'https://acuityos.com';
    }
    public static function can_create_hook(): bool {
        global $DB;
        return $DB->count_records('local_webhookengine_hook') < 5;
    }
}
