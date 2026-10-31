# Changelog

All notable changes to the `local_webhookengine` plugin will be documented in this file.

## [1.0.3] - 2026-11-01

### Fixed
- Fixed critical header-wipe regression where pausing or resuming a webhook deleted stored encrypted custom authorization headers. Introduced surgical `hook_repository::set_hook_status()` method to guarantee credential isolation during lifecycle state transitions.
- Hardened webhook replay safety: strictly reject replaying deliveries whose payload has been purged under GDPR data retention policies (`payload_purged_cannot_replay`), preventing dispatch of synthetic `{}` empty bodies over the network.
- Enhanced replay idempotency: manual replays reset attempt counters to 0 while preserving RFC 4122 delivery UUIDs for downstream deduplication.
- Added comprehensive unit test coverage (`tests/hook_repository_test.php`, `tests/delivery_replay_test.php`) verifying header preservation and replay payload integrity.

## [1.0.2] - 2026-10-27

### Fixed
- Added missing `status_queued` language string in `lang/en/local_webhookengine.php` for pending deliveries.
- Normalized event names with leading backslashes (`'\\' . ltrim($name, '\\')`) across observer and repository to ensure custom event class subscriptions match correctly.
- Replaced dead support link in `manage.php` footer with active public repository link.
- Aligned documentation files (`documentation.html` and `README.md`) to explicitly declare Moodle 4.5 to 5.2 certified support.

## [1.0.1] - 2026-10-13

### Changed
- Removed all licensing restrictions and tier gates to provide full, unrestricted functionality under GNU GPL v3.
- Migrated HTTP client to Moodle core `\curl` helper with strict SSRF protection.
- Enforced unconditional localhost and private IP validation in URL validator.
- Updated database message provider defaults to use integer bitmasks.
- Switched secret encryption exclusively to Moodle core encryption subsystem.

### Added
- One-time dismissible alert for newly generated webhook signing secrets with copy-to-clipboard action.
- Missing language strings for capabilities and message providers.
- Moodle GPL license boilerplate docblocks in all Mustache templates.

## [1.0.0] - 2026-09-20

### Added
- Generic Moodle event capture engine for any core or third-party event.
- Standard Webhooks v1 signature specification compliance (`webhook-id`, `webhook-timestamp`, `webhook-signature`).
- High-efficiency observer with zero database queries for non-subscribed events.
- Resilient asynchronous background delivery via Moodle ad-hoc tasks.
- Advanced retry backoff schedule (1m, 5m, 15m, 1h, 3h, 6h, 12h, 24h) with jitter.
- Health monitor with automatic pausing for persistently failing endpoints.
- Event filtering by Course ID and Category hierarchy (with recursive subcategories).
- Opt-in payload enrichments for user data, email, course information, and raw event data.
- Custom JSON payload templating engine with strict placeholder validation.
- End-to-end secret and custom header encryption using `\core\encryption`.
- Complete Privacy API implementation with full export, deletion, and external data mapping.
- Full administration interface: Manage Webhooks, Edit/Create form, Deliveries log, and Replay capabilities.
