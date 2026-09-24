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
 * English language strings for local_webhookengine.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['add_new_hook'] = 'Add new webhook';
$string['attempts'] = 'Attempts';
$string['autopause_reason_duration'] = 'Endpoint has had no successful deliveries for over 3 consecutive days.';
$string['autopause_reason_streak'] = 'Endpoint exceeded {$a} consecutive delivery failures.';
$string['blocked_host'] = 'Target destination host is blocked by site security policy.';
$string['cachedef_eventmap'] = 'Cached mapping of Moodle events to active webhook endpoints.';
$string['cachedef_ratelimit'] = 'Short-lived rate limit counters for webhook deliveries.';
$string['confirm_delete_hook'] = 'Are you sure you want to delete the webhook "{$a}"?';
$string['confirm_replay_all'] = 'Are you sure you want to requeue all failed deliveries for this webhook?';
$string['confirm_rotate_secret'] = 'Are you sure you want to rotate the signing secret? The previous secret will stop working immediately.';
$string['cron_delayed_warning'] = 'Oldest queued delivery is over 15 minutes old. Verify that Moodle cron is running every minute.';
$string['custom_headers'] = 'Custom HTTP headers';
$string['custom_headers_help'] = 'Enter optional custom headers in "Name: value" format, one per line (max 10). Sensitive header values will be encrypted at rest.';
$string['deliveries'] = 'Deliveries log';
$string['deliveries_for_hook'] = 'Deliveries for {$a}';
$string['destination_host'] = 'Destination host';
$string['duration_ms'] = 'Duration';
$string['edit_hook'] = 'Edit webhook: {$a}';
$string['enabled'] = 'Enabled';
$string['error_blocked_url'] = 'Delivery URL is blocked by Moodle HTTP security restrictions.';
$string['error_event_denylisted'] = 'The event "{$a}" is in the security denylist and cannot be subscribed to.';
$string['error_hook_not_found'] = 'Webhook configuration not found.';
$string['error_invalid_action'] = 'Invalid action requested.';
$string['error_invalid_header_format'] = 'Invalid header line format: {$a}. Use "Name: value".';
$string['error_invalid_json_template'] = 'Custom payload template is not valid JSON.';
$string['error_invalid_url'] = 'Please enter a valid URL.';
$string['error_maxattempts_range'] = 'Max attempts must be between 1 and 10.';
$string['error_no_events_selected'] = 'You must select at least one event to subscribe to.';
$string['error_post_only'] = 'State changes are only accepted via POST requests.';
$string['error_reserved_header'] = 'Header name "{$a}" is reserved and cannot be set manually.';
$string['error_secret_unreadable'] = 'Webhook signing secret could not be decrypted.';
$string['error_template_too_large'] = 'Custom template exceeds maximum allowed size of 16 KB.';
$string['error_timeout_range'] = 'Timeout must be between 1 and 30 seconds.';
$string['error_too_many_headers'] = 'Maximum of 10 custom headers allowed.';
$string['error_unknown_placeholder'] = 'Unknown placeholder path "{$a}" in template.';
$string['event_catalog'] = 'Event catalog';
$string['event_delivery_replayed'] = 'Webhook delivery replayed';
$string['event_hook_created'] = 'Webhook created';
$string['event_hook_deleted'] = 'Webhook deleted';
$string['event_hook_updated'] = 'Webhook updated';
$string['event_name'] = 'Event name';
$string['event_secret_rotated'] = 'Webhook secret rotated';
$string['event_test_sent'] = 'Webhook test event dispatched';
$string['events_count'] = 'Events';
$string['events_subscription'] = 'Event subscriptions';
$string['fail_streak_badge'] = '{$a} fails';
$string['filter_categoryids'] = 'Course category IDs';
$string['filter_categoryids_help'] = 'Comma-separated course category IDs to limit notifications to.';
$string['filter_courseids'] = 'Course IDs';
$string['filter_courseids_help'] = 'Comma-separated course IDs to limit notifications to. Leave empty to capture events across all courses.';
$string['filter_includesubcats'] = 'Include subcategories in category filter';
$string['filter_realusersonly'] = 'Only real user actions';
$string['filter_realusersonly_help'] = 'Ignore background automated events where the actor is guest or user ID 0.';
$string['filters'] = 'Scope and filters';
$string['hook_created_success'] = 'Webhook created successfully. Save your signing secret now: it will never be displayed again.';
$string['hook_deleted_success'] = 'Webhook deleted successfully.';
$string['hook_name'] = 'Webhook name';
$string['hook_updated_success'] = 'Webhook configuration updated successfully.';
$string['hook_url'] = 'Endpoint URL';
$string['hook_url_help'] = 'Target HTTPS URL where webhook payloads will be posted.';
$string['hookpaused'] = 'Webhook paused notification';
$string['hooks'] = 'Webhooks';
$string['http_credentials_rejected'] = 'Credentials (username/password) in URLs are strictly forbidden.';
$string['http_not_allowed'] = 'Only secure HTTPS URLs are permitted.';
$string['http_status'] = 'HTTP status';
$string['id'] = 'ID';
$string['killswitch_active'] = 'Killswitch ACTIVE';
$string['last_success'] = 'Last success';
$string['local/webhookengine:manage'] = 'Manage and configure webhook endpoints';
$string['local/webhookengine:replay'] = 'Replay webhook deliveries and trigger test events';
$string['local/webhookengine:viewlogs'] = 'View webhook delivery logs';
$string['manage_hooks'] = 'Event Webhooks';
$string['max_attempts'] = 'Maximum retry attempts';
$string['message_hookpaused_body'] = 'Webhook endpoint "{$a->name}" has been automatically paused due to consecutive delivery failures. Reason: {$a->reason}. Please review your endpoint status at {$a->url}.';
$string['message_hookpaused_small'] = 'Webhook "{$a}" has been automatically paused.';
$string['message_hookpaused_subject'] = 'Webhook auto-paused: {$a}';
$string['network_delivery'] = 'Network and delivery';
$string['never'] = 'Never';
$string['no_hooks_configured'] = 'No webhooks have been created yet.';
$string['oldest_queued_age'] = 'Oldest queued age';
$string['pause'] = 'Pause';
$string['payload_includecourse'] = 'Include course details (id, shortname, fullname, categoryid)';
$string['payload_includeemail'] = 'Include user email address (requires user details)';
$string['payload_includeother'] = 'Include event "other" metadata array';
$string['payload_includeuser'] = 'Include user details (id, username, firstname, lastname)';
$string['payload_options'] = 'Payload configuration';
$string['payload_otherkeys'] = 'Filter specific "other" keys (comma-separated, optional)';
$string['payload_template'] = 'Custom JSON payload template (optional)';
$string['payload_template_help'] = 'Optional custom JSON template. Placeholders like {{event.courseid}}, {{user.firstname}} will be replaced safely with JSON-encoded values.';
$string['pluginname'] = 'Event Webhooks';
$string['privacy:metadata:hook:createdby'] = 'The administrator user who configured this webhook.';
$string['privacy:metadata:local_webhookengine_delivery'] = 'Log of webhook event deliveries and outcomes.';
$string['privacy:metadata:local_webhookengine_delivery:courseid'] = 'The course associated with the event.';
$string['privacy:metadata:local_webhookengine_delivery:eventname'] = 'The Moodle event name dispatched.';
$string['privacy:metadata:local_webhookengine_delivery:payload'] = 'Temporary event payload content.';
$string['privacy:metadata:local_webhookengine_delivery:relateduserid'] = 'The target or related user for the event.';
$string['privacy:metadata:local_webhookengine_delivery:timecreated'] = 'Timestamp when the event delivery was queued.';
$string['privacy:metadata:local_webhookengine_delivery:userid'] = 'The user who triggered the event.';
$string['privacy:metadata:local_webhookengine_hook'] = 'Webhook endpoint configurations.';
$string['privacy:metadata:local_webhookengine_hook:createdby'] = 'The administrator who created the webhook.';
$string['privacy:metadata:local_webhookengine_hook:name'] = 'The name given to the webhook destination.';
$string['privacy:metadata:local_webhookengine_hook:timecreated'] = 'Timestamp when the webhook was created.';
$string['privacy:metadata:local_webhookengine_hook:url'] = 'The destination URL where webhooks are delivered.';
$string['privacy:metadata:webhook_endpoint'] = 'External HTTP destination receiving signed event notifications.';
$string['privacy:metadata:webhook_endpoint:course_details'] = 'Course identification details if opted in.';
$string['privacy:metadata:webhook_endpoint:courseid'] = 'Course identifier associated with the event.';
$string['privacy:metadata:webhook_endpoint:eventname'] = 'Event class name dispatched.';
$string['privacy:metadata:webhook_endpoint:relateduserid'] = 'Related user identifier.';
$string['privacy:metadata:webhook_endpoint:user_details'] = 'User name and optional email if opted in.';
$string['privacy:metadata:webhook_endpoint:userid'] = 'User identifier of the actor.';
$string['queued_deliveries'] = 'Queued deliveries';
$string['replay'] = 'Replay';
$string['replay_all_failed'] = 'Replay all failed';
$string['replayed_count_notice'] = '{$a} deliveries requeued for background dispatch.';
$string['resume'] = 'Resume';
$string['rotate_secret'] = 'Rotate secret';
$string['search_events'] = 'Search popular events, or type custom event name...';
$string['search_all_events'] = 'Search or select from all 600+ Moodle events, or type custom event...';
$string['custom_events_hint'] = 'Pro unlocked: You can select from all 600+ Moodle events below, or type any custom third-party event class name directly.';
$string['events_field_help'] = 'Select one or more events from the curated list, or type any custom or third-party plugin event class name and press Enter to subscribe.';
$string['secret_rotated_alert'] = 'New signing secret generated: {$a}. Copy it now as it will not be displayed again.';
$string['select_events'] = 'Select events';
$string['send_test'] = 'Send test event';
$string['setting_allowinsecurehttp'] = 'Allow plain HTTP endpoints (development only)';
$string['setting_allowinsecurehttp_desc'] = 'When enabled, plain HTTP endpoints can be configured. Strictly for local development and testing.';
$string['setting_defaulttimeout'] = 'Default request timeout (seconds)';
$string['setting_defaulttimeout_desc'] = 'Default HTTP connection and read timeout for outbound webhook requests.';
$string['setting_eventdenylist'] = 'Event denylist';
$string['setting_eventdenylist_desc'] = 'List of fully-qualified event class names (one per line) that cannot be subscribed to.';
$string['setting_globalqueuemax'] = 'Global queue maximum limit';
$string['setting_globalqueuemax_desc'] = 'Maximum number of pending deliveries in queue before rate shed protection triggers.';
$string['setting_killswitch'] = 'Global kill switch';
$string['setting_killswitch_desc'] = 'When activated, halts all webhook processing and drops incoming event queueing immediately.';
$string['setting_logretention'] = 'Delivery log retention (days)';
$string['setting_logretention_desc'] = 'Number of days to keep delivery records before purging.';
$string['setting_payloadretention'] = 'Payload data retention (days)';
$string['setting_payloadretention_desc'] = 'Number of days to retain payload content before clearing to conserve space.';
$string['setting_ratecapperhook'] = 'Per-hook rate limit (events/minute)';
$string['setting_ratecapperhook_desc'] = 'Maximum number of events a single hook will process per minute during event surges.';
$string['settings'] = 'Settings';
$string['status'] = 'Status';
$string['status_active'] = 'Active';
$string['status_dropped'] = 'Dropped';
$string['status_failed'] = 'Failed';
$string['status_paused'] = 'Paused';
$string['status_retrying'] = 'Retrying';
$string['status_success'] = 'Success';
$string['support_link_text'] = 'Event Webhooks - Documentation and Support';
$string['system_operational'] = 'System operational';
$string['system_status'] = 'System status';
$string['task_check_health'] = 'Webhook health monitor and auto-pause check';
$string['task_purge_logs'] = 'Purge expired webhook logs and payload retention';
$string['task_send_delivery'] = 'Send webhook delivery';
$string['test_sent_result'] = 'Test event dispatched. HTTP {$a->status}, duration {$a->duration} ms. Error details: {$a->error}.';
$string['time_created'] = 'Queued at';
$string['timeout_seconds'] = 'Request timeout (seconds)';
$string['uuid'] = 'Delivery UUID';
$string['pro_badge'] = '[PRO]';
$string['free_events_notice'] = 'Free edition: only quiz events are available.';
$string['free_hook_limit_reached'] = 'The free edition is limited to a maximum of {$a} webhooks. Please upgrade to Pro for unlimited webhooks.';
$string['free_hooks_usage'] = 'Free edition: {$a->used} of {$a->max} webhooks used.';
$string['pro_events_locked'] = 'The following features require the Pro edition:';
$string['pro_events_locked_heading'] = 'Locked Pro features';
$string['pro_feature_core_events'] = 'All 160+ Moodle core events (assignments, grades, forums, enrolments, badges, etc.)';
$string['pro_feature_custom_events'] = 'Customized and third-party plugin events (direct class name tagging)';
$string['pro_feature_headers_filters'] = 'Custom HTTP request headers and unlimited endpoints';
$string['upgrade_to_pro'] = 'Upgrade to Pro';
$string['activation_code'] = 'Pro Activation Code';
$string['activation_code_desc'] = 'Paste the activation code you received after purchasing the Pro edition to unlock all events instantly.';
$string['pro_active'] = 'Pro Edition: Active';
$string['pro_active_desc'] = 'All events and premium features are unlocked.';
$string['activation_invalid'] = 'Invalid activation code. Please check and try again.';
