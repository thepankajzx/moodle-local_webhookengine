# Changelog

All notable changes to the `local_webhookengine` plugin will be documented in this file.

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
