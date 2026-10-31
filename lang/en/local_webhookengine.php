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
$string['archive_freq_14d'] = 'Every 14 days (Bi-weekly)';
$string['archive_freq_30d'] = 'Every 30 days (Monthly)';
$string['archive_freq_7d'] = 'Every 7 days (Weekly)';
$string['archive_ret_180d'] = '180 days (6 months)';
$string['archive_ret_365d'] = '365 days (1 year)';
$string['archive_ret_90d'] = '90 days (3 months)';
$string['archive_ret_indefinite'] = 'Keep forever (Never auto-delete)';
$string['archived_backups'] = 'Archived backups';
$string['archives'] = 'Archives';
$string['archives_for_hook'] = 'Archived logs for {$a}';
$string['attempts'] = 'Attempts';
$string['autopause_reason_duration'] = 'Endpoint has had no successful deliveries for over 3 consecutive days.';
$string['autopause_reason_streak'] = 'Endpoint exceeded {$a} consecutive delivery failures.';
$string['blocked_host'] = 'Target destination host is blocked by site security policy.';
$string['cachedef_eventmap'] = 'Cached mapping of Moodle events to active webhook endpoints.';
$string['cachedef_ratelimit'] = 'Short-lived rate limit counters for webhook deliveries.';
$string['clear_headers'] = 'Clear stored custom headers';
$string['confirm_delete_hook'] = 'Are you sure you want to delete the webhook "{$a}"?';
$string['confirm_replay_all'] = 'Are you sure you want to requeue all failed deliveries for this webhook?';
$string['confirm_rotate_secret'] = 'Are you sure you want to rotate the signing secret? The previous secret will stop working immediately.';
$string['continue_to_manage'] = 'Continue to Webhooks';
$string['copy_secret'] = 'Copy secret';
$string['course_id'] = 'Course ID';
$string['created_date'] = 'Created: {$a}';
$string['cron_delayed_warning'] = 'Oldest queued delivery is over 15 minutes old. Verify that Moodle cron is running every minute.';
$string['custom_events_hint'] = 'You can select from Moodle events below, or type any custom third-party event class name directly.';
$string['custom_headers'] = 'Custom HTTP headers';
$string['custom_headers_help'] = 'Enter optional custom headers in "Name: value" format, one per line (max 10). Sensitive header values will be encrypted at rest.';
$string['deliveries'] = 'Deliveries log';
$string['deliveries_for_hook'] = 'Deliveries for {$a}';
$string['destination_host'] = 'Destination host';
$string['download_archive'] = 'Download archive';
$string['download_deliveries'] = 'Download delivery logs';
$string['drops_badge'] = '{$a} dropped';
$string['drops_count'] = 'Dropped events';
$string['duration_ms'] = 'Duration';
$string['edit_hook'] = 'Edit webhook: {$a}';
$string['enabled'] = 'Enabled';
$string['error_blocked_url'] = 'Delivery URL is blocked by Moodle HTTP security restrictions.';
$string['error_domain_not_allowed'] = 'Destination domain is not permitted by site security policy.';
$string['error_event_denylisted'] = 'The event "{$a}" is in the security denylist and cannot be subscribed to.';
$string['error_hook_not_found'] = 'Webhook configuration not found.';
$string['error_invalid_action'] = 'Invalid action requested.';
$string['error_invalid_header_format'] = 'Invalid header line format: {$a}. Use "Name: value".';
$string['error_invalid_json_template'] = 'Custom payload template is not valid JSON.';
$string['error_invalid_url'] = 'Please enter a valid URL.';
$string['error_maxattempts_range'] = 'Max attempts must be between 1 and 10.';
$string['error_no_events_selected'] = 'You must select at least one event to subscribe to.';
$string['error_post_only'] = 'State changes are only accepted via POST requests.';
$string['error_replay_payload_purged'] = 'Cannot replay delivery: payload has been purged in compliance with data retention policy.';
$string['error_reserved_header'] = 'Header name "{$a}" is reserved and cannot be set manually.';
$string['error_secret_unreadable'] = 'Webhook signing secret could not be decrypted.';
$string['error_template_too_large'] = 'Custom template exceeds maximum allowed size of 16 KB.';
$string['error_timeout_range'] = 'Timeout must be between 1 and 30 seconds.';
$string['error_too_many_headers'] = 'Maximum of 10 custom headers allowed.';
$string['error_unknown_placeholder'] = 'Unknown placeholder path "{$a}" in template.';
$string['error_unresolvable_host'] = 'Destination host could not be resolved via DNS.';
$string['event_catalog'] = 'Event catalog';
$string['event_delivery_replayed'] = 'Webhook delivery replayed';
$string['event_hook_created'] = 'Webhook created';
$string['event_hook_deleted'] = 'Webhook deleted';
$string['event_hook_updated'] = 'Webhook updated';
$string['event_name'] = 'Event name';
$string['event_secret_rotated'] = 'Webhook secret rotated';
$string['event_test_sent'] = 'Webhook test event dispatched';
$string['events_count'] = 'Events';
$string['events_field_help'] = 'Select one or more events from the curated list, or type any custom or third-party plugin event class name and press Enter to subscribe.';
$string['events_subscription'] = 'Event subscriptions';
$string['fail_streak_badge'] = '{$a} fails';
$string['filename'] = 'File name';
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
$string['include_personal_data'] = 'Include full payloads and personal data (Restricted)';
$string['killswitch_active'] = 'Killswitch ACTIVE';
$string['last_success'] = 'Last success';
$string['local/webhookengine:exportlogs'] = 'Export webhook delivery audit logs';
$string['local/webhookengine:exportpersonaldata'] = 'Export webhook payloads containing personal data';
$string['local/webhookengine:manage'] = 'Manage and configure webhook endpoints';
$string['local/webhookengine:replay'] = 'Replay webhook deliveries and trigger test events';
$string['local/webhookengine:viewlogs'] = 'View webhook delivery logs';
$string['manage_hooks'] = 'Event Webhooks';
$string['max_attempts'] = 'Maximum retry attempts';
$string['message_hookpaused_body'] = 'Webhook endpoint "{$a->name}" has been automatically paused due to consecutive delivery failures. Reason: {$a->reason}. Please review your endpoint status at {$a->url}.';
$string['message_hookpaused_small'] = 'Webhook "{$a}" has been automatically paused.';
$string['message_hookpaused_subject'] = 'Webhook auto-paused: {$a}';
$string['messageprovider:hookpaused'] = 'Notification when a webhook is paused due to repeated delivery failures';
$string['messageprovider:ratelimit_alert'] = 'Notification when a webhook endpoint reaches its rate limit';
$string['network_delivery'] = 'Network and delivery';
$string['never'] = 'Never';
$string['new_secret_title'] = 'Webhook created successfully';
$string['new_secret_warning'] = 'Save your signing secret now. For security reasons, it will never be displayed again.';
$string['no_archives_available'] = 'No archived log packages available yet.';
$string['no_hooks_configured'] = 'No webhooks have been created yet.';
$string['oldest_queued_age'] = 'Oldest queued age';
$string['pause'] = 'Pause';
$string['payload'] = 'Payload';
$string['payload_includecourse'] = 'Include course details (id, shortname, fullname, categoryid)';
$string['payload_includeemail'] = 'Include user email address (requires user details)';
$string['payload_includeother'] = 'Include event "other" metadata array';
$string['payload_includeuser'] = 'Include user details (id, username, firstname, lastname)';
$string['payload_options'] = 'Payload configuration';
$string['payload_otherkeys'] = 'Filter specific "other" keys (comma-separated, optional)';
$string['payload_purged'] = 'Purged';
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
$string['ratelimit_alert_body'] = 'Webhook "{$a->hookname}" (ID: {$a->hookid}) reached its rate limit threshold at {$a->time}. Subsequent events are being recorded in the drop ledger.';
$string['ratelimit_alert_subject'] = 'Webhook rate limit reached: {$a}';
$string['reason_hook_backlog_exceeded'] = 'Hook retry backlog exceeded';
$string['reason_hook_disabled_or_missing'] = 'Hook disabled or deleted';
$string['reason_hook_paused'] = 'Hook paused';
$string['reason_killswitch_enabled'] = 'Kill switch active';
$string['reason_observer_error'] = 'Observer error';
$string['reason_queue_overload'] = 'Global queue overloaded';
$string['reason_rate_limited'] = 'Rate limited';
$string['related_user_id'] = 'Related User ID';
$string['replay'] = 'Replay';
$string['replay_all_failed'] = 'Replay all failed';
$string['replayed_count_notice'] = '{$a} deliveries requeued for background dispatch.';
$string['resume'] = 'Resume';
$string['rotate_secret'] = 'Rotate secret';
$string['search_events'] = 'Search popular events, or type custom event name...';
$string['secret_already_revealed_desc'] = 'This signing secret has already been revealed and securely destroyed from server memory. Webhook signing secrets are never stored in plaintext and cannot be displayed again. If you have misplaced your secret, use the Rotate Secret action on the webhook configuration page.';
$string['secret_already_revealed_title'] = 'Signing secret already viewed or expired';
$string['secret_copied'] = 'Secret copied to clipboard.';
$string['secret_rotated_alert'] = 'New signing secret generated: {$a}. Copy it now as it will not be displayed again.';
$string['secret_rotated_title'] = 'Webhook Secret Rotated';
$string['select_events'] = 'Select events';
$string['send_test'] = 'Send test event';
$string['setting_alloweddestinationdomains'] = 'Destination domain allowlist';
$string['setting_alloweddestinationdomains_desc'] = 'Optional domain allowlist. When configured, outbound webhooks may only be sent to endpoints matching one of these domains or wildcard patterns (one per line, e.g. api.example.com, *.partner.org). Leave empty to allow any valid public destination.';
$string['setting_allowedinternalhosts'] = 'Internal network and host allowlist';
$string['setting_allowedinternalhosts_desc'] = 'List of internal hostnames, IP addresses, or CIDR subnets (one per line, e.g. 10.0.0.0/8, 192.168.1.50, webhook.internal) permitted to receive webhooks.';
$string['setting_allowinsecurehttp'] = 'Allow plain HTTP endpoints (development only)';
$string['setting_allowinsecurehttp_desc'] = 'When enabled, plain HTTP endpoints can be configured. Strictly for local development and testing.';
$string['setting_archive_frequency'] = 'Archive frequency';
$string['setting_archive_frequency_desc'] = 'How often delivery records should be bundled into compressed .csv.gz archives.';
$string['setting_archive_include_payload'] = 'Include raw payloads in archives (GDPR warning)';
$string['setting_archive_include_payload_desc'] = 'When enabled, raw JSON payloads and user IDs will be included in the compressed archive files. Ensure compliance with institution data protection policies.';
$string['setting_archive_retention'] = 'Archive file retention';
$string['setting_archive_retention_desc'] = 'How long compressed archive files are retained in storage before automated deletion.';
$string['setting_defaulttimeout'] = 'Default request timeout (seconds)';
$string['setting_defaulttimeout_desc'] = 'Default HTTP connection and read timeout for outbound webhook requests.';
$string['setting_enable_auto_archive'] = 'Enable automated log archiving';
$string['setting_enable_auto_archive_desc'] = 'When enabled, delivery logs are automatically compressed into .csv.gz archives and stored in secure Moodle storage before database cleanup.';
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
$string['signing_secret'] = 'Signing secret';
$string['status'] = 'Status';
$string['status_active'] = 'Active';
$string['status_dropped'] = 'Dropped';
$string['status_dropped_reason'] = 'Dropped: {$a}';
$string['status_failed'] = 'Failed';
$string['status_paused'] = 'Paused';
$string['status_queued'] = 'Queued';
$string['status_retrying'] = 'Retrying';
$string['status_success'] = 'Success';
$string['support_link_text'] = 'Event Webhooks - Documentation and Support';
$string['system_operational'] = 'System operational';
$string['system_status'] = 'System status';
$string['task_archive_deliveries'] = 'Archive expired webhook delivery logs';
$string['task_check_health'] = 'Webhook health monitor and auto-pause check';
$string['task_purge_logs'] = 'Purge expired webhook logs and payload retention';
$string['task_send_delivery'] = 'Send webhook delivery';
$string['test_sent_result'] = 'Test event dispatched. HTTP {$a->status}, duration {$a->duration} ms. Error details: {$a->error}.';
$string['time_created'] = 'Queued at';
$string['timeout_seconds'] = 'Request timeout (seconds)';
$string['user_id'] = 'User ID';
$string['uuid'] = 'Delivery UUID';
$string['webhookengine:exportlogs'] = 'Export webhook delivery audit logs';
$string['webhookengine:exportpersonaldata'] = 'Export webhook payloads containing personal data';
$string['webhookengine:manage'] = 'Manage and configure webhook endpoints';
$string['webhookengine:replay'] = 'Replay webhook deliveries and trigger test events';
$string['webhookengine:viewlogs'] = 'View webhook delivery logs';
