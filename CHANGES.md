# Changelog

All notable changes to the `local_webhookengine` plugin will be documented in this file.

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
